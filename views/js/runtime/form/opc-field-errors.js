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
    field.removeAttribute('aria-invalid');
    delete field.dataset.opcFieldError;
    delete field.dataset.opcFieldErrorValue;
  }
}

/**
 * Render a single, caller-managed field error with the same accessible treatment as the
 * server-verdict renderer below (is-invalid + aria-invalid + role="alert"). Used for errors whose
 * lifecycle is owned by another runtime (guest email, address modal): no value snapshot is taken,
 * so clearEditedFieldErrors leaves them alone and the owner clears them (see clearFieldError).
 */
export function showFieldError(field, message) {
  if (!isFormControl(field)) {
    return;
  }

  clearFieldError(field);

  if (!message) {
    return;
  }

  const target = getErrorTarget(field);
  field.classList.add('is-invalid');
  field.setAttribute('aria-invalid', 'true');
  field.dataset.opcFieldError = '1';

  const error = document.createElement('div');
  error.className = `invalid-feedback d-block ${FIELD_ERROR_CLASS}`;
  error.setAttribute('role', 'alert');
  error.textContent = message;
  target.classList.add('has-error');
  target.appendChild(error);
}

// The buyer edited a field after its error rendered (its value moved from the snapshot taken at
// render time): that fix is unverifiable on an inconclusive response, so give it the benefit of
// the doubt — the next conclusive verdict re-renders the truth if the fix was wrong. Untouched
// fields keep their (still accurate) errors.
export function clearEditedFieldErrors(root) {
  if (!(root instanceof HTMLElement)) {
    return;
  }

  root.querySelectorAll('[data-opc-field-error="1"]').forEach((field) => {
    // No snapshot = the error was flagged by another renderer (address modal, guest email), whose
    // own lifecycle manages it: without a render-time value there is no "edited since" to detect.
    if (isFormControl(field)
      && field.dataset.opcFieldErrorValue !== undefined
      && field.value !== field.dataset.opcFieldErrorValue) {
      clearFieldError(field);
    }
  });
}

function clearFieldErrors(root) {
  if (!(root instanceof HTMLElement)) {
    return;
  }

  root.querySelectorAll('[data-opc-field-error="1"]').forEach((field) => clearFieldError(field));
}

export function renderFieldErrors(root, errors, {focusFirstInvalid = true} = {}) {
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
    field.setAttribute('aria-invalid', 'true');
    field.dataset.opcFieldError = '1';
    // Snapshot the rejected value so clearEditedFieldErrors can tell a real edit from a re-focus.
    field.dataset.opcFieldErrorValue = field.value;

    const error = document.createElement('div');
    error.className = `invalid-feedback d-block ${FIELD_ERROR_CLASS}`;
    // Announce the inserted message to screen readers — errors can render without a focus move.
    error.setAttribute('role', 'alert');
    error.textContent = messages[0];
    target.classList.add('has-error');
    target.appendChild(error);

    firstInvalidField = firstInvalidField || field;
  });

  if (!firstInvalidField) {
    return false;
  }

  // Bringing the buyer to the first error is only right when they asked for the validation (the
  // final submit): an autosave render must neither scroll the page nor steal the keyboard focus.
  if (focusFirstInvalid) {
    firstInvalidField.scrollIntoView({block: 'center', behavior: 'smooth'});
    firstInvalidField.focus();
  }

  return true;
}
