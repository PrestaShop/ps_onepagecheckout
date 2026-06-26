function isNativeFormControl(element) {
  return element instanceof HTMLInputElement
    || element instanceof HTMLSelectElement
    || element instanceof HTMLTextAreaElement;
}

export function reportFirstInvalidNativeField(form, isElementVisible) {
  const invalidField = Array.from(form.elements).find((element) => (
    isNativeFormControl(element)
    && element.willValidate
    && isElementVisible(element)
    && !element.checkValidity()
  ));

  if (invalidField && typeof invalidField.reportValidity === 'function') {
    invalidField.focus();
    invalidField.reportValidity();

    return;
  }

  form.reportValidity();
}
