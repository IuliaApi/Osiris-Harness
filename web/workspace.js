import { createApiChecks } from './api-checks.js';
import { createChatWorkbench } from './chat-workbench.js';
import { validChatModel } from './model-choice.js';
import { showProviderLogin } from './provider-login.js';
import { initCommandMenu } from './command-menu.js';
const $=id=>document.getElementById(id);
const appState={name:'',csrf:'',root:null,runtime:null,route:'',projects:[],sessions:[],links:{}};
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
  const contentType = response.headers.get('Content-Type') || '';
  let data = await response.text();
  if (contentType.split(';')[0] === 'application/json' && data) {
    // Keep malformed responses available for the diagnostics transcript.
    try { data = JSON.parse(data); } catch (error) { if (!(error instanceof SyntaxError)) throw error; }
  }
  const reported = response.headers.get('X-Error-Page') || '';
  const errorPage = response.ok ? null : (
    /^\/errors\/[45][0-9]{2}\/[a-z][a-z0-9_]{0,63}$/.test(reported) && reported.startsWith(`/errors/${response.status}/`)
      ? reported : `/errors/${response.status}/request_failed`
  );
  return {
    ok: response.ok, status: response.status, url: path, data, contentType,
    requestId: response.headers.get('X-Request-Id'), errorPage,
  };
}
let navigatingToError = false;
function navigateToError(url) {
  if (navigatingToError) return;
  navigatingToError = true;
  // Back reopens the workspace page; the failed write was a fetch, not a form navigation.
  location.assign(url);
}
async function request(method, path, payload, headers) {
  let result;
  try { result = await fetchResponse(method, path, payload, 'application/json', headers); }
  catch (error) {
    if (error instanceof TypeError) navigateToError('/errors/503/connection_interrupted');
    throw error;
  }
  if (!result.ok) {
    const anonymousDiscovery = result.status === 401 && method === 'GET' && path === '/auth/me' && !appState.name;
    if (!anonymousDiscovery) navigateToError(result.errorPage);
    const error = new Error(`${result.data?.error || 'Request failed'} (HTTP ${result.status}; request ${result.requestId || 'unknown'})`);
    error.status = result.status;
    throw error;
  }
  return result.data;
}
function addErrorLink(container, {errorPage, status}) {
  if (!errorPage || [...container.querySelectorAll('a')].some(a => a.getAttribute('href') === errorPage)) return;
  const label = errorPage.split('/').at(-1).replaceAll('_', ' ');
  const link = el('a', '', `HTTP ${status} · ${label} — error details (new tab)`);
  link.href = errorPage;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  container.append(link);
}
function projectName(id){return id===null?'Private chat':appState.projects.find(p=>p.id===id)?.name||id;}
function getPath(){return views[location.pathname]?location.pathname:'/dashboard';}
function go(path,replace=false){
  if(!appState.name)path='/login';
  history[replace?'replaceState':'pushState']({},'',path);
  return renderRoute();
}
function visible(node,show){node.classList.toggle('hidden',!show);}
let routeRevision = 0;
async function renderRoute(){
  const revision = ++routeRevision;
  if (appState.route === '/chat') workbench.leave();
  const logged=Boolean(appState.name);
  visible($('login-screen'),!logged);visible($('app'),logged);
  if(!logged){
    const registering=location.pathname==='/register';
    if(!registering&&location.pathname!=='/login')history.replaceState({},'','/login');
    const action = registering ? 'Create account' : 'Sign in';
    document.title = `${action} — Osiris`;
    $('auth-submit').textContent = action;
    $('auth-switch').textContent = registering ? 'Back to sign in' : 'Create account';
    $('auth-switch').href = registering ? '/login' : '/register';
    $('auth-password').autocomplete = registering ? 'new-password' : 'current-password';
    if (registering) {
      $('auth-name').pattern = String.raw`[A-Za-z0-9_\-]{1,100}`;
      $('auth-name').title = 'Use letters, digits, underscores or hyphens.';
    } else {
      $('auth-name').removeAttribute('pattern');
      $('auth-name').removeAttribute('title');
    }
    return;
  }
  document.title='Osiris — workspace';
  const path=getPath();if(path!==location.pathname)history.replaceState({},'',path);
  appState.route=path;
  $('username').textContent=appState.name;$('avatar').textContent=appState.name[0].toUpperCase();$('greeting').textContent=`Welcome back, ${appState.name}`;
  for(const [p,[id]] of Object.entries(views))visible($(id+'-view'),p===path);
  document.querySelectorAll('[data-route]').forEach(b=>b.classList.toggle('active',b.dataset.route===path));$('page-label').textContent=views[path][1];
  try{
    await refreshData();if(revision!==routeRevision)return;
    if(path==='/dashboard')renderDashboard();
    if(path==='/workspace')await loadSessions();
    if(path==='/chat')await workbench.enter();
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
  appState.nextSessions = rel(sessions,'next','GET');
  $('api-state').textContent='API connected';$('api-dot').classList.remove('off');
  const writable = writableProjects();
  const available = {
    'new-chat': rel(root, 'createSession', 'POST'),
    'new-project': rel(root, 'createProject', 'POST'),
    'new-session': writable.length > 0,
  };
  for (const button of document.querySelectorAll('[data-action]')) visible(button, available[button.dataset.action]);
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
function openChat(session) {
  return go('/chat?thread=' + encodeURIComponent(session.id));
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

// Management uses item and collection affordances; no role checks or writable URLs live here.
let manager=null;
function managePage(link,kind,label,parent=null){manager={link,kind,label,parent};return renderManage();}
function labelFor(item){return item.name||item.title||item.path||item.username||item.userId||item.id||'Record';}
function section(root,title,body){const node=el('section','resource-card');node.append(el('h3','chat-heading',title));if(body)node.append(el('p','item-meta',body));root.append(node);return node;}
function formAction(root,label,fields,submit,initial={},inline=false){
  const panel=el(inline?'section':'details','resource-card'),form=el('form','fields');
  panel.append(inline?el('h3','chat-heading',label):el('summary','',label),form);root.append(panel);
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
    try{await submit(payload);if(!inline)panel.open=false;notify(label+' saved.');}
    catch(err){error.textContent=err.message;}finally{save.disabled=false;for(const input of Object.values(controls))if(input.type==='password')input.value='';}
  };
  return controls;
}
function modelChoice(){
  const ai=appState.runtime?.ai;if(!ai)return null;
  let pref;try{pref=JSON.parse(localStorage.getItem('osiris:model:'+appState.userId));}catch{}
  if(pref&&ai.providers.some(p=>p.id===pref.provider)&&validChatModel(pref.provider,pref.model))return {provider:pref.provider,model:pref.model};
  const provider=ai.providers.find(p=>p.id===ai.defaultProvider)||ai.providers[0];
  return provider?{provider:provider.id,model:provider.defaultModel}:null;
}
function openProviderSettings(){
  manager={link:rel(appState.root,'runtime','GET'),kind:'settings',label:'Model settings & credentials'};
  return go('/manage');
}
function connectProvider(node, link, refresh) {
  return showProviderLogin(node,link,{el,follow,rel,refresh,notify});
}
function renderSettings(body,runtime){
  appState.runtime=runtime;const providers=runtime.ai.providers,choice=modelChoice();
  const methods=rel(runtime.ai,'loginMethods','GET');
  if(methods){
    const node=section(body,'Connect a provider','Choose how to sign in to your provider.');
    void connectProvider(node,methods,renderManage);
  }
  if(choice){
    const node=section(body,'Chat model',`Default for new threads: ${choice.model?choice.provider+' / '+choice.model:'No model selected'}. This preference is saved in this browser.`);
    const inputs=formAction(node,'Save model',[['provider','Provider',providers.map(p=>p.id)],['model','Model']],prefs=>{
      if(!validChatModel(prefs.provider,prefs.model))throw Error('Choose a valid model for this provider. Use /model in chat to see the list.');
      localStorage.setItem('osiris:model:'+appState.userId,JSON.stringify(prefs));return renderManage();
    },choice);
    inputs.provider.onchange=()=>{inputs.model.value=providers.find(p=>p.id===inputs.provider.value).defaultModel;};
  }
  for(const p of providers){
    const node=section(body,p.label,`${p.authType==='login'?'Signed in':p.configured?'Configured':'Not configured'}. Connectivity is checked when sending a message.`);
    const set=rel(p,'setCredential','PUT'),del=rel(p,'deleteCredential','DELETE');
    if(set)formAction(node,'Set key',[['apiKey','Provider API key','password']],async payload=>{await follow(set,payload);await renderManage();});
    if(del)node.append(button(p.authType==='login'?'Disconnect provider':'Remove key',async()=>{if(confirm(`Remove your ${p.label} credential from Osiris? This does not cancel a subscription.`)){await follow(del);await renderManage();}}));
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
  const action=(link,label,fields,initial={},map=x=>x,inline=false)=>{
    if(link)formAction(root,label,fields,async payload=>{await follow(link,map(payload),versionHeader(item));await renderManage();},initial,inline);
  };
  for(const [relName,child,label] of childPages){
    const link=rel(item,relName,'GET');
    if(link)nav.append(button(label,()=>managePage(link,child,label+' · '+labelFor(item),manager)));
  }
  if(kind==='session'&&rel(item,'self','GET')&&!collection)nav.append(button('Open conversation',()=>openChat(item)));
  for(const [type,[name,label,fields]] of Object.entries(createActions)){
    const link=rel(item,name,'POST')||(collection&&kind===type?rel(item,'create','POST'):null);
    action(link,label,fields,{},payload=>type==='message'?{role:'user',...payload}:payload,type==='project');
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
const passwordEncoder = new TextEncoder();
function updatePasswordChecks() {
  const input = $('auth-password');
  const bytes = passwordEncoder.encode(input.value).byteLength;
  $('password-rules').hidden = bytes === 0;
  $('password-min').checked = bytes >= 12;
  $('password-max').checked = bytes <= 72;
  if (bytes) input.setAttribute('aria-describedby', 'password-rules');
  else input.removeAttribute('aria-describedby');
  return bytes >= 12 && bytes <= 72;
}

async function submitAuth(event, landing) {
  event.preventDefault();
  const registering = location.pathname === '/register';
  const name = $('auth-name'), password = $('auth-password'), error = $('auth-error');
  error.textContent = '';
  if (registering && !updatePasswordChecks()) {
    password.focus();
    return;
  }
  if (registering && password.value.includes('\0')) {
    error.textContent = 'Remove the null character from your password.';
    password.focus();
    return;
  }
  const submit = $('auth-submit');
  if (submit.disabled) return;
  submit.disabled = true;
  try {
    const credentials = {username: registering ? name.value : name.value.trim(), password: password.value};
    signedIn(await request('POST', registering ? '/auth/register' : '/auth/login', credentials));
    $('auth-form').reset();
    updatePasswordChecks();
    await go(registering ? '/dashboard' : landing, true);
  } catch (err) {
    if (!navigatingToError) error.textContent = err.message;
  } finally {
    submit.disabled = false;
  }
}
async function init(){
  for(const b of document.querySelectorAll('[data-action]'))visible(b,false);
  visible($('chat-form'),false);
  bind('delete-session','click',workbench.remove);
  bind('rename-thread','click',workbench.rename);
  initCommandMenu(() => !appState.name ? [] : [
    ...workbench.commands(),
    ...Object.entries(views).map(([path, [, label]]) => ({label: `Open ${label}`, run: () => go(path)})),
    {label:'Log out', run:() => $('logout').click()},
  ], notify);
  bind('checks-refresh','click',async()=>{await refreshData();notify('API check data refreshed.');});
  for(const b of document.querySelectorAll('[data-bs-dismiss="modal"]'))b.onclick=()=>hideModal(b.closest('.modal').id);
  for(const b of document.querySelectorAll('[data-route]'))b.onclick=()=>go(b.dataset.route);
  for(const b of document.querySelectorAll('[data-action="new-chat"]'))b.onclick=()=>workbench.create().catch(error=>notify(error.message));
  for(const b of document.querySelectorAll('[data-action="new-project"]'))b.onclick=()=>showModal('project-modal');
  for(const b of document.querySelectorAll('[data-action="new-session"]'))b.onclick=openNewSession;
  const landing = views[location.pathname] ? location.pathname + location.search : '/dashboard';
  $('auth-password').addEventListener('input', updatePasswordChecks);
  bind('auth-form','submit',event => submitAuth(event, landing));
  bind('logout','click',async()=>{await follow(rel(appState.root,'logout','POST'));workbench.forgetTab();location.replace('/login');});
  bind('filter-form','submit',e=>{e.preventDefault();return loadSessions();});
  for(const [id,key] of [['previous-page','previous'],['next-page','next']])bind(id,'click',()=>loadSessions(appState.links[key]));
  bind('chat-form','submit',workbench.send);
  bind('session-form','submit',createSession);bind('project-form','submit',createProject);bind('archive-session','click',workbench.archive);bind('refresh-sessions','click',renderRoute);
  window.addEventListener('popstate',renderRoute);
  window.addEventListener('pageshow',e=>{if(e.persisted)location.reload();});
  try{signedIn(await request('GET','/auth/me'));}catch(e){
    if (navigatingToError) return;
    if(e.status!==401)$('auth-error').textContent=e.message;
  }
  await renderRoute();
}
const apiChecks = createApiChecks({ appState, rel, queryLink, fetchResponse, refreshData, addErrorLink });
const workbench = createChatWorkbench({state:appState, follow, rel, queryLink, go, projectName, modelChoice, notify, button, openProviderSettings, connectProvider});
init().catch(e=>notify(e.message));
