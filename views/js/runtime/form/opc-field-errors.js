const FIELD_ERROR_CLASS = 'js-opc-field-error';

function isFormControl(element) {
  return element instanceof HTMLInputElement
    || element instanceof HTMLSelectElement
    || element instanceof HTMLTextAreaElement;
}

function isVisibleFormControl(element) {
  return isFormControl(element)
    && !element.disabled
    && !(element instanceof HTMLInputElement && element.type === 'hidden')
    && element.offsetParent !== null;
}

function getErrorTarget(field) {
  return field.closest('.form-group, .mb-3') || field.parentElement || field;
}

function getErrorMessages(fieldErrors) {
  const messages = Array.isArray(fieldErrors) ? fieldErrors : [fieldErrors];

  return messages
    .filter((message) => typeof message === 'string')
    .map((message) => message.trim())
    .filter(Boolean);
}

function findVisibleField(root, fieldName) {
  return Array.from(root.querySelectorAll('input, select, textarea')).find((field) => (
    isVisibleFormControl(field) && field.name === fieldName
  )) || null;
}

export function getFieldErrors(response) {
  const fieldErrors = {};

  if (!response || typeof response !== 'object') {
    return fieldErrors;
  }

  const errorGroups = [
    response.form_errors,
    ...Object.values(
      response.validation_errors && typeof response.validation_errors === 'object'
        ? response.validation_errors
        : {}
    ),
  ];

  errorGroups.forEach((errors) => {
    if (!errors || typeof errors !== 'object' || Array.isArray(errors)) {
      return;
    }

    Object.entries(errors).forEach(([fieldName, fieldError]) => {
      if (fieldName === '' || (!Array.isArray(fieldError) && typeof fieldError !== 'string')) {
        return;
      }

      fieldErrors[fieldName] = fieldError;
    });
  });

  return fieldErrors;
}

export function getFieldErrorMessages(errors) {
  if (!errors || typeof errors !== 'object' || Array.isArray(errors)) {
    return [];
  }

  return [...new Set(Object.values(errors).flatMap((fieldErrors) => getErrorMessages(fieldErrors)))];
}

export function clearFieldError(field) {
  if (!isFormControl(field)) {
    return;
  }

  const target = getErrorTarget(field);
  target.querySelector(`.${FIELD_ERROR_CLASS}`)?.remove();

  if (!target.querySelector(`.${FIELD_ERROR_CLASS}`)) {
    target.classList.remove('has-error');
  }

  if (field.dataset.opcFieldError === '1') {
    field.classList.remove('is-invalid');
    delete field.dataset.opcFieldError;
  }
}

function clearFieldErrors(root) {
  if (!(root instanceof HTMLElement)) {
    return;
  }

  root.querySelectorAll('[data-opc-field-error="1"]').forEach((field) => clearFieldError(field));
}

export function renderFieldErrors(root, errors) {
  if (!(root instanceof HTMLElement) || !errors || typeof errors !== 'object' || Array.isArray(errors)) {
    return false;
  }

  clearFieldErrors(root);

  let firstInvalidField = null;

  Object.entries(errors).forEach(([fieldName, fieldErrors]) => {
    const messages = getErrorMessages(fieldErrors);
    const field = fieldName ? findVisibleField(root, fieldName) : null;

    if (!field || messages.length === 0) {
      return;
    }

    const target = getErrorTarget(field);

    clearFieldError(field);
    field.classList.add('is-invalid');
    field.dataset.opcFieldError = '1';

    const error = document.createElement('div');
    error.className = `invalid-feedback d-block ${FIELD_ERROR_CLASS}`;
    error.textContent = messages[0];
    target.classList.add('has-error');
    target.appendChild(error);

    firstInvalidField = firstInvalidField || field;
  });

  if (!firstInvalidField) {
    return false;
  }

  firstInvalidField.scrollIntoView({block: 'center', behavior: 'smooth'});
  firstInvalidField.focus();

  return true;
}
