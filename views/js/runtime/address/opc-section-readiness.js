import OPC_OPTION_LIST_STATE from '../opc-option-list-state';
import OPC_SELECTORS from '../../selectors';
import {getConfiguredOpcMessage} from '../opc-runtime';
import {showFieldError} from '../form/opc-field-errors';
import {
  getAwaitingAddressHint,
  hasAddressPersistFailed,
  isBuyerIdentified,
  isInlineAutosaveActive,
} from './opc-address-context';

/**
 * Shared awaiting-address rendering for the option sections (carriers, payment), kept out of the list
 * runtimes so they stay thin. The "which section is ready / which fields are missing" logic lives in
 * opc-address-context; this module only turns it into the awaiting-address hint UI.
 */

const AWAITING_ADDRESS_FIELDS_SELECTOR = '.js-opc-awaiting-address-fields';
const AWAITING_ADDRESS_MESSAGE_SELECTOR = '.js-opc-awaiting-address-message';

/**
 * The option sections are about to say "enter your email above": also flag the email field itself,
 * so the hint and the field designate each other (SPE-151 — the buyer must see WHICH field blocks
 * the order, right at the field). Only an EMPTY, not-already-flagged field is flagged: a value
 * being typed keeps its blur-time verdict from the guest-init runtime, and an existing message
 * (e.g. a specific invalid-format one) is never clobbered by the generic required one. The
 * guest-init runtime clears the flag on the next input in the field.
 */
function flagEmailFieldAsBlocker() {
  const contactSection = document.querySelector(OPC_SELECTORS.opc.contactSection);
  const emailField = contactSection && contactSection.querySelector(OPC_SELECTORS.inputs.email);

  if (!emailField || emailField.dataset.opcFieldError === '1') {
    return;
  }

  if (String(emailField.value || '').trim() !== '') {
    return;
  }

  showFieldError(
    emailField,
    getConfiguredOpcMessage('emailRequired', 'Please enter your email address.')
  );
}

function getTemplateHtml(templateSelector) {
  const template = document.querySelector(templateSelector);

  return template ? template.innerHTML : '';
}

/**
 * Keep the awaiting-address hint's missing-fields list in sync with what the buyer still has to fill.
 *
 * @param {JQuery} $container the section container holding the awaiting-address hint
 */
export function fillAwaitingAddressFields($container) {
  const fieldsTarget = $container.find(AWAITING_ADDRESS_FIELDS_SELECTOR).first();
  const messageTarget = $container.find(AWAITING_ADDRESS_MESSAGE_SELECTOR).first();
  const {reason, labels} = getAwaitingAddressHint();

  // Email is in, but a required consent box is unchecked (guest-init can't run): name that blocker.
  if (reason === 'awaiting-consent') {
    if (messageTarget.length) {
      messageTarget.text(getConfiguredOpcMessage(
        'awaitingAddressNeedsConsent',
        'Please accept the required terms above to see the delivery and payment options.',
      ));
    }

    if (fieldsTarget.length) {
      fieldsTarget.text('');
    }

    return;
  }

  // Address complete but the buyer is not identified yet: swap the "complete your address" base
  // sentence for a contact-step call to action — their email is the only thing still missing.
  if (reason === 'awaiting-identity') {
    if (messageTarget.length) {
      messageTarget.text(getConfiguredOpcMessage(
        'awaitingAddressNeedsContact',
        'Enter your email address above to see the delivery and payment options.',
      ));
    }

    if (fieldsTarget.length) {
      fieldsTarget.text('');
    }

    flagEmailFieldAsBlocker();

    return;
  }

  // Address complete but rejected by per-country validation: "complete your address" would be wrong
  // (it IS complete). Point the buyer at the highlighted field errors shown above the section.
  if (reason === 'invalid-address') {
    if (messageTarget.length) {
      messageTarget.text(getConfiguredOpcMessage(
        'addressFieldsInvalid',
        'Please correct the highlighted address fields.',
      ));
    }

    if (fieldsTarget.length) {
      fieldsTarget.text('');
    }

    return;
  }

  // Address complete & valid but it could not be saved (guest-init / autosave failed): tell the buyer
  // so they can retry instead of waiting on a loader that will never resolve.
  if (reason === 'persist-failed') {
    if (messageTarget.length) {
      messageTarget.text(getConfiguredOpcMessage(
        'awaitingAddressPersistFailed',
        "We couldn't save your delivery details. Please check your information above and try again.",
      ));
    }

    if (fieldsTarget.length) {
      fieldsTarget.text('');
    }

    return;
  }

  if (!fieldsTarget.length) {
    return;
  }

  fieldsTarget.text(labels.length
    ? ` ${getConfiguredOpcMessage('awaitingAddressMissingFields', 'Still to complete:')} ${labels.join(', ')}`
    : '');
}

/**
 * Render a section's awaiting-address hint (template + live missing-fields list) into its container.
 * The caller is responsible for setting the section's option-list state (it differs per section).
 *
 * @param {JQuery} $container       the section container
 * @param {string} templateSelector CSS selector of the <template> holding the awaiting-address markup
 */
export function renderAwaitingAddress($container, templateSelector) {
  if (!$container || !$container.length) {
    return;
  }

  $container.html(getTemplateHtml(templateSelector));
  fillAwaitingAddressFields($container);
}

/**
 * Build the awaiting/loading/available readiness controller for an option section (carriers,
 * payment), so the gating logic lives in ONE place instead of being duplicated in each list runtime.
 * The section-specific bits (container, state get/set, awaiting render, loader, fetch) are injected.
 *
 * @param {Object} cfg
 * @param {() => JQuery}            cfg.getContainer  the section container ($)
 * @param {() => boolean}          cfg.isReady       whether a usable address exists for the section
 * @param {() => string}           cfg.getState      current option-list state of the container
 * @param {() => void}             cfg.renderAwaiting render the awaiting-address hint + set its state
 * @param {() => void}             cfg.showLoading   show the section loader + set the LOADING state
 * @param {() => void}             cfg.fetch         fetch + render the options
 * @returns {{syncReadiness: () => void, refreshReadiness: () => void}}
 */
export function createSectionReadiness({getContainer, isReady, getState, renderAwaiting, showLoading, fetch}) {
  // On address-field edits: keep the awaiting/loading state in sync WITHOUT fetching while the inline
  // autosave is active — the reveal waits for the server to confirm the address is persisted & valid,
  // so the list is computed against the persisted cart and never revealed for an address about to be
  // rejected. When no autosave will run (a saved address already in the cart), reveal directly.
  const syncReadiness = () => {
    if (!getContainer().length) {
      return;
    }

    if (!isReady()) {
      renderAwaiting();

      return;
    }

    // AVAILABLE / EMPTY / FAILED are RESOLVED terminal results (options shown, or a definitive
    // no-carrier / error result already rendered against the persisted address). A trailing debounced
    // syncReadiness must NOT downgrade them back to a loader — otherwise a no-carrier (EMPTY) or errored
    // (FAILED) section gets overwritten by a spinner that never clears (it would wait for an
    // opcAddressPersisted that already fired). A genuine address change still re-fetches via
    // opcAddressPersisted -> refreshReadiness (which fetches for any non-AVAILABLE state).
    const currentState = getState();
    if (
      currentState === OPC_OPTION_LIST_STATE.AVAILABLE
      || currentState === OPC_OPTION_LIST_STATE.EMPTY
      || currentState === OPC_OPTION_LIST_STATE.FAILED
    ) {
      return;
    }

    if (isInlineAutosaveActive()) {
      // The reveal waits for a server persist confirmation. If that can never come — a save that
      // failed, or a complete address from a not-yet-identified guest (no customer to attach it to) —
      // show the awaiting hint (a recoverable error / the contact step) instead of an endless loader.
      if (hasAddressPersistFailed() || !isBuyerIdentified()) {
        renderAwaiting();

        return;
      }

      if (getState() !== OPC_OPTION_LIST_STATE.LOADING) {
        showLoading();
      }

      return;
    }

    fetch();
  };

  // On the server's address confirmation (autosave persist settled): reveal when ready (persisted &
  // valid), otherwise stay awaiting.
  const refreshReadiness = () => {
    if (!getContainer().length) {
      return;
    }

    if (!isReady()) {
      renderAwaiting();

      return;
    }

    if (getState() !== OPC_OPTION_LIST_STATE.AVAILABLE) {
      fetch();
    }
  };

  return {syncReadiness, refreshReadiness};
}
