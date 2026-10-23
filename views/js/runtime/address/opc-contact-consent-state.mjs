const REQUIRED_CHECKBOX_SELECTOR = 'input[type="checkbox"][required]';

/**
 * Shared consent-gate predicates extracted so they can be exercised without a browser DOM.
 * The address modal selector is passed by the browser adapter to keep this module independent
 * of the webpack-only selector map.
 *
 * @param {Element|null} container
 * @param {string} addressModalSelector
 * @returns {Element[]}
 */
export function getRequiredConsentCheckboxes(container, addressModalSelector) {
  if (!container) {
    return [];
  }

  return Array.from(container.querySelectorAll(REQUIRED_CHECKBOX_SELECTOR)).filter((checkbox) => {
    return Boolean(checkbox.name)
      && !checkbox.disabled
      && !checkbox.closest(addressModalSelector);
  });
}

/**
 * @param {Element|null} container
 * @param {string} addressModalSelector
 * @param {string} contactEmailSelector
 * @returns {boolean}
 */
export function isRequiredConsentMissingForContainer(container, addressModalSelector, contactEmailSelector) {
  if (!container || !container.querySelector(contactEmailSelector)) {
    return true;
  }

  const checkboxes = getRequiredConsentCheckboxes(container, addressModalSelector);

  return checkboxes.some((checkbox) => !checkbox.checked);
}

/**
 * @param {Element|null} container
 * @param {string} addressModalSelector
 * @returns {boolean}
 */
export function hasUncheckedRequiredConsentForContainer(container, addressModalSelector) {
  const checkboxes = getRequiredConsentCheckboxes(container, addressModalSelector);

  return checkboxes.length > 0 && checkboxes.some((checkbox) => !checkbox.checked);
}
