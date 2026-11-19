import { parentPort, workerData } from 'node:worker_threads';
import { randomBytes } from 'node:crypto';
import {
  getOAuthApiKey, getOAuthProviders, getProviderDefinition,
  loginRiskWarning, normalizeOAuthCredentialExpiry, refreshOAuthToken,
  type OAuthCredentials, type OAuthPrompt, type OAuthProvider,
} from '@oh-my-pi/pi-ai/registry';
import { authPolicyFor, authProviders } from '@oh-my-pi/pi-catalog/compat/auth';
import { setTransports } from '@oh-my-pi/pi-utils/logger';
import { isLocalGateway, localProviderUrl } from './local-providers';
import { validCallbackCode } from './browser-callback';

// These flows handle secrets. Never enable the SDK's default rotating log files on the service.
setTransports({file:false, console:false});

export class AuthError extends Error {
  constructor(public status:number, public code:string, message:string) { super(message); }
}
const invalidGrant = () => new AuthError(403, 'login_invalid', 'This provider sign-in is invalid or expired. Reconnect the provider.');
const unsupported:Record<string,string> = {
  'devin-cli':'Requires importing a credential from a local Devin CLI. Use Devin or the Windsurf browser sign-in instead.',
  'antigravity-cli':'Requires installing and running an external CLI. This server does not execute provider commands.',
  'gitlab-duo-agent':'This transport creates remote workflows and can change GitLab project settings. Use GitLab Duo Chat instead.',
  stencil:'This sign-in authorizes hosted Stencil services, not a chat model.',
};
for (const id of ['exa','kagi','parallel','perplexity','tavily']) unsupported[id]='The SDK uses this sign-in for web search, not chat.';
for (const id of ['cloudflare-workers-ai','liquid','nace','typesafe']) unsupported[id]='The SDK offers decision/judge models here, not chat.';

// Some key-entry methods offer a dashboard link. That alone isn't a browser sign-in.
const browserHooks:Record<string,true> = {'github-copilot':true,cursor:true,'devin-windsurf':true,'openai-codex-device':true,
  'kiro-login':true,snowflake:true,kilo:true,'mistral-vibe-sign-in':true};
function opensBrowser(id:string) {
  const login=authPolicyFor(id)?.login;
  return login?.kind==='oauth-code' || login?.kind==='device-code' || (login?.kind==='custom' && Object.hasOwn(browserHooks,login.hook));
}

export function loginRegistry() {
  const official = getOAuthProviders();
  const rows = official.map(info => {
    const local = isLocalGateway(info.id);
    const configured = workerData?.osirisLoginWorker ? workerData.provider===info.id && !!workerData.localUrl : !!localProviderUrl(info.id);
    const reason = unsupported[info.id] ?? (local && !configured ? 'Requires an operator-configured local provider port.' :
      !info.available ? 'Unavailable according to the installed provider SDK.' : undefined);
    return {id:info.id, name:info.name, provider:info.storeCredentialsAs ?? info.id, available:!reason, browser:opensBrowser(info.id), ...(reason && {reason})};
  });
  // The SDK intentionally omits paused logins from getOAuthProviders(); display, never re-enable them.
  for (const policy of authProviders()) {
    if (policy.disabled && policy.login && policy.showInLoginList !== false && !rows.some(row => row.id === policy.id)) {
      rows.push({id:policy.id, name:policy.name, provider:policy.storeAs ?? policy.id, available:false, browser:opensBrowser(policy.id), reason:policy.disabled});
    }
  }
  return rows;
}

const maxGrant = 32768;
type Grant = {provider:string,type:'keyless'} | {provider:string,type:'api_key',key:string} |
  {provider:string,type:'oauth',expires:number,data:OAuthCredentials};
const record = (v:unknown): v is Record<string,any> => !!v && typeof v === 'object' && !Array.isArray(v);
function encode(grant:Grant) {
  const raw = JSON.stringify(grant);
  if (Buffer.byteLength(raw) > maxGrant) throw invalidGrant();
  return raw;
}
function oauthData(v:unknown):v is OAuthCredentials {
  return record(v) && typeof v.access === 'string' && !!v.access && typeof v.refresh === 'string' && Number.isFinite(v.expires);
}
function decode(provider:string, raw:string):Grant {
  if (typeof raw !== 'string' || Buffer.byteLength(raw) > maxGrant || !loginRegistry().some(r=>r.available && r.provider===provider)) throw invalidGrant();
  let g;
  try { g = JSON.parse(raw); } catch { throw invalidGrant(); }
  if (!record(g) || g.provider !== provider || !(g.type==='oauth' && oauthData(g.data) && g.expires===g.data.expires ||
    g.type==='api_key' && typeof g.key==='string' && g.key.length && !/[\x00-\x1f\x7f]/.test(g.key) ||
    g.type==='keyless' && provider==='ollama')) throw invalidGrant();
  return g as Grant;
}

/** Explicit already-resolved credential only. No selection, OS/home loading or implicit refresh. */
export async function loginAuth(provider:string, credential:string) {
  const grant = decode(provider,credential);
  if (grant.type !== 'oauth') return {apiKey:grant.type==='api_key' ? grant.key : 'ollama'};
  try {
    if (grant.expires <= Date.now()) throw invalidGrant();
    const resolved = await getOAuthApiKey(provider as OAuthProvider,{[provider]:grant.data});
    if (!resolved?.apiKey) throw invalidGrant();
    const {accountId,orgId,region,inferenceRegion} = grant.data;
    return {apiKey:resolved.apiKey, oauthIdentity:{accountId,orgId,region,inferenceRegion}};
  } catch { throw invalidGrant(); }
}

async function rotate(provider:string, credential:string, signal:AbortSignal) {
  const g=decode(provider,credential);
  if (g.type!=='oauth' || g.expires > Date.now()+300_000) return credential;
  if (!g.data.refresh && g.expires > Date.now()) return credential;
  const minted = await refreshOAuthToken(provider as OAuthProvider, g.data, signal);
  // Official refreshers may omit optional identity fields which are fixed at interactive login.
  const data = normalizeOAuthCredentialExpiry(provider,{...g.data,...minted});
  if (!oauthData(data) || data.expires <= Date.now()) throw invalidGrant();
  return encode({provider,type:'oauth',expires:data.expires,data});
}

export type BrowserPrompt = {id:string,message:string,secret:boolean,allowEmpty:boolean,callback?:boolean,placeholder?:string};
type PromptRule = Omit<BrowserPrompt,'id'|'placeholder'> & {accepts:(answer:string)=>boolean};
const scalarKey = (s:string) => /^[A-Za-z0-9._~+/=:@%-]{1,8192}$/.test(s) && !s.includes('://') && !s.startsWith('/') && !s.startsWith('@');
const clean = (s:string,max:number) => s.replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g,'').slice(0,max);

// Only specific, bounded value classes may reach prompts that affect network routing. The browser
// never supplies a server URL, a serialized credential, a shell expression, or a session cookie.
function ruleFor(id:string,p:OAuthPrompt):PromptRule {
  const message=clean(p.message,2400), allowEmpty=!!p.allowEmpty;
  const rule = (accepts:PromptRule['accepts'],title=message,secret=false,empty=allowEmpty) => ({message:title, secret, allowEmpty:empty, accepts});
  if (p.riskAcknowledgement) return rule(s=>/^(yes|no)$/i.test(s));
  if (id==='github-copilot' && /enterprise url/i.test(message)) return rule(s=>s==='' || s==='github.com', 'GitHub.com sign-in (leave blank or enter github.com). Custom enterprise hosts are not accepted.',false,true);
  if (id.startsWith('alibaba-') && /select .*(endpoint|region)/i.test(message)) return rule(s=>s==='1'||s==='2','Choose region: 1 = International, 2 = China.');
  if (id==='snowflake' && /account identifier/i.test(message)) return rule(s=>/^[a-z\d][a-z\d_-]{1,61}[a-z\d]$/i.test(s),'Enter your Snowflake organization-account identifier, not a URL.');
  if (id==='kiro') {
    if (/Start URL/i.test(message)) return rule(s=>/^https:\/\/[a-z\d-]{1,63}\.awsapps\.com\/start\/?$/.test(s),'Enter your AWS access portal URL: https://<directory>.awsapps.com/start');
    if (/Enter Region/i.test(message)) return rule(s=>/^(us|eu|ap|af|ca|me|sa|il|mx)-(?:gov-)?[a-z]+-\d$/.test(s));
    if (!/api key/i.test(message) || /\n/.test(message)) return rule(s=>/^\d{1,3}$/.test(s));
  }
  if (/cloudflare/i.test(message) && /account ID/i.test(message)) return rule(s=>/^[a-f0-9]{32}$/i.test(s));
  if (/cloudflare/i.test(message) && /gateway ID/i.test(message)) return rule(s=>/^[a-z0-9][a-z0-9-]{0,63}$/.test(s));
  if (authPolicyFor(id)?.login?.kind==='api-key' || /(?:paste|enter).*?(?:api key|token)/i.test(message)) {
    return rule(s=>s==='' && allowEmpty || scalarKey(s),message,true);
  }
  throw new Error('Unsupported provider prompt');
}

/** Strict callback match before delegating code exchange and PKCE verification to the SDK. */
export function callbackRule(authorize:URL):PromptRule {
  const target = authorize.searchParams.get('redirect_uri') ?? authorize.searchParams.get('callback_url');
  if (!target) throw new Error('Missing callback target');
  const redirect=new URL(target), expectedState=authorize.searchParams.get('state');
  const local = redirect.protocol==='http:' && ['localhost','127.0.0.1','[::1]'].includes(redirect.hostname);
  if (!(local || ['zcode:', 'vscode:'].includes(redirect.protocol)) || redirect.username || redirect.password) throw new Error('Unsupported callback origin');
  const endpoint = `${redirect.protocol}//${redirect.host}${redirect.pathname}`;
  return {message:`If sign-in does not finish automatically, paste the entire redirect URL (${endpoint}), including its query.`,secret:true,allowEmpty:false,callback:true,
    accepts(input) {
      try {
        const url=new URL(input), code=url.searchParams.getAll('code');
        return url.protocol===redirect.protocol && url.host===redirect.host && url.pathname===redirect.pathname && !url.username && !url.password &&
          !url.hash && code.length===1 && validCallbackCode(code[0]) &&
          (expectedState ? url.searchParams.getAll('state').length===1 && url.searchParams.get('state')===expectedState : true);
      } catch { return false; }
    }};
}

/** Worker is ephemeral and has an empty HOME and environment; no imports from other apps or login files. */
async function runWorker() {
  const port=parentPort!;
  const ctrl=new AbortController();
  let pending:{id:string,rule:PromptRule,resolve:(s:string)=>void,reject:(err:Error)=>void}|undefined;
  port.on('message',(event)=>{
    if (event.cancel) {ctrl.abort();pending?.reject(new Error('Cancelled'));return;}
    if (!pending || pending.id!==event.id || typeof event.answer!=='string') return;
    if (!pending.rule.accepts(event.answer)) {
      port.postMessage({retry:true,message:'That value is not valid for this sign-in step. Check the instructions and try again.'});return;
    }
    const answer=pending;pending=undefined;answer.resolve(event.answer);
  });
  function ask(rule:PromptRule,signal?:AbortSignal):Promise<string> {
    if (signal?.aborted || ctrl.signal.aborted || pending) return Promise.reject(new Error('Prompt no longer available'));
    const {promise,resolve,reject}=Promise.withResolvers<string>();
    const id=randomBytes(16).toString('hex');
    const abort=()=>{if(pending?.id===id){pending=undefined;reject(new Error('Prompt closed'));}};
    pending={id,rule,resolve:(s)=>{signal?.removeEventListener('abort',abort);resolve(s);},reject};
    signal?.addEventListener('abort',abort,{once:true});
    port.postMessage({prompt:{id,message:rule.message,secret:rule.secret,allowEmpty:rule.allowEmpty,...(rule.callback && {callback:true})}});
    return promise;
  }
  try {
    if (workerData.action==='resolve') {
      port.postMessage({credential:await rotate(workerData.provider,workerData.credential,ctrl.signal)});return;
    }
    const id:string=workerData.provider, info=loginRegistry().find(r=>r.id===id && r.available);
    const def=getProviderDefinition(id);
    if (!info || !def?.login) throw new Error('Unsupported login');
    const warning=loginRiskWarning(id);
    if (warning && (await ask(ruleFor(id,{message:`${warning}\nType yes to accept this provider’s terms risk, or no to cancel.`,riskAcknowledgement:true}))).toLowerCase()!=='yes') throw new Error('Cancelled');
    const login=authPolicyFor(id)?.login;
    if (id==='xiaomi' || (login?.kind==='api-key' && ['anthropic-messages','chat-completions'].includes(login.validate?.kind ?? ''))) {
      const assent=await ask(ruleFor(id,{message:'This provider validates a key by making a model request. This can use paid quota. Type yes to authorize validation, or no to stop without sending a key.',riskAcknowledgement:true}));
      if (assent.toLowerCase()!=='yes') throw new Error('Validation not authorized');
    }
    let authorize:URL|undefined;
    const result=await def.login({signal:ctrl.signal,
      fetch:(input,init)=>{
        const url=new URL(typeof input==='string' || input instanceof URL ? input : input.url);
        if (url.protocol!=='https:' || url.username || url.password || url.port || !url.hostname.includes('.') ||
          /(^[\d.]+$|^\[|\.(?:local|internal|localhost|invalid)$)/i.test(url.hostname)) throw new Error('Invalid provider destination');
        // The SDK and its signed provider responses supply URLs. No arbitrary endpoints are accepted from prompts.
        return fetch(input,{...init,redirect:'error',signal:init?.signal ? AbortSignal.any([init.signal,ctrl.signal]) : ctrl.signal});
      },
      onAuth(info){
        const u=new URL(info.url);
        if (u.protocol!=='https:' || u.username || u.password || info.url.length>8192) throw new Error('Unsafe sign-in URL');
        authorize=u;
        port.postMessage({url:u.href,message:clean(info.instructions ?? 'Open the provider sign-in page to continue.',2000)});
      },
      onPrompt(p){
        // The optional quota-cookie import isn't needed for inference and must never collect a browser session.
        if (id==='alibaba-token-plan' && p.allowEmpty && /quota reporting/i.test(p.message)) return Promise.resolve('');
        return ask(ruleFor(id,p));
      },
      onManualCodeInput(signal){if(!authorize)throw new Error('Missing sign-in URL');return ask(callbackRule(authorize),signal);},
    });
    if (ctrl.signal.aborted) throw new Error('Cancelled');
    let grant:Grant;
    const provider=info.provider;
    if (typeof result==='string') {
      if (!result && provider!=='ollama') throw new Error('No credential issued');
      grant=result ? {type:'api_key',provider,key:result} : {type:'keyless',provider};
    } else {
      const data=normalizeOAuthCredentialExpiry(provider,{...result,authorizedAt:Date.now()});
      if (!oauthData(data)) throw new Error('Invalid OAuth grant');
      grant={type:'oauth',provider,expires:data.expires,data};
    }
    port.postMessage({credential:encode(grant)});
  } catch {
    // Upstream errors can contain auth codes, tokens, cookies and provider response bodies.
    port.postMessage({failed:true});
  }
}
if (parentPort && workerData?.osirisLoginWorker===true) void runWorker();
