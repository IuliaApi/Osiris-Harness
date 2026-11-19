import endpoints from './callback-endpoints.json';

const fixed:Readonly<Record<string,string>>=endpoints;
export type CallbackBinding={endpoint:string,state:string};
export type CallbackCandidate={state:string,expiresAt:number,callback?:CallbackBinding,prompt?:{callback?:boolean}};

// A random fallback port doesn't become a published port just because another login uses it.
// OpenRouter has no state, so it has no entry: it must use the authenticated manual prompt.
export function bindCallback(loginId:string,authorize:URL,published:ReadonlySet<string>):CallbackBinding|undefined {
  if (!Object.hasOwn(fixed,loginId) || authorize.protocol!=='https:') return;
  const endpoint=fixed[loginId];
  if (!published.has(new URL(endpoint).port)) return;
  const targets=authorize.searchParams.getAll('redirect_uri'), states=authorize.searchParams.getAll('state');
  if (targets.length!==1 || targets[0]!==endpoint || states.length!==1) return;
  // The installed SDK uses 16 random bytes (hex) or a random v4 UUID, generated per login.
  if (!/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})$/.test(states[0])) return;
  return {endpoint,state:states[0]};
}

export function readyForCallback(t:CallbackCandidate,now:number) {
  return t.state==='pending' && now<t.expiresAt && !!t.prompt?.callback && !!t.callback;
}
export const validCallbackCode=(code:string)=>/^[A-Za-z0-9._~+/=%:-]{1,8192}$/.test(code) && !code.includes('://');

const response=(status:number,text:string)=>new Response(
  `<!doctype html><html lang="en"><meta charset="utf-8"><title>Osiris provider sign-in</title><h1>Provider sign-in</h1><p>${text}</p></html>`,
  {status,headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store','Referrer-Policy':'no-referrer',
    'Content-Security-Policy':"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",'X-Content-Type-Options':'nosniff'}});
const invalid=()=>response(404,'This callback did not match an active sign-in. Return to Osiris for instructions.');

// Never proxy the incoming request: its cookies, headers, issuer diagnostics and other query
// parameters must not reach the SDK. Dispatch only the bound prompt answer (or a denial).
export function receiveCallback<T extends CallbackCandidate>(req:Request,flows:Iterable<T>,deliver:(t:T,answer:string|null)=>void):Response {
  if (req.method!=='GET' || req.url.length>16384 || req.body) return invalid();
  let url:URL;
  try {url=new URL(req.url);} catch {return invalid();}
  if (url.protocol!=='http:' || req.headers.get('host')!==url.host || url.username || url.password || url.hash) return invalid();
  const endpoint=url.origin+url.pathname, query=url.searchParams;
  const states=query.getAll('state'), codes=query.getAll('code'), errors=query.getAll('error');
  if (states.length!==1 || !(codes.length===1 && validCallbackCode(codes[0]) && !errors.length ||
    errors.length===1 && /^[a-zA-Z0-9_.-]{1,128}$/.test(errors[0]) && !codes.length)) return invalid();
  let match:T|undefined;
  const now=Date.now();
  for (const t of flows) {
    if (!readyForCallback(t,now) || t.callback!.endpoint!==endpoint || t.callback!.state!==states[0]) continue;
    if (match) return invalid();
    match=t;
  }
  if (!match) return invalid();
  // Claim synchronously, before the worker starts the code exchange. A reload cannot submit twice.
  delete match.callback;
  const answer=codes.length ? endpoint+'?'+new URLSearchParams({code:codes[0],state:states[0]}) : null;
  deliver(match,answer);
  return response(200,answer===null ? 'The provider did not authorize sign-in. Return to Osiris to start again.' :
    'Provider response received. Return to Osiris to check whether sign-in completed. You can close this tab.');
}
