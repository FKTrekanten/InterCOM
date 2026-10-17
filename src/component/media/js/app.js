import { JoomlaEditor } from 'editor-api';
import { DraftCache, composerControls, composerSendBlocker } from './draft-cache.mjs';
import { PreviewScheduler, requiresTeam, subjectLabel } from './preview.mjs';

(() => {
  'use strict';
  const form = document.getElementById('ic-form');
  if (!form) return;
  const initial = Joomla.getOptions('com_intercom', {});
  let draft = initial.draft || null, dirty = !draft, busy = false, statusUnknown = false;
  let step = 0, editLanguage = initial.language || 'da', previewLanguage = editLanguage, previewTheme = 'light';
  let rendered = null, audienceDirty = false, estimating = false, activeAt = Date.now();
  const audienceFields = ['type','tags','disciplines','memberships','age_from','age_to','gender'];
  const audienceKey = value => JSON.stringify(Object.fromEntries(audienceFields.map(k => [k, Array.isArray(value[k]) ? [...value[k]].sort() : (k === 'disciplines' ? [] : value[k])])));
  let savedAudience = initial.message ? audienceKey(initial.message) : '';
  let storage; try {storage = window.localStorage;} catch {}
  const cache = new DraftCache(storage, initial.cacheContext, initial.retentionDays);
  let cacheId = draft?.id || 0, cacheTimer, estimatePromise = null, restoredBodies = null;
  const localStatus = document.getElementById('ic-local-status');
  const persistLocal = () => {
    if (!editable() || !dirty) return;
    const ok = cache.save(cacheId, Number(draft?.revision || 0), message(), editLanguage, step);
    localStatus.textContent = text(ok ? 'LOCAL_SAVED' : 'LOCAL_UNAVAILABLE');
  };
  const rememberDraft = () => {
    if (!draft) return;
    document.getElementById('ic-new-message').hidden = false;
    if (cacheId !== draft.id) {cache.clear(cacheId); cacheId = draft.id;}
    const url = new URL(window.location.href); url.searchParams.delete('new'); url.searchParams.set('id',draft.id); window.history.replaceState(null,'',url);
    persistLocal();
  };
  const status = document.getElementById('ic-status');
  const confirm = document.getElementById('ic-confirm');
  const frame = document.getElementById('ic-preview-frame');
  const previewStatus = document.getElementById('ic-preview-status');
  const groupError = document.getElementById('ic-group-error');
  const text = key => Joomla.Text._('COM_INTERCOM_' + key);
  const editable = () => !statusUnknown && (!draft || ['draft','tested'].includes(draft.state));
  const statePanel = document.getElementById('ic-state-panel');
  let actionError = false;
  function report(message, error = false) {
    status.textContent = message;
    actionError = error;
    status.setAttribute('role', error ? 'alert' : 'status');
    status.setAttribute('aria-live', error ? 'assertive' : 'polite');
    if (error) {status.focus?.(); status.scrollIntoView?.({block:'nearest', behavior:'smooth'});}
    syncStatus();
  }
  function syncStatus() {
    const op = draft?.test_operation;
    const currentOp = op && Number(op.revision) === Number(draft.revision) ? op : null;
    const title = draft?.status_title || ('STATE_' + (draft?.state || 'draft').toUpperCase());
    const detail = draft?.status_detail || (draft?.state === 'tested' ? (initial.simulation ? 'FAKE_TESTED' : 'TESTED') : 'STATUS_DRAFT');
    document.getElementById('ic-state').textContent = text(title);
    const scheduled = draft?.state === 'scheduled' && Number(draft.send_at) ? ' · ' + new Date(Number(draft.send_at) * 1000).toLocaleString(initial.locale, {timeZone:initial.timezone || 'UTC',timeZoneName:'short'}) : '';
    const updated = currentOp?.updated_at ? ' · ' + new Date(currentOp.updated_at.replace(' ', 'T') + 'Z').toLocaleString(initial.locale, {timeZone:initial.timezone || 'UTC',timeZoneName:'short'}) : '';
    document.getElementById('ic-state-help').textContent = text(statusUnknown ? 'STATUS_UNKNOWN' : detail) + scheduled;
    document.getElementById('ic-mail-id').textContent = draft ? '#' + draft.id : '';
    document.getElementById('ic-operation').textContent = currentOp ? text('OPERATION_REFERENCE') + ': ' + currentOp.operation_id + updated : '';
    const operationDetails = document.getElementById('ic-operation-details');
    if (operationDetails) operationDetails.hidden = !currentOp;
    let result = '';
    if (currentOp && ['accepted','failed','uncertain'].includes(currentOp.outcome) && ['test_da','accepted_da','test_en','accepted_en','complete'].includes(currentOp.stage) && !initial.simulation) {
      result = ['da','en'].map(lang => text(Number(currentOp['accepted_' + lang]) ? 'TEST_ACCEPTED_' + lang.toUpperCase() : 'TEST_NOT_CONFIRMED') + (Number(currentOp['accepted_' + lang]) ? '' : ' (' + lang.toUpperCase() + ')')).join(' · ');
    }
    document.getElementById('ic-test-status').textContent = result || status.textContent || text(detail);
    statePanel?.setAttribute('data-severity', actionError || statusUnknown ? 'error' : (draft?.status_severity || 'info'));
    statePanel?.setAttribute('aria-busy', String(busy));
    const refresh = document.getElementById('ic-status-refresh');
    if (refresh) refresh.disabled = busy || !draft;
    document.querySelectorAll('.intercom [data-copy-id]').forEach(button => {button.disabled = busy;});
  }
  async function refreshStatus() {
    if (!draft) return false;
    const body = new FormData(form); body.set('task','api.status'); body.set('id',draft.id);
    try {
      const response = await fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || text('ERROR'));
      draft = result.data; statusUnknown = false; confirm.checked = false;
      if (draft.state !== 'uncertain') document.getElementById('ic-recovery')?.remove?.();
      sync(); return true;
    } catch {
      statusUnknown = true; confirm.checked = false; sync(); return false;
    }
  }
  const mailboxPages = new Map();
  let mailboxRequest = 0;
  const mailboxKey = url => JSON.stringify(['box','mail_search','mail_state','mail_page'].map(key => url.searchParams.get(key) || (key === 'box' ? 'drafts' : key === 'mail_page' ? '1' : '')));
  function cacheMailbox(page, url) {
    const updated = Date.now();
    const current = page.getElementById('ic-mailbox');
    if (current) mailboxPages.set(mailboxKey(url), {node:current.cloneNode(true), updated});
    page.querySelectorAll('template[data-mailbox-url]').forEach(template => {
      const node = template.content.querySelector('#ic-mailbox');
      if (node) mailboxPages.set(mailboxKey(new URL(template.dataset.mailboxUrl, url)), {node:node.cloneNode(true), updated});
    });
  }
  if (initial.mailbox) cacheMailbox(document, new URL(window.location.href));
  async function loadMailbox(url, force = false) {
    const request = ++mailboxRequest;
    const focused = document.getElementById('ic-mailbox')?.contains(document.activeElement);
    document.getElementById('ic-mailbox')?.setAttribute('aria-busy','true');
    try {
      let entry = mailboxPages.get(mailboxKey(url));
      if (force || !entry || Date.now() - entry.updated > 60000) {
        const response = await fetch(url,{headers:{Accept:'text/html'}});
        if (!response.ok) throw new Error(text('ERROR'));
        const page = new DOMParser().parseFromString(await response.text(),'text/html');
        if (!page.getElementById('ic-mailbox')) throw new Error(text('ERROR'));
        if (request !== mailboxRequest) return;
        cacheMailbox(page, url);
        entry = mailboxPages.get(mailboxKey(url));
      }
      if (request !== mailboxRequest) return;
      const replacement = entry.node.cloneNode(true);
      replacement.removeAttribute('aria-busy');
      document.getElementById('ic-mailbox')?.replaceWith(replacement);
      // Mailbox requests may outlive the first audience save. Merge only list
      // navigation into the current composer URL, preserving its latest draft.
      const currentUrl = new URL(window.location.href);
      for (const key of ['box','mail_search','mail_state','mail_page']) {
        if (url.searchParams.has(key)) currentUrl.searchParams.set(key,url.searchParams.get(key));
        else currentUrl.searchParams.delete(key);
      }
      if (draft) {currentUrl.searchParams.set('id',draft.id); currentUrl.searchParams.delete('new');}
      window.history.replaceState(null,'',currentUrl);
      syncStatus();
      if (focused) replacement.querySelector('.ic-mail-tabs [aria-current=page]')?.focus({preventScroll:true});
    } catch (error) {
      if (request === mailboxRequest) report(error.message || text('ERROR'),true);
    } finally {
      if (request === mailboxRequest) document.getElementById('ic-mailbox')?.removeAttribute('aria-busy');
    }
  }
  async function refreshMailbox(box) {
    if (!initial.mailbox) return;
    mailboxPages.clear();
    const url = new URL(window.location.href);
    if (box) {url.searchParams.set('box',box); url.searchParams.delete('mail_state'); url.searchParams.delete('mail_search'); url.searchParams.delete('mail_page');}
    await loadMailbox(url, true);
  }
  document.addEventListener('click', event => {
    const link = event.target.closest?.('#ic-mailbox .ic-mail-tabs a, #ic-mailbox .ic-mail-pages a');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    const url = new URL(link.href);
    if (draft) url.searchParams.set('id',draft.id);
    url.searchParams.delete('new');
    void loadMailbox(url);
  });
  document.addEventListener('submit', event => {
    if (!event.target.matches?.('#ic-mailbox .ic-mail-search')) return;
    event.preventDefault();
    const url = new URL(window.location.href);
    for (const [key, value] of new FormData(event.target)) url.searchParams.set(key,value);
    if (draft) url.searchParams.set('id',draft.id);
    url.searchParams.delete('new');
    url.searchParams.delete('mail_page');
    void loadMailbox(url, true);
  });
  document.getElementById('ic-status-refresh')?.addEventListener('click', async () => {
    if (busy) return;
    busy = true; sync(); const ok = await refreshStatus(); busy = false;
    report(ok ? '' : text('STATUS_UNKNOWN'), !ok); sync();
    if (ok) await refreshMailbox();
  });
  const copyKeys = new Map();
  document.addEventListener('click', async event => {
    const button = event.target.closest?.('.intercom [data-copy-id]');
    if (!button || busy || estimating) return;
    if (dirty && !window.confirm(text('COPY_SAVED'))) return;
    const source = button.dataset.copyId;
    if (!copyKeys.has(source)) copyKeys.set(source,Array.from(crypto.getRandomValues(new Uint8Array(16)), v => v.toString(16).padStart(2,'0')).join(''));
    const body = new FormData(form); body.set('task','api.copy'); body.set('id',source); body.set('request_key',copyKeys.get(source));
    busy = true; sync(); report(text('COPYING'));
    try {
      const response = await fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || text('ERROR'));
      persistLocal(); dirty = false;
      const url = new URL(initial.composerUrl,window.location.href); url.searchParams.set('id',result.data.id); url.searchParams.set('box','drafts'); url.searchParams.delete('new');
      window.location.assign(url);
    } catch (error) {report(error.message || text('ERROR'),true);}
    finally {busy = false; sync();}
  });
  document.getElementById('ic-recover')?.addEventListener('click', async () => {
    if (busy || !draft || !document.getElementById('ic-recovery-confirm')?.checked) return;
    const body = new FormData(form); body.set('task','api.recover'); body.set('id',draft.id); body.set('revision',draft.revision); body.set('legacy_verified','1');
    busy = true; sync();
    try {
      const response = await fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || text('ERROR'));
      draft = result.data; dirty = false; cache.clear(cacheId); document.getElementById('ic-recovery')?.remove();
      report(text('STATUS_DRAFT')); await refreshMailbox('drafts');
    } catch (error) {report(error.message || text('ERROR'),true);}
    finally {busy = false; sync();}
  });
  const getEditor = id => JoomlaEditor.get(id) || Joomla.editors?.instances?.[id] || null;
  const bodyValue = lang => getEditor('body_' + lang)?.getValue() ?? form.elements['body_' + lang].value;
  for (const [key, value] of Object.entries(initial.message || {})) {
    // Editor values are rendered by Joomla on the server, including conversion of old plain-text drafts.
    if (key.startsWith('body_')) continue;
    if (Array.isArray(value)) {
      const select = form.querySelector(`select[name="${key}[]"]`);
      if (select) Array.from(select.options).forEach(el => { el.selected = value.includes(el.value); });
    } else if (form.elements.namedItem(key)) form.elements.namedItem(key).value = value;
  }
  function message() {
    const result = Object.fromEntries(['type','sender','subject_da','subject_en','gender'].map(k => [k, form.elements[k].value]));
    result.format = 'html';
    for (const lang of ['da','en']) result['body_' + lang] = bodyValue(lang);
    for (const k of ['tags','disciplines','memberships']) result[k] = Array.from(form.elements[k + '[]'].selectedOptions).map(option => option.value);
    for (const k of ['age_from','age_to']) result[k] = Number(form.elements[k].value || 0);
    return result;
  }
  function markEditorsSaved() {
    // Joomla's TinyMCE decorator exposes the provider. Keep its navigation warning
    // in sync only after these exact editor values have been saved successfully.
    for (const lang of ['da','en']) getEditor('body_' + lang)?.instance?.setDirty?.(false);
  }
  function needsTeam() {
    return requiresTeam(initial.types?.[form.elements.type.value], ['tags','disciplines'].flatMap(k => Array.from(form.elements[k + '[]'].selectedOptions).map(el => el.value)), initial.availableTeams || []);
  }
  function showStep(value) {
    if (editable() && Number(value) > 0 && needsTeam()) {
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
    const sendState = {busy:busy || statusUnknown,dirty,audienceDirty,estimating,approved:initial.releaseApproved};
    const controls = composerControls(draft, sendState);
    form.querySelector('[data-action=save]').disabled = !controls.save;
    form.querySelector('[data-action=preview]').disabled = !controls.preview;
    confirm.disabled = !controls.confirm;
    form.querySelector('[data-action=release]').disabled = confirm.disabled || !confirm.checked;
    form.querySelector('[data-action=delete]').disabled = !controls.delete;
    const restore = form.querySelector('[data-action=restore]');
    restore.hidden = draft?.state !== 'deleted'; restore.disabled = busy;
    form.querySelector('[data-action=cancel]').disabled = busy || !draft || dirty || !editable();
    form.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),textarea').forEach(el => {el.readOnly = !editable();});
    form.querySelectorAll('[data-panel] select,[data-panel] input[type=radio]').forEach(el => {el.disabled = !editable();});
    form.querySelectorAll('joomla-field-fancy-select').forEach(el => {if (editable()) el.choicesInstance?.enable?.(); else el.choicesInstance?.disable?.();});
    syncStatus();
    form.querySelectorAll('[data-firstname]').forEach(el => {el.disabled = busy || !editable();});
    document.getElementById('ic-preview-subject').textContent = subjectLabel(initial.types?.[form.elements.type.value]?.prefixes?.[previewLanguage], form.elements['subject_' + previewLanguage].value);
    document.getElementById('ic-preview-sender').textContent = form.elements.sender.value;
    document.querySelectorAll('.intercom [data-preview-lang]').forEach(el => el.setAttribute('aria-pressed', el.dataset.previewLang === previewLanguage));
    const estimate = document.getElementById('ic-estimate');
    if (estimate) {
      const checked = draft?.estimate_checked ? new Date(draft.estimate_checked.replace(' ', 'T') + 'Z') : null;
      const stale = audienceDirty || !checked || Date.now() - checked.getTime() > (initial.estimateMinutes || 5) * 60000;
      estimate.textContent = estimating ? text('ESTIMATE_LOADING') : draft?.estimate_error ? text('ESTIMATE_UNAVAILABLE') : draft?.estimate_count != null ? text(initial.simulation ? 'ESTIMATE_SIMULATED' : 'ESTIMATE') + ': ' + draft.estimate_count + (stale ? ' · ' + text('ESTIMATE_STALE') : '') + (checked ? ' · ' + text('ESTIMATE_CHECKED') + ' ' + checked.toLocaleString(initial.locale, {timeZone:initial.timezone || 'UTC'}) : '') : text('ESTIMATE_UNAVAILABLE');
      estimate.setAttribute('aria-busy', String(estimating));
      form.querySelectorAll('[data-estimate-line]').forEach(el => {el.textContent = estimate.textContent; el.setAttribute('aria-busy',String(estimating));});
    }
    const refresh = document.getElementById('ic-estimate-refresh');
    form.querySelectorAll('[data-refresh-estimate]').forEach(el => {el.disabled = busy || !editable() || needsTeam();});
    if (refresh) refresh.disabled = busy || !editable() || needsTeam();
    const blocker = composerSendBlocker(draft, sendState);
    const acceptanceNotice = document.getElementById('ic-acceptance-notice');
    acceptanceNotice.hidden = Boolean(initial.releaseApproved);
    acceptanceNotice.textContent = text('RELEASE_NOT_VERIFIED');
    document.getElementById('ic-send-help').textContent = blocker && blocker !== 'RELEASE_NOT_VERIFIED' ? text(blocker) : '';
    const groups = Array.from(form.elements['tags[]'].selectedOptions).map(el => el.textContent.trim());
    const disciplines = Array.from(form.elements['disciplines[]'].selectedOptions).map(el => el.textContent.trim());
    const memberships = Array.from(form.elements['memberships[]'].selectedOptions).map(el => el.textContent.trim());
    document.getElementById('ic-audience-summary').textContent = [...groups, ...disciplines, ...memberships, ...memberCriteria()].join(', ') || text(initial.allAudience ? 'ALL_AUDIENCE' : 'NO_GROUPS');
    document.querySelectorAll('.intercom [data-preview-theme]').forEach(el => el.setAttribute('aria-pressed', String(el.dataset.previewTheme === previewTheme)));
    const html = rendered?.[previewLanguage + (previewTheme === 'dark' ? '_dark' : '')];
    if (html && frame.srcdoc !== html) frame.srcdoc = html;
    if (!needsTeam()) {
      groupError.hidden = true;
      form.querySelectorAll('[aria-describedby="ic-group-error"]').forEach(el => {el.removeAttribute('aria-invalid'); el.removeAttribute('aria-describedby');});
    }
  }
  function memberCriteria() {
    const data = message(), parts = [];
    if (data.age_from || data.age_to) parts.push(text('AGES') + ' ' + (data.age_from && !data.age_to ? data.age_from + '+' : data.age_from + '-' + data.age_to));
    if (data.gender) parts.push(text(data.gender === 'female' ? 'FEMALE' : 'MALE'));
    return parts;
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
    activeAt = Date.now(); audienceDirty = audienceKey(message()) !== savedAudience;
    if (!editable()) return;
    dirty = true; confirm.checked = false; if (!actionError) status.textContent = text('DIRTY');
    clearTimeout(cacheTimer); cacheTimer = setTimeout(persistLocal,500);
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
  function estimateAudience(force = false) {
    if (estimatePromise) return estimatePromise;
    estimatePromise = runEstimate(force).finally(() => {estimatePromise = null;});
    return estimatePromise;
  }
  async function runEstimate(force) {
    if (busy || !editable() || needsTeam()) return;
    const snapshot = message(), key = audienceKey(snapshot);
    const payload = new FormData(form);
    payload.set('task', 'api.saveaudience'); payload.set('message', JSON.stringify(snapshot));
    payload.set('id', draft?.id || 0); payload.set('revision', draft?.revision || 0);
    confirm.checked = false; estimating = true; sync();
    try {
      const response = await fetch(form.action, {method:'POST', body:payload, headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || text('ERROR'));
      draft = result.data; savedAudience = key; audienceDirty = audienceKey(message()) !== key;
      rememberDraft();
      payload.set('force',force ? '1' : '0'); payload.set('task', 'api.estimate'); payload.set('id', draft.id); payload.set('revision', draft.revision);
      // Exactly one count request. Persist the local/server draft identity before slow provider work.
      const refreshed = await fetch(form.action, {method:'POST',body:payload,headers:{Accept:'application/json'}});
      const count = await refreshed.json();
      if (!refreshed.ok || !count.success) throw new Error(count.error || text('ERROR'));
      draft = count.data; rememberDraft();
      if (!actionError) status.textContent = draft.estimate_error ? text('ESTIMATE_UNAVAILABLE') : text('SAVED');
    } catch (error) {
      status.textContent = error.message || text('ESTIMATE_UNAVAILABLE');
      if (draft) draft.estimate_error = true;
    } finally {estimating = false; sync();}
  }
  form.querySelectorAll('[data-step],[data-go]').forEach(button => button.addEventListener('click', async () => {
    const value = Number(button.dataset.step ?? button.dataset.go), wasRecipients = step === 0;
    if (busy || showStep(value) === false) return;
    if (value > 0 && (wasRecipients || audienceDirty) && editable()) await estimateAudience();
  }));
  form.querySelectorAll('[data-refresh-estimate]').forEach(button => button.addEventListener('click', () => estimateAudience(true)));
  document.getElementById('ic-estimate-refresh')?.addEventListener('click', () => estimateAudience(true));
  setInterval(() => {
    if (document.hidden || busy || estimating || !draft || Date.now() - activeAt > 300000 || Number(draft.mailing_attempted) !== 0) return;
    const body = new FormData(form); body.set('task','api.keepalive'); body.set('id',draft.id); body.set('revision',draft.revision);
    fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'}}).catch(() => {});
    sync();
  }, 60000);
  form.querySelectorAll('[data-edit-lang]').forEach(button => button.addEventListener('click', () => {showLanguage(button.dataset.editLang); previewLanguage = editLanguage; sync();}));
  document.querySelectorAll('.intercom [data-preview-lang]').forEach(button => button.addEventListener('click', () => {previewLanguage = button.dataset.previewLang; sync();}));
  form.querySelectorAll('[data-firstname]').forEach(button => button.addEventListener('click', () => {
    const lang = button.dataset.firstname;
    const placeholder = lang === 'da' ? '{FIRSTNAME[std:Medlem]}' : '{FIRSTNAME[std:Member]}';
    const editor = getEditor('body_' + lang);
    if (editor) editor.replaceSelection(placeholder);
    else {
      const textarea = form.elements['body_' + lang];
      textarea.setRangeText(placeholder, textarea.selectionStart, textarea.selectionEnd, 'end');
    }
    changed();
  }));
  document.querySelectorAll('.intercom [data-preview-theme]').forEach(button => button.addEventListener('click', () => {previewTheme = button.dataset.previewTheme; sync();}));
  let recoveredStep = 0;
  const recovery = editable() && !initial.fresh ? cache.load(cacheId, Number(draft?.revision || 0)) : null;
  if (!editable()) cache.clear(cacheId);
  if (recovery) {
    const recovered = recovery.message;
    const allowed = ['tags','disciplines','memberships'].every(k => recovered[k].every(v => Array.from(form.elements[k + '[]'].options).some(o => o.value === v)))
      && Array.from(form.querySelectorAll('input[name=type]')).some(r => r.value === recovered.type);
    if (allowed) {
      for (const [key,value] of Object.entries(recovered)) {
        if (Array.isArray(value)) {
          const select = form.elements[key + '[]'];
          Array.from(select.options).forEach(o => {o.selected = value.includes(o.value);});
          const choices = select.closest('joomla-field-fancy-select')?.choicesInstance;
          if (choices) {choices.removeActiveItems(); choices.setChoiceByValue(value);}
        }
        else if (form.elements.namedItem(key)) form.elements.namedItem(key).value = value;
      }
      recoveredStep = recovery.step;
      restoredBodies = {da:recovered.body_da,en:recovered.body_en};
      for (const lang of ['da','en']) {const editor = getEditor('body_' + lang); if (editor) {editor.setValue(restoredBodies[lang]); delete restoredBodies[lang];}}
      dirty = JSON.stringify(recovered) !== JSON.stringify(initial.message || {});
      audienceDirty = audienceKey(recovered) !== savedAudience;
      editLanguage = recovery.language === 'da' ? 'da' : 'en'; previewLanguage = editLanguage;
      localStatus.textContent = text('LOCAL_RECOVERED');
    }
  }
  showStep(recoveredStep); showLanguage(editLanguage);
  form.addEventListener('submit', e => e.preventDefault());
  form.addEventListener('input', e => {
    if (e.target.classList.contains('choices__input')) return;
    if (![confirm, form.elements.send_at].includes(e.target)) changed(e.target.name?.startsWith('body_')); else sync();
  });
  form.addEventListener('change', e => {
    if (e.target.matches('select,input[type=radio]')) changed(['type','tags[]'].includes(e.target.name)); else sync();
  });
  // Modern and legacy Joomla editor APIs expose live values, including iframe editors.
  // Initial provider normalisation is recorded without invalidating an unchanged tested draft.
  const previous = new Map(), editorModes = new Map();
  setInterval(() => {
    if (document.hidden) return;
    let firstProvider = false;
    for (const lang of ['da','en']) {
      const editor = getEditor('body_' + lang);
      if (!editor) continue;
      const mode = editable() ? 'design' : 'readonly';
      if (editor.instance?.mode?.set && editorModes.get(editor) !== mode) {editor.instance.mode.set(mode); editorModes.set(editor,mode);}
      if (restoredBodies && Object.hasOwn(restoredBodies,lang)) {editor.setValue(restoredBodies[lang]); delete restoredBodies[lang]; previous.set(lang,editor.getValue());}
      const value = editor.getValue();
      if (!previous.has(lang) && value.trim()) firstProvider = true;
      if (previous.has(lang) && value !== previous.get(lang)) changed();
      previous.set(lang,value);
    }
    // Providers may initialise after the first request; refresh their baseline without dirtying a saved draft.
    if (firstProvider) scheduleRender(true);
  }, 300);
  document.addEventListener('visibilitychange', () => {if (document.hidden) persistLocal();});
  window.addEventListener('pagehide',persistLocal);
  window.addEventListener('beforeunload', e => {persistLocal(); if (dirty && ['subject_da','subject_en','body_da','body_en'].some(k => message()[k].trim())) {e.preventDefault(); e.returnValue = '';}});
  function confirmDelete() {
    const dialog = document.getElementById('ic-delete-dialog');
    dialog.returnValue = 'cancel';
    return new Promise(resolve => {
      dialog.addEventListener('close', () => resolve(dialog.returnValue === 'delete'), {once:true});
      dialog.showModal();
    });
  }
  form.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', async () => {
    const action = button.dataset.action;
    if (busy) return;
    if (estimatePromise) {status.textContent = text('ESTIMATE_LOADING'); await estimatePromise;}
    if (busy || (!editable() && !['delete','restore'].includes(action))) return;
    // Catch an iframe editor change even when this click precedes the polling tick.
    for (const lang of ['da','en']) {
      const loaded = initial.editorBodies?.[lang];
      const baseline = previous.has(lang) ? previous.get(lang) : loaded;
      if (baseline !== undefined && bodyValue(lang) !== baseline) changed();
    }
    if (!['save','preview','delete','restore'].includes(action) && dirty) return;
    if (action === 'delete' && !await confirmDelete()) return;
    if (action === 'save' && needsTeam()) {showStep(0); showStep(1); return;}
    if (action === 'preview' && !validForm()) return;
    if (action === 'delete' && !draft) {cache.clear(cacheId); dirty = false; window.location.assign(initial.composerUrl); return;}
    if (action === 'release' && !window.confirm(text('CONFIRM_SEND'))) return;
    const body = new FormData(form);
    body.set('task','api.' + action);
    body.set('id',draft?.id || 0); body.set('revision',draft?.revision || 0);
    const submittedMessage = JSON.stringify(message());
    if (action === 'save') body.set('message',submittedMessage);
    body.set('approved_count', draft?.estimate_count ?? -1);
    body.set('confirm',confirm.checked ? '1' : '0');
    body.set('send_at',form.elements.send_at.value ? Math.floor(new Date(form.elements.send_at.value).getTime()/1000) : 0);
    // Use the exact submitted editor values as the baseline for both save and test.
    // A later polling tick must not mistake already-tested edits for new changes.
    if (['save','preview'].includes(action)) for (const lang of ['da','en']) previous.set(lang,bodyValue(lang));
    busy = true; sync();
    if (action === 'preview') report(text('PREPARING_TEST'));
    let authoritative = false;
    try {
      if (action === 'preview' && (dirty || !draft)) {
        const save = new FormData(form); save.set('task','api.save'); save.set('message',submittedMessage);
        save.set('id',draft?.id || 0); save.set('revision',draft?.revision || 0);
        const savedResponse = await fetch(form.action,{method:'POST',body:save,headers:{Accept:'application/json'}});
        const savedResult = await savedResponse.json();
        if (!savedResponse.ok || !savedResult.success) throw new Error(savedResult.error || text('ERROR'));
        draft = savedResult.data; savedAudience = audienceKey(JSON.parse(submittedMessage)); audienceDirty = audienceKey(message()) !== savedAudience; dirty = submittedMessage !== JSON.stringify(message()); rememberDraft();
        if (dirty) throw new Error(text('DIRTY'));
        markEditorsSaved();
        body.set('id',draft.id); body.set('revision',draft.revision);
      }
      const response = await fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.success) {
        if (result.data) {draft = result.data; authoritative = true;} confirm.checked = false;
        throw new Error(result.error || text('ERROR'));
      }
      if (action === 'delete') {cache.clear(cacheId); dirty = false; window.location.assign(initial.composerUrl); return;}
      if (result.data) draft = result.data;
      if (action === 'restore') {cache.clear(cacheId); dirty = false; window.location.reload(); return;}
      if (action === 'cancel') draft.state = 'cancelled';
      dirty = submittedMessage !== JSON.stringify(message()); confirm.checked = false;
      rememberDraft();
      if (!dirty) {cache.clear(cacheId); localStatus.textContent = ''; if (['save','preview'].includes(action)) markEditorsSaved();}
      if (action === 'save') {savedAudience = audienceKey(JSON.parse(submittedMessage)); audienceDirty = audienceKey(message()) !== savedAudience; scheduleRender(); }
      report(dirty ? text('DIRTY') : text(action === 'preview' ? (initial.simulation ? 'FAKE_TESTED' : 'TESTED') : action === 'release' ? (initial.simulation ? 'FAKE_SUBMITTED' : 'SUBMITTED') : 'SAVED'));
      await refreshMailbox(action === 'release' ? 'sent' : action === 'cancel' ? 'closed' : undefined);
    } catch (e) {
      if (!authoritative && draft && ['preview','release'].includes(action)) await refreshStatus();
      report(e.message || text('ERROR'),true); confirm.checked = false;
    }
    finally {busy = false; sync();}
  }));
  sync(); scheduleRender(true);
})();
