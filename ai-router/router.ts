import { complete } from '@oh-my-pi/pi-ai';
import type { Api, AssistantMessage, Context, FetchImpl, Model, StreamOptions, ToolChoice } from '@oh-my-pi/pi-ai';
import { accountModels, boundedBody, destination, keyModel, routes, validModel, validProvider } from './catalog';
import { loginAuth, loginRegistry } from './auth';
import { conversation, fields, finish, invalidRequest, invalidTurn, record, RouteError } from './turns';
import { nativeCalls } from './native-wire';
export { RouteError };

type ChatRequest = Record<string, unknown> & {provider: string; model: string};
function requestBody(body: unknown): ChatRequest {
  if (!fields(body, ['provider','model','authType','apiKey','system','messages','tools','steps'])
    || !validProvider(body.provider) || !validModel(body.model)) throw invalidRequest();
  return {...body, provider: body.provider, model: body.model};
}
function keyPreparation(body: ChatRequest) {
  const {provider, model, apiKey, authType = 'apiKey'} = body;
  if (authType !== 'apiKey' || !Object.hasOwn(routes, provider)) throw invalidRequest();
  if (provider === 'ollama' ? apiKey != null : typeof apiKey !== 'string' || !/^[!-~]{1,4096}$/.test(apiKey)) {
    throw new RouteError(503, 'ai_not_configured', 'No valid provider key configured');
  }
  const selected = keyModel(provider, model);
  return {selected, ...conversation(body, selected), apiKey: provider === 'ollama' ? 'ollama' : apiKey as string, oauthIdentity: undefined};
}
export function prepare(body: unknown) { return keyPreparation(requestBody(body)); }

async function scopedLogin(provider: string, credential: unknown) {
  if (typeof credential !== 'string' || !credential.length || credential.length > 65536
    || !loginRegistry().some(row => row.available && row.provider === provider)) throw invalidRequest();
  return loginAuth(provider, credential);
}
export async function listModels(input: unknown) {
  if (!fields(input, ['provider','authType','apiKey']) || !validProvider(input.provider) || input.authType !== 'login') throw invalidRequest();
  const {apiKey, oauthIdentity} = await scopedLogin(input.provider, input.apiKey);
  const {models, source} = await accountModels(input.provider, apiKey, fetch, oauthIdentity);
  const secrets = loginSecrets(input.provider, apiKey, input.apiKey as string);
  if (models.some(({id}) => secrets.some(secret => id.includes(secret)))) throw invalidTurn();
  return {models: models.map(({id}) => ({id})), source};
}

async function prepareLogin(body: ChatRequest, transport: FetchImpl) {
  const {apiKey, oauthIdentity} = await scopedLogin(body.provider, body.apiKey);
  const {models} = await accountModels(body.provider, apiKey, transport, oauthIdentity);
  const selected = models.find(m => m.id === body.model);
  if (!selected) throw new RouteError(422, 'validation_error', 'Select a chat model listed for this provider');
  return {selected, ...conversation(body, selected), apiKey, oauthIdentity};
}
export function providerFailure(status:number|undefined,quota=false):RouteError|null {
  if(status===401||status===403)return new RouteError(502,'ai_upstream_auth','The provider rejected your credential. Update the key or sign in again.');
  if(status===429)return quota
    ?new RouteError(503,'ai_upstream_quota','The provider reports exhausted API credit or quota. Check API billing for the project that owns this key.')
    :new RouteError(503,'ai_upstream_rate_limit','The provider is rate-limited. Wait before trying again.');
  return null;
}
// Some adapters have a separate, non-inference credential or model-assignment exchange.
// Only the named SDK control-plane paths may precede the single metered inference request.
function preflight(provider: string, url: URL) {
  return (provider === 'gitlab-duo' && url.href === 'https://gitlab.com/api/v4/ai/third_party_agents/direct_access')
    || (provider === 'devin' && url.origin === 'https://server.codeium.com' && /\/(?:GetUserJwt|AssignModel)$/.test(url.pathname));
}
function timeoutError() { return new RouteError(504, 'ai_upstream_timeout', 'AI request timed out. No chat messages were saved.'); }
function fromUpstream(status: number, raw: string, provider: string) {
  let detail;
  try { detail = JSON.parse(raw)?.error; } catch { /* Never forward arbitrary upstream response text. */ }
  const quota = ['insufficient_quota','credit_balance_exhausted','billing_hard_limit_reached'].includes(detail?.code)
    || detail?.type === 'insufficient_quota';
  if (provider === 'ollama' && [400,422].includes(status) && typeof detail === 'string'
    && /(?:does not support|doesn.t support|unsupported)\s+tools?\b|\btools?\s+(?:are\s+)?not supported/i.test(detail)) {
    return new RouteError(422, 'ai_tools_unsupported', 'This model does not support native tools. Select a tool-capable model.');
  }
  return providerFailure(status, quota) ?? new RouteError(502, 'ai_upstream_failure', 'The provider rejected the request. Check your model and account permissions.');
}
function secretValues(value: string) {
  const secrets = [value];
  // SDK structured credentials may hold bearer/refresh tokens and client secrets.
  try {
    const visit = (obj: unknown, depth: number) => {
      if (!record(obj) || depth > 8) return;
      for (const [k,v] of Object.entries(obj)) {
        if (typeof v === 'string' && v && /token|secret|api.?key|^(access|refresh|key)$/i.test(k)) secrets.push(v);
        else if (record(v)) visit(v, depth + 1);
      }
    };
    visit(JSON.parse(value), 0);
  } catch { /* Scalar provider key. */ }
  return secrets;
}

function loginSecrets(provider: string, key: string, grant: string) {
  // The SDK's explicit keyless sentinel is the provider name itself, not a
  // credential. Still mask the actual envelope (including a stored Ollama key).
  return [...(provider === 'ollama' && key === 'ollama' ? [] : secretValues(key)), ...secretValues(grant)];
}
// pi-ai's Ollama serializer keeps tool names but omits call/result IDs. Restore
// only identities from our validated history, so repeated calls to one tool are
// replayed unambiguously on servers which support native IDs.
function ollamaPayload(payload: unknown, context: Context) {
  if (!record(payload) || !Array.isArray(payload.messages)) throw invalidRequest();
  const past = context.messages.flatMap(m => m.role === 'assistant' ? m.content.filter(b => b.type === 'toolCall') : []);
  const results = context.messages.filter(m => m.role === 'toolResult');
  let n = 0, r = 0;
  for (const msg of payload.messages) {
    if (msg.role === 'assistant' && Array.isArray(msg.tool_calls)) {
      for (const [i,c] of msg.tool_calls.entries()) {
        const saved = past[n++];
        if (!saved || c.function?.name !== saved.name) throw invalidRequest();
        c.id = saved.id; c.function.index = i;
      }
    } else if (msg.role === 'tool') {
      const saved = results[r++];
      if (!saved || msg.tool_name !== saved.toolName) throw invalidRequest();
      msg.tool_call_id = saved.toolCallId;
    }
  }
  if (n !== past.length || r !== results.length) throw invalidRequest();
  return {...payload, think: false};
}

type CompleteTurn = (model: Model<Api>, context: Context, options: StreamOptions & {toolChoice?: ToolChoice; preferWebsockets?: boolean}) =>
  Promise<Pick<AssistantMessage, 'content'|'stopReason'|'errorStatus'>>;
export async function reply(input: unknown, completeFn: CompleteTurn = complete, transport: FetchImpl = fetch) {
  const body = requestBody(input);
  const deadline = AbortSignal.timeout(45000);
  const {selected, context, apiKey, oauthIdentity, ids, allowCalls} = body.authType === 'login' ? await prepareLogin(body, transport) : keyPreparation(body);
  const controller = new AbortController();
  const signal = AbortSignal.any([controller.signal, deadline]);
  let sent = false, dispatched = false, failure: RouteError | undefined, wire: string | undefined;
  const controls = new Set<string>();
  const singleRequestFetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(input instanceof Request ? input.url : String(input));
    if (!destination(body.provider, url, selected.baseUrl) || url.searchParams.has('key') || url.searchParams.has('access_token')) throw invalidTurn();
    // Old routes are fixed down to the path; neither model metadata nor an environment override can redirect a key.
    if (body.authType !== 'login') {
      const path = body.provider === 'anthropic' ? '/v1/messages' : body.provider === 'openai' ? '/v1/responses' : '/api/chat';
      if (url.pathname !== path) throw invalidTurn();
    }
    const control = preflight(body.provider, url);
    if (sent || controls.has(url.href)) throw new Error('Automatic provider retry disabled');
    if (control) controls.add(url.href); else sent = true;
    const response = await transport(input, {...init, redirect: 'error', signal});
    let bytes;
    try { bytes = await boundedBody(response, response.ok ? 2 * 1024 * 1024 : 65536); }
    catch { failure = invalidTurn(); controller.abort(); throw failure; }
    if (!response.ok) {
      failure = fromUpstream(response.status, bytes.toString(), body.provider);
      controller.abort();
      throw failure;
    }
    if (!control && ['openai-responses','openai-codex-responses','anthropic-messages','ollama-chat','openai-completions'].includes(selected.api)) wire = bytes.toString();
    return new Response(bytes, {status: response.status, headers: response.headers});
  };
  let result;
  try {
    result = await completeFn(selected as Model<Api>, context, {apiKey, oauthIdentity, maxTokens: Math.min(2048, selected.maxTokens || 2048), signal,
      cacheRetention: 'none', storeResponses: false, preferWebsockets: false, maxHttpAttempts: 1, codexSseMaxAttempts: 1,
      ...(selected.api === 'cursor-agent' ? {transport: 'http2', externalToolExecutor: true} : {}),
      ...(selected.api === 'ollama-chat' ? {onPayload: (p: unknown) => ollamaPayload(p,context)} : {}),
      ...(!allowCalls && context.tools?.length ? {toolChoice: 'none'} : {}),
      fetch: singleRequestFetch, acceptEmptyResponse: true,
      beforeDispatch: () => { if (dispatched) throw new Error('Automatic provider retry disabled'); dispatched = true; },
      providerRetryWait: async () => { throw new Error('Automatic provider retry disabled'); }});
  } catch {
    if (failure) throw failure;
    if (deadline.aborted) throw timeoutError();
    throw new RouteError(502, 'ai_upstream_failure', 'Unable to complete the provider request. Check the credential, model, and provider availability.');
  }
  if (failure) throw failure;
  if (deadline.aborted || result.stopReason === 'aborted') throw timeoutError();
  if (result.stopReason === 'error') throw providerFailure(result.errorStatus) ?? new RouteError(502,'ai_upstream_failure','Provider request failed. Check your credential, quota, and model availability.');
  let content = result.content, reason = result.stopReason;
  if (wire !== undefined) {
    const api = selected.api;
    try {
      const calls = nativeCalls(api, wire);
      const sdkCalls = content.filter(b => b.type === 'toolCall');
      if (calls.length !== sdkCalls.length) throw invalidTurn();
      for (const [i,c] of calls.entries()) {
        const b = sdkCalls[i];
        // OAuth Anthropic and some Responses gateways prefix tool names on the wire.
        // Correlate by native ID, then use the SDK-decoded name. Ollama generates SDK IDs instead.
        if (b.rawBlock !== undefined || b.customWireName !== undefined
          || (api === 'ollama-chat' ? b.name !== c.name : b.id.split('|',1)[0] !== c.id)) throw invalidTurn();
        c.name = b.name;
      }
      content = [...content.filter(b => b.type !== 'toolCall'), ...calls];
      // Some Ollama versions use done_reason:stop even for a completed native tool batch.
      if (api === 'ollama-chat' && calls.length && reason === 'stop') reason = 'toolUse';
    } catch { throw invalidTurn(); }
  }
  const turn = finish(content, reason, ids, allowCalls, body.provider === 'ollama' ? undefined : apiKey);
  const answer = {provider: body.provider, model: body.model, ...turn};
  const encoded = JSON.stringify(answer);
  if (Buffer.byteLength(encoded) > 262144 || (body.authType === 'login' && loginSecrets(body.provider, apiKey, body.apiKey as string)
    .some(s => encoded.includes(JSON.stringify(s).slice(1,-1))))) throw invalidTurn();
  return answer;
}
