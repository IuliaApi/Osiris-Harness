import { expect, test } from 'bun:test';
import { randomBytes } from 'node:crypto';
import { authRequest, browserCallbackRequest, loginAuth, loginRegistry } from './auth';
import { callbackRule } from './auth-provider';

const actor=()=>randomBytes(32).toString('hex');
type View={id:string, provider:string, state:string, url?:string, message?:string,
  automaticCallback?:boolean, prompt?:{id:string,message:string,secret:boolean,allowEmpty:boolean,callback?:boolean}, credential?:string};
async function observe(owner:string,id:string,when:(v:View)=>boolean) {
  const deadline=Date.now()+15_000;
  do {
    const v=await authRequest('status',{actor:owner,id}) as View;
    // This integration test polls the same status endpoint as the browser; fake timers cannot drive an SDK worker.
    if (when(v)) return v;
    if (v.state==='failed') throw new Error('SDK login failed before reaching the expected step');
    await Bun.sleep(25);
  } while(Date.now()<deadline);
  throw new Error('Timed out waiting for SDK login step');
}

test('SDK roster includes subscriptions, canonical aliases, paused and unsupported methods with reasons',()=>{
  const rows=loginRegistry();
  for (const id of ['anthropic','kimi-code','openai-codex-device','devin-windsurf','kilo','mistral','cursor','kiro','snowflake'])
    expect(rows.find(x=>x.id===id)).toMatchObject({browser:true,available:true});
  for (const id of ['ollama','vercel-ai-gateway','alibaba-token-plan','xiaomi','cloudflare-ai-gateway','devin-cli'])
    expect(rows.find(x=>x.id===id)).toMatchObject({browser:false});
  expect(rows.find(x=>x.id==='openai-codex-device')?.provider).toBe('openai-codex');
  expect(rows.find(x=>x.id==='devin-windsurf')?.provider).toBe('devin');
  for(const id of ['devin-cli','antigravity-cli','google-antigravity']) {
    const row=rows.find(x=>x.id===id);expect(row?.available).toBe(false);expect(row?.reason).toBeTruthy();
  }
});

test('real SDK keyless OLLAMA login: owner-only, secret prompt, internal handoff, no fallback or refresh',async()=>{
  const owner=actor(), other=actor();
  const {id}=await authRequest('start',{actor:owner,provider:'ollama'}) as View;
  try {
    expect(id).toMatch(/^[\da-f]{64}$/);
    for (const action of ['status','cancel']) await expect(authRequest(action,{actor:other,id})).rejects.toMatchObject({status:404});
    const pending=await observe(owner,id,v=>!!v.prompt);
    expect(pending.prompt).toMatchObject({secret:true,allowEmpty:true});
    expect(pending.credential).toBeUndefined();
    await expect(authRequest('answer',{actor:other,id,promptId:pending.prompt!.id,answer:''})).rejects.toMatchObject({status:404});
    await expect(authRequest('answer',{actor:owner,id,promptId:actor(),answer:''})).rejects.toMatchObject({status:409});
    await authRequest('answer',{actor:owner,id,promptId:pending.prompt!.id,answer:''});
    const final=await observe(owner,id,v=>v.state==='completed');
    expect(final.prompt).toBeUndefined();expect(final.url).toBeUndefined();
    const credential=final.credential!;
    expect(JSON.parse(credential)).toEqual({provider:'ollama',type:'keyless'});
    expect(await loginAuth('ollama',credential)).toEqual({apiKey:'ollama'});
    await expect(loginAuth('anthropic',credential)).rejects.toMatchObject({code:'login_invalid'});
    expect(await authRequest('resolve',{provider:'ollama',credential})).toEqual({credential});
  } finally {await authRequest('cancel',{actor:owner,id});}
  await expect(authRequest('status',{actor:owner,id})).rejects.toMatchObject({status:404});
},25_000);

test('real multi-step SDK form rejects structured keys and never includes a submitted answer in a pending view',async()=>{
  const owner=actor(), secret='vck_synthetic_noncredential';
  const {id}=await authRequest('start',{actor:owner,provider:'vercel-ai-gateway'}) as View;
  try {
    const step=await observe(owner,id,v=>!!v.prompt);
    expect(step.url).toStartWith('https://vercel.com/');
    await authRequest('answer',{actor:owner,id,promptId:step.prompt!.id,answer:'{"baseUrl":"http://127.0.0.1"}'});
    const retry=await observe(owner,id,v=>!!v.prompt);
    expect(JSON.stringify(retry)).not.toContain('127.0.0.1');
    const acknowledged=await authRequest('answer',{actor:owner,id,promptId:retry.prompt!.id,answer:secret});
    expect(JSON.stringify(acknowledged)).not.toContain(secret);
    const final=await observe(owner,id,v=>v.state==='completed');
    expect(await loginAuth('vercel-ai-gateway',final.credential!)).toEqual({apiKey:secret});
  } finally {await authRequest('cancel',{actor:owner,id});}
},20_000);

test('real subscription authorization URL and PKCE/state callback; cancel without contacting sign-in provider',async()=>{
  const owner=actor(), {id}=await authRequest('start',{actor:owner,provider:'anthropic'}) as View;
  try {
    const v=await observe(owner,id,v=>!!v.url && !!v.prompt);
    const auth=new URL(v.url!);
    expect(auth.origin).toBe('https://claude.ai');expect(auth.searchParams.get('code_challenge')).toBeTruthy();
    const target=auth.searchParams.get('redirect_uri')!, state=auth.searchParams.get('state')!;
    expect(v.prompt!.message).toContain(target);expect(v.prompt!.secret).toBe(true);
    expect(v.prompt!.callback).toBe(true);expect(v.automaticCallback).toBeUndefined();
    const rule=callbackRule(auth);
    expect(rule.accepts(`${target}?code=fake&state=${state}`)).toBe(true);
    for(const input of ['fake',`${target}?code=fake`,`${target}?code=fake&state=wrong`,
      `${target}/wrong?code=fake&state=${state}`,`https://evil.invalid/?code=fake&state=${state}`,`${target}?code=a&code=b&state=${state}`])
      expect(rule.accepts(input)).toBe(false);
  } finally {await authRequest('cancel',{actor:owner,id});}
},20_000);

test('real SDK loopback sign-in binds one actor; provider denial consumes it without exchanging or exposing a code',async()=>{
  const owner=actor(), ports=new Set(['59654']);
  const {id}=await authRequest('start',{actor:owner,provider:'devin-windsurf'},ports) as View;
  try {
    const step=await observe(owner,id,v=>!!v.prompt);
    expect(step.automaticCallback).toBe(true);expect(step.prompt!.callback).toBe(true);
    expect(step.credential).toBeUndefined();
    const auth=new URL(step.url!),target=auth.searchParams.get('redirect_uri')!,state=auth.searchParams.get('state')!;
    const denial=(s:string)=>new Request(target+'?'+new URLSearchParams({state:s,error:'access_denied',error_description:'private upstream details'}),
      {headers:{Host:new URL(target).host,Cookie:'private-cookie=ignored'}});
    expect(browserCallbackRequest(denial(actor())).status).toBe(404);
    await expect(authRequest('status',{actor:actor(),id})).rejects.toMatchObject({status:404});
    const response=browserCallbackRequest(denial(state));
    expect(response.status).toBe(200);
    const text=await response.text();
    for (const secret of [state,'private upstream details','private-cookie',owner,id]) expect(text).not.toContain(secret);
    const final=await authRequest('status',{actor:owner,id}) as View;
    expect(final.state).toBe('failed');expect(final.message).toContain('did not authorize');
    expect(final.credential).toBeUndefined();expect(final.automaticCallback).toBeUndefined();expect(final.prompt).toBeUndefined();
    expect(browserCallbackRequest(denial(state)).status).toBe(404);
  } finally {await authRequest('cancel',{actor:owner,id});}
},20_000);

test('official API-key shaping retains OAuth identity and refuses expired or mismatched grants',async()=>{
  const expires=Date.now()+3_600_000;
  const data={access:'synthetic-access',refresh:'synthetic-refresh',expires,accountId:'synthetic-account',projectId:'synthetic-project'};
  const credential=JSON.stringify({type:'oauth',provider:'google-gemini-cli',expires,data});
  const result=await loginAuth('google-gemini-cli',credential);
  expect(JSON.parse(result.apiKey)).toMatchObject({token:data.access,projectId:data.projectId});
  expect(result.oauthIdentity?.accountId).toBe(data.accountId);
  for(const input of [credential.replaceAll(String(expires),'1'), 'not-json', '{}', 'x'.repeat(32769)])
    await expect(loginAuth('google-gemini-cli',input)).rejects.toMatchObject({code:'login_invalid'});
});

test('rejects arbitrary control fields, navigation URLs, blocked imports and unscoped requests before opening a flow',async()=>{
  const owner=actor();
  for(const body of [{provider:'ollama'}, {actor:owner,provider:'ollama',url:'https://evil.invalid'},
    {actor:owner,provider:'constructor'}, {actor:'browser-supplied-name',provider:'ollama'}]) {
    await expect(authRequest('start',body)).rejects.toMatchObject({status:422});
  }
  await expect(authRequest('start',{actor:owner,provider:'devin-cli'})).rejects.toMatchObject({code:'login_unavailable'});
});

test('billable validation requires separate, affirmative consent before asking for a key',async()=>{
  const owner=actor(), {id}=await authRequest('start',{actor:owner,provider:'zai'}) as View;
  try {
    const step=await observe(owner,id,v=>!!v.prompt);
    expect(step.url).toBeUndefined();
    expect(step.prompt!.message).toContain('paid quota');
    expect(step.prompt!.secret).toBe(false);
    await authRequest('answer',{actor:owner,id,promptId:step.prompt!.id,answer:'no'});
    const denied=await observe(owner,id,v=>v.state==='failed');
    expect(denied.credential).toBeUndefined();expect(denied.url).toBeUndefined();expect(denied.prompt).toBeUndefined();
  } finally {await authRequest('cancel',{actor:owner,id});}
},20_000);

test('official Copilot refresher renews an expired synthetic row without network or touching another account',async()=>{
  const provider='github-copilot', expires=1;
  const credential=JSON.stringify({type:'oauth',provider,expires,data:{
    access:'expired-synthetic',refresh:'synthetic-renewed',expires,accountId:'this-account',
  }});
  const result=await authRequest('resolve',{provider,credential}) as {credential:string};
  const grant=JSON.parse(result.credential);
  expect(grant.provider).toBe(provider);expect(grant.expires).toBeGreaterThan(Date.now());
  expect(grant.data.accountId).toBe('this-account');
  expect(JSON.parse((await loginAuth(provider,result.credential)).apiKey).token).toBe('synthetic-renewed');
},20_000);
