document.addEventListener('DOMContentLoaded', () => {
  const group = document.getElementById('jform_group_id');
  const forms = document.getElementById('jform_unsubscribe_form_id');
  let requestNumber = 0;
  const label = key => Joomla.Text._('COM_INTERCOM_' + key);
  if (group && forms) group.addEventListener('change', async () => {
    const number = ++requestNumber;
    forms.replaceChildren(new Option(label('UNSUBSCRIBE_LOADING'), ''));
    forms.disabled = true;
    try {
      if (!Number(group.value)) {
        forms.replaceChildren(new Option(label('CHOOSE_UNSUBSCRIBE'), ''));
        return;
      }
      const url = 'index.php?option=com_intercom&task=connection.forms&format=json&group_id=' + encodeURIComponent(group.value);
      const response = await fetch(url, {headers:{Accept:'application/json'}});
      const result = await response.json();
      if (number !== requestNumber) return;
      if (!response.ok || !result.success) throw new Error();
      forms.replaceChildren(new Option(label('CHOOSE_UNSUBSCRIBE'), ''), ...result.data.map(form => new Option(form.name, form.id)));
    } catch {
      if (number === requestNumber) forms.replaceChildren(new Option(label('UNSUBSCRIBE_LOAD_ERROR'), ''));
    } finally {
      if (number === requestNumber) forms.disabled = false;
    }
  });
  document.querySelectorAll('[data-intercom-task]').forEach(button => {
    button.addEventListener('click', () => {
      const task = button.dataset.intercomTask;
      if (!['savecredentials', 'connect', 'importtokens'].includes(task)) return;
      const form = button.closest('form');
      form.action = 'index.php?option=com_intercom';
      form.elements.namedItem('task').value = 'connection.' + task;
      form.submit();
    });
  });
});
