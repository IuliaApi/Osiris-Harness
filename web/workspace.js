import { createApiChecks } from './api-checks.js';
const $=id=>document.getElementById(id);
const appState={name:'',csrf:'',root:null,runtime:null,route:'',projects:[],sessions:[],current:null,detail:null,links:{},sending:false};
const views={'/dashboard':['dashboard','Dashboard'],'/workspace':['sessions','Sessions'],'/chat':['chat','Chat'],'/manage':['manage','Manage'],'/api-checks':['api-checks','API Checks']};
const rel=(resource,name,method)=>resource?._links?.[name]?.method===method?resource._links[name]:null;
function localPath(path){
  if(typeof path!=='string'||!path.startsWith('/')||path.startsWith('//')||/[\\\x00-\x20]/.test(path)||new URL(path,location.origin).origin!==location.origin)throw Error('Invalid local API link.');
  return path;
}
function queryLink(link,params){
  if(!link||link.method!=='GET')throw Error('Read link unavailable.');
  const u=new URL(localPath(link.href),location.origin);
  for(const [key,value] of Object.entries(params))u.searchParams.set(key,value);
  return {...link,href:u.pathname+u.search};
}
function follow(link,body,headers){
  if(!link)throw Error('This action is not available.');
  return request(link.method,link.href,body,headers);
}
function el(tag,cls,content){const e=document.createElement(tag);if(cls)e.className=cls;if(content!==undefined)e.textContent=String(content);return e;}
function empty(target,message){const n=$(target);n.replaceChildren(el('div','empty',message));}
function notify(message){const t=$('toast');t.textContent=message;t.classList.remove('hidden');clearTimeout(notify.timer);notify.timer=setTimeout(()=>t.classList.add('hidden'),6500);}
async function fetchResponse(method, path, body, accept = 'application/json', headers = {}) {
  const options = { method, credentials: 'same-origin', redirect: 'error', headers: { Accept: accept, ...headers } };
  if (method !== 'GET' && appState.csrf) options.headers['X-CSRF-Token'] = appState.csrf;
  if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }
  const response = await fetch(localPath(path), options);
  if (response.status === 401 && appState.name) {
    $('app').classList.add('hidden');
    location.replace('/login');
    throw new Error('Sign in again.');
  }
  const contentType = response.headers.get('Content-Type') || '';
  let data = await response.text();
  if (contentType.split(';')[0] === 'application/json' && data) {
    // Keep malformed responses available for the diagnostics transcript.
    try { data = JSON.parse(data); } catch (error) { if (!(error instanceof SyntaxError)) throw error; }
  }
  return {
    ok: response.ok, status: response.status, url: path, data, contentType,
    requestId: response.headers.get('X-Request-Id'),
  };
}
async function request(method, path, payload, headers) {
  const result = await fetchResponse(method, path, payload, 'application/json', headers);
  if (!result.ok) {
    const error = new Error(`${result.data?.error || 'Request failed'} (HTTP ${result.status}; request ${result.requestId || 'unknown'})`);
    error.status = result.status;
    throw error;
  }
  return result.data;
}
function projectName(id){return appState.projects.find(p=>p.id===id)?.name||id||'Unassigned';}
function getPath(){return views[location.pathname]?location.pathname:'/dashboard';}
function go(path,replace=false){
  if(!appState.name)path='/login';
  history[replace?'replaceState':'pushState']({},'',path);
  return renderRoute();
}
function visible(node,show){node.classList.toggle('hidden',!show);}
async function renderRoute(){
  const logged=Boolean(appState.name);
  visible($('login-screen'),!logged);visible($('app'),logged);
  if(!logged){if(location.pathname!=='/login')history.replaceState({},'','/login');return;}
  const path=getPath();if(path!==location.pathname)history.replaceState({},'',path);
  appState.route=path;
  $('username').textContent=appState.name;$('avatar').textContent=appState.name[0].toUpperCase();$('greeting').textContent=`Welcome back, ${appState.name}`;
  for(const [p,[id]] of Object.entries(views))visible($(id+'-view'),p===path);
  document.querySelectorAll('[data-route]').forEach(b=>b.classList.toggle('active',b.dataset.route===path));$('page-label').textContent=views[path][1];
  try{
    await refreshData();if(appState.route!==path)return;
    if(path==='/dashboard')renderDashboard();
    if(path==='/workspace')await loadSessions();
    if(path==='/chat'){renderChatSessions();await loadChat();}
    if(path==='/manage')await renderManage();
  }catch(e){notify(e.message);}
}
async function refreshData(){
  const root=await request('GET','/api');
  const [projects,sessions,runtime]=await Promise.all([
    follow(queryLink(rel(root,'projects','GET'),{limit:100,sort:'name',order:'asc'})),
    follow(queryLink(rel(root,'sessions','GET'),{limit:100,sort:'updatedAt',order:'desc'})),
    follow(rel(root,'runtime','GET'))]);
  Object.assign(appState,{root,runtime,projects:projects.items,sessions:sessions.items,projectTotal:projects.page.total,sessionTotal:sessions.page.total});
  $('api-state').textContent='API connected';$('api-dot').classList.remove('off');
  const writable = writableProjects();
  for (const button of document.querySelectorAll('[data-action]')) {
    visible(button, button.dataset.action === 'new-project' ? rel(root, 'createProject', 'POST') : writable.length > 0);
  }
  apiChecks.updateChoices(writable, appState.sessions);
}
function writableProjects(){return appState.projects.filter(p=>rel(p,'createSession','POST'));}
function row(title,subtitle,status,onClick){const b=el('button','item-row');b.type='button';const l=el('span');l.append(el('div','item-title',title),el('div','item-meta',subtitle||''));b.append(l);if(status)b.append(el('span','badge-os '+status,status));b.addEventListener('click',onClick);return b;}
function renderDashboard(){const s=appState.sessions,p=appState.projects;$('count-projects').textContent=appState.projectTotal;$('count-sessions').textContent=appState.sessionTotal;$('count-active').textContent=s.filter(x=>x.status==='active').length+(s.length<appState.sessionTotal?'+':'');
  const r=$('recent-sessions');r.replaceChildren();if(!s.length)empty('recent-sessions','No sessions available.');else for(const x of s.slice(0,7))r.append(row(x.title,`${projectName(x.projectId)} · ${new Date(x.updatedAt||x.createdAt).toLocaleDateString()}`,x.status,()=>openChat(x)));
  const pr=$('dashboard-projects');pr.replaceChildren();if(!p.length)empty('dashboard-projects','No projects available.');else for(const x of p.slice(0,7))pr.append(row(x.name,x.language||'Project',null,()=>{manager={link:rel(x,'self','GET'),kind:'project',label:x.name};go('/manage');}));
}
function listLink(){const q={limit:10,sort:$('filter-sort').value,order:'desc'};if($('filter-q').value.trim())q.q=$('filter-q').value.trim();if($('filter-status').value)q.status=$('filter-status').value;return queryLink(rel(appState.root,'sessions','GET'),q);}
async function loadSessions(link){const data=await follow(link||listLink());appState.links=data._links||{};const rows=$('session-results');rows.replaceChildren();if(!data.items.length)empty('session-results','No sessions match your filters.');else for(const s of data.items){const r=el('div','session-item');r.append(el('div','item-title truncate',s.title),el('div','item-meta truncate',projectName(s.projectId)),el('span','badge-os '+s.status,s.status));if(rel(s,'self','GET'))r.append(button('Open',()=>openChat(s)));rows.append(r);}
  const p=data.page;$('page-info').textContent=`${p.total} sessions · ${p.total?p.offset+1:0}–${Math.min(p.offset+data.items.length,p.total)}`;$('previous-page').disabled=!rel(data,'previous','GET');$('next-page').disabled=!rel(data,'next','GET');
}
function button(label,fn){const b=el('button','btn-os',label);b.type='button';b.onclick=async()=>{b.disabled=true;try{await fn();}catch(e){notify(e.message);}finally{b.disabled=false;}};return b;}
function renderChatSessions(){
  const root=$('chat-session-list');root.replaceChildren();
  for(const s of appState.sessions){
    const b=button(s.title,()=>selectSession(s));b.className='chat-session'+(appState.current?.id===s.id?' selected':'');b.append(el('small','truncate',projectName(s.projectId)));root.append(b);
  }
  if(!appState.sessions.length)empty('chat-session-list','No sessions available.');
}
async function openChat(s){appState.current=s;return go('/chat');}
async function selectSession(s){appState.current=s;renderChatSessions();await loadChat();}
function messageNode(m){const block=el('article','message '+(m.role==='user'?'user':'assistant'));block.append(el('div','message-label',m.role),el('div','message-text',m.content));return block;}
async function loadChat(){
  const s=appState.current,box=$('chat-history');
  appState.detail=null;for(const id of ['archive-session','delete-session','chat-form'])visible($(id),false);
  $('chat-title').textContent=s?.title||'Select a session';$('chat-project').textContent='';$('chat-status').textContent='—';empty('chat-history',s?'Loading conversation…':'Choose a session to open its conversation.');
  if(!s)return;
  const d=await follow(queryLink(rel(s,'self','GET'),{include:'project,messages'}));
  if(d.messagesPage?.hasMore){
    const latest=await follow(queryLink(rel(d,'messages','GET'),{sort:'createdAt',order:'desc',limit:25}));
    d.messages=latest.items.reverse();
  }
  if(appState.current!==s)return;
  appState.detail=d;$('chat-title').textContent=d.title;$('chat-project').textContent=d.project?.name||projectName(d.projectId);$('chat-status').textContent=d.status;
  visible($('archive-session'),d.status==='active'&&rel(d,'update','PATCH'));visible($('delete-session'),rel(d,'delete','DELETE'));visible($('chat-form'),d.status==='active'&&rel(d,'chat','POST'));
  $('message-text').disabled=appState.sending;$('send-message').disabled=appState.sending;$('chat-hint').textContent='Provider and model are selected in Manage.';
  box.replaceChildren();
  if(d.messagesPage?.hasMore)box.append(el('p','hint','Showing the latest 25 messages. Open Manage → Sessions → Messages for earlier pages.'));
  if(!d.messages?.length)empty('chat-history','No messages yet.');else for(const m of d.messages)box.append(messageNode(m));box.scrollTop=box.scrollHeight;
}
async function sendChat(event){
  event.preventDefault();const d=appState.detail,text=$('message-text').value.trim();if(!text||appState.sending)return;
  const settings=modelChoice();if(!settings)throw Error('No provider available. Check Manage → Model settings.');
  appState.sending=true;$('send-message').disabled=true;$('message-text').disabled=true;$('chat-hint').textContent='Waiting for provider…';
  try{await follow(rel(d,'chat','POST'),{message:text,...settings});$('message-text').value='';}
  finally{appState.sending=false;await loadChat();}
}
function showModal(id){$(id).classList.add('fallback-open');$(id).setAttribute('aria-hidden','false');$(id).querySelector('input').focus();}
function hideModal(id){$(id).classList.remove('fallback-open');$(id).setAttribute('aria-hidden','true');}
function openNewSession(){
  const select=$('session-project');select.replaceChildren();
  for(const p of writableProjects()){const option=el('option','',p.name);option.value=p.id;select.append(option);}
  if(select.length)showModal('session-modal');
}
async function createSession(e){
  e.preventDefault();const p=writableProjects().find(x=>x.id===$('session-project').value);
  const s=await follow(rel(p,'createSession','POST'),{title:$('session-title').value.trim()});
  hideModal('session-modal');$('session-form').reset();await openChat(s);
}
async function createProject(e){
  e.preventDefault();const payload={name:$('project-name').value.trim()};
  for(const [key,id] of [['language','project-language'],['repositoryUrl','project-url']])if($(id).value.trim())payload[key]=$(id).value.trim();
  await follow(rel(appState.root,'createProject','POST'),payload);hideModal('project-modal');$('project-form').reset();await renderRoute();notify('Project created.');
}
async function archiveSession(){if(confirm('Archive this session?')){await follow(rel(appState.detail,'update','PATCH'),{status:'archived'});await renderRoute();}}
async function deleteSession(){if(confirm('Delete this session and all its messages and tasks?')){await follow(rel(appState.detail,'delete','DELETE'));appState.current=null;await renderRoute();}}

// Management uses item and collection affordances; no role checks or writable URLs live here.
let manager=null;
function managePage(link,kind,label,parent=null){manager={link,kind,label,parent};return renderManage();}
function labelFor(item){return item.name||item.title||item.path||item.username||item.userId||item.id||'Record';}
function section(root,title,body){const node=el('section','resource-card');node.append(el('h3','chat-heading',title));if(body)node.append(el('p','item-meta',body));root.append(node);return node;}
function formAction(root,label,fields,submit,initial={}){
  const details=el('details','resource-card'),summary=el('summary','',label),form=el('form','fields');details.append(summary,form);root.append(details);
  const controls={};
  for(const [key,caption,type='text',optional=false] of fields){
    const wrap=el('label','',caption),choices=Array.isArray(type),input=el(choices?'select':type==='textarea'?'textarea':'input','input-os');
    if(choices)for(const value of type)input.append(el('option','',value));
    else if(type!=='textarea'){input.type=type;if(type==='password')input.autocomplete='new-password';}
    input.name=key;input.required=!optional;if(initial[key]!=null)input.value=initial[key];
    wrap.append(input);form.append(wrap);controls[key]=input;
  }
  const save=el('button','btn-os primary',label),error=el('div','notice');error.setAttribute('role','alert');form.append(save,error);
  form.onsubmit=async e=>{
    e.preventDefault();if(save.disabled)return;save.disabled=true;error.textContent='';
    const payload={};for(const [key,input] of Object.entries(controls)){if(input.value!=='')payload[key]=input.value;}
    try{await submit(payload);details.open=false;notify(label+' saved.');}
    catch(err){error.textContent=err.message;}finally{save.disabled=false;for(const input of Object.values(controls))if(input.type==='password')input.value='';}
  };
  return controls;
}
function modelChoice(){
  const ai=appState.runtime?.ai;if(!ai)return null;
  let pref;try{pref=JSON.parse(localStorage.getItem('osiris:model:'+appState.userId));}catch{}
  if(pref&&ai.providers.some(p=>p.id===pref.provider)&&typeof pref.model==='string'&&pref.model)return {provider:pref.provider,model:pref.model};
  const provider=ai.providers.find(p=>p.id===ai.defaultProvider)||ai.providers[0];
  return provider?{provider:provider.id,model:provider.defaultModel}:null;
}
function renderSettings(body,runtime){
  appState.runtime=runtime;const providers=runtime.ai.providers,choice=modelChoice();
  if(choice){
    const node=section(body,'Chat model',`Current choice: ${choice.provider} / ${choice.model}. Only this preference is saved in this browser.`);
    const inputs=formAction(node,'Save model',[['provider','Provider',providers.map(p=>p.id)],['model','Model']],prefs=>{
      if(!/^[A-Za-z0-9][A-Za-z0-9_.:/-]{0,79}$/.test(prefs.model))throw Error('Use a model identifier of 1–80 characters.');
      localStorage.setItem('osiris:model:'+appState.userId,JSON.stringify(prefs));return renderManage();
    },choice);
    inputs.provider.onchange=()=>{inputs.model.value=providers.find(p=>p.id===inputs.provider.value).defaultModel;};
  }
  for(const p of providers){
    const node=section(body,p.label,`${p.configured?'Configured':'Not configured'}. Connectivity is checked when sending a message.`);
    const set=rel(p,'setCredential','PUT'),del=rel(p,'deleteCredential','DELETE');
    if(set)formAction(node,'Set key',[['apiKey','Provider API key','password']],async payload=>{await follow(set,payload);await renderManage();});
    if(del)node.append(button('Remove key',async()=>{if(confirm(`Remove your ${p.label} credential?`)){await follow(del);await renderManage();}}));
  }
}
const projectFields=[['name','Name'],['language','Language','text',true],['repositoryUrl','Public GitHub URL','url',true]];
const sessionFields=[['title','Title'],['status','Status',['active','completed','archived']]];
const childPages=[['sessions','session','Sessions'],['members','member','Members'],['messages','message','Messages'],['tasks','task','Tasks'],['toolRuns','run','Tool requests'],['context','context','Project context']];
const createActions={
  session:['createSession','Create session',[['title','Title']]],
  message:['createMessage','Append message',[['content','Message','textarea']]],
  task:['createTask','Create task',[['title','Title']]],
  project:['createProject','Create project',projectFields],
  member:['setMember','Set membership',[['userId','Account ID'],['role','Membership role',['viewer','member']]]]
};
function versionHeader(item){return Number.isInteger(item.version)?{'If-Match':`"${item.version}"`}:{};}
function renderActions(root,item,kind,collection=false){
  const nav=el('div','actions');root.append(nav);
  const action=(link,label,fields,initial={},map=x=>x)=>{
    if(link)formAction(root,label,fields,async payload=>{await follow(link,map(payload),versionHeader(item));await renderManage();},initial);
  };
  for(const [relName,child,label] of childPages){
    const link=rel(item,relName,'GET');
    if(link)nav.append(button(label,()=>managePage(link,child,label+' · '+labelFor(item),manager)));
  }
  if(kind==='session'&&rel(item,'self','GET')&&!collection)nav.append(button('Open conversation',()=>openChat(item)));
  for(const [type,[name,label,fields]] of Object.entries(createActions)){
    const link=rel(item,name,'POST')||(collection&&kind===type?rel(item,'create','POST'):null);
    action(link,label,fields,{},payload=>type==='message'?{role:'user',...payload}:payload);
  }
  const tool=rel(item,'requestTool','POST')||(collection&&kind==='run'?rel(item,'create','POST'):null);
  if(tool){
    action(tool,'Request file read',[['path','Workspace filename']],{},p=>({operation:'workspace.read',...p}));
    action(tool,'Request file write',[['path','Workspace filename'],['content','Contents','textarea']],{},p=>({operation:'workspace.write',...p}));
  }
  const update=rel(item,'update','PATCH');
  if(kind==='project')action(update,'Edit project',projectFields,item,p=>({...p,language:p.language||null,repositoryUrl:p.repositoryUrl||null}));
  action(rel(item,'setVisibility','PATCH'),'Change visibility',[['visibility','Visibility',['private','public']]],item);
  if(kind==='session')action(update,'Edit session',sessionFields,item);
  if(kind==='task'&&update){
    const next={pending:['running'],running:['completed','failed'],failed:['pending']}[item.status]||[];
    if(next.length)action(update,'Transition task',[['status','Next state',next],['result','Result','textarea',true]]);
  }
  for(const [key,title] of [['approve','Approve request'],['execute','Execute approved request']]){
    const link=rel(item,key,'POST');if(link)nav.append(button(title,async()=>{if(confirm(`${title}: ${item.operation} ${item.path}?`)){await follow(link,{},versionHeader(item));await renderManage();}}));
  }
  const del=rel(item,'delete','DELETE');
  if(del)nav.append(button('Delete '+kind,async()=>{
    if(!confirm(`Delete ${kind} ${labelFor(item)}?${kind==='session'?' This also deletes its contents.':''}`))return;
    await follow(del,undefined,versionHeader(item));if(manager.kind===kind&&!collection&&!manager.listing)manager=manager.parent;await renderManage();
  }));
}
function renderItem(root,item,kind){
  const values=Object.entries(item).filter(([key,value])=>!key.startsWith('_')&&value!==null&&['string','number','boolean'].includes(typeof value)).map(([k,v])=>`${k}: ${v}`).join('\n');
  const node=section(root,labelFor(item),values);renderActions(node,item,kind);
}
async function renderManage(){
  const root=appState.root,body=$('manage-body'),nav=$('manage-nav');nav.replaceChildren();
  const destinations=[['projects','project','Projects'],['sessions','session','Sessions'],['runtime','settings','Model settings & credentials'],['budget','budget','Daily budget']];
  for(const [key,kind,label] of destinations){
    const link=rel(root,key,'GET');if(link)nav.append(button(label,()=>managePage(kind==='session'?queryLink(link,{limit:25}):link,kind,label)));
  }
  const prune=rel(root,'prune','POST');
  if(prune)nav.append(button('Prune expired authentication data',async()=>{
    if(confirm('Remove expired logins, expired rate-limit windows and old authentication audit records?')){
      await follow(prune);notify('Expired authentication data pruned.');
    }
  }));
  manager??={link:rel(root,'projects','GET'),kind:'project',label:'Projects'};
  const page=manager;body.replaceChildren(el('p','hint','Loading…'));
  const data=await follow(page.link);if(manager!==page)return;
  body.replaceChildren(el('h2','modal-title',page.label));if(page.parent)body.append(button('Back',()=>{manager=page.parent;return renderManage();}));
  if(page.kind==='settings'){renderSettings(body,data);return;}
  if(['budget','context'].includes(page.kind)){body.append(el('pre','response-output',JSON.stringify(data,(key,value)=>key==='_links'?undefined:value,2)));return;}
  page.listing=Array.isArray(data.items);
  if(!page.listing){renderItem(body,data,page.kind);return;}
  renderActions(body,data,page.kind,true);
  if(!data.items.length)body.append(el('p','hint','No records available.'));
  for(const item of data.items)renderItem(body,item,page.kind);
  const pager=el('div','actions');for(const [key,label] of [['previous','Previous page'],['next','Next page']]){
    const link=rel(data,key,'GET');if(link)pager.append(button(label,()=>managePage(link,page.kind,page.label,page.parent)));
  }body.append(pager);
}

function bind(id,event,handler){$(id).addEventListener(event,async e=>{try{await handler(e);}catch(error){notify(error.message);}});}
function signedIn(data){appState.name=data.user.username;appState.csrf=data.csrfToken;appState.userId=data.user.id;}
async function init(){
  for(const b of document.querySelectorAll('[data-action]'))visible(b,false);
  visible($('chat-form'),false);bind('delete-session','click',deleteSession);
  bind('checks-refresh','click',async()=>{await refreshData();notify('API check data refreshed.');});
  for(const b of document.querySelectorAll('[data-bs-dismiss="modal"]'))b.onclick=()=>hideModal(b.closest('.modal').id);
  for(const b of document.querySelectorAll('[data-route]'))b.onclick=()=>go(b.dataset.route);
  for(const b of document.querySelectorAll('[data-action]'))b.onclick=()=>b.dataset.action==='new-project'?showModal('project-modal'):openNewSession();
  const landing=getPath();
  bind('login-form','submit',async e=>{
    e.preventDefault();$('login-error').textContent='';
    try{signedIn(await request('POST','/auth/login',{username:$('login-name').value.trim(),password:$('login-password').value}));$('login-form').reset();await go(landing,true);}
    catch(err){$('login-error').textContent=err.message;$('login-password').value='';$('login-password').focus();}
  });
  bind('logout','click',async()=>{await follow(rel(appState.root,'logout','POST'));location.replace('/login');});
  bind('filter-form','submit',e=>{e.preventDefault();return loadSessions();});
  for(const [id,key] of [['previous-page','previous'],['next-page','next']])bind(id,'click',()=>loadSessions(appState.links[key]));
  bind('chat-form','submit',sendChat);bind('message-text','keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();if(!$('send-message').disabled)$('chat-form').requestSubmit();}});
  bind('session-form','submit',createSession);bind('project-form','submit',createProject);bind('archive-session','click',archiveSession);bind('refresh-sessions','click',renderRoute);
  window.addEventListener('popstate',renderRoute);
  window.addEventListener('pageshow',e=>{if(e.persisted)location.reload();});
  try{signedIn(await request('GET','/auth/me'));}catch(e){if(e.status!==401)$('login-error').textContent=e.message;}
  await renderRoute();
}
const apiChecks = createApiChecks({ appState, rel, queryLink, fetchResponse, refreshData });
init().catch(e=>notify(e.message));
