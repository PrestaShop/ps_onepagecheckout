const VALIDATION_ALERT_CLASS = 'js-opc-validation-error';
const VALIDATION_ALERT_SELECTOR = `.${VALIDATION_ALERT_CLASS}`;

export function clearValidationAlert() {
  document.querySelectorAll(VALIDATION_ALERT_SELECTOR).forEach((alert) => alert.remove());
}

export function renderValidationAlert(messages, fallbackElement = null) {
  if (!Array.isArray(messages) || messages.length === 0) {
    return false;
  }

  // Use the theme notification area when known (Classic or Hummingbird); otherwise show the alert near the OPC form.
  const target = document.querySelector('#notifications .notifications-container')
    || document.querySelector('#notifications > .container')
    || fallbackElement;
  if (!(target instanceof HTMLElement)) {
    return false;
  }

  clearValidationAlert();

  const alert = document.createElement('article');
  alert.className = `alert alert-danger ${VALIDATION_ALERT_CLASS}`;
  alert.setAttribute('role', 'alert');
  alert.setAttribute('data-alert', 'danger');

  const list = document.createElement('ul');
  messages.forEach((message) => {
    const item = document.createElement('li');
    item.textContent = message;
    list.appendChild(item);
  });
  alert.appendChild(list);

  if (target === fallbackElement) {
    target.before(alert);
  } else {
    target.prepend(alert);
  }

  alert.scrollIntoView({block: 'start', behavior: 'smooth'});

  return true;
}
