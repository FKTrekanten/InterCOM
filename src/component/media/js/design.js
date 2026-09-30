import { PreviewScheduler } from './preview.mjs';

const form = document.getElementById('ic-design-form');
if (form) {
  const frame = document.getElementById('ic-design-preview');
  const language = document.getElementById('ic-design-language');
  const theme = document.getElementById('ic-design-theme');
  const status = document.getElementById('ic-design-preview-status');
  let rendered = null;
  const paint = () => {
    const html = rendered?.[language.value + '_' + theme.value];
    if (html && frame.srcdoc !== html) frame.srcdoc = html;
  };
  const scheduler = new PreviewScheduler(async () => {
    if (!form.checkValidity()) throw new Error(Joomla.Text._('COM_INTERCOM_ERROR'));
    const data = new FormData(form);
    const response = await fetch('index.php?option=com_intercom&task=management.renderdesign', {method:'POST', body:data, headers:{Accept:'application/json'}});
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.error || Joomla.Text._('COM_INTERCOM_ERROR'));
    return result.data;
  }, (result, error) => {
    if (result) {rendered = result; paint();}
    status.textContent = error?.message || Joomla.Text._('COM_INTERCOM_PREVIEW_UPDATED');
  }, pending => {
    frame.setAttribute('aria-busy', String(pending));
    if (pending) status.textContent = Joomla.Text._('COM_INTERCOM_PREVIEW_UPDATING');
  });
  form.addEventListener('input', () => scheduler.schedule());
  form.addEventListener('change', () => scheduler.schedule());
  for (const select of [language, theme]) select.addEventListener('change', paint);
  scheduler.schedule(true);
}
