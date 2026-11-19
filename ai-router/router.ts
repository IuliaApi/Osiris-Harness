import { complete } from '@oh-my-pi/pi-ai';
import { getBundledModel } from '@oh-my-pi/pi-catalog';
import type { Context, Model, Api } from '@oh-my-pi/pi-ai';

const routes = {
  openai: {api:'openai-responses',baseUrl:'https://api.openai.com/v1'},
  anthropic: {api:'anthropic-messages',baseUrl:'https://api.anthropic.com'},
  ollama: {api:'ollama-chat',baseUrl:'http://host.docker.internal:11434'},
} as const;
export class RouteError extends Error {
  constructor(public status:number,public code:string,message:string){super(message);}
}
export function prepare(body:any) {
  if(!body||typeof body!=='object'||Array.isArray(body)||Object.keys(body).some(k=>!['provider','model','apiKey','system','messages'].includes(k)))throw new RouteError(422,'validation_error','Invalid router request');
  const {provider,model,apiKey,system,messages}=body;
  if(!Object.hasOwn(routes,provider)||typeof model!=='string'||!/^[A-Za-z0-9][A-Za-z0-9_.:/-]{0,79}$/.test(model))throw new RouteError(422,'validation_error','Invalid provider or model');
  if(provider!=='ollama'&&(typeof apiKey!=='string'||! /^[!-~]{1,4096}$/.test(apiKey)))throw new RouteError(503,'ai_not_configured','No valid provider key configured');
  if(typeof system!=='string'||system.length>5000||!Array.isArray(messages)||messages.length<1||messages.length>13||messages.some(m=>!m||!['user','assistant'].includes(m.role)||typeof m.content!=='string'||m.content.length>6000))throw new RouteError(422,'validation_error','Invalid conversation');
  const route=routes[provider as keyof typeof routes];
  const known=getBundledModel(provider,model);
  const selected={...known,...route,id:model,name:model,provider,compat:known?.compat??{},reasoning:known?.reasoning??false,input:['text'],cost:{input:0,output:0,cacheRead:0,cacheWrite:0},contextWindow:32768,maxTokens:2048} as Model<Api>;
  const usage={input:0,output:0,cacheRead:0,cacheWrite:0,totalTokens:0,cost:{input:0,output:0,cacheRead:0,cacheWrite:0,total:0}};
  const context:Context={systemPrompt:[system],messages:messages.map(m=>m.role==='user'?{role:'user',content:m.content,timestamp:Date.now()}:{role:'assistant',content:[{type:'text',text:m.content}],api:selected.api,provider,model,usage,stopReason:'stop',timestamp:Date.now()})};
  return {selected,context,apiKey:provider==='ollama'?'ollama':apiKey};
}
export function providerFailure(status:number|undefined,quota=false):RouteError|null {
  if(status===401||status===403)return new RouteError(502,'ai_upstream_auth','The provider rejected your API key. Update it in API key settings.');
  if(status===429)return quota
    ?new RouteError(503,'ai_upstream_quota','The provider reports exhausted API credit or quota. Check API billing for the project that owns this key.')
    :new RouteError(503,'ai_upstream_rate_limit','The provider is rate-limited. Wait before trying again.');
  return null;
}
export async function reply(body:any,completeFn=complete){
  const {selected,context,apiKey}=prepare(body);
  const controller=new AbortController();
  const signal=AbortSignal.any([controller.signal,AbortSignal.timeout(45000)]);
  let result,requests=0,upstreamStatus:number|undefined,quota=false;
  // Preserve the first HTTP failure even if the provider library tries to retry it.
  const singleRequestFetch:typeof fetch=async(input,init)=>{
    if(++requests>1)throw new Error('Automatic provider retry disabled');
    const response=await fetch(input,{...init,redirect:'error',signal});
    upstreamStatus=response.status;
    if(response.status===429){
      const failure=await response.clone().json().catch(()=>null);
      quota=['insufficient_quota','credit_balance_exhausted','billing_hard_limit_reached'].includes(failure?.error?.code)||failure?.error?.type==='insufficient_quota';
    }
    if(response.status>=400)controller.abort();
    return response;
  };
  try{result=await completeFn(selected,context,{apiKey,maxTokens:2048,signal,cacheRetention:'none',fetch:singleRequestFetch,acceptEmptyResponse:true,providerRetryWait:async()=>{throw new Error('Automatic provider retry disabled');}});}
  catch{
    const failure=providerFailure(upstreamStatus,quota);if(failure)throw failure;
    if(signal.aborted)throw new RouteError(504,'ai_upstream_timeout','AI request timed out. No chat messages were saved.');
    throw new RouteError(502,'ai_upstream_failure','Unable to complete the provider request. Check the key, model, and provider availability.');
  }
  const upstreamFailure=providerFailure(upstreamStatus,quota);if(upstreamFailure)throw upstreamFailure;
  if(upstreamStatus!==undefined&&upstreamStatus>=400)throw new RouteError(502,'ai_upstream_failure','The provider rejected the request. Check your model and API project permissions.');
  if(result.stopReason==='error'){
    const failure=providerFailure(upstreamStatus??result.errorStatus,quota);if(failure)throw failure;
    throw new RouteError(502,'ai_upstream_failure','Provider request failed. Check your API key, quota, and model availability.');
  }
  if(signal.aborted||result.stopReason==='aborted')throw new RouteError(504,'ai_upstream_timeout','AI request timed out. No chat messages were saved.');
  const text=result.content.filter(b=>b.type==='text').map(b=>b.text).join('\n');
  if(!text.trim()||Array.from(text).length>10000||!['stop','length'].includes(result.stopReason))throw new RouteError(502,'ai_upstream_failure','AI provider returned no usable text answer');
  return {provider:body.provider,model:body.model,text};
}
