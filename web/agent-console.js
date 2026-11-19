import { terminalLine, toolLine } from './terminal-prompt.js';

const byId = id => document.getElementById(id);
const active = run => ['ready', 'working', 'review'].includes(run?.status);
const STEP_LIMIT = 8;

function button(label, disabled, action) {
  const node = document.createElement('button');
  node.className = 'btn-os';
  node.type = 'button';
  node.disabled = disabled;
  node.textContent = label;
  node.addEventListener('click', action);
  return node;
}

function contentPreview(content) {
  const preview = document.createElement('pre');
  preview.dataset.preview = '';
  // Keep consent legible for both many-line files and long, unbroken lines.
  const excerpt = content.slice(0, 480).split('\n', 6).join('\n');
  if (excerpt === content) {
    preview.textContent = content || '(empty file)';
    return preview;
  }
  preview.textContent = excerpt + '…';
  const full = document.createElement('details'), label = document.createElement('summary');
  label.textContent = 'Show full proposed contents';
  const text = document.createElement('pre');
  text.textContent = content;
  full.append(label, text);
  const block = document.createDocumentFragment();
  block.append(preview, full);
  return block;
}

function checkedTurn(expected, actual) {
  if (actual?.id !== expected.id || actual.sessionId !== expected.sessionId) throw new Error('Unexpected agent turn.');
  return actual;
}

function checkedRequest(session, event, request) {
  if (request?.id !== event.runId || request.sessionId !== session.id || request.path !== event.path ||
      request.operation !== 'workspace.write' || typeof request.content !== 'string' ||
      !['requested', 'approved', 'completed'].includes(request.status) || !Number.isSafeInteger(request.version)) {
    throw new Error('Unexpected file change returned for this turn.');
  }
  return request;
}

export function createAgentConsole({follow, rel, changed, reloadHistory, scroll}) {
  const controls = byId('agent-controls'), output = byId('agent-output'), status = byId('agent-state');
  const clock = byId('agent-clock');
  let timer = 0, started = 0;
  function showClock(thinking) {
    if (thinking && !timer) {
      started = performance.now();
      clock.textContent = '0s';
      timer = setInterval(() => { clock.textContent = `${Math.floor((performance.now() - started) / 1000)}s`; }, 1000);
    } else if (!thinking && timer) { clearInterval(timer); timer = 0; }
    clock.hidden = !thinking;
  }
  const more = byId('agent-continue'), cancel = byId('agent-cancel'), refresh = byId('agent-refresh');
  const starting = new Set();
  let view = null;
  const owns = (v, ticket) => view === v && v.ticket === ticket;
  const blocked = () => !view || starting.has(view.session.id) || !!(view.action || view.error || active(view.run));
  const allApplied = v => v.requests?.size > 0 && [...v.requests.values()].every(r => r.status === 'completed');

  function eventNode(v, event) {
    const request = v.requests?.get(event.runId);
    if (!request) return toolLine(event);
    if (request.status === 'completed') return toolLine({...event, output:request.result});
    const run = v.run;
    const row = document.createElement('article');
    row.className = 'message terminal-tool agent-request';
    row.dataset.runId = request.id;
    const title = document.createElement('h4');
    title.id = `change-${request.id}`;
    row.setAttribute('aria-labelledby', title.id);
    title.textContent = `Allow ${event.name} of ${request.path}?`;
    const label = document.createElement('p');
    label.textContent = 'Allow once applies these contents. Deny ends this turn.';
    const actions = document.createElement('div');
    actions.className = 'thread-actions';
    const locked = !!(v.action || v.error) || !rel(run, 'step', 'POST');
    const action = request.status === 'requested' ? 'approve' : 'execute';
    actions.append(
      button('Allow once', locked || !rel(request, action, 'POST'), () => decide(v, run, event, request, true)),
      button('Deny', locked || !rel(run, 'cancel', 'POST'), () => decide(v, run, event, request, false))
    );
    row.append(title, label, contentPreview(request.content), actions);
    return row;
  }

  function render() {
    const run = view?.run, action = view?.action;
    const busy = action || (view && starting.has(view.session.id) && 'start');
    showClock(busy === 'step');
    byId('thinking-dots').hidden = !['load', 'start', 'step', 'requests', 'history'].includes(busy);
    controls.classList.toggle('has-error', !!view?.error || run?.status === 'failed');
    controls.hidden = !view || !(run || busy || view.error);
    output.replaceChildren();
    if (run && run.status !== 'completed') {
      output.append(terminalLine('user', 'you ❯', run.prompt), ...run.events.map(e => eventNode(view, e)));
    } else if (run?.events.length && run.status === 'completed' && !view.session.messages.some(message =>
      message.agentEvents?.length === run.events.length && message.agentEvents.every((e, i) => {
        const saved = run.events[i];
        return e.name === saved.name && e.path === saved.path && e.output === saved.output && e.runId === saved.runId;
      }))) {
      output.append(...run.events.map(toolLine));
    }
    if (!view) return;
    const model = run && `${run.provider}/${run.model}`;
    let text = '';
    if (busy === 'load') text = 'Reading saved run…';
    else if (busy === 'start') text = 'Starting run…';
    else if (busy === 'step') text = `Thinking · ${model}${run.events.length ? ' · reading tool results' : ''}`;
    else if (busy === 'cancel') text = 'Cancelling run…';
    else if (busy === 'requests') text = 'Reading proposed file changes…';
    else if (busy === 'consent') text = 'Checking file change…';
    else if (busy === 'history') text = 'Loading saved reply…';
    else if (run) text = {
      ready: 'Ready for the next model step. Choose Continue.',
      working: `A step is in progress on ${model}. Refresh to see its result.`,
      review: allApplied(view) ? 'All changes applied. Choose Continue.' : 'This turn needs permission to change a file.',
      completed: 'Completed',
      cancelled: 'Cancelled. Applied changes are kept. A provider call already in flight may still finish.',
      failed: `Failed: ${run.error || 'The run did not complete.'}`,
    }[run.status];
    status.textContent = view.error ? `${view.error} Refresh the run to check its saved state; this will not repeat a model call or file change.` : text;
    more.hidden = !['ready', 'review'].includes(run?.status) || !rel(run, 'step', 'POST');
    more.disabled = !!(busy || view.error) || (run?.status === 'review' && !allApplied(view));
    cancel.hidden = !active(run) || !rel(run, 'cancel', 'POST');
    cancel.disabled = !!view.error || (!!busy && busy !== 'step');
    if (!cancel.hidden && !cancel.disabled) status.textContent += ' Esc cancels remaining steps, not a call already sent.';
    refresh.hidden = !active(run) && !view.error;
    refresh.disabled = !!busy;
    changed();
  }
  function setThread(session) {
    view = session ? {session, run:null, requests:null, action:'load', error:'', ticket:0} : null;
    render();
  }
  function fail(v, ticket, error) {
    if (!owns(v, ticket)) return;
    v.action = '';
    v.error = error.message;
    render();
  }
  async function settle(v, ticket, run) {
    if (!owns(v, ticket)) return;
    if (run && run.sessionId !== v.session.id) throw new Error('Run does not belong to this thread.');
    v.run = run;
    v.action = '';
    v.requests = null;
    v.error = '';
    if (run?.status === 'review') {
      v.action = 'requests';
      render();
      const events = run.events.filter(e => e.runId);
      if (!events.length) throw new Error('The turn did not identify its proposed changes.');
      const requests = await Promise.all(events.map(e => readRequest(v, e)));
      if (!owns(v, ticket)) return;
      v.requests = new Map(requests.map(r => [r.id, r]));
      v.action = '';
    }
    if (run?.status === 'completed') {
      v.action = 'history';
      render();
      await reloadHistory(v.session);
      if (!owns(v, ticket)) return;
      v.action = '';
    }
    render();
    scroll();
  }
  async function readRequest(v, event) {
    return checkedRequest(v.session, event, await follow(rel(event, 'toolRun', 'GET')));
  }

  async function decide(v, run, event, shown, allow) {
    if (view !== v || v.run !== run || v.action || v.error || v.requests?.get(event.runId) !== shown) return;
    const ticket = ++v.ticket;
    v.action = 'consent';
    render();
    const readTurn = async () => checkedTurn(run, await follow(rel(run, 'self', 'GET')));
    const originalStep = rel(run, 'step', 'POST')?.href;
    const sameStep = current => originalStep && current.status === 'review' && rel(current, 'step', 'POST')?.href === originalStep;
    try {
      let current = await readTurn();
      if (!owns(v, ticket)) return;
      if (!sameStep(current)) return await settle(v, ticket, current);
      const liveEvent = current.events.find(e => e.runId === shown.id && e.path === shown.path && e.name === event.name);
      if (!liveEvent) throw new Error('The proposed file change is no longer part of this turn.');
      if (!allow) {
        const cancelled = await follow(rel(current, 'cancel', 'POST'), {});
        return await settle(v, ticket, checkedTurn(run, cancelled));
      }

      let request = await readRequest(v, liveEvent);
      if (!owns(v, ticket)) return;
      const transition = async action => checkedRequest(v.session, liveEvent, await follow(
        rel(request, action, 'POST'), {}, {'If-Match': `"${request.version}"`}
      ));
      if (request.content !== shown.content) throw new Error('The proposed contents have changed.');
      if (request.status === 'requested') {
        request = await transition('approve');
        if (!owns(v, ticket)) return;
        if (request.content !== shown.content) throw new Error('The approved contents have changed.');
      }
      if (request.status === 'approved') {
        current = await readTurn();
        if (!owns(v, ticket)) return;
        if (!sameStep(current)) return await settle(v, ticket, current);
        request = await transition('execute');
        if (!owns(v, ticket)) return;
      }
      if (request.status !== 'completed') throw new Error('The file change was not applied.');

      // Re-read the turn and its named proposals. Only the user's Allow once can
      // advance this exact round; restoring or refreshing the tab is read-only.
      current = await readTurn();
      await settle(v, ticket, current);
      if (owns(v, ticket) && sameStep(current) && allApplied(v)) await drive(v, ticket, current);
    } catch (error) { fail(v, ticket, error); }
    finally {
      if (owns(v, ticket) && document.activeElement === document.body) {
        const next = output.querySelector('[data-run-id] button:not(:disabled)') ||
          [refresh, more, cancel].find(b => !b.hidden && !b.disabled);
        next?.focus();
      }
    }
  }
  // Restoring the tab is read-only. A working run may belong to another tab.
  async function hydrate() {
    const v = view;
    if (!v) return;
    const ticket = ++v.ticket;
    v.action = 'load';
    v.error = '';
    render();
    try {
      const link = rel(v.session, 'agent', 'GET');
      const run = link ? (await follow(link)).run : null;
      await settle(v, ticket, run);
    } catch (error) { fail(v, ticket, error); }
  }
  async function drive(v, ticket, run) {
    for (let steps = 0; steps < STEP_LIMIT; ++steps) {
      if (!owns(v, ticket)) return;
      const step = rel(run, 'step', 'POST');
      if (!step) break;
      v.run = run;
      v.action = 'step';
      render();
      scroll();
      run = checkedTurn(run, await follow(step, {}));
      if (!owns(v, ticket)) return;
      if (run.status !== 'ready') break;
    }
    await settle(v, ticket, run);
  }
  async function start(payload, accepted) {
    const v = view;
    if (blocked()) return;
    const ticket = ++v.ticket;
    starting.add(v.session.id);
    v.action = 'start';
    render();
    try {
      const run = await follow(rel(v.session, 'startAgent', 'POST'), payload);
      starting.delete(v.session.id);
      if (run.sessionId !== v.session.id) throw new Error('Unexpected run returned by start.');
      accepted();
      if (!owns(v, ticket)) {
        if (view?.session.id === v.session.id) await hydrate();
        return;
      }
      if (run.status === 'ready') await drive(v, ticket, run);
      else await settle(v, ticket, run);
    } catch (error) {
      starting.delete(v.session.id);
      fail(v, ticket, error);
      if (view && view !== v && view.session.id === v.session.id) await hydrate();
    }
  }
  more.addEventListener('click', async () => {
    const v = view;
    if (!v || more.disabled || more.hidden) return;
    const ticket = ++v.ticket;
    try { await drive(v, ticket, v.run); } catch (error) { fail(v, ticket, error); }
  });
  async function cancelRun() {
    const v = view;
    if (!v || cancel.disabled || cancel.hidden) return;
    const ticket = ++v.ticket; // Ignore any outstanding step response; do not advance it.
    v.action = 'cancel';
    v.error = '';
    render();
    try {
      const run = checkedTurn(v.run, await follow(rel(v.run, 'cancel', 'POST'), {}));
      await settle(v, ticket, run);
    } catch (error) { fail(v, ticket, error); }
  }
  cancel.addEventListener('click', () => {
    if (confirm('Cancel the remaining steps? This cannot retract a model call already sent or undo applied writes.')) cancelRun();
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || event.defaultPrevented || event.isComposing || event.repeat ||
        cancel.disabled || cancel.hidden || !view || !event.target.closest?.('#message-text, #terminal-scroll') ||
        document.querySelector('dialog[open], .modal.fallback-open')) return;
    event.preventDefault();
    cancelRun();
  });
  refresh.addEventListener('click', () => hydrate());
  return {setThread, hydrate, start, blocked, historyChanged:render};
}
