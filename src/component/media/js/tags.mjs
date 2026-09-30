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
