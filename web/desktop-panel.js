const companion = 'http://127.0.0.1:8765';
const keys = ['Enter', 'Tab', 'Backspace', 'Escape', 'Left', 'Right', 'Up', 'Down'];

function post(path, thread, fields, token, options) {
  const headers = {'Content-Type': 'application/json'};
  if (token) headers.Authorization = `Bearer ${token}`;
  return fetch(companion + path, {
    method: 'POST', mode: 'cors', credentials: 'omit', redirect: 'error', cache: 'no-store',
    referrerPolicy: 'no-referrer', headers, body: JSON.stringify({thread, ...fields}), ...options,
  });
}

function disconnect(thread, token) {
  if (token) void post('/disconnect', thread, {}, token, {keepalive: true}).catch(() => {});
}

function element(tag, id, text) {
  const node = document.createElement(tag);
  if (id) node.id = id;
  if (text) node.textContent = text;
  return node;
}

function labelFor(input, text) {
  const label = element('label', null, text);
  label.htmlFor = input.id;
  label.className = 'label-os';
  return label;
}

function button(id, label, activate) {
  const node = element('button', id, label);
  node.type = 'button';
  node.className = 'btn-os';
  node.addEventListener('click', event => {
    event.preventDefault();
    if (event.isTrusted && !node.disabled) activate();
  });
  return node;
}

function form(id, name) {
  const node = element('form', id);
  node.setAttribute('aria-label', name);
  node.method = 'dialog';
  // Only a trusted button activation (including implicit Enter) can send input.
  node.addEventListener('submit', event => event.preventDefault());
  return node;
}

class CompanionError extends Error {
  constructor(status) { super(); this.status = status; }
}

export function createDesktopPanel() {
  let dispose = null;
  function reset() {
    dispose?.();
    dispose = null;
  }

  function open(thread) {
    reset();
    if (typeof thread !== 'string' || !/^[A-Za-z0-9_.-]{1,128}$/.test(thread)) return;

    const dialog = element('dialog', 'desktop-dialog');
    dialog.setAttribute('aria-labelledby', 'desktop-heading');
    dialog.setAttribute('aria-describedby', 'desktop-consent');
    const header = element('header');
    const close = button('desktop-close', 'Close', reset);
    header.append(element('h2', 'desktop-heading', 'Desktop control'), close);
    const consent = element('p', 'desktop-consent', 'Only you control the selected window. Captures and input stay between this browser and the local companion. They are not added to the chat or sent to the AI. Close this panel to disconnect.');
    const status = element('p', 'desktop-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-atomic', 'true');

    const pairing = form('desktop-pair-form', 'Pair with the desktop companion');
    const instructions = element('p');
    instructions.append('On your Windows PC, run ', element('code', null, '.\\desktop.ps1'), ' from the Osiris folder. Select one app window, enable sharing in the companion, then enter its pairing code here.');
    const code = element('input', 'desktop-code');
    code.type = 'password';
    code.className = 'input-os';
    code.autocomplete = 'off';
    code.spellcheck = false;
    code.required = true;
    code.autofocus = true;
    const pairButton = button('desktop-pair', 'Pair window', pair);
    pairButton.type = 'submit';
    pairButton.classList.add('primary');
    pairing.append(instructions, labelFor(code, 'Pairing code'), code, pairButton);

    const controls = element('section', 'desktop-controls');
    controls.setAttribute('aria-label', 'Selected window controls');
    const toolbar = element('div');
    toolbar.className = 'desktop-toolbar';
    const target = element('strong', 'desktop-target');
    const targetLabel = element('p', null, 'Selected window: ');
    targetLabel.append(target);
    const refresh = button('desktop-refresh', 'Capture window', () => capture(false));
    toolbar.append(targetLabel, refresh);
    const stage = element('div', 'desktop-preview');
    const image = element('img', 'desktop-frame');
    image.alt = 'Latest capture of the selected window';
    image.draggable = false;
    stage.append(image);
    const hint = element('p', null, 'Click a point in the capture, or enter its pixel coordinates below. Input goes to the selected window and refreshes the capture.');
    const actions = element('fieldset', 'desktop-actions');
    actions.append(element('legend', null, 'Window input'));
    const coords = element('details');
    coords.append(element('summary', null, 'Click by coordinates'));
    const point = form('desktop-point-form', 'Click by coordinates');
    const xInput = element('input', 'desktop-x'), yInput = element('input', 'desktop-y');
    for (const [input, name] of [[xInput, 'X'], [yInput, 'Y']]) {
      input.type = 'number';
      input.min = '0';
      input.required = true;
      input.className = 'input-os';
      point.append(labelFor(input, name), input);
    }
    const clickPoint = button('desktop-click', 'Click point', () => {
      if (point.reportValidity()) send({kind: 'click', x: xInput.valueAsNumber, y: yInput.valueAsNumber});
    });
    clickPoint.type = 'submit';
    point.append(clickPoint);
    coords.append(point);
    const typing = form('desktop-text-form', 'Send text to the selected window');
    const text = element('textarea', 'desktop-text');
    text.className = 'input-os';
    text.rows = 2;
    text.autocomplete = 'off';
    text.spellcheck = false;
    text.required = true;
    const sendText = button('desktop-send-text', 'Send text', () => {
      if (!typing.reportValidity()) return;
      if (Array.from(text.value).length > 1024) return say('Enter no more than 1,024 characters at a time.', true);
      send({kind: 'text', text: text.value});
    });
    sendText.type = 'submit';
    typing.append(labelFor(text, 'Text to send'), text, sendText);
    const keyRow = element('div', 'desktop-keys');
    keyRow.setAttribute('role', 'group');
    keyRow.setAttribute('aria-label', 'Send one key');
    for (const key of keys) {
      const control = button(null, key, () => send({kind: 'key', key}));
      control.dataset.key = key;
      control.setAttribute('aria-label', `Send ${key} key`);
      keyRow.append(control);
    }
    actions.append(coords, typing, keyRow);
    controls.append(toolbar, hint, stage, actions);
    dialog.append(header, consent, status, pairing, controls);

    let token = '', frame = '', imageUrl = '', pending = null, disposed = false;
    const current = job => !disposed && dialog.open && pending === job;
    function say(message, error = false) {
      status.textContent = message;
      status.classList.toggle('desktop-error', error);
    }
    function update() {
      pairing.hidden = !!token;
      controls.hidden = !token;
      code.disabled = pairButton.disabled = refresh.disabled = !!pending;
      actions.disabled = !frame || !!pending;
      close.textContent = token ? 'Disconnect and close' : 'Close';
      stage.hidden = !frame;
    }
    function clearFrame() {
      frame = '';
      image.removeAttribute('src');
      if (imageUrl) URL.revokeObjectURL(imageUrl);
      imageUrl = '';
      xInput.value = yInput.value = '';
      stage.hidden = true;
    }
    function stopRequest() {
      if (!pending) return;
      clearTimeout(pending.timer);
      pending.controller?.abort();
      pending = null;
    }
    function fail(kind, statusCode) {
      if (kind !== 'pair' && [401, 403, 410].includes(statusCode)) {
        clearFrame();
        disconnect(thread, token);
        token = target.textContent = text.value = '';
        say('Sharing ended. Select and enable a window in the companion to pair again.', true);
      } else if (kind === 'pair') {
        say(statusCode ? 'Pairing refused. Check the code, the allowed site, and that sharing is enabled.' : 'Cannot pair. Check that the companion is running and allows this site.', true);
      } else if (statusCode === 409) {
        say('The capture is out of date. Capture the window again before sending input.', true);
      } else if (kind === 'action') {
        say('Input was not confirmed. Capture the window to check it before sending anything else.', true);
      } else {
        say('Could not display a capture. Check the companion and capture the window again.', true);
      }
    }
    async function run(kind, message, work) {
      if (pending || disposed || !dialog.open) return false;
      // Keep reading a late pair reply so we can revoke a token granted after close/timeout.
      const job = {controller: kind === 'pair' ? null : new AbortController()};
      pending = job;
      job.timer = setTimeout(() => {
        if (!current(job)) return;
        stopRequest();
        clearFrame();
        fail(kind);
        update();
      }, 15000);
      update();
      say(message);
      try {
        await work(job);
        return current(job);
      } catch (error) {
        if (current(job)) { clearFrame(); fail(kind, error.status); }
        return false;
      } finally {
        if (current(job)) {
          clearTimeout(job.timer);
          pending = null;
          update();
        }
      }
    }
    async function pair() {
      if (token || !pairing.reportValidity()) return;
      const value = code.value.trim();
      code.value = '';
      const paired = await run('pair', 'Pairing with the local companion…', async job => {
        const response = await post('/pair', thread, {code: value});
        if (!response.ok) throw new CompanionError(response.status);
        const result = await response.json();
        if (typeof result?.token !== 'string' || !/^[A-Za-z0-9_-]+$/.test(result.token)) throw new Error();
        if (!current(job) || typeof result.title !== 'string') {
          disconnect(thread, result.token);
          if (current(job)) throw new Error();
          return;
        }
        token = result.token;
        target.textContent = result.title;
        say('Paired. Capture the window when you are ready.');
      });
      if (paired && !disposed && token) refresh.focus();
    }
    async function capture(afterInput) {
      if (!token || pending) return;
      clearFrame();
      await run('frame', afterInput ? 'Input sent. Refreshing the capture…' : 'Capturing the window…', async job => {
        const response = await post('/frame', thread, {}, token, {signal: job.controller.signal});
        if (!response.ok) throw new CompanionError(response.status);
        const id = response.headers.get('X-Frame-Id');
        if (!id || response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase() !== 'image/png') throw new Error();
        const blob = await response.blob();
        if (!current(job)) return;
        image.src = imageUrl = URL.createObjectURL(blob);
        await image.decode();
        if (!current(job)) return;
        frame = id;
        xInput.max = String(image.naturalWidth - 1);
        yInput.max = String(image.naturalHeight - 1);
        say(`Capture ready: ${image.naturalWidth} × ${image.naturalHeight} pixels.`);
      });
    }
    async function send(fields) {
      if (!token || !frame || pending || !dialog.open) return;
      const seen = frame;
      clearFrame();
      if (fields.kind === 'text') text.value = '';
      const sent = await run('action', 'Sending input…', async job => {
        const response = await post('/action', thread, {frame: seen, ...fields}, token, {signal: job.controller.signal});
        if (response.status !== 204) throw new CompanionError(response.status);
      });
      if (sent && !disposed) await capture(true);
    }
    image.addEventListener('click', event => {
      if (!event.isTrusted || event.button !== 0 || !frame || pending) return;
      const bounds = image.getBoundingClientRect();
      const x = Math.floor((event.clientX - bounds.left) * image.naturalWidth / bounds.width);
      const y = Math.floor((event.clientY - bounds.top) * image.naturalHeight / bounds.height);
      if (x >= 0 && x < image.naturalWidth && y >= 0 && y < image.naturalHeight) send({kind: 'click', x, y});
    });

    dispose = () => {
      disposed = true;
      stopRequest();
      clearFrame();
      disconnect(thread, token);
      token = code.value = text.value = target.textContent = '';
      window.removeEventListener('pagehide', reset);
      window.removeEventListener('popstate', reset);
      window.removeEventListener('hashchange', reset);
      if (dialog.open) dialog.close();
      dialog.remove();
    };
    dialog.addEventListener('cancel', event => { event.preventDefault(); reset(); });
    dialog.addEventListener('close', () => { if (!disposed) reset(); });
    // Keep workspace shortcuts out of the modal, particularly while entering a code or text.
    dialog.addEventListener('keydown', event => event.stopPropagation());
    window.addEventListener('pagehide', reset);
    window.addEventListener('popstate', reset);
    window.addEventListener('hashchange', reset);
    update();
    document.body.append(dialog);
    dialog.showModal();
  }

  return {open, reset};
}
