import { randomBytes } from 'node:crypto';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Worker } from 'node:worker_threads';
import { AuthError, loginRegistry, type BrowserPrompt } from './auth-provider';
import { localProviderUrl } from './local-providers';
export { AuthError, loginAuth, loginRegistry } from './auth-provider';

type State = 'pending' | 'completed' | 'cancelled' | 'failed';
type Transaction = { id:string, actor:string, provider:string, loginId:string, expiresAt:number, state:State,
  timer:NodeJS.Timeout, worker?:Worker, url?:string, message?:string, prompt?:BrowserPrompt,
  submitted?:BrowserPrompt, credential?:string };
const transactions = new Map<string,Transaction>();
const ttl = 10 * 60_000;
let loginWorkers = 0, refreshWorkers = 0;

function spawnWorker(data:Record<string,string>, onExit:()=>void) {
  // Kimi, for example, writes a device identifier. Give each attempt a distinct, transient
  // HOME; never expose app secrets, project settings, provider env keys or external commands.
  const home=mkdtempSync(join(tmpdir(),'osiris-login-'));
  try {
    const worker=new Worker(new URL('./auth-provider.ts',import.meta.url),{
      env:{HOME:home, USERPROFILE:home, APPDATA:home, LOCALAPPDATA:home, XDG_CONFIG_HOME:home, XDG_DATA_HOME:home,
        XDG_CACHE_HOME:home, XDG_STATE_HOME:home, PATH:'', TMPDIR:home, PI_AUTH_NO_BORROW:'1',
        OTEL_SDK_DISABLED:'true', AWS_EC2_METADATA_DISABLED:'true'},
      workerData:{osirisLoginWorker:true,...data,localUrl:localProviderUrl(data.provider)},stdout:true,stderr:true,execArgv:[],
    });
    worker.stdout.resume();worker.stderr.resume();  // discard, do not log provider diagnostics
    worker.once('exit',()=>{try{rmSync(home,{recursive:true,force:true});}finally{onExit();}});
    return worker;
  } catch(error) { rmSync(home,{recursive:true,force:true});throw error; }
}

function end(t:Transaction,state:State,message?:string) {
  t.state=state;t.message=message;delete t.url;delete t.prompt;delete t.submitted;
  if (state!=='completed') delete t.credential;
  const worker=t.worker;delete t.worker;
  if (worker) {
    // Several SDK polling flows do not honor AbortSignal during network IO; termination also
    // stops these, closes SDK loopback listeners, and prevents late credentials from resurfacing.
    worker.postMessage({cancel:true});void worker.terminate();
  }
}
function drop(t:Transaction) {
  clearTimeout(t.timer); transactions.delete(t.id); end(t,'cancelled');
}
function view(t:Transaction) {
  // Internal only: PHP persists credential on completion, and must omit it from its public projection.
  const {id,state,provider,loginId,expiresAt,url,message,prompt,credential}=t;
  return {id,state,provider,loginId,expiresAt,...(url&&{url}),...(message&&{message}),...(prompt&&{prompt}),...(credential&&{credential})};
}
const invalid = () => new AuthError(422,'validation_error','Invalid provider sign-in request.');
const busy = () => new AuthError(429,'login_busy','Too many active sign-ins. Try again shortly.');

function start(actor:string,loginId:string) {
  const info=loginRegistry().find(row=>row.id===loginId);
  if (!info) throw invalid();
  if (!info.available) throw new AuthError(422,'login_unavailable',info.reason!);
  for (const t of transactions.values()) if (t.actor===actor && t.provider===info.provider) drop(t);
  if (loginWorkers>=3 || transactions.size>=64 || [...transactions.values()].filter(t=>t.actor===actor).length>=4) throw busy();
  const id=randomBytes(32).toString('hex');
  const worker=spawnWorker({action:'login',provider:loginId},()=>loginWorkers--);
  loginWorkers++;
  const t:Transaction={actor,provider:info.provider,loginId,id,state:'pending',expiresAt:Date.now()+ttl,worker,
    timer:setTimeout(()=>drop(t),ttl)};
  t.timer.unref();transactions.set(id,t);
  const fail=()=>{
    if (t.state==='pending') end(t,'failed','The provider could not complete sign-in. Check the chosen account, provider prerequisites and callback instructions, then start again.');
  };
  worker.on('error',fail);worker.once('exit',fail);
  worker.on('message',event=>{
    if (t.state!=='pending' || transactions.get(id)!==t) return;
    if (event.failed) {fail();return;}
    if (typeof event.credential==='string') {
      t.credential=event.credential;end(t,'completed','Provider sign-in completed.');return;
    }
    if (event.retry) {
      t.prompt=t.submitted;delete t.submitted;t.message=event.message;return;
    }
    if (event.url) {t.url=event.url;t.message=event.message;}
    if (event.prompt) {t.prompt=event.prompt;delete t.submitted;}
  });
  return view(t);
}

async function resolve(provider:string, credential:string) {
  if (Buffer.byteLength(credential)>32768) throw invalid();
  if (refreshWorkers>=2) throw busy();
  const w=spawnWorker({action:'resolve',provider,credential},()=>refreshWorkers--);
  refreshWorkers++;
  const {promise,resolve,reject}=Promise.withResolvers<{credential:string}>();
  const fail=()=>reject(new AuthError(502,'login_refresh_failed','The provider could not renew this sign-in. Try again or reconnect the account.'));
  const deadline=setTimeout(fail,15_000);
  w.once('error',fail);w.once('exit',fail);
  w.once('message',event=>typeof event.credential==='string' ? resolve({credential:event.credential}) : fail());
  try {return await promise;} finally {clearTimeout(deadline);void w.terminate();}
}

/** Called only after server-to-server authentication. actor is supplied by PHP, never a public request. */
export async function authRequest(action:string,raw:unknown) {
  if (raw===null || typeof raw!=='object' || Array.isArray(raw)) throw invalid();
  const body=raw as Record<string,unknown>;
  const allowed = action==='resolve' ? ['provider','credential'] : action==='start' ? ['actor','provider'] :
    action==='answer' ? ['actor','id','promptId','answer'] : action==='cancel'||action==='status' ? ['actor','id'] : [];
  if (!allowed.length || Object.keys(body).length!==allowed.length || !allowed.every(k=>typeof body[k]==='string')) throw invalid();
  if (action==='resolve') return resolve(body.provider as string,body.credential as string);
  const actor=body.actor as string;
  if (!/^[0-9a-f]{64}$/.test(actor)) throw invalid();
  if (action==='start') return start(actor,body.provider as string);
  const t=transactions.get(body.id as string);
  if (!t || t.actor!==actor || Date.now()>=t.expiresAt) throw new AuthError(404,'login_not_found','This provider sign-in was not found or has expired.');
  if (action==='cancel') {drop(t);return view(t);}
  if (action==='answer') {
    if (t.state!=='pending' || !t.prompt || t.prompt.id!==body.promptId) throw new AuthError(409,'login_step_changed','The provider sign-in step changed. Refresh its status.');
    const answer=body.answer as string;
    if ((!answer && !t.prompt.allowEmpty) || Buffer.byteLength(answer)>16384 || /[\x00-\x1f\x7f]/.test(answer)) throw invalid();
    t.submitted=t.prompt; delete t.prompt;
    t.worker!.postMessage({id:body.promptId,answer});
  }
  return view(t);
}
