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
  const status = el('p','hint'), begin = el('button','btn-os primary','Connect'), cancel = el('button','btn-os','Cancel');
  const open = el('a','btn-os','Open sign-in page'), controls = el('div','fields');
  open.target = '_blank'; open.rel = 'noopener noreferrer';
  for (const b of [begin,cancel]) b.type = 'button';
  status.setAttribute('role','status'); loading.replaceWith(label,status,begin,cancel,open,controls);
  const selected = () => methods.find(m => m.id === select.value);
  function ready(message) {
    controls.replaceChildren(); open.hidden = true; open.removeAttribute('href');
    cancel.hidden = true; cancel.onclick = null; begin.hidden = false; select.disabled = false;
    begin.disabled = !startLink || !selected()?.available;
    status.textContent = message || selected()?.reason || (methods.length ? '' : 'No methods available.');
  }
  select.onchange = () => ready(); ready();

  begin.onclick = async () => {
    const method = selected();
    if (select.disabled || !method?.available) return;
    select.disabled = true; begin.hidden = true; cancel.disabled = false;
    status.textContent = 'Contacting provider…';
    let popup, message, wake, next, promptId, submit, cancelLink, abandoned = false;
    const launched = new Set();
    function closePopup() {
      if (popup && !popup.closed) popup.close();
      popup = null;
    }
    // A settings route can be hidden in place; the chat picker removes its form.
    // Stop immediately in either case, even while the start request is in flight.
    const observer = new MutationObserver(() => {
      if (node.isConnected && !node.closest('[hidden],.hidden,dialog:not([open])')) return;
      abandoned = true; closePopup(); wake?.(); observer.disconnect();
    });
    for (let ancestor = node; ancestor; ancestor = ancestor.parentElement) {
      observer.observe(ancestor,{childList:true,attributes:true,attributeFilter:['class','hidden','open']});
    }
    function queue(action) {
      if (cancel.disabled) return;
      next = action; cancel.disabled = true;
      if (submit) submit.disabled = true;
      wake?.();
    }
    cancel.onclick = () => queue({type:'cancel'});

    try {
      if (method.browser === true) {
        // Reserve under the click's user activation. "noopener" in window.open would
        // discard the handle we need to navigate; detach while still on about:blank.
        try {
          popup = window.open('about:blank','_blank');
          if (popup) {
            popup.opener = null;
            popup.document.title = 'Provider sign-in';
            popup.document.body.textContent = 'Preparing sign-in. If Osiris asks for details, return to its settings tab.';
          }
        } catch { closePopup(); } // Browser policy can deny the new window. Keep the link fallback.
      }
      let link = startLink, body = {provider:method.id};
      while (!abandoned) {
        const result = await follow(link,body);
        cancelLink = result.state === 'pending' ? rel(result,'cancel','POST') : null;
        if (abandoned) break;
        if (result.state === 'completed') {
          await refresh(); notify(`Connected ${method.name}.`); break;
        }
        if (result.state !== 'pending') {
          message = result.message || (result.state === 'cancelled' ? 'Sign-in cancelled.' : 'Sign-in failed.');
          break;
        }
        cancel.hidden = false; cancel.disabled = !!next;
        const automatic = result.automaticCallback === true;
        const hint = automatic ? 'Finish signing in with the provider. Osiris will connect automatically.' : result.message || 'Waiting for provider…';
        if (status.textContent !== hint) status.textContent = hint;
        if (result.url) {
          const url = new URL(result.url);
          if (url.protocol !== 'https:' || url.username || url.password) throw Error('Invalid provider sign-in URL.');
          open.href = url.href;
          if (popup && !popup.closed && !launched.has(url.href)) {
            launched.add(url.href);
            try { popup.location.replace(url.href); popup.focus(); } catch { open.hidden = false; }
          }
          if (!popup || popup.closed) open.hidden = false;
        } else {
          open.hidden = true; open.removeAttribute('href');
        }
        // Preserve focus, partially typed text and recovery disclosure across polls.
        const prompt = result.prompt;
        if (promptId !== prompt?.id) {
          controls.replaceChildren(); promptId = prompt?.id; submit = null;
          if (prompt) {
            const form = el('form','fields'), field = el('label','',prompt.message), input = el('input','input-os');
            submit = el('button','btn-os','Continue');
            input.type = prompt.secret ? 'password' : 'text'; input.autocomplete = 'off'; input.spellcheck = false;
            input.maxLength = 16384; input.required = !prompt.allowEmpty; input.placeholder = prompt.placeholder || '';
            field.append(input); form.append(field,submit);
            if (prompt.callback === true && automatic) {
              const recovery = el('details');
              recovery.append(el('summary','','Paste redirect URL instead'),form); controls.append(recovery);
            } else {
              controls.append(form);
              if (popup && !popup.closed && !result.url && !prompt.callback) {
                window.focus(); input.focus();
              }
            }
            form.onsubmit = event => {
              event.preventDefault(); queue({type:'answer',promptId:prompt.id,answer:input.value});
            };
          }
        }
        if (submit) submit.disabled = !!next;
        // One request at a time. A click during a status request is consumed after
        // its response, and an answer is only sent for the still-current prompt.
        if (!next) await new Promise(resolve => {
          const timer = setTimeout(resolve,1500);
          wake = () => { clearTimeout(timer); resolve(); };
        });
        wake = null;
        if (abandoned) break;
        const action = next; next = null; body = {};
        if (action?.type === 'answer' && action.promptId === promptId) {
          link = rel(result,'answer','POST'); body = {promptId,answer:action.answer};
        } else link = rel(result,action?.type === 'cancel' ? 'cancel' : 'status','POST');
      }
    } catch (error) { message = error.message; }
    finally {
      observer.disconnect(); closePopup();
      if (abandoned && cancelLink) {
        try { await follow(cancelLink,{}); } catch (error) { message = error.message; }
      }
      if (node.isConnected) ready(message);
    }
  };
}
