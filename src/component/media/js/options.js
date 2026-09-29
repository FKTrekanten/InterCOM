document.addEventListener('DOMContentLoaded', () => {
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
