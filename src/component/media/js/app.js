(() => {
  'use strict';
  const form = document.getElementById('ic-form');
  if (!form) return;
  const initial = Joomla.getOptions('com_intercom', {});
  let draft = initial.draft || null, dirty = !draft, busy = false;
  const status = document.getElementById('ic-status');
  const confirm = document.getElementById('ic-confirm');
  const text = key => Joomla.Text._('COM_INTERCOM_' + key);
  const editable = () => !draft || ['draft','tested'].includes(draft.state);
  for (const [key, value] of Object.entries(initial.message || {})) {
    if (Array.isArray(value)) {
      form.querySelectorAll(`input[name="${key}[]"]`).forEach(el => {el.checked = value.includes(el.value);});
    } else if (form.elements.namedItem(key)) form.elements.namedItem(key).value = value;
  }
  function sync() {
    form.querySelector('[data-action=save]').disabled = busy || !editable();
    form.querySelector('[data-action=preview]').disabled = busy || !draft || dirty || !editable();
    confirm.disabled = busy || !draft || dirty || draft.state !== 'tested';
    form.querySelector('[data-action=release]').disabled = confirm.disabled || !confirm.checked;
    form.querySelector('[data-action=cancel]').disabled = busy || !draft || dirty || !editable();
    document.getElementById('ic-preview-subject').textContent = form.elements.subject_da.value;
    document.getElementById('ic-preview-body').textContent = form.elements.body_da.value;
  }
  form.addEventListener('input', e => {
    if (![confirm, form.elements.send_at].includes(e.target)) {
      dirty = true; confirm.checked = false; status.textContent = text('DIRTY');
    }
    sync();
  });
  window.addEventListener('beforeunload', e => {if (dirty && form.elements.subject_da.value) {e.preventDefault(); e.returnValue = '';}});
  form.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', async () => {
    const action = button.dataset.action;
    if (busy) return;
    if (action === 'save' && !form.reportValidity()) return;
    if (action === 'release' && !window.confirm(text('CONFIRM_SEND'))) return;
    const body = new FormData(form);
    body.set('task', 'api.' + action);
    body.set('id', draft?.id || 0); body.set('revision', draft?.revision || 0);
    if (action === 'save') {
      const message = Object.fromEntries(['type','sender','subject_da','subject_en','body_da','body_en','gender'].map(k=>[k,body.get(k)]));
      for (const k of ['tags','memberships']) message[k] = body.getAll(k+'[]');
      for (const k of ['age_from','age_to']) message[k] = Number(body.get(k)||0);
      body.set('message', JSON.stringify(message));
    }
    body.set('confirm', confirm.checked ? '1' : '0');
    body.set('send_at', form.elements.send_at.value ? Math.floor(new Date(form.elements.send_at.value).getTime()/1000) : 0);
    busy = true; sync();
    try {
      const response = await fetch(form.action, {method:'POST',body,headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || text('ERROR'));
      if (result.data) draft = result.data;
      if (action === 'cancel') draft.state = 'cancelled';
      dirty = false; confirm.checked = false;
      status.textContent = text(action === 'preview' ? (initial.simulation ? 'FAKE_TESTED' : 'TESTED') : action === 'release' ? (initial.simulation ? 'FAKE_SUBMITTED' : 'SUBMITTED') : 'SAVED');
    } catch (e) {status.textContent = e.message || text('ERROR'); if (action==='release') {if(draft)draft.state='uncertain';}}
    finally {busy = false; sync();}
  }));
  sync();
})();
