import { timingSafeEqual } from 'node:crypto';
import { listModels, reply, RouteError } from './router';
import { authRequest, AuthError, loginRegistry } from './auth';
const key=(await Bun.file(process.env.OSIRIS_AI_ROUTER_KEY_FILE||'/run/osiris/router.key').text()).trim();
if(!/^[A-Za-z0-9+/]{43}=$/.test(key))throw Error('Invalid router authentication configuration');
const json=(data:unknown,status=200)=>Response.json(data,{status,headers:{'Cache-Control':'no-store'}});
// PHP may replay four completed rounds (including Unicode file content); bound that body separately from output.
Bun.serve({port:8090,hostname:'0.0.0.0',maxRequestBodySize:2*1024*1024,idleTimeout:90,async fetch(req){
  const url=new URL(req.url);
  if(req.method==='GET'&&url.pathname==='/health')return json({status:'ok',engine:'@oh-my-pi/pi-ai'});
  const supplied=req.headers.get('authorization')||'',expected='Bearer '+key;
  const suppliedBytes=Buffer.from(supplied),expectedBytes=Buffer.from(expected);
  if(suppliedBytes.length!==expectedBytes.length||!timingSafeEqual(suppliedBytes,expectedBytes))return json({error:'Unauthorized',code:'unauthorized'},401);
  if (url.search) return json({error:'Unexpected query parameters',code:'validation_error'},400);
  if(req.method==='GET'&&url.pathname==='/logins')return json({providers:loginRegistry()});
  const auth = /^\/auth\/(start|status|answer|cancel|resolve)$/.exec(url.pathname);
  if(req.method!=='POST'||(!auth&&url.pathname!=='/chat'&&url.pathname!=='/models'))return json({error:'Not found',code:'not_found'},404);
  let input;
  try { input = await req.json(); } catch { return json({error:'Invalid JSON',code:'validation_error'},400); }
  try{
    if (auth) return json(await authRequest(auth[1] as 'start'|'status'|'answer'|'cancel'|'resolve', input));
    return json(await (url.pathname === '/chat' ? reply(input) : listModels(input)));
  }catch(error){
    if(error instanceof RouteError || error instanceof AuthError)return json({error:error.message,code:error.code},error.status);
    return json({error:'AI router unavailable',code:'ai_upstream_failure'},502);
  }
}});
console.log('Osiris AI router listening on internal port 8090');
