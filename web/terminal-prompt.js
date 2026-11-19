export async function copyText(text) {
  if (navigator.clipboard) return navigator.clipboard.writeText(text);
  // HTTP installations lack the async Clipboard API. Use a user-initiated copy
  // event there, without touching the prompt selection or claiming a failed copy.
  let copied = false;
  function copy(event) {
    if (!event.clipboardData) return;
    event.clipboardData.setData('text/plain', text);
    event.preventDefault();
    copied = true;
  }
  document.addEventListener('copy', copy);
  try {
    if (!document.execCommand('copy') || !copied) throw new Error('Clipboard unavailable. Select the text and copy it manually.');
  } finally { document.removeEventListener('copy', copy); }
}

// Terminal output is text, including command arguments and model/tool responses.
export function terminalLine(role, label, text) {
  const row = document.createElement('article');
  row.className = `message ${role}`;
  const title = document.createElement('div');
  title.className = 'message-label';
  title.textContent = label;
  const body = document.createElement(role === 'terminal-tool' ? 'pre' : 'div');
  body.className = 'message-text';
  body.textContent = text;
  row.append(title, body);
  return row;
}

export function toolLine(event) {
  return terminalLine('terminal-tool', `tool · ${event.name}${event.path ? ' · ' + event.path : ''}`, event.output);
}

export function createTerminalPrompt({editor, commands, suggestions, changed, readTab, writeTab}) {
  let key, history = [], cursor = 0, scratch = '', selected = -1;
  function dismiss() {
    selected = -1;
    suggestions.hidden = true;
    editor.removeAttribute('aria-activedescendant');
  }
  function highlight(index) {
    suggestions.children[selected]?.setAttribute('aria-selected', 'false');
    selected = index;
    const option = suggestions.children[index];
    option.setAttribute('aria-selected', 'true');
    editor.setAttribute('aria-activedescendant', option.id);
    option.scrollIntoView({block:'nearest'});
  }
  function suggest() {
    dismiss();
    const prefix = editor.value;
    if (document.activeElement !== editor || !/^\/\S*$/.test(prefix) ||
        editor.selectionStart !== prefix.length || editor.selectionEnd !== prefix.length) return;
    suggestions.replaceChildren();
    for (const [name, command] of Object.entries(commands)) {
      if (!name.startsWith(prefix.toLowerCase())) continue;
      const option = document.createElement('li');
      option.id = `slash-option-${name.slice(1)}`;
      option.dataset.command = name;
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      option.textContent = name;
      const help = document.createElement('small');
      help.id = option.id + '-help';
      option.setAttribute('aria-label', name);
      option.setAttribute('aria-describedby', help.id);
      help.textContent = command.help;
      option.append(help);
      suggestions.append(option);
    }
    if (suggestions.childElementCount) {
      suggestions.hidden = false;
      highlight(0);
    }
  }
  function choose(name, run) {
    editor.value = run ? name : name + ' ';
    editor.setSelectionRange(editor.value.length, editor.value.length);
    editor.dispatchEvent(new Event('input'));
    dismiss();
    if (run && !document.getElementById('send-message').disabled) editor.form.requestSubmit();
  }
  suggestions.addEventListener('mousedown', event => event.preventDefault()); // Keep focus in the prompt.
  suggestions.addEventListener('click', event => {
    const option = event.target.closest('[data-command]');
    if (option && !suggestions.hidden) choose(option.dataset.command, true);
  });
  function reset(historyKey) {
    key = historyKey;
    history = [];
    if (key) {
      try {
        const saved = JSON.parse(readTab(key));
        if (Array.isArray(saved)) history = saved.filter(s => typeof s === 'string').slice(-40);
      } catch { /* A malformed tab preference is not command history. */ }
    }
    cursor = history.length;
    scratch = '';
    dismiss();
  }
  function remember(text) {
    if (history.at(-1) !== text) history.push(text);
    if (history.length > 40) history.shift();
    cursor = history.length;
    scratch = '';
    dismiss();
    if (key) writeTab(key, JSON.stringify(history));
  }
  editor.addEventListener('input', event => {
    cursor = history.length;
    if (event.isComposing) dismiss(); else suggest();
  });
  editor.addEventListener('compositionstart', dismiss);
  editor.addEventListener('compositionend', suggest);
  editor.addEventListener('focus', suggest);
  editor.addEventListener('blur', dismiss);
  editor.addEventListener('keydown', event => {
    if (event.isComposing || event.keyCode === 229 || event.altKey || event.ctrlKey || event.metaKey) return;
    if (!suggestions.hidden) {
      const count = suggestions.childElementCount;
      if (event.key === 'Escape') {
        event.preventDefault();
        dismiss();
        return;
      }
      if (!event.shiftKey && editor.selectionStart === editor.value.length && editor.selectionEnd === editor.value.length) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          highlight((selected + (event.key === 'ArrowDown' ? 1 : -1) + count) % count);
          return;
        }
        if (event.key === 'Tab' || event.key === 'Enter') {
          event.preventDefault();
          if (!event.repeat) choose(suggestions.children[selected].dataset.command, event.key === 'Enter');
          return;
        }
      }
      dismiss();
    }
    if (event.shiftKey) return;
    if (event.key === 'Enter') {
      event.preventDefault();
      if (!event.repeat && !document.getElementById('send-message').disabled) editor.form.requestSubmit();
      return;
    }
    const value = editor.value;
    if (editor.selectionStart !== editor.selectionEnd) return;
    if (event.key === 'ArrowUp' && cursor > 0 && !value.slice(0, editor.selectionStart).includes('\n')) {
      if (cursor === history.length) scratch = value;
      --cursor;
    } else if (event.key === 'ArrowDown' && cursor < history.length && !value.slice(editor.selectionEnd).includes('\n')) {
      ++cursor;
    } else return;
    event.preventDefault();
    editor.value = cursor === history.length ? scratch : history[cursor];
    editor.setSelectionRange(editor.value.length, editor.value.length);
    // Navigating history is not an edit: keep the unfinished draft in tab storage.
    changed();
  });
  return {reset, remember};
}
