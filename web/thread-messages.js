import { copyText, toolLine } from './terminal-prompt.js';

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function button(label, action) {
  const node = element('button', 'btn-os', label);
  node.type = 'button';
  node.addEventListener('click', action);
  return node;
}

// Only fences are interpreted; prose, language labels and code remain literal text.
function* messageParts(text) {
  const markers = /^ {0,3}(`{3,}|~{3,})([^\r\n]*)(?:\r\n?|\n|$)/gm;
  let fence = null;
  let start = 0;
  for (const match of text.matchAll(markers)) {
    const [, marker, info] = match;
    if (!fence) {
      if (marker[0] === '`' && info.includes('`')) continue;
      if (match.index > start) yield {text: text.slice(start, match.index)};
      fence = {marker, language: info.trim().split(/\s+/, 1)[0]};
    } else {
      if (marker[0] !== fence.marker[0] || marker.length < fence.marker.length || info.trim()) continue;
      yield {language: fence.language, text: text.slice(start, match.index)};
      fence = null;
    }
    start = match.index + match[0].length;
  }
  if (fence) yield {language: fence.language, text: text.slice(start)};
  else if (start < text.length) yield {text: text.slice(start)};
}

export function createMessageRenderer({notify}) {
  function copyButton(label, content, feedback) {
    const copy = button(label, async () => {
      try { await copyText(content); if (copy.isConnected) notify(feedback); }
      catch (error) { if (copy.isConnected) notify(error.message); }
    });
    return copy;
  }
  function codeBlock(language, content) {
    const block = element('section', 'thread-code');
    const bar = element('div', 'thread-code-bar');
    bar.append(element('span', '', language || 'Code'), copyButton('Copy code', content, 'Code copied.'));
    const pre = element('pre');
    pre.append(element('code', '', content));
    block.append(bar, pre);
    return block;
  }

  function messageNode(message) {
    const user = message.role === 'user';
    const block = element('article', `message ${user ? 'user' : message.role === 'tool' ? 'terminal-tool' : 'assistant'}`);
    if (message.agentEvents) block.append(...message.agentEvents.map(toolLine));
    const label = element('div', 'message-label', user ? 'you ❯' : message.role === 'assistant' ? 'model' : message.role);
    if (message.role === 'assistant') {
      const copy = copyButton('Copy reply', message.content, 'Model reply copied.');
      copy.className = 'subtle-link message-copy';
      label.append(copy);
    }
    block.append(label);
    for (const part of messageParts(message.content)) {
      block.append(part.language === undefined ? element('div', 'message-text', part.text) : codeBlock(part.language, part.text));
    }
    return block;
  }

  return {messageNode};
}
