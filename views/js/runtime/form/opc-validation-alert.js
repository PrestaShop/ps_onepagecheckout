const VALIDATION_ALERT_CLASS = 'js-opc-validation-error';
const VALIDATION_ALERT_SELECTOR = `.${VALIDATION_ALERT_CLASS}`;

export function clearValidationAlert() {
  document.querySelectorAll(VALIDATION_ALERT_SELECTOR).forEach((alert) => alert.remove());
}

export function clearSectionValidationAlert(alertId) {
  if (!alertId) {
    return;
  }

  const alert = document.getElementById(alertId);

  if (alert instanceof HTMLElement && alert.matches(VALIDATION_ALERT_SELECTOR)) {
    alert.remove();
  }
}

function createValidationAlert(messages, alertId = '') {
  const alert = document.createElement('article');
  alert.className = `alert alert-danger ${VALIDATION_ALERT_CLASS}`;
  if (alertId) {
    alert.id = alertId;
  }
  alert.setAttribute('role', 'alert');
  alert.setAttribute('data-alert', 'danger');

  const list = document.createElement('ul');
  messages.forEach((message) => {
    const item = document.createElement('li');
    item.textContent = message;
    list.appendChild(item);
  });
  alert.appendChild(list);

  return alert;
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

  const alert = createValidationAlert(messages);

  if (target === fallbackElement) {
    target.before(alert);
  } else {
    target.prepend(alert);
  }

  alert.scrollIntoView({block: 'start', behavior: 'smooth'});

  return true;
}

export function renderSectionValidationAlert(messages, titleSelector, alertId) {
  if (!Array.isArray(messages) || messages.length === 0 || !titleSelector || !alertId) {
    return false;
  }

  const title = document.querySelector(titleSelector);
  if (!(title instanceof HTMLElement)) {
    return false;
  }

  clearValidationAlert();

  title.after(createValidationAlert(messages, alertId));
  title.scrollIntoView({block: 'start', behavior: 'smooth'});

  return true;
}
