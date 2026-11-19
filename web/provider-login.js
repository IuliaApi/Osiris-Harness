// Login grants stay server-side. The browser handles only the current provider challenge.
export async function showProviderLogin(node, methodsLink, {el, follow, rel, refresh, notify}) {
  const loading = el('p','hint','Loading sign-in methods…'); node.append(loading);
  let catalog;
  try { catalog = await follow(methodsLink); } catch (error) { loading.textContent = error.message; return; }
  if (!node.isConnected) return;
  const methods = catalog.providers, startLink = rel(catalog,'startLogin','POST');
  const label = el('label','','Sign-in method'), select = el('select','input-os');
  methods.forEach(method => { const opt = el('option','',method.name); opt.value = method.id; select.append(opt); });
  label.append(select);
  const status = el('p','hint'), controls = el('div','fields'), begin = el('button','btn-os primary','Connect provider');
  const cancel = el('button','btn-os','Cancel sign-in'); cancel.hidden = true;
  for (const b of [begin,cancel]) b.type = 'button';
  status.setAttribute('role','status'); loading.replaceWith(label,status,begin,cancel,controls);
  let flow = null, timer, busy = false, revision = 0;
  const selected = () => methods.find(m => m.id === select.value);
  const ready = message => {
    clearTimeout(timer); flow = null; busy = false; select.disabled = false; begin.hidden = false; cancel.hidden = true;
    begin.disabled = !startLink || !selected()?.available; controls.replaceChildren();
    status.textContent = message || selected()?.reason || (methods.length ? 'Continue with this provider to link it to your Osiris account.' : 'No methods available.');
  };
  select.onchange = () => ready(); ready();
  async function step(link,payload) {
    if (busy || !link || !node.isConnected) return;
    clearTimeout(timer); busy = true; const ticket = ++revision;
    try {
      const result = await follow(link,payload);
      if (ticket !== revision || !node.isConnected) return;
      busy = false; flow = result;
      if (result.state === 'completed') { ready(); await refresh(); notify(`Connected ${result.provider}. Select a model in chat.`); return; }
      if (result.state !== 'pending') { ready('Sign-in cancelled.'); return; }
      select.disabled = true; begin.hidden = true; cancel.hidden = false;
      status.textContent = result.message || 'Waiting for the provider…';
      controls.replaceChildren();
      if (result.url) {
        const url = new URL(result.url);
        if (url.protocol !== 'https:' || url.username || url.password) throw Error('Provider returned an invalid sign-in URL.');
        const a = el('a','btn-os','Open provider sign-in'); a.href = url.href; a.target = '_blank'; a.rel = 'noopener noreferrer'; controls.append(a);
      }
      if (result.prompt) {
        const form = el('form','fields'), field = el('label','',result.prompt.message), input = el('input','input-os'), submit = el('button','btn-os','Continue');
        input.type = result.prompt.secret ? 'password' : 'text'; input.autocomplete = 'off'; input.spellcheck = false; input.maxLength = 16384;
        input.required = !result.prompt.allowEmpty; input.placeholder = result.prompt.placeholder || ''; field.append(input); form.append(field,submit); controls.append(form);
        form.onsubmit = event => { event.preventDefault(); if (busy) return; const answer = input.value; input.value = ''; submit.disabled = true; void step(rel(result,'answer','POST'),{promptId:result.prompt.id,answer}); };
        // Device/callback flows can finish in the other tab while a manual fallback prompt is open.
        const check = el('button','btn-os','Check sign-in'); check.type = 'button'; check.onclick = () => {void step(rel(result,'status','POST'),{});}; controls.append(check);
      } else timer = setTimeout(() => {void step(rel(result,'status','POST'),{});},1500);
    } catch (error) { if (ticket === revision && node.isConnected) ready(error.message); }
  }
  begin.onclick = () => { if (busy || !selected()?.available) return; begin.hidden = true; select.disabled = true; status.textContent = 'Contacting provider…'; void step(startLink,{provider:select.value}); };
  cancel.onclick = async () => {
    if (!flow) return; const link = rel(flow,'cancel','POST'); ++revision; clearTimeout(timer); cancel.disabled = true;
    try { await follow(link,{}); ready('Sign-in cancelled.'); } catch (error) { ready(error.message); } finally { cancel.disabled = false; }
  };
}
