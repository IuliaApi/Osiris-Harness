const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
// Real Chromium, synthetic API only. Run: node scripts/test-chat-ui.cjs
const root=path.join(__dirname,'..');
const link=(href,method='GET')=>({href,method});
const projects=[{id:'project',name:'Synthetic test project',_links:{createSession:link('/sessions','POST')}}];
const sessions=['one','two','archived'].map(id=>({id,title:'Session '+id,projectId:'project',status:id==='archived'?'archived':'active',_links:{self:link('/sessions/'+id),...(id==='archived'?{}:{chat:link('/sessions/'+id+'/chat','POST')})}}));
let failed=false;
const server=http.createServer(async(req,res)=>{
  const url=new URL(req.url,'http://127.0.0.1');const json=data=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify(data));};
  if(url.pathname==='/auth/me')return json({user:{id:'fixture',username:'Tester'},csrfToken:'synthetic-csrf'});
  if(url.pathname==='/api')return json({_links:{projects:link('/projects'),sessions:link('/sessions'),runtime:link('/api/runtime')}});
  if(url.pathname==='/projects')return json({items:projects,page:{total:1}});
  if(url.pathname==='/sessions')return json({items:sessions,page:{total:3},_links:{}});
  if(url.pathname==='/api/runtime')return json({ai:{defaultProvider:'openai',routing:'oh-my-pi',providers:[{id:'openai',label:'OpenAI',defaultModel:'gpt-4.1-mini',configured:true},{id:'anthropic',label:'Claude',defaultModel:'claude-haiku-4-5',configured:false}]}});
  if(req.method==='POST'&&url.pathname.endsWith('/chat')){
    let raw='';for await(const chunk of req)raw+=chunk;const body=JSON.parse(raw);
    assert.equal(body.provider,'openai');assert.equal(body.model,'gpt-4.1-mini');assert.equal(req.headers['x-csrf-token'],'synthetic-csrf');
    await new Promise(r=>setTimeout(r,350));
    if(body.message==='fail once'&&!failed){failed=true;res.statusCode=503;return json({error:'Synthetic provider outage'});}
    return json({userMessage:{role:'user',content:body.message},assistantMessage:{role:'assistant',content:'Example:\n```js\nconst safe = "<script>";\n```'}});
  }
  const session=sessions.find(s=>url.pathname==='/sessions/'+s.id);
  if(session){await new Promise(r=>setTimeout(r,session.id==='one'?100:10));return json({...session,project:projects[0],messages:[]});}
  if(url.pathname==='/assets/workspace.js'){
    res.setHeader('Content-Type','text/javascript');return res.end(fs.readFileSync(path.join(root,'web/workspace.js'),'utf8')+'\nglobalThis.uiTest={appState,$,resizeComposer,selectSession,sendChat,messageNode,renderChatModel,modelChoice};');
  }
  if(url.pathname==='/assets/api-checks.js'){res.setHeader('Content-Type','text/javascript');return res.end(fs.readFileSync(path.join(root,'web/api-checks.js'),'utf8'));}
  if(url.pathname==='/assets/workspace.css'){res.setHeader('Content-Type','text/css');return res.end(fs.readFileSync(path.join(root,'web/workspace.css'),'utf8'));}
  res.setHeader('Content-Type','text/html');res.end(fs.readFileSync(path.join(root,'web/index.html'),'utf8').replace(/<link[^>]+https:[^>]+>/g,''));
});
const listen=(s,port=0)=>new Promise(r=>s.listen(port,'127.0.0.1',r));
let chrome,ws,profile;
(async()=>{
  await listen(server);const port=server.address().port;
  const probe=http.createServer();await listen(probe);const debugPort=probe.address().port;await new Promise(r=>probe.close(r));
  profile=fs.mkdtempSync(path.join(os.tmpdir(),'osiris-ui-'));
  const binary=process.env.CHROME_PATH||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  chrome=spawn(binary,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-address=127.0.0.1','--remote-debugging-port='+debugPort,'--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});
  let tabs;for(let i=0;i<80;i++){try{tabs=await(await fetch('http://127.0.0.1:'+debugPort+'/json/list')).json();break;}catch{await new Promise(r=>setTimeout(r,100));}}
  assert(tabs,'Chrome did not start');ws=new WebSocket(tabs.find(t=>t.type==='page').webSocketDebuggerUrl);
  await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});let seq=0;const pending=new Map();
  ws.onmessage=e=>{const data=JSON.parse(e.data);if(data.id){const {resolve,reject}=pending.get(data.id);pending.delete(data.id);data.error?reject(Error(data.error.message)):resolve(data.result);}};
  const cdp=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});
  const evaluate=async(expression)=>{const result=await cdp('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.text+' '+result.exceptionDetails.exception?.description);return result.result.value;};
  await cdp('Page.navigate',{url:'http://127.0.0.1:'+port+'/chat'});
  for(let i=0;i<100;i++){if(await evaluate('Boolean(globalThis.uiTest?.appState.runtime)'))break;await new Promise(r=>setTimeout(r,100));}
  const result=await evaluate(`(async()=>{
    const {appState,$,resizeComposer,selectSession,sendChat,messageNode}=uiTest;
    const check=(value,label)=>{if(!value)throw Error(label);};
    const get=id=>appState.sessions.find(s=>s.id===id);
    await selectSession(get('one'));
    check($('chat-provider').value==='openai','OpenAI selected');
    check(document.querySelectorAll('.starter-grid button').length===3,'starter prompts');
    document.querySelector('.starter-grid button').click();check($('message-text').value.startsWith('Help me debug'),'starter fills composer');
    $('message-text').value='draft one';resizeComposer();await selectSession(get('two'));$('message-text').value='draft two';resizeComposer();await selectSession(get('one'));check($('message-text').value==='draft one','drafts isolated');
    const a=selectSession(get('one')),b=selectSession(get('two'));await Promise.all([a,b]);check(appState.detail.id==='two','stale response ignored');
    $('message-text').value='hello';resizeComposer();const sending=sendChat({preventDefault(){}});
    check(document.querySelectorAll('.thinking-dots span').length===3,'three waiting dots');check($('message-text').disabled,'composer locked');check($('message-text').value==='','input cleared immediately');await selectSession(get('one'));check(appState.current.id==='two','switch blocked');await sending;
    check(!document.querySelector('.thinking-dots'),'pending removed');check($('message-text').value==='','draft cleared');check(document.querySelector('pre code').textContent.includes('<script>'),'safe code rendering');check(!document.querySelector('.message-text script'),'HTML not executed');
    $('message-text').value='fail once';resizeComposer();await sendChat({preventDefault(){}});check(document.querySelector('.message.error'),'inline failure');check($('message-text').value==='','failed input remains clear');check(document.querySelector('.message.user .message-text'),'failed message kept in history');document.querySelector('.message.error button').click();for(let i=0;i<50&&appState.sending;i++)await new Promise(r=>setTimeout(r,20));check(!document.querySelector('.message.error'),'retry recovered');check([...document.querySelectorAll('.message.user .message-text')].filter(n=>n.textContent==='fail once').length===1,'retry no duplicate');
    await selectSession(get('archived'));check($('message-text').disabled,'archived composer disabled');check($('chat-form').classList.contains('hidden'),'archived form hidden');
    await selectSession(get('one'));$('message-text').value='';resizeComposer();check($('send-message').disabled,'blank send disabled');
    $('chat-provider').value='anthropic';$('chat-provider').dispatchEvent(new Event('change'));check($('chat-provider-state').textContent.includes('Add your API key'),'missing key indicated');check($('chat-model').value==='claude-haiku-4-5','provider model updated');
    check(!localStorage.getItem('osiris:model:fixture').includes('apiKey'),'preferences contain no credential');
    return 'PASS: provider/model + CSRF routing, starters, drafts, race protection, pending dots, safe code, success, failure/retry, read-only and unconfigured states';
  })()`);
  console.log(result);
  await cdp('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
  assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Mobile page overflows');
  await cdp('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  assert(await evaluate('(()=>{const n=uiTest.messageNode({role:"user",content:"test"});document.body.append(n);const ok=getComputedStyle(n).animationName==="none";n.remove();return ok;})()'),'Reduced motion not respected');
  console.log('PASS: mobile width and reduced motion');
  if(process.env.UI_SCREENSHOT){const shot=await cdp('Page.captureScreenshot',{format:'png'});fs.writeFileSync(process.env.UI_SCREENSHOT,Buffer.from(shot.data,'base64'));}
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(ws)ws.close();if(chrome){chrome.kill();await new Promise(r=>setTimeout(r,500));}server.closeAllConnections();server.close();if(profile&&path.dirname(path.resolve(profile))===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('osiris-ui-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});});
