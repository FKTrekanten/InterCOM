// Local recovery never represents a saved server revision or a successful send test.
export class DraftCache {
  constructor(storage, context, days = 30, clock = Date) {
    this.storage = storage; this.prefix = 'intercom.draft.v1.' + context + '.';
    this.ttl = Math.max(1, Math.min(3650, Number(days) || 30)) * 86400000; this.clock = clock;
  }
  load(id, revision) {
    try {
      const value = JSON.parse(this.storage?.getItem(this.prefix + id) || 'null');
      if (!value) return null;
      if (value.version !== 1 || !Number.isFinite(value.at) || value.at > this.clock.now() || this.clock.now() - value.at > this.ttl) {
        this.clear(id); return null;
      }
      if (value.revision !== revision) return null; // Never replace a newer server revision.
      if (!['da','en'].includes(value.language) || !Number.isInteger(value.step) || value.step < 0 || value.step > 2) return null;
      if (!value.message || ['sender','subject_da','subject_en','body_da','body_en','type'].some(k => typeof value.message[k] !== 'string' || value.message[k].length > (k.startsWith('body_') ? 100000 : 255))) return null;
      if (['tags','memberships'].some(k => !Array.isArray(value.message[k]) || value.message[k].some(v => typeof v !== 'string'))) return null;
      if (['age_from','age_to'].some(k => !Number.isInteger(value.message[k]) || value.message[k] < 0 || value.message[k] > 120) || !['','male','female'].includes(value.message.gender) || value.message.format !== 'html') return null;
      const keys = ['sender','subject_da','subject_en','body_da','body_en','type','tags','memberships','age_from','age_to','gender','format'];
      value.message = Object.fromEntries(keys.map(k => [k,value.message[k]]));
      return value;
    } catch { return null; }
  }
  save(id, revision, message, language, step) {
    try { this.storage?.setItem(this.prefix + id, JSON.stringify({version:1,revision,message,language,step,at:this.clock.now()})); return !!this.storage; }
    catch { return false; }
  }
  clear(id) { try {this.storage?.removeItem(this.prefix + id);} catch {} }
}

export function composerSendBlocker(draft, {dirty = false, audienceDirty = false, approved = false, estimating = false} = {}) {
  if (estimating) return 'ESTIMATE_LOADING';
  if (!approved) return 'RELEASE_NOT_VERIFIED';
  if (audienceDirty) return 'AUDIENCE_CHANGED';
  if (!draft || dirty || draft.state !== 'tested') return 'TEST_REQUIRED';
  const count = Number(draft.estimate_count);
  if (draft.estimate_error || draft.estimate_count == null || draft.estimate_count === '' || !Number.isInteger(count) || count < 0) return 'ESTIMATE_UNAVAILABLE';
  if (count === 0) return 'NO_RECIPIENTS';
  return '';
}

export function composerControls(draft, {busy = false, dirty = false, audienceDirty = false, approved = false, estimating = false} = {}) {
  const editable = !draft || ['draft','tested'].includes(draft.state);
  return {save:!busy && editable, preview:!busy && editable,
    delete:!busy && (!draft || ['draft','tested','cancelled'].includes(draft.state)),
    confirm:!busy && !composerSendBlocker(draft, {dirty,audienceDirty,approved,estimating})};
}
