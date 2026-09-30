import { JoomlaEditor } from 'editor-api';

(() => {
  'use strict';
  const form = document.getElementById('ic-form');
  if (!form) return;
  const initial = Joomla.getOptions('com_intercom', {});
  let draft = initial.draft || null, dirty = !draft, busy = false;
  let step = 0, editLanguage = 'da', previewLanguage = 'da', renderTimer, renderSequence = 0;
  let rendered = null;
  const status = document.getElementById('ic-status');
  const confirm = document.getElementById('ic-confirm');
  const frame = document.getElementById('ic-preview-frame');
  const text = key => Joomla.Text._('COM_INTERCOM_' + key);
  const editable = () => !draft || ['draft','tested'].includes(draft.state);
  const bodyValue = lang => (JoomlaEditor.get('body_' + lang) || null)?.getValue() ?? form.elements['body_' + lang].value;
  for (const [key, value] of Object.entries(initial.message || {})) {
    // Editor values are rendered by Joomla on the server, including conversion of old plain-text drafts.
    if (key.startsWith('body_')) continue;
    if (Array.isArray(value)) {
      const select = form.querySelector(`select[name="${key}[]"]`);
      if (select) Array.from(select.options).forEach(el => { el.selected = value.includes(el.value); });
    } else if (form.elements.namedItem(key)) form.elements.namedItem(key).value = value;
  }
  function message() {
    const data = new FormData(form);
    const result = Object.fromEntries(['type','sender','subject_da','subject_en','gender'].map(k => [k, data.get(k)]));
    result.format = 'html';
    for (const lang of ['da','en']) result['body_' + lang] = bodyValue(lang);
    for (const k of ['tags','memberships']) result[k] = data.getAll(k + '[]');
    for (const k of ['age_from','age_to']) result[k] = Number(data.get(k) || 0);
    return result;
  }
  function showStep(value) {
    step = Number(value);
    form.querySelectorAll('[data-panel]').forEach(el => { el.hidden = Number(el.dataset.panel) !== step; });
    form.querySelectorAll('[data-step]').forEach(el => el.setAttribute('aria-current', Number(el.dataset.step) === step ? 'step' : 'false'));
  }
  function showLanguage(value) {
    editLanguage = value;
    form.querySelectorAll('[data-language-panel]').forEach(el => { el.hidden = el.dataset.languagePanel !== value; });
    form.querySelectorAll('[data-edit-lang]').forEach(el => el.setAttribute('aria-pressed', el.dataset.editLang === value));
  }
  function validForm() {
    const invalid = Array.from(form.querySelectorAll('input,select')).find(el => !el.checkValidity());
    if (invalid) {
      const panel = invalid.closest('[data-panel]');
      if (panel) showStep(panel.dataset.panel);
      const language = invalid.closest('[data-language-panel]');
      if (language) showLanguage(language.dataset.languagePanel);
      invalid.reportValidity();
      return false;
    }
    if (['da','en'].some(lang => !bodyValue(lang).trim())) {
      showStep(1); status.textContent = text('INVALID_MESSAGE'); return false;
    }
    return true;
  }
  function sync() {
    form.querySelector('[data-action=save]').disabled = busy || !editable();
    form.querySelector('[data-action=preview]').disabled = busy || !draft || dirty || !editable();
    confirm.disabled = busy || !draft || dirty || draft.state !== 'tested';
    form.querySelector('[data-action=release]').disabled = confirm.disabled || !confirm.checked;
    form.querySelector('[data-action=cancel]').disabled = busy || !draft || dirty || !editable();
    form.querySelectorAll('[data-firstname]').forEach(el => {el.disabled = busy || !editable();});
    document.getElementById('ic-preview-subject').textContent = rendered?.subjects?.[previewLanguage] || form.elements['subject_' + previewLanguage].value;
    document.getElementById('ic-preview-sender').textContent = form.elements.sender.value;
    document.querySelectorAll('.intercom [data-preview-lang]').forEach(el => el.setAttribute('aria-pressed', el.dataset.previewLang === previewLanguage));
    const groups = Array.from(form.elements['tags[]'].selectedOptions).map(el => el.textContent.trim());
    const memberships = Array.from(form.elements['memberships[]'].selectedOptions).map(el => el.textContent.trim());
    document.getElementById('ic-audience-summary').textContent = [...groups, ...memberships].join(', ') || text(initial.allAudience ? 'ALL_AUDIENCE' : 'NO_GROUPS');
    if (rendered && frame.srcdoc !== rendered[previewLanguage]) frame.srcdoc = rendered[previewLanguage];
  }
  function scheduleRender() {
    // Immediately discard a stale preview while a changed message is being rendered.
    rendered = null; frame.removeAttribute('srcdoc');
    const sequence = ++renderSequence;
    clearTimeout(renderTimer);
    renderTimer = setTimeout(async () => {
      const data = message();
      if (!data.type) return;
      const payload = new FormData(form);
      payload.set('task','api.render'); payload.set('message',JSON.stringify(data));
      try {
        const response = await fetch(form.action,{method:'POST',body:payload,headers:{Accept:'application/json'}});
        const result = await response.json();
        if (sequence !== renderSequence) return;
        if (!response.ok || !result.success) return;
        rendered = result.data; sync();
      } catch { /* Saving reports validation/provider errors; rendering never changes draft state. */ }
    }, 400);
  }
  function changed() {
    dirty = true; confirm.checked = false; status.textContent = text('DIRTY');
    sync(); scheduleRender();
  }
  form.querySelectorAll('[data-step],[data-go]').forEach(button => button.addEventListener('click', () => showStep(button.dataset.step ?? button.dataset.go)));
  form.querySelectorAll('[data-edit-lang]').forEach(button => button.addEventListener('click', () => {showLanguage(button.dataset.editLang); previewLanguage = editLanguage; sync();}));
  document.querySelectorAll('.intercom [data-preview-lang]').forEach(button => button.addEventListener('click', () => {previewLanguage = button.dataset.previewLang; sync();}));
  form.querySelectorAll('[data-firstname]').forEach(button => button.addEventListener('click', () => {
    const lang = button.dataset.firstname;
    const placeholder = lang === 'da' ? '{FIRSTNAME[std:Medlem]}' : '{FIRSTNAME[std:Member]}';
    const editor = JoomlaEditor.get('body_' + lang);
    if (editor) editor.replaceSelection(placeholder);
    else {
      const textarea = form.elements['body_' + lang];
      textarea.setRangeText(placeholder, textarea.selectionStart, textarea.selectionEnd, 'end');
    }
    changed();
  }));
  showStep(0); showLanguage('da');
  form.addEventListener('submit', e => e.preventDefault());
  form.addEventListener('input', e => {
    if (e.target.classList.contains('choices__input')) return;
    if (![confirm, form.elements.send_at].includes(e.target)) changed(); else sync();
  });
  form.addEventListener('change', e => {
    if (e.target.matches('select,input[type=radio]')) changed(); else sync();
  });
  // Joomla editor providers expose values through one API; this also handles iframe editors.
  // Initial provider normalisation is recorded without invalidating an unchanged tested draft.
  const previous = new Map();
  setInterval(() => {
    if (document.hidden) return;
    for (const lang of ['da','en']) {
      const editor = JoomlaEditor.get('body_' + lang);
      if (!editor) continue;
      const value = editor.getValue();
      if (previous.has(lang) && value !== previous.get(lang)) changed();
      previous.set(lang,value);
    }
  }, 300);
  window.addEventListener('beforeunload', e => {if (dirty && form.elements.subject_da.value) {e.preventDefault(); e.returnValue = '';}});
  form.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', async () => {
    const action = button.dataset.action;
    if (busy) return;
    // Catch an iframe editor change even when this click precedes the polling tick.
    for (const lang of ['da','en']) {
      if (previous.has(lang) && bodyValue(lang) !== previous.get(lang)) changed();
    }
    if (action !== 'save' && dirty) return;
    if (action === 'save' && !validForm()) return;
    if (action === 'release' && !window.confirm(text('CONFIRM_SEND'))) return;
    const body = new FormData(form);
    body.set('task','api.' + action);
    body.set('id',draft?.id || 0); body.set('revision',draft?.revision || 0);
    const submittedMessage = JSON.stringify(message());
    if (action === 'save') body.set('message',submittedMessage);
    body.set('confirm',confirm.checked ? '1' : '0');
    body.set('send_at',form.elements.send_at.value ? Math.floor(new Date(form.elements.send_at.value).getTime()/1000) : 0);
    if (action === 'save') for (const lang of ['da','en']) previous.set(lang,bodyValue(lang));
    busy = true; sync();
    try {
      const response = await fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || text('ERROR'));
      if (result.data) draft = result.data;
      if (action === 'cancel') draft.state = 'cancelled';
      dirty = submittedMessage !== JSON.stringify(message()); confirm.checked = false;
      if (action === 'save') { if (!dirty) showStep(2); scheduleRender(); }
      status.textContent = dirty ? text('DIRTY') : text(action === 'preview' ? (initial.simulation ? 'FAKE_TESTED' : 'TESTED') : action === 'release' ? (initial.simulation ? 'FAKE_SUBMITTED' : 'SUBMITTED') : 'SAVED');
    } catch (e) {status.textContent = e.message || text('ERROR'); if (action === 'release' && draft) draft.state = 'uncertain';}
    finally {busy = false; sync();}
  }));
  sync(); scheduleRender();
})();
