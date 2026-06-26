export function updateButtonSpinner(button, selector, loading) {
  if (!(button instanceof HTMLElement)) {
    return;
  }

  const spinner = button.querySelector(selector);

  if (spinner instanceof HTMLElement) {
    spinner.classList.toggle('d-none', !loading);
  }
}
