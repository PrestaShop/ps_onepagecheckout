import {OPC_EVENTS} from './events';
import OPC_SELECTORS from './selectors';
import {AJAX_STATUS_ABORT} from './runtime/opc-runtime';
import {getConfiguredOpcMessage} from './runtime/opc-runtime';
import {getConfiguredOpcUrl} from './runtime/opc-runtime';
import {normalizeErrorEventResponse} from './runtime/opc-runtime';
import {markAddressPersistFailed, markBuyerIdentified} from './runtime/address/opc-address-context';
import {isRequiredConsentMissing} from './runtime/address/opc-contact-consent';
import {clearFieldError} from './runtime/form/opc-field-errors';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

(function psOpcGuestInitRuntime() {

const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

const CREATE_DEBOUNCE_MS = 250;
const UPDATE_DEBOUNCE_MS = 900;
const OPC_FORM_SELECTOR = OPC_SELECTORS.opc.checkout;
const OPC_CONTACT_SECTION_SELECTOR = OPC_SELECTORS.opc.contactSection;
const EMAIL_FIELD_SELECTOR = OPC_SELECTORS.inputs.email;
const INPUT_FIELD_SELECTOR = 'input, select, textarea';
const CHECKBOX_FIELD_SELECTOR = 'input[type="checkbox"]';
const ADDRESS_MODAL_SELECTOR = OPC_SELECTORS.modals.address;
const REQUEST_COMPLETED = 4;
const MODULE_GUEST_INIT_URL_KEY = 'guestInit';

let debounceTimer = null;
let inFlightRequest = null;
let lastSubmittedFingerprint = '';
let pendingFingerprint = '';
let guestCustomerId = getGuestCustomerIdFromContext();
let isFinalSubmitInProgress = false;

/**
 * @typedef {Object} OpcGuestInitPayload
 * @property {string} email
 * @property {string} [token]
 * @property {string} [static_token]
 *
 * Additional personal-information fields are serialized when relevant.
 */

/**
 * @typedef {Object} OpcGuestInitResponse
 * @property {boolean} success
 * @property {number} id_customer
 * @property {boolean} [customer_created]
 * @property {Object<string, Array<string>>} [errors]
 * @property {string} [token]
 * @property {string} [static_token]
 */

function getCustomerIdFromContext() {
  const {customer} = prestashop;

  if (!customer) {
    return 0;
  }

  return Number(customer.id || 0);
}

function getCartCustomerIdFromContext() {
  const {cart} = prestashop;

  if (!cart) {
    return 0;
  }

  return Number(cart.id_customer || 0);
}

function getCustomerEmailFromContext() {
  const {customer} = prestashop;

  if (!customer) {
    return '';
  }

  return String(customer.email || '').trim();
}

function getGuestCustomerIdFromContext() {
  const customerId = getCustomerIdFromContext();

  if (!Number.isInteger(customerId) || customerId <= 0) {
    return 0;
  }

  return isCustomerLoggedIn() ? 0 : customerId;
}

function hasPersistedGuestContext() {
  if (isCustomerLoggedIn()) {
    return false;
  }

  const cartCustomerId = getCartCustomerIdFromContext();

  if (Number.isInteger(cartCustomerId) && cartCustomerId > 0) {
    return true;
  }

  return getCustomerEmailFromContext() !== '';
}

function isGuestUpdateMode() {
  guestCustomerId = guestCustomerId || getGuestCustomerIdFromContext();

  return guestCustomerId > 0 || hasPersistedGuestContext();
}

function getOpcContactContainer() {
  return $(OPC_CONTACT_SECTION_SELECTOR).first();
}

function isFieldInsideAddressModal(element) {
  return $(element).closest(ADDRESS_MODAL_SELECTOR).length > 0;
}

function getEmailField($container) {
  return $container.find(EMAIL_FIELD_SELECTOR).first();
}

function isCustomerLoggedIn() {
  const {customer} = prestashop;

  return Boolean(customer && customer.is_logged);
}

function isGuestInitApplicable() {
  if (isCustomerLoggedIn()) {
    return false;
  }

  return $(OPC_FORM_SELECTOR).length > 0 && getOpcContactContainer().length > 0;
}

/**
 * Adaptive invalid-email message: state what is actually wrong with the typed value
 * (missing "@", missing domain, missing local part) instead of a generic message,
 * falling back to the generic text for the remaining invalid shapes.
 */
function getInvalidEmailMessage($emailField) {
  const emailValue = String($emailField.val() || '').trim();
  const atIndex = emailValue.indexOf('@');

  if (atIndex === -1) {
    return getConfiguredOpcMessage('emailMissingAt', 'The email address is missing an "@" (e.g. name@example.com).');
  }

  if (atIndex === emailValue.length - 1) {
    return getConfiguredOpcMessage('emailMissingDomain', 'The email address is missing the part after the "@" (e.g. name@example.com).');
  }

  if (atIndex === 0) {
    return getConfiguredOpcMessage('emailMissingLocalPart', 'The email address is missing the part before the "@" (e.g. name@example.com).');
  }

  return getConfiguredOpcMessage('invalidEmail', 'Please enter a valid email address.');
}

function isEmailValid($emailField) {
  if (!$emailField.length) {
    return false;
  }

  const emailValue = String($emailField.val() || '').trim();

  if (!emailValue) {
    return false;
  }

  return $emailField.get(0).checkValidity();
}

/**
 * @param {JQuery<HTMLElement>} $container
 *
 * @returns {OpcGuestInitPayload}
 */
function collectPayload($container) {
  const payload = {
    email: String(getEmailField($container).val() || '').trim(),
  };

  const globalPrestashop = window && window.prestashop ? window.prestashop : prestashop;
  let securityToken = '';

  if (globalPrestashop && globalPrestashop.static_token) {
    securityToken = String(globalPrestashop.static_token);
  } else if (globalPrestashop && globalPrestashop.token) {
    securityToken = String(globalPrestashop.token);
  }

  if (securityToken !== '') {
    payload.token = securityToken;
    payload.static_token = securityToken;
  }

  $container.find(INPUT_FIELD_SELECTOR).each((_, element) => {
    const fieldName = element.name;
    const inputType = String(element.type || '').toLowerCase();

    if (!fieldName || fieldName === 'email' || element.disabled || isFieldInsideAddressModal(element)) {
      return;
    }

    if (['hidden', 'submit', 'button', 'image', 'reset', 'file'].includes(inputType)) {
      return;
    }

    if (inputType === 'checkbox') {
      payload[fieldName] = element.checked ? '1' : '0';
      return;
    }

    if (inputType === 'radio') {
      if (element.checked) {
        payload[fieldName] = String(element.value || '');
      }

      return;
    }

    payload[fieldName] = String($(element).val() || '').trim();
  });

  return payload;
}

function collectCheckboxState($container, selector = CHECKBOX_FIELD_SELECTOR) {
  const checkboxes = {};

  $container.find(selector).each((_, checkbox) => {
    const fieldName = checkbox.name;

    if (!fieldName || checkbox.disabled || isFieldInsideAddressModal(checkbox)) {
      return;
    }

    checkboxes[fieldName] = checkbox.checked ? '1' : '0';
  });

  return checkboxes;
}

function collectRequiredCheckboxState($container) {
  return collectCheckboxState($container, `${CHECKBOX_FIELD_SELECTOR}[required]`);
}

function getPayloadFingerprint(payload) {
  const sortedKeys = Object.keys(payload).sort();

  return sortedKeys.map((key) => `${key}:${payload[key]}`).join('|');
}

function setInitialGuestEmailFingerprint() {
  const guestUpdateMode = isGuestUpdateMode();

  if (!guestUpdateMode) {
    return;
  }

  const $container = getOpcContactContainer();
  const $emailField = getEmailField($container);

  if (!isEmailValid($emailField)) {
    return;
  }

  lastSubmittedFingerprint = getPayloadFingerprint({
    email: String($emailField.val() || '').trim(),
  });
}

function stopGuestInitForFinalSubmit() {
  if (isFinalSubmitInProgress) {
    return;
  }

  isFinalSubmitInProgress = true;
  window.clearTimeout(debounceTimer);
  pendingFingerprint = '';

  if (inFlightRequest && inFlightRequest.readyState !== REQUEST_COMPLETED) {
    inFlightRequest.abort();
  }
}

function abortPendingGuestInit() {
  pendingFingerprint = '';

  if (inFlightRequest && inFlightRequest.readyState !== REQUEST_COMPLETED) {
    inFlightRequest.abort();
  }
}

function tryGuestInit() {
  if (isFinalSubmitInProgress) {
    return;
  }

  if (!isGuestInitApplicable()) {
    return;
  }

  const $container = getOpcContactContainer();
  const $emailField = getEmailField($container);

  if (!isEmailValid($emailField)) {
    abortPendingGuestInit();
    return;
  }

  const payload = collectPayload($container);
  const requiredCheckboxState = collectRequiredCheckboxState($container);
  const guestUpdateMode = isGuestUpdateMode();

  if (!guestUpdateMode && isRequiredConsentMissing($container.get(0))) {
    abortPendingGuestInit();
    return;
  }

  const payloadFingerprint = guestUpdateMode
    ? getPayloadFingerprint({email: payload.email})
    : getPayloadFingerprint({
      email: payload.email,
      ...requiredCheckboxState,
    });

  if (payloadFingerprint === lastSubmittedFingerprint || payloadFingerprint === pendingFingerprint) {
    return;
  }

  pendingFingerprint = payloadFingerprint;
  if (inFlightRequest && inFlightRequest.readyState !== REQUEST_COMPLETED) {
    inFlightRequest.abort();
  }

  const guestInitUrl = getConfiguredOpcUrl(MODULE_GUEST_INIT_URL_KEY);

  inFlightRequest = $.post(
    guestInitUrl,
    payload,
  )
    .done(/** @param {OpcGuestInitResponse} resp */ (resp) => {
      if (resp && (resp.token || resp.static_token) && window && window.prestashop) {
        const refreshedToken = String(resp.static_token || resp.token || '');

        if (refreshedToken !== '') {
          window.prestashop.token = refreshedToken;
          window.prestashop.static_token = refreshedToken;
          prestashop.token = refreshedToken;
          prestashop.static_token = refreshedToken;
        }
      }

      if (resp && resp.success) {
        const responseCustomerId = Number(resp.id_customer || 0);

        if (responseCustomerId > 0) {
          guestCustomerId = responseCustomerId;
          if (window.prestashop) {
            window.prestashop.customer = window.prestashop.customer || {};
            window.prestashop.customer.id = responseCustomerId;
            prestashop.customer = window.prestashop.customer;
          }
          // Once guest identity exists, dedupe on email only and ignore checkbox toggles.
          lastSubmittedFingerprint = getPayloadFingerprint({email: payload.email});
        } else {
          lastSubmittedFingerprint = payloadFingerprint;
        }
        // The buyer now has a checkout customer: a complete inline address can be persisted, so the
        // option sections may move from the "enter your email" hint to loading/revealing. Mark this
        // before emitting so every opcGuestInitSuccess handler already sees the buyer as identified.
        markBuyerIdentified();
        prestashop.emit(OPC_EVENTS.opcGuestInitSuccess, {resp});
      } else if (resp && resp.errors && Array.isArray(resp.errors.token) && resp.errors.token.length > 0) {
        // Token errors need fresh context, avoid retry loops while payload stays unchanged.
        lastSubmittedFingerprint = payloadFingerprint;
      } else if (resp && resp.success === false) {
        // No checkout customer was created: a complete address can never be persisted, flag it so
        // the option sections drop their loader for a recoverable error.
        markAddressPersistFailed();
        prestashop.emit(OPC_EVENTS.opcGuestInitFailed, {
          resp: normalizeErrorEventResponse(resp),
        });
        prestashop.emit(OPC_EVENTS.opcAddressPersistFailed, {
          resp: normalizeErrorEventResponse(resp),
        });
      }
    })
    .fail((resp, textStatus) => {
      if (textStatus === AJAX_STATUS_ABORT) {
        return;
      }

      markAddressPersistFailed();
      prestashop.emit(OPC_EVENTS.opcGuestInitFailed, {
        resp: normalizeErrorEventResponse(resp),
      });
      prestashop.emit(OPC_EVENTS.opcAddressPersistFailed, {
        resp: normalizeErrorEventResponse(resp),
      });
    })
    .always(() => {
      pendingFingerprint = '';
    });
}

function scheduleGuestInit() {
  if (isFinalSubmitInProgress) {
    return;
  }

  const debounceMs = isGuestUpdateMode()
    ? UPDATE_DEBOUNCE_MS
    : CREATE_DEBOUNCE_MS;

  window.clearTimeout(debounceTimer);
  debounceTimer = window.setTimeout(tryGuestInit, debounceMs);
}

// Field-level email feedback to connect the option-section "enter your email" hint to the actual field
// validated on blur (not while typing), and cleared the moment the buyer edits it again.
function showContactFieldError($field, message) {
  const field = $field.get(0);

  if (!field) {
    return;
  }

  clearFieldError(field);

  if (!message) {
    return;
  }

  const target = field.closest('.form-group, .mb-3') || field.parentElement || field;
  field.classList.add('is-invalid');
  field.dataset.opcFieldError = '1';

  const error = document.createElement('div');
  error.className = 'invalid-feedback d-block js-opc-field-error';
  error.textContent = message;
  target.classList.add('has-error');
  target.appendChild(error);
}

$(() => {
  if (!isGuestInitApplicable()) {
    return;
  }

  setInitialGuestEmailFingerprint();

  $('body').on('input change', `${OPC_CONTACT_SECTION_SELECTOR} ${EMAIL_FIELD_SELECTOR}`, (event) => {
    // Clear a stale invalid-email message as soon as the buyer edits the field again.
    clearFieldError($(event.currentTarget).get(0));
    scheduleGuestInit();
  });

  // Validate the email format on blur so the buyer sees, right at the field, why guest-init (and thus
  // the carriers/payment) is not progressing — without nagging mid-typing or on an untouched-empty field.
  $('body').on('focusout', `${OPC_CONTACT_SECTION_SELECTOR} ${EMAIL_FIELD_SELECTOR}`, (event) => {
    const $emailField = $(event.currentTarget);

    if (String($emailField.val() || '').trim() === '') {
      clearFieldError($emailField.get(0));

      return;
    }

    showContactFieldError(
      $emailField,
      isEmailValid($emailField) ? '' : getInvalidEmailMessage($emailField)
    );
  });

  $('body').on('change', `${OPC_CONTACT_SECTION_SELECTOR} ${INPUT_FIELD_SELECTOR}`, (event) => {
    if (isFieldInsideAddressModal(event.currentTarget)) {
      return;
    }

    scheduleGuestInit();
  });

  prestashop.on(OPC_EVENTS.opcFinalSubmitStarted, () => {
    stopGuestInitForFinalSubmit();
  });

  scheduleGuestInit();
});
})();
