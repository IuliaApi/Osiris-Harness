export function initCommandMenu(getCommands, notify) {
  const trigger = document.getElementById('command-trigger');
  const dialog = document.getElementById('command-menu');
  const search = document.getElementById('command-search');
  const list = document.getElementById('command-list');
  const empty = document.getElementById('command-empty');
  let commands = [];
  let opener = trigger;

  if (/Mac|iPhone|iPad/.test(navigator.platform)) {
    document.getElementById('command-shortcut').textContent = '⌘ K';
  }

  function close() {
    dialog.close();
    (opener.isConnected ? opener : trigger).focus({ preventScroll: true });
  }

  function filter() {
    const query = search.value.trim().toLowerCase();
    list.replaceChildren();
    for (const command of commands) {
      if (!command.label.toLowerCase().includes(query)) continue;
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = command.label;
      button.addEventListener('click', async () => {
        close();
        try { await command.run(); } catch (error) { notify(error.message); }
      });
      item.append(button);
      list.append(item);
    }
    empty.hidden = list.childElementCount !== 0;
  }

  function open(invoker) {
    opener = invoker instanceof HTMLElement && invoker !== document.body ? invoker : trigger;
    commands = getCommands();
    search.value = '';
    filter();
    dialog.showModal();
    search.focus();
  }

  trigger.addEventListener('click', () => open(trigger));
  document.getElementById('command-close').addEventListener('click', close);
  dialog.addEventListener('cancel', event => {
    event.preventDefault();
    close();
  });
  window.addEventListener('popstate', () => { if (dialog.open) close(); });
  search.addEventListener('input', filter);
  dialog.addEventListener('keydown', event => {
    if (event.isComposing) return;
    if (event.key === 'Enter' && event.target === search) {
      event.preventDefault();
      list.firstElementChild?.firstElementChild.click();
      return;
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    event.preventDefault();
    const item = event.target.closest('li');
    const next = event.key === 'ArrowDown'
      ? item?.nextElementSibling || list.firstElementChild
      : item?.previousElementSibling || list.lastElementChild;
    next?.firstElementChild.focus();
  });
  document.addEventListener('keydown', event => {
    if (event.defaultPrevented || event.isComposing || event.altKey || event.shiftKey ||
        !(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k') return;
    if (trigger.closest('.hidden') || document.querySelector('.modal.fallback-open')) return;
    event.preventDefault();
    if (event.repeat) return;
    if (dialog.open) close();
    else open(document.activeElement);
  });
}
