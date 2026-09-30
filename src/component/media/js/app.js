import { JoomlaEditor } from 'editor-api';
import { PreviewScheduler, requiresTeam, subjectLabel } from './preview.mjs';

(() => {
  'use strict';
  const form = document.getElementById('ic-form');
  if (!form) return;
  const initial = Joomla.getOptions('com_intercom', {});
  let draft = initial.draft || null, dirty = !draft, busy = false;
  let step = 0, editLanguage = initial.language || 'da', previewLanguage = editLanguage, previewTheme = 'light';
  let rendered = null;
  const status = document.getElementById('ic-status');
  const confirm = document.getElementById('ic-confirm');
  const frame = document.getElementById('ic-preview-frame');
  const previewStatus = document.getElementById('ic-preview-status');
  const groupError = document.getElementById('ic-group-error');
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
  function needsTeam() {
    return requiresTeam(initial.types?.[form.elements.type.value], Array.from(form.elements['tags[]'].selectedOptions).map(el => el.value), initial.availableTeams || []);
  }
  function showStep(value) {
    if (Number(value) > 0 && needsTeam()) {
      groupError.hidden = false;
      const select = form.elements['tags[]'];
      const target = select.closest('joomla-field-fancy-select').querySelector('.choices__input') || select;
      target.setAttribute('aria-invalid', 'true'); target.setAttribute('aria-describedby', 'ic-group-error');
      target.focus();
      return false;
    }
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
    if (needsTeam()) {showStep(0); showStep(1); return false;}
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
    document.getElementById('ic-preview-subject').textContent = subjectLabel(initial.types?.[form.elements.type.value]?.prefixes?.[previewLanguage], form.elements['subject_' + previewLanguage].value);
    document.getElementById('ic-preview-sender').textContent = form.elements.sender.value;
    document.querySelectorAll('.intercom [data-preview-lang]').forEach(el => el.setAttribute('aria-pressed', el.dataset.previewLang === previewLanguage));
    const groups = Array.from(form.elements['tags[]'].selectedOptions).map(el => el.textContent.trim());
    const memberships = Array.from(form.elements['memberships[]'].selectedOptions).map(el => el.textContent.trim());
    document.getElementById('ic-audience-summary').textContent = [...groups, ...memberships].join(', ') || text(initial.allAudience ? 'ALL_AUDIENCE' : 'NO_GROUPS');
    document.querySelectorAll('.intercom [data-preview-theme]').forEach(el => el.setAttribute('aria-pressed', String(el.dataset.previewTheme === previewTheme)));
    const html = rendered?.[previewLanguage + (previewTheme === 'dark' ? '_dark' : '')];
    if (html && frame.srcdoc !== html) frame.srcdoc = html;
    if (!needsTeam()) {
      groupError.hidden = true;
      form.querySelectorAll('[aria-describedby="ic-group-error"]').forEach(el => {el.removeAttribute('aria-invalid'); el.removeAttribute('aria-describedby');});
    }
  }
  const scheduler = new PreviewScheduler(async () => {
    const data = message();
    if (!data.type || needsTeam()) throw new Error(text('GROUP_REQUIRED'));
    const payload = new FormData(form);
    payload.set('task','api.render'); payload.set('message',JSON.stringify(data));
    const response = await fetch(form.action,{method:'POST',body:payload,headers:{Accept:'application/json'}});
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.error || text('ERROR'));
    return result.data;
  }, (result, error) => {
    if (result) {rendered = result; sync();}
    previewStatus.textContent = error ? text('PREVIEW_OUTDATED') + ' ' + error.message : text('PREVIEW_UPDATED');
  }, pending => {
    frame.setAttribute('aria-busy', String(pending));
    if (pending) previewStatus.textContent = text('PREVIEW_UPDATING');
  });
  function scheduleRender(immediate = false) { scheduler.schedule(immediate); }
  function changed(render = true) {
    dirty = true; confirm.checked = false; status.textContent = text('DIRTY');
    sync(); if (render) scheduleRender();
  }
  // Joomla's multi-select does not toggle closed when its existing trigger is clicked.
  form.querySelectorAll('joomla-field-fancy-select').forEach(field => {
    let closeOnClick = false;
    const trigger = target => target.closest('.choices__inner') && !target.closest('button') && !(target.matches('.choices__input') && target.value);
    field.addEventListener('pointerdown', event => {
      closeOnClick = !!field.choicesInstance?.dropdown.isActive && !!trigger(event.target);
    }, true);
    field.addEventListener('mousedown', event => {
      if (closeOnClick) {event.preventDefault(); event.stopPropagation();}
    }, true);
    field.addEventListener('click', event => {
      if (closeOnClick && trigger(event.target)) {
        event.preventDefault(); event.stopPropagation(); field.choicesInstance.hideDropdown(true);
      }
      closeOnClick = false;
    }, true);
  });
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
  document.querySelectorAll('.intercom [data-preview-theme]').forEach(button => button.addEventListener('click', () => {previewTheme = button.dataset.previewTheme; sync();}));
  showStep(0); showLanguage(editLanguage);
  form.addEventListener('submit', e => e.preventDefault());
  form.addEventListener('input', e => {
    if (e.target.classList.contains('choices__input')) return;
    if (![confirm, form.elements.send_at].includes(e.target)) changed(e.target.name?.startsWith('body_')); else sync();
  });
  form.addEventListener('change', e => {
    if (e.target.matches('select,input[type=radio]')) changed(['type','tags[]'].includes(e.target.name)); else sync();
  });
  // Joomla editor providers expose values through one API; this also handles iframe editors.
  // Initial provider normalisation is recorded without invalidating an unchanged tested draft.
  const previous = new Map();
  setInterval(() => {
    if (document.hidden) return;
    let firstProvider = false;
    for (const lang of ['da','en']) {
      const editor = JoomlaEditor.get('body_' + lang);
      if (!editor) continue;
      const value = editor.getValue();
      if (!previous.has(lang) && value.trim()) firstProvider = true;
      if (previous.has(lang) && value !== previous.get(lang)) changed();
      previous.set(lang,value);
    }
    // Providers may initialise after the first request; refresh their baseline without dirtying a saved draft.
    if (firstProvider) scheduleRender(true);
  }, 300);
  window.addEventListener('beforeunload', e => {if (dirty && form.elements.subject_da.value) {e.preventDefault(); e.returnValue = '';}});
  form.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', async () => {
    const action = button.dataset.action;
    if (busy) return;
    // Catch an iframe editor change even when this click precedes the polling tick.
    for (const lang of ['da','en']) {
      const loaded = initial.editorBodies?.[lang];
      const baseline = previous.has(lang) ? previous.get(lang) : loaded;
      if (baseline !== undefined && bodyValue(lang) !== baseline) changed();
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
  sync(); scheduleRender(true);
})();
