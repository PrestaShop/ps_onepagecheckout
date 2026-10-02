import OPC_SELECTORS from '../../selectors';
import {
  hasUncheckedRequiredConsentForContainer,
  isRequiredConsentMissingForContainer,
} from './opc-contact-consent-state.mjs';

/**
 * Single source of truth for the required-consent gate, shared by opc-guest-init (which decides
 * whether to FIRE the guest-init request) and the option-section readiness (which decides what the
 * awaiting hint should SAY). A missing container or email field is treated as not rendered yet; a
 * rendered guest contact form with no required checkbox has no consent to withhold. DOM-based, so it
 * is consistent across the separate webpack entry bundles.
 */

export function getContactContainer() {
  return document.querySelector(OPC_SELECTORS.opc.contactSection);
}

/**
 * Mirror of guest-init's hasMissingRequiredConsent(collectRequiredCheckboxState): the required-consent
 * gate is NOT satisfied before the guest email field renders, or when any required checkbox is unchecked.
 * Once that field exists, no required checkbox means there is no consent to withhold.
 * (guest-init additionally bypasses this in guest-update mode — that guard stays in guest-init.)
 *
 * @param {Element|null} [container] the contact section element
 * @returns {boolean}
 */
export function isRequiredConsentMissing(container = getContactContainer()) {
  return isRequiredConsentMissingForContainer(
    container,
    OPC_SELECTORS.modals.address,
    OPC_SELECTORS.inputs.email
  );
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
  return hasUncheckedRequiredConsentForContainer(container, OPC_SELECTORS.modals.address);
}
