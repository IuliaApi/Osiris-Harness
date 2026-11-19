const byId = id => document.getElementById(id);

export function createSideQuestions({follow, rel, getChoice}) {
  const dialog = byId('side-questions'), history = byId('side-history'), question = byId('side-question');
  const answer = byId('side-answer'), status = byId('side-status'), ask = byId('side-ask'), reply = byId('side-follow');
  let view = null;
  const selected = () => view?.items.find(row => row.id === history.value);
  function render() {
    const row = selected(), write = rel(view?.thread, 'askAside', 'POST');
    answer.textContent = row ? `${row.question}\n\n${row.answer}` : '';
    history.disabled = !view?.items.length;
    question.disabled = !write || !!view?.busy;
    ask.disabled = question.disabled;
    reply.disabled = !row || question.disabled;
    status.textContent = view?.status || (row ? `${row.provider}/${row.model}` : 'No saved side questions.');
  }
  function list(v) {
    history.replaceChildren(...v.items.map(row => new Option(row.question, row.id)));
    render();
  }
  async function submit(v, text, replyTo = null) {
    const link = rel(v.thread, 'askAside', 'POST');
    if (!link || !text || v.busy) return;
    v.busy = true;
    const choice = getChoice();
    v.status = `Thinking · ${choice.provider}/${choice.model}`;
    render();
    try {
      const row = await follow(link, {message:text, ...choice, ...(replyTo ? {replyTo} : {})});
      if (view !== v) return;
      v.items = [row, ...v.items].slice(0, 20);
      question.value = '';
      v.status = '';
      list(v);
    } catch (e) { if (view === v) v.status = e.message; }
    finally { if (view === v) { v.busy = false; render(); } }
  }
  async function open(thread, text) {
    const read = rel(thread, 'asides', 'GET');
    if (!read) throw new Error('Open a thread to read its side questions.');
    if (text && !rel(thread, 'askAside', 'POST')) throw new Error('This thread cannot send a side question.');
    if (!dialog.open) dialog.showModal();
    if (view?.busy) return;
    const v = view = {thread, busy:true, items:[], status:'Loading saved side questions…'};
    question.value = text;
    list(v);
    try {
      const page = await follow(read);
      if (view !== v) return;
      v.items = page.items;
      v.busy = false;
      v.status = '';
      list(v);
      if (text) await submit(v, text);
      else if (dialog.open && !question.disabled) question.focus();
    } catch (e) {
      if (view === v) { v.status = e.message; v.busy = false; render(); }
    }
  }
  history.addEventListener('change', render);
  byId('side-close').addEventListener('click', () => dialog.close());
  byId('side-form').addEventListener('submit', e => {
    e.preventDefault();
    if (view) void submit(view, question.value.trim(), e.submitter === reply ? selected()?.id : null);
  });
  return {open, reset() {
    view = null;
    if (dialog.open) dialog.close();
    history.replaceChildren();
    question.value = answer.textContent = status.textContent = '';
  }};
}
