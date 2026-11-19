import { normalizeSnowflakeAccountUrl } from '@oh-my-pi/pi-ai/registry/snowflake';
import { isLocalGateway, localProviderUrl } from './local-providers';
import { PROVIDER_DESCRIPTORS, factoryDroidModelManagerOptions } from '@oh-my-pi/pi-catalog/provider-models';
import type { AccountScope } from '@oh-my-pi/pi-catalog/wire/factory-droid';
import type { FetchImpl } from '@oh-my-pi/pi-catalog';
import { CODEX_CLIENT_VERSION, getBundledModel, getBundledModels, getCodexAccountId, getGitLabDuoModels,
  openaiCodexModelManagerOptions, googleGeminiCliModelManagerOptions, googleAntigravityModelManagerOptions,
  kiroModelManagerOptions, githubCopilotModelManagerOptions, devinModelManagerOptions, cursorModelManagerOptions } from '@oh-my-pi/pi-catalog';
import { buildModel } from '@oh-my-pi/pi-catalog/build';
import type { Api, Model, ModelManagerOptions, ModelSpec } from '@oh-my-pi/pi-catalog';
import { invalidRequest, record, RouteError } from './turns';

export const routes = {
  openai: {api: 'openai-responses', baseUrl: 'https://api.openai.com/v1'},
  anthropic: {api: 'anthropic-messages', baseUrl: 'https://api.anthropic.com'},
  ollama: {api: 'ollama-chat', baseUrl: 'http://host.docker.internal:11434'},
} as const;
export const validProvider = (s: unknown): s is string => typeof s === 'string' && s !== 'omp' && /^[a-z0-9][a-z0-9.-]{0,79}$/.test(s);
export const validModel = (s: unknown): s is string => typeof s === 'string' && s.length <= 240
  && /^~?[A-Za-z0-9][A-Za-z0-9_.:@+()[\]~-]*(?:\/[A-Za-z0-9_.:@+()[\]~-]+)*$/.test(s) && !/\/(?:\.|\.\.)(?:\/|$)/.test(s);
export function keyModel(provider: string, id: string) {
  if (!Object.hasOwn(routes, provider)) throw invalidRequest();
  const route = routes[provider as keyof typeof routes];
  const known = getBundledModel(provider, id);
  if (known && known.kind && !['chat', 'tiny'].includes(known.kind)) throw invalidRequest();
  // Unknown Ollama tags and newly released cloud models need a materialized identity/compat object, too.
  return known ? {...known, ...route, requestModelId: id} : buildModel({...route, provider, id, name: id, input: ['text'],
    reasoning: false, cost: {input: 0, output: 0, cacheRead: 0, cacheWrite: 0}, contextWindow: 32768, maxTokens: 2048});
}

// Credential endpoints may narrow a catalog route (e.g. Copilot Business or Kiro's region),
// but are never arbitrary URLs. Unknown/self-hosted endpoints are not a SaaS credential target.
const extraHosts: Record<string, RegExp> = {
  'alibaba-token-plan': /^token-plan\.(?:ap-southeast-1|cn-beijing)\.maas\.aliyuncs\.com$/,
  'alibaba-coding-plan': /^coding(?:-intl)?\.dashscope\.aliyuncs\.com$/,
  'openai-codex': /^chatgpt\.com$/,
  'github-copilot': /^(?:api\.github\.com|api(?:\.(?:individual|business|enterprise))?\.githubcopilot\.com)$/,
  'google-gemini-cli': /^(?:cloudcode-pa|daily-cloudcode-pa(?:\.sandbox)?)\.googleapis\.com$/,
  'google-antigravity': /^(?:cloudcode-pa|daily-cloudcode-pa(?:\.sandbox)?)\.googleapis\.com$/,
  'factory-droid': /^api(?:\.eu)?\.factory\.ai$/,
  'gitlab-duo': /^(?:cloud\.)?gitlab\.com$/,
  kiro: /^(?:runtime|management)\.[a-z]{2}(?:-[a-z0-9]+)+-\d+\.kiro\.dev$/,
  cursor: /^api2\.cursor\.sh$/,
  devin: /^server\.codeium\.com$/,
};
const allowedApis = ['openai-completions', 'openai-responses', 'openai-codex-responses', 'anthropic-messages', 'ollama-chat',
  'openrouter', 'google-generative-ai', 'google-gemini-cli', 'kiro-api', 'factory-droid-agent', 'devin-agent', 'cursor-agent'];
// Dynamic-only official gateways have no bundled rows from which to derive an allowed origin.
const discoveryBase: Record<string, string> = {
  'charm-hyper': 'https://hyper.charm.land/v1', modal: 'https://inference.us-west.modal.direct/v1',
  otari: 'https://api.otari.ai/api/v1', siliconflow: 'https://api.siliconflow.com/v1', 'siliconflow-cn': 'https://api.siliconflow.cn/v1',
  'singularityapi-dev': 'https://api.singularityapi.dev/v1', 'singularityapi-tech': 'https://api.singularityapi.tech/v1',
};
export function destination(provider: string, url: URL, trustedBase?: string) {
  if (url.username || url.password || url.hash) return false;
  if (isLocalGateway(provider)) {
    const base = localProviderUrl(provider);
    return Boolean(base && (url.href === base + '/models' || url.href === base + '/chat/completions'));
  }
  if (provider === 'ollama') return url.origin === routes.ollama.baseUrl;
  if (url.protocol !== 'https:' || (url.port && url.port !== '443') || !url.hostname.includes('.')
    || /(?:\.localhost|\.local|\.internal|\.test|\.invalid|\.example)$/.test(url.hostname) || /^[\d.]+$/.test(url.hostname)) return false;
  return Boolean(trustedBase && url.origin === new URL(trustedBase).origin)
    || (Object.hasOwn(extraHosts, provider) && extraHosts[provider].test(url.hostname));
}
function supported(m: ModelSpec<Api> | Model<Api>, provider: string, trustedOrigins: Set<string>) {
  if (m.provider !== provider || !validModel(m.id) || (m.kind && m.kind !== 'chat' && m.kind !== 'tiny') || !allowedApis.includes(m.api)) return false;
  try {
    const url = new URL(m.baseUrl);
    return destination(provider, url, trustedOrigins.has(url.origin) ? url.origin : undefined);
  } catch { return false; }
}

export async function boundedBody(response: Response, limit: number) {
  const reader = response.body?.getReader();
  if (!reader) return Buffer.alloc(0);
  const chunks: Uint8Array[] = []; let length = 0;
  try {
    for (;;) {
      const {value, done} = await reader.read();
      if (done) break;
      length += value.byteLength;
      if (length > limit) { await reader.cancel(); throw new Error('Provider response exceeds size limit'); }
      chunks.push(value);
    }
    return Buffer.concat(chunks, length);
  } finally { reader.releaseLock(); }
}

/** No shared SDK model cache: subscription discovery uses only this account's explicit key. */
export async function accountModels(provider: string, key: string, transport: FetchImpl = fetch, scope?: AccountScope) {
  let bundled = provider === 'gitlab-duo' ? getGitLabDuoModels() : getBundledModels(provider);
  if (provider === 'snowflake') {
    const c = JSON.parse(key);
    if (!record(c) || typeof c.enterpriseUrl !== 'string') throw invalidRequest();
    const account = normalizeSnowflakeAccountUrl(c.enterpriseUrl);
    bundled = bundled.map(m => ({...m,baseUrl:m.baseUrl.replace(/^https:\/\/snowflake-account\.invalid(?=\/|$)/,account)}));
  }
  const origins = new Set(bundled.flatMap(m => { try { return [new URL(m.baseUrl).origin]; } catch { return []; } }));
  const defaultBase = Object.hasOwn(discoveryBase, provider) ? discoveryBase[provider] : undefined;
  if (defaultBase) origins.add(new URL(defaultBase).origin);
  const signal = AbortSignal.timeout(12000);
  const scopedFetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(input instanceof Request ? input.url : String(input));
    if (!destination(provider, url, origins.has(url.origin) ? url.origin : undefined)) throw new Error('Unknown inventory endpoint');
    const r = await transport(input, {...init, redirect: 'error', signal});
    return new Response(await boundedBody(r, 4 * 1024 * 1024), {status: r.status, headers: r.headers});
  };
  if (isLocalGateway(provider)) {
    const baseUrl = localProviderUrl(provider);
    if (!baseUrl) throw new RouteError(503,'ai_not_configured','Local provider port is not configured');
    const r = await scopedFetch(baseUrl + '/models', {headers:{Authorization: 'Bearer ' + key}});
    if (!r.ok) throw new RouteError(503,'ai_catalog_unavailable','Local provider model list unavailable');
    const data = await r.json(), models: Model<Api>[] = [];
    if (!Array.isArray(data?.data)) throw invalidRequest();
    for (const row of data.data) {
      if (!record(row) || !validModel(row.id)) continue;
      models.push(buildModel({id: row.id, name: row.id, provider, baseUrl, api: 'openai-completions', reasoning: false,
        input: ['text'], cost: {input:0,output:0,cacheRead:0,cacheWrite:0}, contextWindow:32768, maxTokens:2048}));
    }
    return {models,source:'account' as const};
  }
  let options: ModelManagerOptions | undefined;
  switch (provider) {
    case 'ollama': {
      const response = await scopedFetch(routes.ollama.baseUrl + '/api/tags', {headers:{Authorization: 'Bearer ' + key}});
      if (!response.ok) throw new RouteError(503, 'ai_catalog_unavailable', 'Ollama is unavailable');
      const data = await response.json();
      if (!Array.isArray(data?.models)) throw invalidRequest();
      return {models: data.models.filter((m: unknown) => record(m) && validModel(m.name))
        .map((m: {name: string}) => keyModel('ollama', m.name)) as Model<Api>[], source: 'account' as const};
    }
    case 'factory-droid': options = factoryDroidModelManagerOptions({...scope, apiKey: key, fetch: scopedFetch}); break;
    case 'openai-codex': options = openaiCodexModelManagerOptions({clientVersion: CODEX_CLIENT_VERSION, fetch: scopedFetch,
      resolveAccounts: async () => [{accessToken: key, accountId: getCodexAccountId(key)}]}); break;
    case 'github-copilot': options = githubCopilotModelManagerOptions({apiKey: key, fetch: scopedFetch}); break;
    case 'kiro': options = kiroModelManagerOptions({apiKey: key, fetch: scopedFetch}); break;
    case 'cursor': options = cursorModelManagerOptions({apiKey: key, baseUrl: 'https://api2.cursor.sh'}); break;
    case 'devin': options = devinModelManagerOptions({apiKey: key, fetch: scopedFetch}); break;
    case 'google-antigravity': case 'google-gemini-cli': {
      const c = JSON.parse(key);
      if (!record(c) || typeof c.token !== 'string' || typeof c.projectId !== 'string') throw invalidRequest();
      const account = {accessToken: c.token, projectId: c.projectId};
      options = provider === 'google-antigravity'
        ? googleAntigravityModelManagerOptions({resolveAccounts: async () => [account], fetch: scopedFetch})
        : googleGeminiCliModelManagerOptions({oauthToken: account.accessToken, projectId: account.projectId, fetch: scopedFetch});
      break;
    }
    default: {
      const descriptor = PROVIDER_DESCRIPTORS.find(d => d.providerId === provider);
      // Respect authoritative issuer rosters; do not persist SDK disk cache or consult
      // its cross-provider/models.dev discovery fallback.
      if (descriptor && (descriptor.dynamicModelsAuthoritative || defaultBase)) {
        options = descriptor.createModelManagerOptions({apiKey: key, baseUrl: defaultBase ?? bundled[0]?.baseUrl, fetch: scopedFetch});
      }
    }
  }
  if (!options?.fetchDynamicModels) return {models: bundled.filter(m => supported(m, provider, origins)), source: 'catalog' as const};
  let rows;
  try { rows = await options.fetchDynamicModels(); } catch { /* Never substitute another account or a bundled entitlement. */ }
  if (!rows) throw new RouteError(503, 'ai_catalog_unavailable', 'Unable to list the models available to this account');
  return {models: rows.filter(m => supported(m, provider, origins)).map(m => buildModel(m)), source: 'account' as const};
}
