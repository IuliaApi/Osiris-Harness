const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
// Real Chromium and real workspace assets. All API traffic terminates at this
// loopback fixture; the profile, models, CSRF token and replies are synthetic.
const root = path.join(__dirname, '..', 'web');
const link = (href, method = 'GET') => ({href, method});
const nativeModel = 'family/'.padEnd(240, 'm');
const code = '<script>notExecutable()</script>\n';
const reply = 'Example:\n```html\n' + code + '```\nEnd.';
const providers = [
  {id:'openai-codex', label:'OpenAI Codex', configured:true, authType:'login', defaultModel:''},
  {id:'openai', label:'OpenAI', configured:false, defaultModel:'gpt-test'},
];
const sessions = ['one', 'two', 'archived'].map(id => ({
  id, title:`Thread ${id}`, projectId:null, status:id === 'archived' ? 'archived' : 'active',
  _links:{self:link(`/sessions/${id}`), agent:link(`/sessions/${id}/agent`),
    ...(id === 'archived' ? {} : {startAgent:link(`/sessions/${id}/agent`, 'POST')})},
}));
const writes = [], messages = [];
let run = null, failRead = false, heldDetail = null;
const gate = () => {
  let resolve;
  const promise = new Promise(r => { resolve = r; });
  return {promise, resolve};
};
const startGate = gate(), stepGate = gate();
const server = http.createServer(async (req, res) => {
  const {pathname} = new URL(req.url, 'http://127.0.0.1');
  const json = (value, status = 200) => {
    res.writeHead(status, {'Content-Type':'application/json', 'Cache-Control':'no-store',
      ...(status >= 400 ? {'X-Error-Page':`/errors/${status}/${value.code || 'request_failed'}`} : {})});
    res.end(JSON.stringify(value));
  };
  if (req.method !== 'GET') {
    let raw = '';
    for await (const chunk of req) raw += chunk;
    const body = JSON.parse(raw);
    writes.push({pathname, method:req.method, body, csrf:req.headers['x-csrf-token']});
    if (req.method === 'POST' && pathname === '/sessions/one/agent') {
      await startGate.promise;
      run = {id:'turn', sessionId:'one', prompt:body.message, model:body.model, provider:body.provider,
        status:'ready', events:[], _links:{step:link('/turn/step', 'POST')}};
      return json(run, 201);
    }
    if (req.method === 'POST' && pathname === '/turn/step') {
      await stepGate.promise;
      messages.push({id:'u', role:'user', content:run.prompt}, {id:'a', role:'assistant', content:reply});
      run = {...run, status:'completed', _links:{}};
      return json({error:'Upstream response lost after saving', code:'ai_upstream_unavailable'}, 503);
    }
    return json({error:'Unexpected write'}, 405);
  }
  if (pathname === '/auth/me') return json({user:{id:'fixture', username:'Tester'}, csrfToken:'fixture-csrf'});
  if (pathname === '/api') return json({_links:{
    projects:link('/projects'), sessions:link('/sessions'), runtime:link('/api/runtime'), models:link('/api/models'),
  }});
  if (pathname === '/projects') return json({items:[], page:{total:0}});
  if (pathname === '/sessions') return json({items:sessions, page:{total:sessions.length}});
  if (pathname === '/api/runtime') return json({ai:{defaultProvider:'openai-codex', providers}});
  if (pathname === '/api/models') return json({providers:providers.map(p => ({
    id:p.id, label:p.label, status:p.configured ? 'available' : 'not_configured', models:p.configured ? [nativeModel] : [],
  }))});
  for (const s of sessions) {
    if (pathname === s._links.agent.href) return json({run:s.id === 'one' ? run : null});
    if (pathname !== s._links.self.href) continue;
    if (s.id === 'two' && heldDetail) {
      const hold = heldDetail;
      heldDetail = null;
      hold.started.resolve();
      await hold.release.promise;
    }
    if (s.id === 'one' && failRead) { failRead = false; return json({error:'Read temporarily unavailable', code:'internal_error'}, 500); }
    return json({...s, messages:s.id === 'one' ? messages : [], messagesPage:{hasMore:false, limit:25}});
  }
  if (pathname.startsWith('/errors/')) {
    res.writeHead(Number(pathname.split('/')[2]), {'Content-Type':'text/plain'});
    return res.end('Synthetic API error');
  }
  let asset;
  if (['/chat','/manage'].includes(pathname)) asset = 'index.html';
  else if (/^\/assets\/[a-z-]+\.(js|css)$/.test(pathname)) asset = path.basename(pathname);
  if (!asset || !fs.existsSync(path.join(root, asset))) return json({error:'Not found'}, 404);
  const type = {'.js':'text/javascript', '.css':'text/css', '.html':'text/html'}[path.extname(asset)];
  res.writeHead(200, {'Content-Type':type + '; charset=utf-8'});
  res.end(fs.readFileSync(path.join(root, asset)));
});

const delay = ms => new Promise(r => setTimeout(r, ms));
let chrome, socket, profile;
async function main() {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = 'http://127.0.0.1:' + server.address().port;
  profile = fs.mkdtempSync(path.join(os.tmpdir(), 'osiris-chat-ui-'));
  const binary = process.env.CHROME_PATH || ({
    win32:['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
      path.join(process.env['ProgramFiles(x86)'] || 'C:\\Program Files (x86)', 'Microsoft/Edge/Application/msedge.exe')].find(fs.existsSync),
    darwin:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  }[process.platform] || 'chromium');
  chrome = spawn(binary, ['--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
    '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'],
    {windowsHide:true, stdio:'ignore'});
  let launchError;
  chrome.on('error', err => { launchError = err; });
  const portFile = path.join(profile, 'DevToolsActivePort');
  for (let i = 0; !fs.existsSync(portFile); ++i) {
    if (launchError) throw launchError;
    // Some packaged browsers relaunch themselves before publishing the port.
    assert(i < 200, 'Headless Chromium did not start; set CHROME_PATH to its executable');
    await delay(100);
  }
  const port = fs.readFileSync(portFile, 'utf8').split(/\r?\n/)[0];
  const tabs = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
  socket = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  let sequence = 0;
  const pending = new Map();
  socket.onmessage = e => {
    const {id, error, result} = JSON.parse(e.data);
    if (!pending.has(id)) return;
    const {resolve, reject} = pending.get(id);
    pending.delete(id);
    if (error) reject(Error(error.message)); else resolve(result);
  };
  function cdp(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++sequence;
      pending.set(id, {resolve, reject});
      socket.send(JSON.stringify({id, method, params}));
    });
  }
  async function page(fn, ...args) {
    const expression = `(${fn})(${args.map(v => JSON.stringify(v)).join(',')})`;
    const {result, exceptionDetails} = await cdp('Runtime.evaluate', {expression, awaitPromise:true, returnByValue:true, userGesture:true});
    if (exceptionDetails) throw Error(exceptionDetails.exception?.description || exceptionDetails.text);
    return result.value;
  }
  async function until(label, fn, ...args) {
    for (let i = 0; i < 100; ++i) {
      if (await page(fn, ...args)) return;
      await delay(40);
    }
    throw Error(`Timed out: ${label} (${await page(() => location.pathname)})`);
  }
  const click = async selector => {
    await until('actionable ' + selector, s => {
      if (document.readyState !== 'complete') return false;
      const node = document.querySelector(s);
      if (!node || node.disabled) return false;
      node.scrollIntoView({block:'center'});
      const r = node.getBoundingClientRect();
      return node.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
    }, selector);
    const point = await page(s => {
      const node = document.querySelector(s);
      node.scrollIntoView({block:'center'});
      const r = node.getBoundingClientRect();
      return {x:r.left + r.width / 2, y:r.top + r.height / 2};
    }, selector);
    for (const type of ['mousePressed', 'mouseReleased']) await cdp('Input.dispatchMouseEvent', {type, ...point, button:'left', clickCount:1});
  };
  const fill = async text => {
    await page(() => { const e = document.querySelector('#message-text'); e.focus(); e.select(); });
    await cdp('Input.insertText', {text});
  };
  async function command(text) {
    await fill(text);
    for (const type of ['keyDown', 'keyUp']) await cdp('Input.dispatchKeyEvent', {type, key:'Enter', code:'Enter', windowsVirtualKeyCode:13});
  }
  const select = id => page(name => [...document.querySelectorAll('.chat-session')].find(b => b.firstChild.textContent === `Thread ${name}`).click(), id);
  const opened = id => until('open ' + id, name => document.querySelector('#chat-title')?.textContent === `Thread ${name}`, id);
  const input = () => document.querySelector('#message-text').value;
  const modelIs = name => document.querySelector('#thread-model').textContent === `Model: openai-codex/${name}`;

  await cdp('Browser.grantPermissions', {origin, permissions:['clipboardReadWrite', 'clipboardSanitizedWrite']});
  await cdp('Page.navigate', {url:origin + '/chat'});
  await opened('one');
  await until('run restored', () => document.querySelector('#agent-controls').hidden);
  assert.equal(await page(() => document.querySelector('#thread-model').textContent), 'No model selected');
  assert(await page(() => document.querySelector('#send-message').disabled));
  assert.equal(await page(() => document.querySelectorAll('.starter-grid button').length), 3);
  await click('.starter-grid button');
  assert.match(await page(input), /^Help me debug/);
  const shortHeight = await page(() => document.querySelector('#message-text').offsetHeight);
  const draft = Array.from({length:16}, (_, i) => 'Draft line ' + i).join('\n');
  await fill(draft);
  assert(await page(h => document.querySelector('#message-text').offsetHeight > h, shortHeight));
  assert.match(await page(() => document.querySelector('#message-count').textContent), new RegExp('^' + draft.length + ' / '));
  await select('two'); await opened('two'); await fill('Another draft');
  await select('one'); await opened('one'); assert.equal(await page(input), draft);

  const hold = heldDetail = {started:gate(), release:gate()};
  await select('two'); await hold.started.promise;
  await select('archived'); await opened('archived');
  hold.release.resolve();
  await until('late response finished', () => performance.getEntriesByType('resource').filter(e => e.name.includes('/sessions/two?')).length >= 2);
  assert.equal(await page(() => document.querySelector('#chat-title').textContent), 'Thread archived');
  assert.equal(await page(() => document.querySelectorAll('.starter-grid button').length), 0);
  await fill('Read only'); assert(await page(() => document.querySelector('#send-message').disabled));
  failRead = true;
  await command('/resume one');
  await until('read failure navigation', () => location.pathname === '/errors/500/internal_error');
  await page(() => history.back()); await opened('one'); assert.equal(await page(input), draft);
  await fill('/mo');
  assert.equal(await page(() => document.querySelector('#slash-suggestions [aria-selected=true]').dataset.command), '/model');
  await command('/model');
  await until('native picker option', () => !!document.querySelector('#picker-list [data-provider="openai-codex"]'));
  await click('#picker-list [data-provider="openai-codex"]'); await until('picked native ID', modelIs, nativeModel);
  await command('/model openai/gpt-test');
  assert.match(await page(() => document.querySelector('#provider-access').textContent), /Set up OpenAI access/);
  await fill('Cannot send'); assert(await page(() => document.querySelector('#send-message').disabled));
  await command('/model openai-codex/' + nativeModel + 'x');
  assert.match(await page(() => document.querySelector('#toast').textContent), /valid model/);
  await command('/model openai-codex/' + nativeModel); await until('typed native ID', modelIs, nativeModel);
  assert.equal(writes.length, 0, 'Navigation, reads, starters and model selection must not write');
  const oldOrigin = await page(() => performance.timeOrigin);
  await cdp('Page.reload'); await until('reload', old => performance.timeOrigin !== old, oldOrigin);
  await opened('one'); await until('restored native ID', modelIs, nativeModel);
  await until('restored run', () => document.querySelector('#agent-controls').hidden);
  assert.equal(await page(() => document.querySelectorAll('#chat-form select, #chat-form input').length), 0);

  await command('Example please');
  await until('start pending', () => document.querySelector('#agent-state').textContent.includes('Starting'));
  await fill('Keep this next draft');
  startGate.resolve();
  await until('thinking', () => !document.querySelector('#thinking-dots').hidden && !document.querySelector('#agent-clock').hidden);
  await cdp('Emulation.setEmulatedMedia', {features:[{name:'prefers-reduced-motion', value:'reduce'}]});
  assert.equal(await page(() => getComputedStyle(document.querySelector('#thinking-dots i')).animationName), 'none');
  stepGate.resolve();
  await until('ambiguous step failure navigation', () => location.pathname === '/errors/503/ai_upstream_unavailable');
  await page(() => history.back()); await opened('one');
  await until('saved reply', () => !!document.querySelector('#chat-history .assistant pre code'));
  assert.equal(await page(input), 'Keep this next draft');
  assert.equal(await page(() => document.querySelector('#chat-history pre code').textContent), code);
  assert.equal(await page(() => document.querySelectorAll('#chat-history script, #chat-history .user + .user').length), 0);
  assert.equal(await page(() => getComputedStyle(document.querySelector('.message.assistant')).animationName), 'none');
  await click('.message-copy');
  await until('copy reply confirmed', () => document.querySelector('#toast').textContent === 'Model reply copied.');
  const copied = async expected => (await navigator.clipboard.readText()).replace(/\r\n/g, '\n') === expected;
  await until('copy entire reply', copied, reply);
  await click('.thread-code button');
  await until('copy code confirmed', () => document.querySelector('#toast').textContent === 'Code copied.');
  await until('copy fenced code', copied, code);
  assert.deepEqual(writes, [
    {pathname:'/sessions/one/agent', method:'POST', body:{message:'Example please', provider:'openai-codex', model:nativeModel, tools:true}, csrf:'fixture-csrf'},
    {pathname:'/turn/step', method:'POST', body:{}, csrf:'fixture-csrf'},
  ], 'Refreshing must not repeat an uncertain step');
  await cdp('Emulation.setDeviceMetricsOverride', {width:390, height:844, deviceScaleFactor:1, mobile:true});
  assert(await page(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), 'Mobile chat overflows');
  if (process.env.UI_SCREENSHOT) {
    const shot = await cdp('Page.captureScreenshot', {format:'png'});
    fs.writeFileSync(process.env.UI_SCREENSHOT, Buffer.from(shot.data, 'base64'));
  }
  await click('#chat-settings');
  await until('provider settings', () => location.pathname === '/manage' && document.querySelector('#manage-body h2')?.textContent === 'Model settings & credentials');
  await page(() => history.back()); await opened('one');
  assert.equal(await page(input), 'Keep this next draft');
  assert.equal(writes.length, 2, 'Reopening a completed run must remain read-only');
  console.log('PASS: terminal chat, drafts, model selection, recovery without replay, copying, mobile layout and provider settings');
  // Optional origin supplied by test-http-no-db.py --browser: the actual PHP
  // front controller with no account cookies or database credentials required.
  const api = process.argv[2];
  if (api) {
    assert(/^http:\/\/127\.0\.0\.1:[0-9]+$/.test(api), 'Expected the disposable loopback PHP service');
    await cdp('Page.navigate', {url:api + '/auth/me'});
    await until('direct API recovery', () => document.querySelector('h1')?.textContent === 'Sign in required');
    assert.equal(await page(() => document.querySelector('link[rel=canonical]').getAttribute('href')), '/errors/401/unauthorized');
    await click('a[href="/login"]');
    await until('sign-in recovery', () => location.pathname === '/login' && document.querySelector('#login-screen')?.classList.contains('hidden') === false);
    if (process.argv[3] === '--disposable-auth') {
      // Opt in only for a disposable PostgreSQL workspace. Both account submissions
      // go through the same real HTML form; no cookies or responses are injected.
      const username = 'Recovery_' + require('node:crypto').randomUUID();
      const secret = require('node:crypto').randomUUID();
      const submitRegistration = async () => {
        await page(() => document.querySelector('#auth-name').focus());
        await cdp('Input.insertText', {text:username});
        await page(() => document.querySelector('#auth-password').focus());
        await cdp('Input.insertText', {text:secret});
        await click('#auth-submit');
      };
      await click('#auth-switch');
      await until('registration form', () => document.querySelector('#auth-submit')?.textContent === 'Create account');
      await submitRegistration();
      await until('signed-in dashboard', () => location.pathname === '/dashboard' && document.querySelector('#api-state')?.textContent === 'API connected');
      await click('#logout');
      await until('signed out', () => location.pathname === '/login' && !document.querySelector('#login-screen')?.classList.contains('hidden'));
      await click('#auth-switch');
      await until('second registration form', () => document.querySelector('#auth-submit')?.textContent === 'Create account');
      await submitRegistration();
      await until('duplicate registration popup', () => location.pathname === '/register' && document.querySelector('#username-taken')?.open);
      await click('#username-taken a[href="/login"]');
      await until('sign in after conflict', () => location.pathname === '/login' && document.querySelector('#auth-submit')?.textContent === 'Sign in');
      assert.equal(await page(() => document.querySelector('#auth-password').value.length), 0);
      console.log('PASS: a duplicate registration leaves the user signed out and offers sign-in from the popup');
    } else {
      // Oversized login is rejected by the real HTTP parser before any DB access.
      await page(() => document.querySelector('#auth-name').focus());
      await cdp('Input.insertText', {text:'SyntheticLogin'});
      await page(() => document.querySelector('#auth-password').focus());
      await cdp('Input.insertText', {text:'x'.repeat(65536)});
      await click('#auth-submit');
      await until('failed form navigation', () => location.pathname === '/errors/413/payload_too_large');
      await click('a[href="/login"]');
      await until('sign-in reopens without replay', () => location.pathname === '/login' && document.querySelector('#auth-submit')?.textContent === 'Sign in');
    }
    await cdp('Page.navigate', {url:api + '/errors/422/login_input_invalid'});
    await until('provider error details', () => document.querySelector('h1')?.textContent === 'Check the sign-in response');
    await click('a[href="/manage"]');
    await until('protected Manage prompts for sign-in', () => location.pathname === '/login');
    console.log('PASS: direct PHP API errors, canonical browser recovery pages and safe sign-in navigation');
  }
}
let deadline;
const timeout = new Promise((_, reject) => { deadline = setTimeout(() => reject(Error('Chromium smoke test timed out')), 60000); });
Promise.race([main(), timeout]).catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  clearTimeout(deadline);
  startGate.resolve(); stepGate.resolve();
  if (socket?.readyState === WebSocket.OPEN) {
    const closed = new Promise(resolve => socket.addEventListener('close', resolve, {once:true}));
    socket.send(JSON.stringify({id:0, method:'Browser.close'}));
    await Promise.race([closed, delay(2000)]);
    socket.close();
  }
  if (chrome?.pid && chrome.exitCode === null && chrome.signalCode === null) {
    const exited = new Promise(resolve => chrome.once('exit', resolve));
    chrome.kill(); await exited;
  }
  server.closeAllConnections(); server.close();
  if (profile) {
    // On Windows the launcher can exit before its subprocesses release locks.
    await fs.promises.rm(profile, {recursive:true, force:true, maxRetries:12, retryDelay:250});
  }
});
