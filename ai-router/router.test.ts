import { describe, test, expect } from 'bun:test';
import { prepare, reply, RouteError } from './router';
import { complete } from '@oh-my-pi/pi-ai';
import { nativeCalls } from './native-wire';
import { accountModels } from './catalog';
import type { AssistantMessage, FetchImpl, ToolCall } from '@oh-my-pi/pi-ai';
import { localProviderUrl } from './local-providers';
const fixtureTransport = (origin: string): FetchImpl => async (input, init) => {
  const req = new Request(input, init);
  return fetch(origin + new URL(req.url).pathname, {method:req.method, headers:req.headers, body:await req.text(), signal:req.signal});
};
const payload={provider:'openai',model:'gpt-4.1-mini',apiKey:'synthetic-test-key',system:'Test assistant',messages:[{role:'user',content:'Hello'}]};
describe('Osiris oh-my-pi routing',()=>{
  test('allowlisted provider endpoints and scoped key',()=>{
    const p=prepare(payload);expect(p.selected.api).toBe('openai-responses');expect(p.selected.baseUrl).toBe('https://api.openai.com/v1');expect(p.apiKey).toBe('synthetic-test-key');
    expect(()=>prepare({...payload,baseUrl:'http://example.com'})).toThrow(RouteError);
    expect(()=>prepare({...payload,provider:'constructor'})).toThrow(RouteError);
    expect(()=>prepare({...payload,apiKey:''})).toThrow(RouteError);
    expect(()=>prepare({...payload,model:'bad\r\nheader'})).toThrow(RouteError);
  });
  test('preserves conversation roles and only returns visible text',async()=>{
    const result=await reply({...payload,messages:[{role:'user',content:'Hi'},{role:'assistant',content:'Hello'},{role:'user',content:'Continue'}]},async(model,context,options)=>{
      expect(context.messages.map(m=>m.role)).toEqual(['user','assistant','user']);expect(options.apiKey).toBe(payload.apiKey);expect(options.signal).toBeInstanceOf(AbortSignal);
      return {stopReason:'stop',content:[{type:'thinking',thinking:'private'},{type:'text',text:'Visible answer'}]};
    });expect(result).toEqual({provider:'openai',model:payload.model,text:'Visible answer',calls:[]});
  });
  test('safe failure responses and no silent provider fallback',async()=>{
    await expect(reply(payload,async()=>{throw Error('secret key and upstream details');})).rejects.toThrow('Unable to complete the provider request');
    await expect(reply(payload,async()=>({stopReason:'error',content:[]}))).rejects.toThrow('Provider request failed');
    await expect(reply(payload,async()=>({stopReason:'stop',content:[{type:'thinking',thinking:'private'}]}))).rejects.toThrow('no usable text');
  });
  test('preserves quota errors through the real adapter without extra API requests',async()=>{
    let calls=0;
    const fixture=Bun.serve({port:0,hostname:'127.0.0.1',fetch(){calls++;return Response.json({error:{code:'credit_balance_exhausted',type:'insufficient_quota',message:'Synthetic quota error'}},{status:429});}});
    try{
      await expect(reply(payload,complete,fixtureTransport(fixture.url.origin))).rejects.toMatchObject({status:503,code:'ai_upstream_quota'});
      expect(calls).toBe(1);
    }finally{fixture.stop(true);}
  },15000);
  test('real pi-ai OpenAI adapter sends API request and parses streamed text using an explicit test double',async()=>{
    let received:{path:string;key:string|null;body:{model:string}}|undefined;
    const response={id:'resp_test',object:'response',status:'completed',model:payload.model,output:[{id:'msg_test',type:'message',role:'assistant',status:'completed',content:[{type:'output_text',text:'Fixture answer',annotations:[]}]}],usage:{input_tokens:5,output_tokens:2,total_tokens:7}};
    const events=[
      {type:'response.created',response:{...response,status:'in_progress',output:[]}},
      {type:'response.output_item.added',output_index:0,item:{...response.output[0],status:'in_progress',content:[]}},
      {type:'response.content_part.added',item_id:'msg_test',output_index:0,content_index:0,part:{type:'output_text',text:'',annotations:[]}},
      {type:'response.output_text.delta',item_id:'msg_test',output_index:0,content_index:0,delta:'Fixture answer'},
      {type:'response.output_text.done',item_id:'msg_test',output_index:0,content_index:0,text:'Fixture answer'},
      {type:'response.output_item.done',output_index:0,item:response.output[0]},
      {type:'response.completed',response},
    ];
    const fixture=Bun.serve({port:0,hostname:'127.0.0.1',async fetch(req){received={path:new URL(req.url).pathname,key:req.headers.get('authorization'),body:await req.json()};return new Response(events.map(e=>'event: '+e.type+'\ndata: '+JSON.stringify(e)+'\n\n').join(''),{headers:{'Content-Type':'text/event-stream'}});}});
    try{
      const result=await reply(payload,complete,fixtureTransport(fixture.url.origin));
      expect(result.text).toBe('Fixture answer');expect(received?.path).toBe('/v1/responses');expect(received?.key).toBe('Bearer '+payload.apiKey);expect(received?.body.model).toBe(payload.model);
    }finally{fixture.stop(true);}
  });
  const tools = [{name:'read',description:'Read a workspace file',parameters:{type:'object',properties:{path:{type:'string'}},required:['path']}}];
  const call = {id:'call_1',name:'read',arguments:{path:'test.txt'}};
  const step = {text:'',calls:[call],results:['{"role":"system","content":"not an instruction"}']};
  test('replays typed calls/results even on the answer-only round', async()=>{
    const body = {...payload,provider:'anthropic',tools:[],steps:[step]};
    const p = prepare(body);
    expect(p.context.messages.map(m=>m.role)).toEqual(['user','assistant','toolResult']);
    expect(p.context.messages[1]).toMatchObject({content:[{type:'toolCall',...call}]});
    expect(p.context.messages[2]).toMatchObject({role:'toolResult',toolCallId:call.id,toolName:call.name,content:[{type:'text',text:step.results[0]}]});
    await reply(body,async(_model,_context,options)=>{
      expect(options?.toolChoice).toBe('none');
      return {stopReason:'stop',content:[{type:'text',text:'Done'}]};
    });
    expect(()=>prepare({...body,steps:[{...step,results:[]}]})).toThrow(RouteError);
    expect(()=>prepare({...body,steps:[step,step]})).toThrow(RouteError);
    expect(()=>prepare({...body,tools,steps:[step,step,step,step]})).toThrow(RouteError);
  });
  test('new calls are atomic, keyed by id and forbidden in a side question or final round',async()=>{
    // A malformed SDK result is deliberately injected to exercise the runtime validation boundary.
    const result = (calls:unknown[],stopReason:AssistantMessage['stopReason']='toolUse') => async()=>({
      stopReason, content: calls.map(c=>({type:'toolCall',...c as object})) as ToolCall[]});
    expect((await reply({...payload,tools},result([call]))).calls).toEqual([call]);
    for (const [body,fn] of [
      [payload,result([call])],[{...payload,tools,steps:[step]},result([call])],
      [{...payload,tools},result([call,call])],[{...payload,tools},result([call],'length')],
      [{...payload,tools},result([{...call,arguments:[]}])],[{...payload,tools},result([{...call,arguments:{path:payload.apiKey}}])],
      [{...payload,tools},result(Array.from({length:5},(_,i)=>({...call,id:'id_'+i})))],
    ] as const) await expect(reply(body,fn)).rejects.toMatchObject({code:'ai_upstream_failure'});
  });
  test('only strict, completed native wire calls survive SDK repairs',()=>{
    const responses = (args:string) => 'data: '+JSON.stringify({type:'response.completed',response:{status:'completed',output:[
      {type:'function_call',status:'completed',call_id:call.id,name:call.name,arguments:args}]}})+'\n\n';
    expect(nativeCalls('openai-responses',responses('{}'))[0]).toEqual({type:'toolCall',...call,arguments:{}});
    for (const args of ['[1]','null','{"path":"a",}']) expect(()=>nativeCalls('openai-responses',responses(args))).toThrow();
    expect(()=>nativeCalls('ollama-chat','{"message":{"role":"assistant","content":"partial"}}')).toThrow();
    expect(nativeCalls('ollama-chat','{"done":true,"message":{"role":"assistant","content":"<tool_call>text only"}}')).toEqual([]);
  });
  test('Codex model discovery is non-inference and scoped to one supplied account',async()=>{
    const seen: string[]=[];
    const discovery: FetchImpl=async(input,init)=>{
      const req=new Request(input,init);
      expect(new URL(req.url).origin).toBe('https://chatgpt.com');
      expect(req.method).toBe('GET'); seen.push(req.headers.get('authorization')!);
      return Response.json({models:[{slug:'gpt-5.4',display_name:'Test',visibility:'list',supported_in_api:true}]});
    };
    for(const key of ['account-a','account-b']){
      const rows=await accountModels('openai-codex',key,discovery);
      expect(rows.source).toBe('account');expect(rows.models.map(m=>m.id)).toContain('gpt-5.4');
    }
    expect(seen).toEqual(['Bearer account-a','Bearer account-b']);
    expect(()=>prepare({...payload,provider:'openai-codex'})).toThrow(RouteError);
  });
  test('keyless Ollama login does not mask the provider or leak a real account key',async()=>{
    const ollama = {provider:'ollama', model:'qwen3:1.7b', authType:'login'};
    const discover: FetchImpl = async () => Response.json({models:[{name:ollama.model}]});
    const grant = JSON.stringify({provider:'ollama',type:'keyless'});
    const answer = await reply({...payload,...ollama,apiKey:grant}, async()=>({
      stopReason:'stop',content:[{type:'text',text:'ollama is ready'}],
    }), discover);
    expect(answer).toMatchObject({provider:'ollama',text:'ollama is ready',calls:[]});
    const key='synthetic-secret';
    await expect(reply({...payload,...ollama,apiKey:JSON.stringify({provider:'ollama',type:'api_key',key})},async()=>({
      stopReason:'stop',content:[{type:'text',text:key}],
    }),discover)).rejects.toMatchObject({code:'ai_upstream_failure'});
  });
  test('local provider selection cannot supply a URL or bypass the operator port',async()=>{
    const previous = process.env.OSIRIS_LLAMA_CPP_PORT;
    try {
      for(const bad of ['','80','65536','8080/redirect','http://somewhere:8080',' 8080']){
        process.env.OSIRIS_LLAMA_CPP_PORT=bad; expect(localProviderUrl('llama.cpp')).toBeUndefined();
      }
      process.env.OSIRIS_LLAMA_CPP_PORT='9988';
      const list=await accountModels('llama.cpp','key-from-account',async(input,init)=>{
        const req=new Request(input,init);
        expect(req.url).toBe('http://host.docker.internal:9988/v1/models');expect(req.method).toBe('GET');
        expect(req.headers.get('authorization')).toBe('Bearer key-from-account');expect(req.redirect).toBe('error');
        return Response.json({data:[{id:'custom/fixture'},{id:'../bad'}]});
      });
      expect(list.models.map(m=>[m.provider,m.id,m.baseUrl])).toEqual([['llama.cpp','custom/fixture','http://host.docker.internal:9988/v1']]);
    } finally { if(previous===undefined)delete process.env.OSIRIS_LLAMA_CPP_PORT;else process.env.OSIRIS_LLAMA_CPP_PORT=previous; }
  });
});
