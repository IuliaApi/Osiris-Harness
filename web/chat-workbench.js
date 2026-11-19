import { createMessageRenderer } from './thread-messages.js';
import { createAgentConsole } from './agent-console.js';
import { copyText, createTerminalPrompt } from './terminal-prompt.js';
import { createDesktopPanel } from './desktop-panel.js';
import { createSideQuestions } from './side-questions.js';
import { validChatModel } from './model-choice.js';

const byId = id => document.getElementById(id);
const threadUrl = id => `/chat?thread=${encodeURIComponent(id)}`;
const starters = [
  ['Debug a problem', 'Help me debug this issue. Here is what I expected and what happened: '],
  ['Explain some code', 'Explain what this code does, step by step: '],
  ['Plan a change', 'Help me break this feature into small implementation steps: '],
];
const modes = new Map([
  ['ask', ['Ask', 'Ask before every write.']],
  ['allow', ['Allow', 'Create new files automatically. Ask before replacing existing files.']],
  ['yolo', ['YOLO', 'Apply valid file writes and edits without asking.']],
]);

export function createChatWorkbench({state, follow, rel, queryLink, go, projectName, modelChoice, openProviderSettings, connectProvider, notify, button}) {
  const {messageNode} = createMessageRenderer({notify});
  const editor = byId('message-text'), permissions = byId('thread-permissions');
  const picker = byId('thread-picker'), pickerList = byId('picker-list'), pickerSearch = byId('picker-search');
  const pickerStatus = byId('picker-status');
  const rail = byId('chat-session-list'), search = byId('thread-search'), historyBox = byId('chat-history');
  const viewport = byId('terminal-scroll'), hint = byId('chat-hint');
  const scroll = () => { viewport.scrollTop = viewport.scrollHeight; };
  let thread = null, threadRevision = 0, listRevision = 0, searchTimer;
  let listed = [], nextPage = null, creating = false;
  let tools = true, commandBusy = false;
  let choice = null, mode = null, permissionBusy = false, permissionError = '';
  let pickerView = null;
  const agent = createAgentConsole({follow, rel, changed:composerStatus, reloadHistory, scroll});
  const desktop = createDesktopPanel();
  const sides = createSideQuestions({follow, rel, getChoice:requireModel});
  const tabPrefix = () => `osiris:threads:${state.userId}:`;
  const draftKey = id => `${tabPrefix()}draft:${id}`;
  const modelKey = id => `${tabPrefix()}model:${id}`;
  const toolsKey = id => `${tabPrefix()}tools:${id}`;
  // If the browser denies storage or has reached its quota, navigation must
  // still preserve unfinished work for the lifetime of this page.
  const unsaved = new Map();
  function readTab(key) {
    if (unsaved.has(key)) return unsaved.get(key);
    try { return sessionStorage.getItem(key); } catch { return null; }
  }
  function writeTab(key, value) {
    try {
      if (value === null) sessionStorage.removeItem(key);
      else sessionStorage.setItem(key, value);
      unsaved.delete(key);
    } catch { unsaved.set(key, value); }
  }
  const getDraft = id => readTab(draftKey(id)) || '';
  const setDraft = (id, text) => writeTab(draftKey(id), text || null);
  function forgetTab() {
    const prefix = tabPrefix();
    unsaved.clear();
    try {
      for (const key of Object.keys(sessionStorage)) if (key.startsWith(prefix)) sessionStorage.removeItem(key);
    } catch { /* Storage can be blocked. Sign-out must still finish. */ }
  }
  function clearThread() {
    ++threadRevision;
    thread = null;
    commandBusy = false;
    agent.setThread(null);
    prompt.reset(null);
    desktop.reset();
    sides.reset();
    setModelChoice(null);
    mode = null;
    permissionBusy = false;
    permissionError = '';
    permissions.hidden = true;
    if (picker.open) picker.close();
    pickerView = null;
    editor.value = '';
    for (const id of ['chat-form', 'archive-session', 'delete-session', 'rename-thread']) {
      byId(id).classList.add('hidden');
    }
    byId('chat-title').textContent = 'Threads';
    byId('chat-project').textContent = '';
    byId('chat-status').textContent = '—';
    historyBox.replaceChildren();
  }
  function leave() {
    clearTimeout(searchTimer);
    ++listRevision;
    clearThread();
  }
  function composerStatus() {
    const writable = !!rel(thread, 'startAgent', 'POST');
    const slash = editor.value.trimStart().startsWith('/');
    const send = byId('send-message');
    send.disabled = commandBusy || !editor.value.trim() || (!slash && (!writable || agent.blocked() || !!modelProblem()));
    send.setAttribute('aria-label', writable && !slash ? 'Send message' : 'Run command');
    editor.disabled = false;
    byId('tools-indicator').textContent = thread ? `tools ${tools ? 'on' : 'off'}` : '';
    hint.textContent = writable ? 'Enter runs · Shift+Enter adds a line · / for commands' :
      `${thread ? 'Read-only thread' : 'Commands only'} · /resume to open a thread · / for commands`;
  }
  function syncComposer() {
    editor.style.height = 'auto';
    editor.style.height = `${editor.scrollHeight}px`;
    composerStatus();
  }
  window.addEventListener('resize', () => { if (state.route === '/chat') syncComposer(); });
  function selectedProvider() { return state.runtime?.ai?.providers.find(p => p.id === choice?.provider); }
  function modelProblem() {
    const provider = selectedProvider();
    if (provider && !provider.configured) {
      if (provider.id === 'ollama') return 'Ollama is disabled.';
      return `Set up ${provider.label} access in Provider settings.`;
    }
    return validChatModel(choice?.provider, choice?.model) && provider ? '' : 'Choose a model with /model.';
  }
  function requireModel() {
    const problem = modelProblem();
    if (problem) throw new Error(problem);
    return choice;
  }
  byId('chat-settings').addEventListener('click', async () => {
    try { await openProviderSettings(); } catch (error) { notify(error.message); }
  });
  function setModelChoice(value) {
    choice = value;
    const indicator = byId('thread-model');
    indicator.hidden = !thread;
    indicator.textContent = validChatModel(value?.provider, value?.model) ? `Model: ${value.provider}/${value.model}` : 'No model selected';
    const access = byId('provider-access');
    access.textContent = thread ? modelProblem() || (
      value.provider === 'ollama' ? 'Local service · checked on send' :
      selectedProvider()?.authType === 'login' ? 'Provider login connected' : 'Your API key is saved'
    ) : '';
    composerStatus();
  }
  function restoreComposer() {
    const options = state.runtime.ai.providers;
    let restored = modelChoice();
    try {
      const saved = JSON.parse(localStorage.getItem(modelKey(thread.id)));
      if (saved && options.some(item => item.id === saved.provider) && validChatModel(saved.provider, saved.model)) restored = saved;
    } catch { /* An invalid browser preference must not prevent opening the thread. */ }
    setModelChoice(restored);
    editor.value = getDraft(thread.id);
    try { tools = localStorage.getItem(toolsKey(thread.id)) !== 'off'; } catch { tools = true; }
    prompt.reset(`${tabPrefix()}history:${thread.id}`);
    syncComposer();
  }
  function saveModel(provider, model) {
    if (!validChatModel(provider, model)) throw new Error('Choose a valid model identifier.');
    setModelChoice({provider, model});
    try { localStorage.setItem(modelKey(thread.id), JSON.stringify(choice)); }
    catch { notify('Model changed for this view; browser storage is unavailable.'); }
  }
  editor.addEventListener('input', () => {
    if (thread) setDraft(thread.id, editor.value);
    syncComposer();
  });

  function openPicker(kind, title) {
    const view = {kind, providers:null};
    pickerView = view;
    pickerList.replaceChildren();
    pickerSearch.hidden = kind !== 'models';
    pickerSearch.value = '';
    byId('picker-title').textContent = title;
    pickerStatus.textContent = '';
    if (!picker.open) picker.showModal();
    (pickerSearch.hidden ? byId('picker-close') : pickerSearch).focus();
    return view;
  }
  const showing = view => picker.open && pickerView === view;
  byId('picker-close').addEventListener('click', () => picker.close());
  picker.addEventListener('close', () => { if (!picker.open) pickerList.replaceChildren(); });
  picker.addEventListener('keydown', event => {
    if (pickerView?.kind === 'login') return; // Preserve native form and select keys.
    if (event.defaultPrevented || event.isComposing || event.keyCode === 229 ||
        event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
    const searchFocused = event.target === pickerSearch;
    if (event.key === 'Enter' && searchFocused) {
      event.preventDefault();
      pickerList.querySelector('button:enabled')?.click();
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      const buttons = [...pickerList.querySelectorAll('button:enabled')];
      if (!buttons.length) return;
      event.preventDefault();
      const index = buttons.indexOf(event.target);
      const next = event.key === 'ArrowDown' ? index + 1 : (index < 0 ? buttons.length : index) - 1;
      buttons[(next + buttons.length) % buttons.length].focus();
    }
  });

  const currentModel = () => validChatModel(choice?.provider, choice?.model) ?
    `Current: ${choice.provider}/${choice.model}.` : 'No model selected.';
  function renderModels(view) {
    if (!view.providers || !showing(view)) return;
    const query = pickerSearch.value.trim().toLowerCase();
    pickerList.replaceChildren();
    for (const provider of view.providers) {
      const providerMatches = `${provider.label} ${provider.id}`.toLowerCase().includes(query);
      const models = provider.status === 'available' ? provider.models.filter(name =>
        validChatModel(provider.id, name) && (providerMatches || `${provider.id}/${name}`.toLowerCase().includes(query))) : [];
      if (!providerMatches && !models.length) continue;
      const group = document.createElement('section');
      group.setAttribute('aria-label', provider.label);
      const heading = document.createElement('h3');
      heading.textContent = provider.label;
      group.append(heading);
      let note = '';
      if (provider.status === 'not_configured') note = 'Not configured.';
      else if (provider.status !== 'available') note = 'Catalog unavailable.';
      else if (provider.truncated) note = 'Partial list. Use /model provider/model for an exact model ID.';
      else if (!provider.models.length) note = 'No models reported.';
      if (provider.source === 'catalog' && provider.status === 'available') note += `${note ? ' ' : ''}Model catalog only; access depends on your provider account.`;
      if (note) {
        const info = document.createElement('p');
        info.className = 'hint';
        info.textContent = note;
        group.append(info);
      }
      for (const name of models) {
        const option = button(name, () => {
          if (!showing(view)) return;
          saveModel(provider.id, name);
          picker.close();
          notify(`Model: ${provider.id}/${name}`);
        });
        option.dataset.provider = provider.id;
        option.dataset.model = name;
        option.setAttribute('aria-pressed', String(choice?.provider === provider.id && choice.model === name));
        group.append(option);
      }
      pickerList.append(group);
    }
    const current = currentModel();
    pickerStatus.textContent = pickerList.childElementCount ? current :
      `${current} ${query ? 'No matching models or providers.' : 'No provider catalogs reported.'}`;
  }
  pickerSearch.addEventListener('input', () => { if (pickerView?.kind === 'models') renderModels(pickerView); });
  async function openModels() {
    if (!thread) throw new Error('Open a thread first.');
    const view = openPicker('models', 'Choose model');
    pickerStatus.textContent = `${currentModel()} Loading model catalogs…`;
    try {
      const link = rel(state.root, 'models', 'GET');
      if (!link) throw new Error('Model discovery is not available.');
      const result = await follow(link);
      if (!showing(view)) return;
      if (!Array.isArray(result?.providers) || result.providers.some(p =>
        !p || typeof p.id !== 'string' || typeof p.label !== 'string' ||
        !Array.isArray(p.models) || !p.models.every(name => typeof name === 'string'))) {
        throw new Error('Invalid catalog response.');
      }
      view.providers = result.providers;
      renderModels(view);
    } catch (error) {
      if (showing(view)) pickerStatus.textContent = `${currentModel()} Model catalogs unavailable: ${error.message}`;
    }
  }

  function renderPermissions() {
    permissions.textContent = 'Permissions: ' + (mode ? modes.get(mode)[0] : permissionBusy ? 'Loading…' : 'Unavailable');
    const view = pickerView;
    if (view?.kind !== 'permissions' || !showing(view)) return;
    pickerList.replaceChildren();
    const writable = !!rel(thread, 'setPermissions', 'PUT');
    pickerStatus.textContent = permissionError || (permissionBusy ? 'Checking thread permissions…' :
      `Current: ${modes.get(mode)?.[0] ?? 'unavailable'}. File reads are allowed in every mode.${writable ? '' : ' These permissions are read-only.'}`);
    if (permissionError) {
      pickerList.append(button('Reload permissions', () => loadPermissions()));
      return;
    }
    for (const [value, [label, description]] of modes) {
      const option = button(label, () => { if (showing(view)) return loadPermissions(value); });
      option.dataset.mode = value;
      option.setAttribute('aria-pressed', String(mode === value));
      option.disabled = permissionBusy || !mode || !writable;
      const info = document.createElement('small');
      info.id = `permission-description-${value}`;
      option.setAttribute('aria-label', label);
      option.setAttribute('aria-describedby', info.id);
      info.textContent = description;
      option.append(info);
      pickerList.append(option);
    }
  }
  async function loadPermissions(nextMode = null) {
    const original = thread, view = pickerView;
    const link = nextMode ? rel(original, 'setPermissions', 'PUT') : rel(original, 'permissions', 'GET');
    if (!link || permissionBusy) return;
    permissionBusy = true;
    permissionError = '';
    renderPermissions();
    try {
      const result = await follow(link, nextMode ? {mode:nextMode} : undefined);
      if (thread !== original) return;
      if (!modes.has(result?.mode)) throw new Error('Unknown permission mode.');
      mode = result.mode;
      if (nextMode && showing(view)) picker.close();
    } catch (error) {
      if (thread === original) {
        mode = null;
        permissionError = `Thread permissions unavailable: ${error.message}`;
      }
    } finally {
      if (thread === original) {
        permissionBusy = false;
        renderPermissions();
      }
    }
  }
  function openPermissions() {
    if (!rel(thread, 'permissions', 'GET')) throw new Error('Thread permissions are not available.');
    openPicker('permissions', 'Thread permissions');
    renderPermissions();
    loadPermissions();
  }
  permissions.addEventListener('click', openPermissions);

  function drawRail() {
    rail.replaceChildren();
    for (const item of listed) {
      const entry = button(item.title, async () => {
        if (thread?.id !== item.id) {
          history.pushState({}, '', threadUrl(item.id));
          await loadThread(item.id);
        }
      });
      const selected = thread?.id === item.id;
      entry.className = 'chat-session' + (selected ? ' selected' : '');
      if (selected) entry.setAttribute('aria-current', 'page');
      const subtitle = document.createElement('small');
      subtitle.className = 'truncate';
      subtitle.textContent = projectName(item.projectId);
      entry.append(subtitle);
      rail.append(entry);
    }
    if (!listed.length) rail.textContent = search.value.trim() ? 'No matching threads.' : 'No threads yet.';
    if (nextPage) rail.append(button('More threads', () => loadList(nextPage)));
  }
  async function loadList(next = null) {
    const version = ++listRevision;
    const q = search.value.trim();
    if (!q && !next) {
      listed = state.sessions;
      nextPage = state.nextSessions;
    } else {
      const result = await follow(next || queryLink(rel(state.root, 'sessions', 'GET'), {q, sort:'updatedAt', order:'desc', limit:50}));
      if (version !== listRevision || state.route !== '/chat') return;
      listed = next ? [...listed, ...result.items] : result.items;
      nextPage = rel(result, 'next', 'GET');
    }
    drawRail();
  }
  search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    ++listRevision;
    searchTimer = setTimeout(() => loadList().catch(error => notify(error.message)), 150);
  });

  function showHistory(detail, revision) {
    historyBox.replaceChildren();
    if (detail.messagesPage.hasMore) {
      const page = detail.messagesPage;
      let earlier = queryLink(rel(detail,'messages','GET'), {sort:'createdAt', order:'desc', limit:page.limit, offset:page.limit});
      const seen = new Set(detail.messages.map(item => item.id));
      const more = button('Load earlier messages', async () => {
        const before = viewport.scrollHeight;
        const response = await follow(earlier);
        if (revision !== threadRevision || !more.isConnected) return;
        const rows = response.items.reverse().filter(item => !seen.has(item.id));
        for (const item of rows) seen.add(item.id);
        more.after(...rows.map(messageNode));
        thread.messages.unshift(...rows);
        agent.historyChanged();
        earlier = rel(response, 'next', 'GET');
        if (!earlier) more.remove();
        viewport.scrollTop += viewport.scrollHeight - before;
      });
      historyBox.append(more);
    }
    if (detail.messages.length) historyBox.append(...detail.messages.map(messageNode));
    else if (rel(detail, 'startAgent', 'POST')) {
      const welcome = document.createElement('section'), title = document.createElement('h3');
      const guide = document.createElement('p'), grid = document.createElement('div');
      welcome.className = 'chat-welcome';
      title.textContent = 'What are we working on?';
      guide.textContent = 'Write a message, pick a starter, or type / for terminal commands.';
      grid.className = 'starter-grid';
      for (const [label, text] of starters) grid.append(button(label, () => {
        if (thread?.id !== detail.id || editor.value) return;
        editor.value = text;
        editor.focus();
        editor.dispatchEvent(new Event('input'));
      }));
      welcome.append(title, guide, grid);
      historyBox.append(welcome);
    } else historyBox.textContent = 'No messages in this read-only thread.';
    scroll();
  }
  async function reloadHistory(session) {
    const revision = threadRevision;
    const detail = await follow(queryLink(rel(session, 'self', 'GET'), {include:'messages'}));
    if (revision !== threadRevision || thread?.id !== detail.id) return;
    thread.messages = detail.messages;
    thread.messagesPage = detail.messagesPage;
    showHistory(detail, revision);
  }
  function commandsOnly(message) {
    historyBox.textContent = message;
    byId('chat-form').classList.remove('hidden');
    byId('chat-form').classList.add('commands-only');
    prompt.reset(`${tabPrefix()}history:landing`);
    syncComposer();
    drawRail();
  }
  async function loadThread(id) {
    clearThread();
    const revision = threadRevision;
    if (!id) return commandsOnly('Open a thread with /resume or start one with /new.');
    if (!/^[A-Za-z0-9_-]{1,100}$/.test(id)) return commandsOnly('Thread unavailable.');
    const known = listed.find(item => item.id === id) || state.sessions.find(item => item.id === id);
    const link = rel(known, 'self', 'GET') || {method:'GET', href:rel(state.root, 'sessions', 'GET').href + '/' + encodeURIComponent(id)};
    historyBox.textContent = 'Loading thread…';
    let detail;
    try { detail = await follow(queryLink(link, {include:'project,messages'})); }
    catch (error) {
      if (revision !== threadRevision) return;
      if (error.status === 404 && readTab(tabPrefix()+'last') === id) writeTab(tabPrefix()+'last', null);
      commandsOnly(error.status === 404 ? 'Thread unavailable.' : error.message);
      if (error.status !== 404) historyBox.append(button('Reload conversation', () => loadThread(id)));
      return;
    }
    if (revision !== threadRevision || state.route !== '/chat') return;
    thread = detail;
    writeTab(tabPrefix()+'last', id);
    byId('chat-title').textContent = detail.title;
    byId('chat-project').textContent = detail.project?.name || projectName(detail.projectId);
    byId('chat-status').textContent = detail.status;
    const writable = rel(detail, 'update', 'PATCH');
    byId('rename-thread').classList.toggle('hidden', !writable);
    byId('archive-session').classList.toggle('hidden', !writable || detail.status !== 'active');
    byId('delete-session').classList.toggle('hidden', !rel(detail, 'delete', 'DELETE'));
    byId('chat-form').classList.remove('hidden');
    byId('chat-form').classList.toggle('commands-only', !rel(detail, 'startAgent', 'POST'));
    permissions.hidden = !rel(detail, 'permissions', 'GET');
    permissions.textContent = 'Permissions: Loading…';
    agent.setThread(detail);
    restoreComposer();
    const permissionLoad = loadPermissions();
    showHistory(detail, revision);
    drawRail();
    await Promise.all([agent.hydrate(), permissionLoad]);
  }
  async function enter() {
    const id = new URLSearchParams(location.search).get('thread') ?? readTab(tabPrefix()+'last') ?? state.sessions[0]?.id;
    if (id && !location.search) history.replaceState({}, '', threadUrl(id));
    await Promise.all([loadThread(id), loadList()]);
  }
  async function create() {
    const link = rel(state.root, 'createSession', 'POST');
    if (!link || creating) return;
    creating = true;
    try {
      const created = await follow(link, {title:'New chat'});
      search.value = '';
      await go(threadUrl(created.id));
      if (thread?.id === created.id) editor.focus();
    } finally { creating = false; }
  }
  async function rename(proposed) {
    const original = thread;
    const link = rel(original, 'update', 'PATCH');
    if (!link) throw new Error('Renaming this thread is not available.');
    const title = (typeof proposed === 'string' ? proposed : window.prompt('Rename thread', original.title))?.trim();
    if (!title || title === original.title) return;
    const updated = await follow(link, {title});
    if (thread !== original) return;
    thread.title = updated.title;
    for (const list of [listed, state.sessions]) {
      const row = list.find(item => item.id === original.id);
      if (row) row.title = updated.title;
    }
    byId('chat-title').textContent = updated.title;
    drawRail();
  }
  async function archive() {
    const original = thread;
    if (!original || !confirm('Archive this thread?')) return;
    await follow(rel(original, 'update', 'PATCH'), {status:'archived'});
    if (thread?.id === original.id) await go(threadUrl(original.id), true);
  }
  async function remove() {
    const original = thread;
    if (!original || !confirm('Delete this thread and its contents?')) return;
    await follow(rel(original, 'delete', 'DELETE'));
    setDraft(original.id, '');
    writeTab(`${tabPrefix()}history:${original.id}`, null);
    try {
      localStorage.removeItem(modelKey(original.id));
      localStorage.removeItem(toolsKey(original.id));
    } catch { /* Navigation is still required after deletion. */ }
    if (readTab(tabPrefix()+'last') === original.id) writeTab(tabPrefix()+'last', null);
    if (thread?.id === original.id) await go('/chat', true);
  }
  async function changeGoal(original, body) {
    const link = rel(original, 'setGoal', 'PUT');
    if (!link) throw new Error('This thread cannot change its goal.');
    const result = await follow(link, body);
    if (thread !== original) return;
    if (result.status === 'active' && !agent.blocked()) {
      const problem = modelProblem();
      if (problem) return `Goal saved. ${problem}`;
      // Send the objective itself, never the command or its status output.
      void agent.start({message:result.goal, ...choice, tools}, () => {}).catch(e => notify(e.message));
      return 'Working on this goal.';
    }
    return result.status === 'active' ? 'Goal saved for the next turn.' :
      result.status === 'none' ? 'Goal cleared.' : `Goal ${result.status}.`;
  }
  async function goalCommand(arg) {
    const original = thread, link = rel(original, 'goal', 'GET');
    if (!link) throw new Error('Open a thread to use goals.');
    const actions = ['pause', 'resume', 'done', 'clear'];
    if (arg) return changeGoal(original, actions.includes(arg) ? {action:arg} : {goal:arg});
    const view = openPicker('goal', 'Thread goal');
    pickerStatus.textContent = 'Loading goal…';
    const saved = await follow(link);
    if (!showing(view) || thread !== original) return;
    pickerStatus.textContent = saved.goal ? `${saved.status}: ${saved.goal}` : 'No goal set.';
    if (!rel(thread, 'setGoal', 'PUT')) return;
    const form = document.createElement('form'), input = document.createElement('textarea');
    form.className = 'fields';
    input.className = 'input-os';
    input.setAttribute('aria-label','Objective');
    input.maxLength = 2000;
    input.required = true;
    input.value = saved.goal || '';
    const start = document.createElement('button');
    start.className = 'btn-os primary';
    start.type = 'submit';
    start.textContent = 'Set goal and start';
    const apply = async body => {
      if (form.inert) return;
      form.inert = true;
      try {
        const feedback = await changeGoal(original, body);
        if (showing(view)) { picker.close(); if (feedback) notify(feedback); }
      } catch (e) { if (showing(view)) pickerStatus.textContent = e.message; }
      finally { form.inert = false; }
    };
    form.addEventListener('submit', e => { e.preventDefault(); if (input.value.trim()) void apply({goal:input.value.trim()}); });
    form.append(input, start);
    const allowed = saved.status === 'active' ? ['pause','done','clear'] : saved.status === 'paused' ? ['resume','done','clear'] : saved.goal ? ['clear'] : [];
    for (const action of allowed) form.append(button(action[0].toUpperCase() + action.slice(1), () => apply({action})));
    pickerList.append(form);
    input.focus();
  }
  const slashCommands = {
    '/help': {help:'List terminal commands', run:() => Object.entries(slashCommands)
      .map(([name, cmd]) => `${name}${cmd.args ? ' ' + cmd.args : ''}  —  ${cmd.help}`).join('\n')},
    '/new': {help:'Create a private thread', run:async () => {
      if (!rel(state.root, 'createSession', 'POST')) throw new Error('Creating a thread is not available.');
      await create();
    }},
    '/name': {args:'[title]', help:'Rename this thread', run:async arg => {
      await rename(arg || undefined);
      return thread && `Thread: ${thread.title}`;
    }},
    '/login': {help:'Connect a model provider', run:async () => {
      const link = rel(state.runtime?.ai, 'loginMethods', 'GET');
      if (!link) throw new Error('Provider sign-in is not available for this account.');
      const view = openPicker('login', 'Connect a provider');
      const form = document.createElement('div');
      form.className = 'fields';
      pickerList.append(form);
      await connectProvider(form, link, async () => {
        state.runtime = await follow(rel(state.root, 'runtime', 'GET'));
        if (showing(view)) { picker.close(); setModelChoice(choice); }
      });
      if (showing(view)) form.querySelector('select')?.focus();
    }},
    '/model': {args:'[provider/model]', help:'Show or change the model for this thread', run:arg => {
      if (!thread) throw new Error('Open a thread first.');
      if (!arg) { openModels(); return; }
      const split = arg.indexOf('/');
      const id = arg.slice(0, split), name = arg.slice(split + 1);
      if (split < 1 || !state.runtime.ai.providers.some(p => p.id === id)) throw new Error('Use /model provider/model with a supported provider.');
      saveModel(id, name);
      return `Model: ${id}/${name}`;
    }},
    '/goal': {args:'[objective|pause|resume|done|clear]', help:'Set, inspect or manage the thread objective', run:goalCommand},
    '/btw': {args:'[question]', help:'Ask a side question or open its history', run:arg => {
      if (!thread) throw new Error('Open a thread first.');
      void sides.open(thread, arg).catch(e => notify(e.message));
    }},
    '/desktop': {help:'Control a Windows app you explicitly share', run:() => {
      if (!rel(thread, 'startAgent', 'POST')) throw new Error('Open a writable active thread first.');
      desktop.open(thread.id);
    }},
    '/permissions': {help:'Choose when this thread asks before writing files', run:openPermissions},
    '/resume': {args:'[session-id]', help:'Find a thread, or open it by ID', run:async id => {
      if (!id) { search.focus(); return 'Search threads and select one to resume.'; }
      if (!/^[A-Za-z0-9_-]{1,100}$/.test(id)) throw new Error('Use /resume with a session ID, or use /resume alone to search.');
      await go(threadUrl(id));
    }},
    '/session': {help:'Show the current session', run:() => thread ?
      `${thread.title}\nID: ${thread.id}\n${projectName(thread.projectId)} · ${thread.status}` : 'No open session.'},
    '/tools': {args:'[on|off]', help:'Enable workspace tools or request a plain answer', run:arg => {
      if (!thread) throw new Error('Open a thread first.');
      if (arg && arg !== 'on' && arg !== 'off') throw new Error('Use /tools on or /tools off.');
      if (arg) {
        tools = arg === 'on';
        try { localStorage.setItem(toolsKey(thread.id), arg); } catch { /* Keep this view's choice. */ }
      }
      return tools ? 'Tools on. File writes follow this thread’s permissions.' : 'Tools off. The next prompt requests a plain answer.';
    }},
    '/copy': {help:'Copy the most recent model reply', run:async () => {
      const original = thread;
      if (!original) throw new Error('Open a thread first.');
      let reply = original.messages.findLast(m => m.role === 'assistant');
      if (!reply && original.messagesPage.hasMore) {
        const page = await follow(queryLink(rel(original, 'messages', 'GET'), {role:'assistant', sort:'createdAt', order:'desc', limit:1}));
        if (thread !== original) return;
        reply = page.items[0];
      }
      if (!reply) throw new Error('No model reply to copy.');
      await copyText(reply.content);
      return 'Model reply copied.';
    }},
  };
  const prompt = createTerminalPrompt({editor, commands:slashCommands, suggestions:byId('slash-suggestions'), changed:syncComposer, readTab, writeTab});
  function consume(draft, id) {
    if (id && getDraft(id) === draft) setDraft(id, '');
    if (thread?.id === id && editor.value === draft) {
      editor.value = id ? getDraft(id) : '';
      syncComposer();
    }
  }
  async function dispatch(text) {
    const revision = threadRevision;
    const [, name, arg = ''] = /^(\S+)(?:\s+([\s\S]*))?$/.exec(text);
    const cmd = slashCommands[name];
    commandBusy = true;
    composerStatus();
    try {
      if (!cmd) throw new Error(`Unknown command: ${name}. Type /help.`);
      if (arg && !cmd.args) throw new Error(`Usage: ${name}`);
      const result = await cmd.run(arg);
      if (result && revision === threadRevision) notify(result);
    } catch (error) {
      if (revision === threadRevision) notify(error.message);
    } finally {
      if (revision === threadRevision) {
        commandBusy = false;
        composerStatus();
      }
    }
  }
  async function send(event) {
    event.preventDefault();
    const original = thread, draft = editor.value, text = draft.trim();
    if (!text || commandBusy) return;
    if (text.startsWith('/')) {
      prompt.remember(text);
      consume(draft, original?.id);
      await dispatch(text);
      return;
    }
    if (!rel(original, 'startAgent', 'POST') || agent.blocked()) return;
    requireModel();
    prompt.remember(text);
    await agent.start({message:text, ...choice, tools}, () => consume(draft, original.id));
  }
  function commands() {
    const actions = [];
    if (rel(state.root, 'createSession', 'POST')) actions.push({label:'New chat', run:create});
    if (state.route === '/chat') {
      actions.push({label:'Search threads', run:() => search.focus()});
      if (rel(thread,'startAgent','POST')) actions.push({label:'Focus message', run:() => editor.focus()}, {label:'Switch model', run:openModels});
      if (rel(thread,'update','PATCH')) actions.push({label:'Rename thread', run:rename});
      if (rel(thread,'permissions','GET')) actions.push({label:'Thread permissions', run:openPermissions});
    }
    return actions;
  }
  return {enter, leave, create, rename, archive, remove, send, commands, forgetTab};
}
