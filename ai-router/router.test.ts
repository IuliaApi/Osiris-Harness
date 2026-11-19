import { describe, test, expect } from 'bun:test';
import { prepare, reply, RouteError } from './router';
import { complete } from '@oh-my-pi/pi-ai';
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
      return {stopReason:'stop',content:[{type:'thinking',thinking:'private'},{type:'text',text:'Visible answer'}]} as any;
    });expect(result).toEqual({provider:'openai',model:payload.model,text:'Visible answer'});
  });
  test('safe failure responses and no silent provider fallback',async()=>{
    await expect(reply(payload,async()=>{throw Error('secret key and upstream details');})).rejects.toThrow('Unable to complete the provider request');
    await expect(reply(payload,async()=>({stopReason:'error',content:[],errorMessage:'private'}) as any)).rejects.toThrow('Provider request failed');
    await expect(reply(payload,async()=>({stopReason:'stop',content:[{type:'thinking',thinking:'private'}]}) as any)).rejects.toThrow('no usable text');
  });
  test('preserves quota errors through the real adapter without extra API requests',async()=>{
    let calls=0;
    const fixture=Bun.serve({port:0,hostname:'127.0.0.1',fetch(){calls++;return Response.json({error:{code:'credit_balance_exhausted',type:'insufficient_quota',message:'Synthetic quota error'}},{status:429});}});
    try{
      await expect(reply(payload,(model,context,options)=>complete({...model,baseUrl:fixture.url.origin+'/v1'},context,options))).rejects.toMatchObject({status:503,code:'ai_upstream_quota'});
      expect(calls).toBe(1);
    }finally{fixture.stop(true);}
  },15000);
  test('real pi-ai OpenAI adapter sends API request and parses streamed text using an explicit test double',async()=>{
    let received:any;
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
      const result=await reply(payload,(model,context,options)=>complete({...model,baseUrl:fixture.url.origin+'/v1'},context,options));
      expect(result.text).toBe('Fixture answer');expect(received.path).toBe('/v1/responses');expect(received.key).toBe('Bearer '+payload.apiKey);expect(received.body.model).toBe(payload.model);
    }finally{fixture.stop(true);}
  });
});
