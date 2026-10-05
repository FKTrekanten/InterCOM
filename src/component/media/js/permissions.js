import { PreviewScheduler } from './preview.mjs';

const permissions = document.getElementById('ic-permissions');
if (permissions) {
  const form = permissions.closest('form');
  const status = document.getElementById('ic-permissions-status');
  const controls = [...permissions.querySelectorAll('select[data-onchange-task="permissions.apply"]')];
  const rows = controls.map(select => ({
    select,
    icon: document.getElementById('icon_' + select.id),
    output: select.closest('tr').querySelector('output'),
  }));
  const scheduler = new PreviewScheduler(async () => {
    const response = await fetch(permissions.dataset.previewUrl, {
      method: 'POST', body: new FormData(form), headers: {Accept: 'application/json'},
      signal: AbortSignal.timeout(15000),
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.error || Joomla.Text._('COM_INTERCOM_PERMISSIONS_CALCULATION_ERROR'));
    if (rows.some(({select}) => !result.data?.[select.id])) throw new Error(Joomla.Text._('COM_INTERCOM_PERMISSIONS_CALCULATION_ERROR'));
    return result.data;
  }, (result, error) => {
    if (result) {
      for (const {select, output} of rows) {
        const calculation = result[select.id];
        const badge = output.querySelector('span');
        badge.className = calculation.class;
        badge.textContent = calculation.text;
        if (calculation.locked) {
          const lock = document.createElement('span');
          lock.className = 'icon-lock icon-white';
          lock.setAttribute('aria-hidden', 'true');
          badge.prepend(lock);
        }
      }
    }
    status.textContent = error?.message || Joomla.Text._('COM_INTERCOM_PERMISSIONS_PREVIEW');
    status.className = error ? 'alert alert-danger' : '';
  }, pending => {
    for (const {icon, output} of rows) {
      icon.className = pending ? 'joomla-icon joomla-field-permissions__spinner' : '';
      output.setAttribute('aria-busy', String(pending));
    }
    if (pending) {
      status.textContent = Joomla.Text._('COM_INTERCOM_PERMISSIONS_UPDATING');
      status.className = '';
    }
  }, 200);
  // Joomla's native handler writes immediately and derives the wrong asset from this view.
  permissions.addEventListener('change', event => {
    if (!controls.includes(event.target)) return;
    event.stopImmediatePropagation();
    scheduler.schedule();
  }, true);
}
