import { expect, test } from 'bun:test';
import { authPolicyFor } from '@oh-my-pi/pi-catalog/compat/auth';
import { bindCallback, readyForCallback, receiveCallback, type CallbackCandidate } from './browser-callback';
import endpoints from './callback-endpoints.json';

const endpoint=endpoints['openai-codex'], state='349b1bf9c9c44aeca257d3233274f721';
function authorize(target=endpoint,nonce=state) {
  return new URL('https://issuer.example/authorize?'+new URLSearchParams({redirect_uri:target,state:nonce}));
}
function pending(nonce=state):CallbackCandidate {
  return {state:'pending',expiresAt:Date.now()+60_000,prompt:{callback:true},callback:{endpoint,state:nonce}};
}
function request(url:string,method='GET',host=new URL(url).host) {
  return new Request(url,{method,headers:{Host:host,Cookie:'session=private-cookie',Authorization:'Bearer private-bearer'}});
}

test('only a registered SDK redirect, strong state and explicitly published port enable automatic return',()=>{
  for (const [id,target] of Object.entries(endpoints)) {
    const u=new URL(target), policy=authPolicyFor(id)?.login;
    if (policy?.kind==='oauth-code') {
      expect(`${policy.callback.hostname}:${policy.callback.port}${policy.callback.path}`).toBe(u.host+u.pathname);
    }
    expect(bindCallback(id,authorize(target),new Set([u.port]))).toEqual({endpoint:target,state});
    expect(bindCallback(id,authorize(target),new Set())).toBeUndefined();
  }
  const ports=new Set(['1455','54549','59654']);
  for (const target of [endpoint+'/wrong',endpoint.replace('localhost','127.0.0.1'),endpoint.replace('1455','59654'),
    endpoint.replace('localhost','evil.example'),endpoint+'?to=http://localhost:8090',endpoint.replace('http:','https:')])
    expect(bindCallback('openai-codex',authorize(target),ports)).toBeUndefined();
  for (const nonce of ['','1234','anything-not-random-enough']) expect(bindCallback('openai-codex',authorize(endpoint,nonce),ports)).toBeUndefined();
  const ambiguous=authorize();ambiguous.searchParams.append('state',state);
  expect(bindCallback('openai-codex',ambiguous,ports)).toBeUndefined();
  const stateless=new URL('https://openrouter.ai/auth?callback_url=http://localhost:54549/callback');
  expect(bindCallback('openrouter',stateless,ports)).toBeUndefined();
  expect(bindCallback('openrouter',authorize(endpoint),ports)).toBeUndefined();
});

test('dispatches only to the unique matching pending flow, drops request metadata and rejects replay',async()=>{
  const first=pending(),second=pending('6fe4d76c3e9c45e49e2bdd951d800c71');
  const query=new URLSearchParams({code:'private-code',state:second.callback!.state,scope:'private-scope',iss:'https://example.org/private'});
  const req=request(endpoint+'?'+query);
  const received:{flow:CallbackCandidate,answer:string|null}[]=[];
  const handle=()=>receiveCallback(req,[first,second],(flow,answer)=>received.push({flow,answer}));
  const result=handle();
  expect(result.status).toBe(200);expect(received).toHaveLength(1);expect(received[0].flow).toBe(second);
  const answer=new URL(received[0].answer!);
  expect(answer.origin+answer.pathname).toBe(endpoint);
  expect([...answer.searchParams]).toEqual([['code','private-code'],['state',query.get('state')!]]);
  expect(handle().status).toBe(404);expect(received).toHaveLength(1);
  expect(readyForCallback(first,Date.now())).toBe(true);expect(readyForCallback(second,Date.now())).toBe(false);
  const page=await result.text();
  for (const sensitive of [...query.values(),'private-cookie','private-bearer']) expect(page).not.toContain(sensitive);
  expect(result.headers.get('cache-control')).toBe('no-store');expect(result.headers.get('referrer-policy')).toBe('no-referrer');
  expect(result.headers.get('set-cookie')).toBeNull();expect(result.headers.get('location')).toBeNull();
});

test('ambiguous, expired, cancelled, answered and non-callback steps cannot receive a callback',()=>{
  const candidate=pending(),req=request(`${endpoint}?code=synthetic&state=${state}`);
  const unexpected=()=>{throw new Error('Unexpected delivery');};
  for (const flows of [[candidate,pending()],[{...candidate,state:'cancelled'}],[{...candidate,state:'completed'}],
    [{...candidate,expiresAt:Date.now()}],[{...candidate,prompt:undefined}],[{...candidate,prompt:{}}],[]]) {
    expect(receiveCallback(req,flows,unexpected).status).toBe(404);
  }
  expect(readyForCallback(candidate,Date.now())).toBe(true);
});

test('bare, wrong authority, wrong route, malformed and unauthorized callbacks never dispatch or reflect input',async()=>{
  const q=`?code=private-code&state=${state}`,correct=endpoint+q, flow=pending();
  for (const req of [request(endpoint),request(endpoint+'?error=access_denied'),request(correct,'POST'),
    request(correct,'GET','localhost:8090'),request(correct.replace('localhost','example.org')),request(correct.replace(':1455',':54545')),
    request(correct.replace('/auth/callback','/launch')),request(correct.replace('/auth/callback','/auth/start')),
    request(correct.replace(state,'wrong')),request(correct+'&state='+state),request(correct+'&error=access_denied'),
    request(correct+'&code=second'),request(endpoint+`?state=${state}&code=https://example.org/invalid`)]) {
    const result=receiveCallback(req,[flow],()=>{throw new Error('Unexpected delivery');});
    expect(result.status).toBe(404);const text=await result.text();
    expect(text).not.toContain(state);expect(text).not.toContain('private-code');expect(text).not.toContain('example.org');
  }
  expect(readyForCallback(flow,Date.now())).toBe(true);
});
