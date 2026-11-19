const byId = id => document.getElementById(id);

class MissingPrerequisite extends Error {}

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

function formatResponse(method, result) {
  const body = typeof result.data === 'string' ? result.data : JSON.stringify(result.data, null, 2);
  const preview = body.length > 7000 ? body.slice(0, 7000) + '\n… (display truncated)' : body;
  return `${method} ${result.url}\nHTTP ${result.status} | ${result.contentType || 'No Content-Type'} | Request ID ${result.requestId || 'missing'}\n${preview}`;
}

function setOptions(select, items) {
  const selected = select.value;
  select.replaceChildren(...items.map(item => new Option(item.name || item.title, item.id)));
  if (items.some(item => item.id === selected)) select.value = selected;
  return select.value !== selected;
}

export function createApiChecks({ appState, rel, queryLink, fetchResponse, refreshData }) {
  const sessionsLink = () => rel(appState.root, 'sessions', 'GET');
  const nextButton = byId('check-next');
  let nextPage = null;

  async function sessionReads(request) {
    const sessions = await request(sessionsLink(), 200);
    expect(Array.isArray(sessions), 'Expected a session list.');
    await request({ method: 'GET', href: '/sessions/osiris_missing_session_id' }, 404);
    if (!sessions.length) throw new MissingPrerequisite('No visible session to read individually.');
    const session = sessions[0];
    const detail = await request(rel(session, 'self', 'GET'), 200);
    expect(detail.id === session.id && typeof detail.id === 'string', 'The individual response did not match the listed session.');
  }

  function queryParameters() {
    const params = { sort: byId('check-sort').value, order: 'asc', limit: byId('check-limit').value };
    if (byId('check-filter').value) params.status = byId('check-filter').value;
    return params;
  }

  async function readPage(request, link, params, offset) {
    nextPage = null;
    const result = await request(link, 200);
    const { items, page } = result;
    const size = Number(params.limit);
    expect(Array.isArray(items) && page?.limit === size && page.offset === offset &&
      Number.isInteger(page.total) && page.total >= 0 && items.length === Math.max(0, Math.min(size, page.total - offset)),
      'The page size, offset or item count did not match the query.');
    expect(!params.status || items.every(item => item.status === params.status), 'The page contains a session outside the requested status.');
    const next = rel(result, 'next', 'GET');
    const hasMore = offset + items.length < page.total;
    expect(page.hasMore === hasMore && Boolean(next) === hasMore, 'The next link did not match the page metadata.');
    if (next) {
      const url = new URL(next.href, location.origin);
      const expected = { ...params, offset: String(offset + size) };
      expect(url.origin === location.origin && url.pathname === new URL(link.href, location.origin).pathname &&
        url.searchParams.size === Object.keys(expected).length &&
        Object.entries(expected).every(([key, value]) => url.searchParams.get(key) === value),
        'The next link did not preserve the query and advance the offset.');
      nextPage = { link: next, params, offset: offset + size };
    }
  }

  async function sessionQuery(request) {
    const params = queryParameters();
    await readPage(request, queryLink(sessionsLink(), params), params, 0);
  }

  async function sessionPersistence(request, notes) {
    const project = appState.projects.find(item => item.id === byId('check-project').value);
    const title = byId('check-title').value.trim();
    if (!title) throw new MissingPrerequisite('Enter a session title.');
    const created = await request(rel(project, 'createSession', 'POST'), 201, { body: { title } });
    expect(typeof created.id === 'string' && created.title === title && created.projectId === project.id, 'The created session did not match the requested title and project.');
    const renamed = title + ' (updated)';
    const updated = await request(rel(created, 'update', 'PATCH'), 200, { body: { title: renamed } });
    expect(updated.id === created.id && updated.title === renamed, 'The update did not return the renamed session.');
    const saved = await request(rel(created, 'self', 'GET'), 200);
    expect(saved.id === created.id && saved.projectId === project.id && saved.title === renamed, 'The saved session did not retain the new title and project.');
    try { await refreshData(); } catch (error) { notes.push(`Session verified, but the workspace list could not be refreshed: ${error.message}`); }
  }

  async function responseFormats(request) {
    const session = appState.sessions[0];
    if (!session) throw new MissingPrerequisite('A visible session is needed to request its representations.');
    const link = rel(session, 'self', 'GET');
    for (const accept of ['application/json', 'application/xml', 'application/yaml']) {
      const data = await request(link, 200, { accept });
      if (accept === 'application/json') expect(data?.id === session.id, 'JSON did not identify the requested session.');
      else expect(typeof data === 'string' && data.includes(session.id), `${accept} did not contain the requested session ID.`);
    }
    await request(queryLink(sessionsLink(), { sort: 'invalid_field' }), 400);
    await request(link, 406, { accept: 'text/csv' });
  }

  async function projectContext(request, notes) {
    const session = appState.sessions.find(item => item.id === byId('check-session').value);
    if (!session) throw new MissingPrerequisite('No visible session with a context link is selected.');
    const data = await request(rel(session, 'context', 'GET'), 200);
    const external = data.external;
    const matchingProject = session.projectId === null ? data.project === null : typeof session.projectId === 'string' && data.project?.id === session.projectId;
    expect(data.session?.id === session.id && matchingProject && data.sources?.local === 'PostgreSQL', 'The local context did not identify the selected session and its project scope.');
    expect(external?.source && external.source === data.sources.external && ['live', 'fixture', 'none'].includes(external.provenance) &&
      ['ok', 'unavailable', 'not_configured'].includes(external.status), 'The external context did not report a recognized source, provenance and status.');
    notes.push(`Source: ${external.source}; provenance: ${external.provenance}; status: ${external.status}${external.reason ? ' (' + external.reason + ')' : ''}.`);
  }

  async function httpStatuses(request) {
    const sessions = sessionsLink();
    await request(sessions, 200);
    for (const params of [{ limit: 0 }, { offset: 1000001 }, { sort: 'invalid_field' }]) {
      await request(queryLink(sessions, params), 400);
    }
    await request(rel(appState.root, 'runtime', 'GET'), 200);
  }

  async function run(panel, check) {
    const state = panel.querySelector('[role=status]');
    const output = panel.querySelector('pre');
    const controls = panel.querySelectorAll('button, select, input');
    if (panel.hasAttribute('aria-busy')) return;
    panel.setAttribute('aria-busy', 'true');
    for (const control of controls) control.disabled = true;
    state.className = 'check-state';
    state.textContent = 'Running…';
    output.classList.add('hidden');
    const notes = [];
    const request = async (link, status, { body, accept = 'application/json' } = {}) => {
      if (!link) throw new Error('The API did not advertise the required action.');
      let result;
      try { result = await fetchResponse(link.method, link.href, body, accept); }
      catch (error) { throw new Error(`${link.method} ${link.href}: ${error.message}`); }
      notes.push(formatResponse(link.method, result));
      expect(result.status === status, `Expected HTTP ${status}, received ${result.status}.`);
      expect(result.requestId, 'The response did not include an X-Request-Id.');
      const media = result.contentType.split(';')[0].trim().toLowerCase();
      expect(media === (status >= 400 ? 'application/json' : accept), `Unexpected response type: ${result.contentType}.`);
      if (media === 'application/json') expect(result.data && typeof result.data === 'object', 'Expected a JSON object or collection.');
      if (status >= 400) expect(typeof result.data?.error === 'string' && result.data.code && result.data.requestId === result.requestId, 'Expected a structured error with the matching request ID.');
      return result.data;
    };
    try {
      await check(request, notes);
      state.textContent = 'Passed';
      state.classList.add('pass');
    } catch (error) {
      notes.push(error.message);
      state.textContent = error instanceof MissingPrerequisite ? 'Incomplete' : 'Failed';
      if (!(error instanceof MissingPrerequisite)) state.classList.add('fail');
    } finally {
      output.textContent = notes.join('\n\n———\n\n');
      output.classList.remove('hidden');
      panel.removeAttribute('aria-busy');
      for (const control of controls) control.disabled = false;
      if (panel.contains(nextButton)) nextButton.disabled = !nextPage;
    }
  }

  const checks = {
    'session-reads': sessionReads,
    'session-query': sessionQuery,
    'session-persistence': sessionPersistence,
    'response-formats': responseFormats,
    'project-context': projectContext,
    'http-statuses': httpStatuses,
  };
  const queryPanel = byId('session-query');
  function resetResult(panel) {
    if (panel === queryPanel) {
      nextPage = null;
      nextButton.disabled = true;
    }
    const state = panel.querySelector('[role=status]');
    state.className = 'check-state';
    state.textContent = 'Not run';
    panel.querySelector('pre').classList.add('hidden');
  }
  for (const [id, check] of Object.entries(checks)) {
    const panel = byId(id);
    panel.querySelector('[data-run-check]').onclick = () => run(panel, check);
    panel.oninput = () => resetResult(panel);
  }
  nextButton.onclick = () => {
    if (nextPage) {
      const { link, params, offset } = nextPage;
      run(queryPanel, request => readPage(request, link, params, offset));
    }
  };

  return {
    updateChoices(projects, sessions) {
      if (setOptions(byId('check-project'), projects)) resetResult(byId('session-persistence'));
      const readable = sessions.filter(session => rel(session, 'context', 'GET'));
      if (setOptions(byId('check-session'), readable)) resetResult(byId('project-context'));
      byId('session-persistence').classList.toggle('hidden', !projects.length);
      for (const button of document.querySelectorAll('[data-run-check]')) button.disabled = Boolean(button.closest('[aria-busy]'));
    },
  };
}
