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
const OPC_FORM_SELECTOR = '.one-page-checkout';
const OPC_ADDRESS_FORM_SELECTOR = '.js-opc-address-form';
const EMAIL_FIELD_SELECTOR = 'input[name="email"]';
const CHECKBOX_FIELD_SELECTOR = 'input[type="checkbox"]';
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
 * Additional checkbox fields are sent as `'1'` / `'0'`.
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

function getOpcRuntimeConfiguration() {
  if (!window || typeof window.ps_onepagecheckout !== 'object' || !window.ps_onepagecheckout) {
    return null;
  }

  return window.ps_onepagecheckout;
}

function getConfiguredOpcUrl(urlKey) {
  const runtimeConfiguration = getOpcRuntimeConfiguration();

  if (runtimeConfiguration && runtimeConfiguration.urls && runtimeConfiguration.urls[urlKey]) {
    return String(runtimeConfiguration.urls[urlKey]);
  }

  return '';
}

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

function getOpcAddressContainer() {
  return $(OPC_ADDRESS_FORM_SELECTOR).first();
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

  return $(OPC_FORM_SELECTOR).length > 0 && getOpcAddressContainer().length > 0;
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

  $container.find(CHECKBOX_FIELD_SELECTOR).each((_, checkbox) => {
    const fieldName = checkbox.name;

    if (!fieldName) {
      return;
    }

    payload[fieldName] = checkbox.checked ? '1' : '0';
  });

  return payload;
}

function collectRequiredCheckboxState($container) {
  const requiredCheckboxes = {};

  $container.find(`${CHECKBOX_FIELD_SELECTOR}[required]`).each((_, checkbox) => {
    const fieldName = checkbox.name;

    if (!fieldName) {
      return;
    }

    requiredCheckboxes[fieldName] = checkbox.checked ? '1' : '0';
  });

  return requiredCheckboxes;
}

function getPayloadFingerprint(payload) {
  const sortedKeys = Object.keys(payload).sort();

  return sortedKeys.map((key) => `${key}:${payload[key]}`).join('|');
}

function hasMissingRequiredConsent(requiredCheckboxState) {
  return Object.values(requiredCheckboxState).includes('0');
}

function setInitialGuestEmailFingerprint() {
  const guestUpdateMode = isGuestUpdateMode();

  if (!guestUpdateMode) {
    return;
  }

  const $container = getOpcAddressContainer();
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

function tryGuestInit() {
  if (isFinalSubmitInProgress) {
    return;
  }

  if (!isGuestInitApplicable()) {
    return;
  }

  const $container = getOpcAddressContainer();
  const $emailField = getEmailField($container);

  if (!isEmailValid($emailField)) {
    return;
  }

  const payload = collectPayload($container);
  const requiredCheckboxState = collectRequiredCheckboxState($container);
  const guestUpdateMode = isGuestUpdateMode();

  if (!guestUpdateMode && hasMissingRequiredConsent(requiredCheckboxState)) {
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
  if (guestInitUrl === '') {
    prestashop.emit('handleError', {
      eventType: 'opcGuestInit',
      resp: {errors: {'': ['Missing OPC guest init URL.']}},
    });

    return;
  }

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
          // Once guest identity exists, dedupe on email only and ignore checkbox toggles.
          lastSubmittedFingerprint = getPayloadFingerprint({email: payload.email});
        } else {
          lastSubmittedFingerprint = payloadFingerprint;
        }
        prestashop.emit('opcGuestInitSuccess', {resp});
      } else if (resp && resp.errors && Array.isArray(resp.errors.token) && resp.errors.token.length > 0) {
        // Token errors need fresh context, avoid retry loops while payload stays unchanged.
        lastSubmittedFingerprint = payloadFingerprint;
      } else if (resp && resp.success === false) {
        prestashop.emit('handleError', {eventType: 'opcGuestInit', resp});
      }
    })
    .fail((resp) => {
      if (resp && resp.statusText === 'abort') {
        return;
      }

      prestashop.emit('handleError', {eventType: 'opcGuestInit', resp});
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

$(() => {
  if (!isGuestInitApplicable()) {
    return;
  }

  setInitialGuestEmailFingerprint();

  $('body').on('input change', `${OPC_ADDRESS_FORM_SELECTOR} ${EMAIL_FIELD_SELECTOR}`, () => {
    scheduleGuestInit();
  });

  $('body').on('change', `${OPC_ADDRESS_FORM_SELECTOR} ${CHECKBOX_FIELD_SELECTOR}`, () => {
    scheduleGuestInit();
  });

  prestashop.on('updatedOpcAddressForm', () => {
    scheduleGuestInit();
  });
  prestashop.on('opcFinalSubmitStarted', () => {
    stopGuestInitForFinalSubmit();
  });

  scheduleGuestInit();
});
})();
