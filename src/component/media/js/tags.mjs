export function selectionState(choices) {
  const available = Array.from(choices).filter(choice => !choice.disabled);
  const selected = available.filter(choice => choice.checked).length;
  return {disabled:available.length === 0, checked:available.length > 0 && selected === available.length,
    indeterminate:selected > 0 && selected < available.length};
}

if (typeof document !== 'undefined') {
  document.querySelectorAll('[data-tag-section]').forEach(section => {
    const toggle = section.querySelector('[data-toggle-all]');
    const choices = section.querySelectorAll('[data-tag-choice]');
    const sync = () => Object.assign(toggle, selectionState(choices));
    toggle.addEventListener('change', () => {
      choices.forEach(choice => {if (!choice.disabled) choice.checked = toggle.checked;});
      sync();
    });
    choices.forEach(choice => choice.addEventListener('change', sync));
    sync();
  });
}

// Each section has its own order: groups and memberships never cross sections.
export function initialiseOrdering(section) {
  const body = section.querySelector('[data-tag-rows]');
  if (!body) return;
  let dragged = null;
  const syncOrder = () => {
    const rows = [...body.querySelectorAll('[data-tag-row]')];
    rows.forEach((row, index) => {
      row.querySelector('[data-tag-order]').value = index + 1;
      row.querySelector('[data-move-up]').disabled = index === 0;
      row.querySelector('[data-move-down]').disabled = index === rows.length - 1;
    });
  };
  const announce = row => {
    syncOrder();
    const rows = [...body.querySelectorAll('[data-tag-row]')];
    section.querySelector('[data-order-status]').textContent =
      `${row.querySelector('[data-tag-choice]').getAttribute('aria-label')}: ${rows.indexOf(row) + 1} / ${rows.length}`;
  };
  const move = (row, direction) => {
    const neighbour = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
    if (!neighbour) return;
    if (direction < 0) body.insertBefore(row, neighbour);
    else body.insertBefore(neighbour, row);
    announce(row);
  };
  body.querySelectorAll('[data-tag-row]').forEach(row => {
    row.querySelector('[data-move-up]').addEventListener('click', () => move(row, -1));
    row.querySelector('[data-move-down]').addEventListener('click', () => move(row, 1));
    row.querySelector('[data-drag-handle]').addEventListener('keydown', event => {
      if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
      event.preventDefault();
      move(row, event.key === 'ArrowUp' ? -1 : 1);
    });
  });
  body.addEventListener('dragstart', event => {
    if (!event.target.closest('[data-drag-handle]')) return;
    dragged = event.target.closest('[data-tag-row]');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', dragged.querySelector('[data-tag-choice]').value);
    dragged.classList.add('table-active');
  });
  body.addEventListener('dragover', event => {
    if (!dragged || !event.target.closest('[data-tag-row]')) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
  });
  body.addEventListener('drop', event => {
    const target = event.target.closest('[data-tag-row]');
    if (!dragged || !target) return;
    event.preventDefault();
    if (target !== dragged) {
      const bounds = target.getBoundingClientRect();
      body.insertBefore(dragged, event.clientY < bounds.top + bounds.height / 2 ? target : target.nextElementSibling);
      announce(dragged);
    }
  });
  body.addEventListener('dragend', () => {
    dragged?.classList.remove('table-active');
    dragged = null;
  });
  syncOrder();
}

if (typeof document !== 'undefined') {
  document.querySelectorAll('[data-tag-section]').forEach(initialiseOrdering);
}
