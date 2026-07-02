import OPC_SELECTORS from '../../selectors';

/**
 * Single source of truth for the required-consent gate, shared by opc-guest-init (which decides
 * whether to FIRE the guest-init request) and the option-section readiness (which decides what the
 * awaiting hint should SAY). Keeping the rule in one place guarantees they agree — a divergence would
 * re-create a dead-end (a section spinning a loader for a guest-init that will never run, or vice
 * versa). DOM-based, so it is consistent across the separate webpack entry bundles.
 */

const REQUIRED_CHECKBOX_SELECTOR = 'input[type="checkbox"][required]';

// Mirrors opc-guest-init's collectCheckboxState filtering: named, enabled, NOT inside an address modal.
function getRequiredConsentCheckboxes(container) {
  if (!container) {
    return [];
  }

  return Array.from(container.querySelectorAll(REQUIRED_CHECKBOX_SELECTOR)).filter((checkbox) => {
    return Boolean(checkbox.name)
      && !checkbox.disabled
      && !checkbox.closest(OPC_SELECTORS.modals.address);
  });
}

export function getContactContainer() {
  return document.querySelector(OPC_SELECTORS.opc.contactSection);
}

/**
 * Mirror of guest-init's hasMissingRequiredConsent(collectRequiredCheckboxState): the required-consent
 * gate is NOT satisfied when there is no required checkbox at all, or any required one is unchecked.
 * (guest-init additionally bypasses this in guest-update mode — that guard stays in guest-init.)
 *
 * @param {Element|null} [container] the contact section element
 * @returns {boolean}
 */
export function isRequiredConsentMissing(container = getContactContainer()) {
  const checkboxes = getRequiredConsentCheckboxes(container);

  if (checkboxes.length === 0) {
    return true;
  }

  return checkboxes.some((checkbox) => !checkbox.checked);
}

/**
 * The user-RESOLVABLE subset of the above: a required consent checkbox is present but unticked, so the
 * awaiting hint can actionably say "accept the terms" (as opposed to the transient, form-not-yet-
 * rendered empty case, which is not something the buyer can fix by ticking a box).
 *
 * @param {Element|null} [container] the contact section element
 * @returns {boolean}
 */
export function hasUncheckedRequiredConsent(container = getContactContainer()) {
  const checkboxes = getRequiredConsentCheckboxes(container);

  return checkboxes.length > 0 && checkboxes.some((checkbox) => !checkbox.checked);
}
