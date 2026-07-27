import {OPC_EVENTS} from './events';
import OPC_SELECTORS from './selectors';
import OPC_OPTION_LIST_STATE from './runtime/opc-option-list-state';
import {getConfiguredOpcUrl, normalizeErrorEventResponse, getConfiguredOpcMessage, updatePayAmount} from './runtime/opc-runtime';
import {buildSelectAddressPayload} from './runtime/address/opc-address-context';
import {updateButtonSpinner} from './runtime/form/opc-button-loader';
import {clearFieldError, getFieldErrorMessages, getFieldErrors, renderFieldErrors} from './runtime/form/opc-field-errors';
import {reportFirstInvalidNativeField} from './runtime/form/opc-native-validation';
import {clearSectionValidationAlert, clearValidationAlert, renderSectionValidationAlert, renderValidationAlert} from './runtime/form/opc-validation-alert';

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

(function psOpcSubmitRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || null;

if (!$ || !prestashop || typeof prestashop.on !== 'function') {
  return;
}

const OPC_FORM_ID_SELECTOR = OPC_SELECTORS.opc.form;
const CHECKOUT_SELECTOR = OPC_SELECTORS.opc.checkout;
const PAY_BUTTON_SELECTOR = OPC_SELECTORS.opc.payButton;
const BILLING_SECTION_SELECTOR = OPC_SELECTORS.opc.billingSection;
const PAYMENT_METHODS_SELECTOR = OPC_SELECTORS.opc.paymentMethods;
const CHECKOUT_FOOTER_SELECTOR = OPC_SELECTORS.opc.checkoutFooter;
const USE_SAME_ADDRESS_SELECTOR = OPC_SELECTORS.opc.useSameAddress;
const DELIVERY_OPTION_SELECTOR = OPC_SELECTORS.inputs.deliveryOption;
const PAYMENT_OPTION_SELECTOR = OPC_SELECTORS.inputs.paymentOption;
const DELIVERY_VALIDATION_ALERT_ID = 'opc-delivery-validation-alert';
const PAYMENT_VALIDATION_ALERT_ID = 'opc-payment-validation-alert';
const OPC_SUBMIT_URL_KEY = 'opcSubmit';
const PAY_BUTTON_DISABLED_OPTION_LIST_STATES = [
  OPC_OPTION_LIST_STATE.EMPTY,
  OPC_OPTION_LIST_STATE.FAILED,
];

let billingToggleHandler = null;
let addressState = 'idle';
let carriersState = 'idle';
let carrierSelectionState = 'idle';
let paymentMethodsState = 'idle';
let isFinalSubmitInFlight = false;
let hasAttemptedSubmit = false;

function getCheckoutForm() {
  return document.querySelector(OPC_FORM_ID_SELECTOR) || document.querySelector(CHECKOUT_SELECTOR);
}

function getPayButton() {
  return document.querySelector(PAY_BUTTON_SELECTOR);
}

function getCheckoutFooter() {
  return document.querySelector(CHECKOUT_FOOTER_SELECTOR) || document;
}

function getConditionsToApproveInputs() {
  return Array.from(getCheckoutFooter().querySelectorAll('input[name^="conditions_to_approve"]'));
}

function appendConditionsToApproveToFormData(payload) {
  getConditionsToApproveInputs().forEach((input) => {
    if (!(input instanceof HTMLInputElement) || !input.name) {
      return;
    }

    if (input.type === 'checkbox') {
      if (input.checked) {
        payload.set(input.name, input.value);
      }

      return;
    }

    if (String(input.value || '').trim() !== '') {
      payload.set(input.name, input.value);
    }
  });
}

function isElementVisible(element) {
  if (!(element instanceof HTMLElement)) {
    return false;
  }

  const modal = element.closest('.modal');
  if (modal instanceof HTMLElement && !modal.classList.contains('show')) {
    return false;
  }

  const billingSection = element.closest(BILLING_SECTION_SELECTOR);
  if (billingSection instanceof HTMLElement && window.getComputedStyle(billingSection).display === 'none') {
    return false;
  }

  const computedStyle = window.getComputedStyle(element);

  return computedStyle.display !== 'none'
    && computedStyle.visibility !== 'hidden'
    && element.getClientRects().length > 0;
}

function findCheckedPaymentOption(form) {
  return form.querySelector(`${PAYMENT_OPTION_SELECTOR}:checked`)
    || document.querySelector(`${PAYMENT_METHODS_SELECTOR} ${PAYMENT_OPTION_SELECTOR}:checked`)
    || document.querySelector(`${PAYMENT_OPTION_SELECTOR}:checked`);
}

function getPaymentContainer() {
  const paymentContainer = document.querySelector(PAYMENT_METHODS_SELECTOR);

  return paymentContainer instanceof HTMLElement ? paymentContainer : null;
}

function arePaymentMethodsReady() {
  const paymentMethods = document.querySelector(PAYMENT_METHODS_SELECTOR);

  if (!(paymentMethods instanceof HTMLElement) || !isElementVisible(paymentMethods)) {
    return true;
  }

  return paymentMethodsState !== OPC_OPTION_LIST_STATE.LOADING
    && paymentMethodsState !== OPC_OPTION_LIST_STATE.FAILED;
}

function isCheckoutRefreshing() {
  return addressState === OPC_OPTION_LIST_STATE.LOADING
    || carriersState === OPC_OPTION_LIST_STATE.LOADING
    || carrierSelectionState === OPC_OPTION_LIST_STATE.LOADING
    || paymentMethodsState === OPC_OPTION_LIST_STATE.LOADING;
}

function updatePayButtonLoadingState(payButton) {
  updateButtonSpinner(payButton, '[data-opc-pay-spinner]', isCheckoutRefreshing());
}

function validateForm() {
  const form = getCheckoutForm();
  const payButton = getPayButton();

  if (!(form instanceof HTMLFormElement) || !(payButton instanceof HTMLButtonElement)) {
    return;
  }

  // Core parity (themes/_core/js/checkout-payment.js toggleOrderButton): a payment option flagged
  // binary (PaymentOption::setBinary(true), rendered as the `binary` class on the radio by
  // payment-methods.tpl) provides its OWN button (via the displayPaymentByBinaries hook OPC renders),
  // so the standard OPC pay button must be HIDDEN — not merely disabled. The theme's checkout-payment.js
  // (which runs on the OPC page) already handles enabling/disabling that module button on terms, so OPC
  // only needs to hide its own native button here. Module-agnostic: reads the `binary` class only.
  const selectedPaymentOption = findCheckedPaymentOption(form);
  const isBinaryPaymentOption = selectedPaymentOption instanceof HTMLElement
    && selectedPaymentOption.classList.contains('binary');
  payButton.classList.toggle('d-none', isBinaryPaymentOption);

  const requiredTermsUnchecked = getConditionsToApproveInputs()
    .some((input) => input instanceof HTMLInputElement
      && input.required
      && isElementVisible(input)
      && !input.checked);

  const carrierOptionsState = document.querySelector(OPC_SELECTORS.opc.deliveryMethods)?.dataset.opcCarriersState || '';
  const paymentOptionsState = document.querySelector(PAYMENT_METHODS_SELECTOR)?.dataset.opcPaymentMethodsState || '';

  // Native Pay-button gate: UNCHANGED (section states + payment-ready + terms). Deliberately does NOT
  // include native field validity or the carrier/payment selections — the button must not be greyed
  // out on those; the submit handler validates them at click time (ensureSubmitPreconditions).
  const isValid = !isFinalSubmitInFlight
    && !isCheckoutRefreshing()
    && !PAY_BUTTON_DISABLED_OPTION_LIST_STATES.includes(carrierOptionsState)
    && !PAY_BUTTON_DISABLED_OPTION_LIST_STATES.includes(paymentOptionsState)
    && arePaymentMethodsReady()
    && !requiredTermsUnchecked;

  payButton.disabled = !isValid;
  payButton.classList.toggle('disabled', !isValid);

  updatePayButtonLoadingState(payButton);

  // Once the customer has tried to submit, keep native invalid-field highlights in sync.
  if (hasAttemptedSubmit) {
    markFieldsValidity(form);
  }

  // Payload for payment modules (e.g. the ps_checkout SDK reads this via opcFormValidated to gate
  // payment). Unlike the button gate above, this is the FULL, accurate validity — it also covers
  // native field validity and the carrier/payment selections, mirroring the submit-time
  // ensureSubmitPreconditions() so modules don't proceed while required fields are empty/invalid.
  // Side-effect-free (no reportValidity/alerts/focus): this runs on every keystroke. This does NOT
  // affect the OPC button state — that stays on `isValid` so the button is never greyed out here.
  const isFormFullyValid = isValid
    && form.checkValidity()
    && !hasFlaggedFieldErrors(form)
    && isDeliverySelectionValid()
    && isPaymentSelectionValid(form);

  // Gate module-provided binary buttons in lockstep with the native Pay button, following the same
  // `isValid` gate so a binary block is greyed out/blocked whenever OPC's own Pay button would be
  // (mid-refresh, empty or failed carrier/payment sections, payment methods not ready, or required
  // terms unchecked). We use a dedicated `opc-binary-disabled` class — NOT `disabled` — so we never
  // co-write with Core's own terms gating (checkout-payment.js toggleOrderButton), which owns
  // `disabled` on these same blocks. Applied to Core's canonical binary selector.
  const paymentBinarySelector = (prestashop.selectors && prestashop.selectors.checkout && prestashop.selectors.checkout.paymentBinary)
      || '.payment-binary, .js-payment-binary';
  document.querySelectorAll(paymentBinarySelector).forEach((block) => {
    block.classList.toggle('opc-binary-disabled', !isFormFullyValid);
  });

  prestashop.emit(OPC_EVENTS.opcFormValidated, {isValid: isFormFullyValid});

  return isFormFullyValid;
}

function initBillingToggle() {
  const useSameAddressField = document.querySelector(USE_SAME_ADDRESS_SELECTOR);
  const billingSection = document.querySelector(BILLING_SECTION_SELECTOR);

  if (!(useSameAddressField instanceof HTMLInputElement) || !(billingSection instanceof HTMLElement)) {
    return;
  }

  if (billingToggleHandler) {
    useSameAddressField.removeEventListener('change', billingToggleHandler);
  }

  billingToggleHandler = () => {
    const isBillingVisible = !useSameAddressField.checked;

    billingSection.style.display = isBillingVisible ? '' : 'none';
    validateForm();
  };

  useSameAddressField.addEventListener('change', billingToggleHandler);
}

function reportRequiredConditionsValidity() {
  for (const input of getConditionsToApproveInputs()) {
    if (!(input instanceof HTMLInputElement) || !input.required || !isElementVisible(input)) {
      continue;
    }

    if (input.checkValidity()) {
      continue;
    }

    input.reportValidity();

    return false;
  }

  return true;
}

function resolveDeliverySelection() {
  const deliveryContainer = document.querySelector(OPC_SELECTORS.opc.deliveryMethods);

  if (!(deliveryContainer instanceof HTMLElement) || !isElementVisible(deliveryContainer)) {
    return true;
  }

  const deliveryOptions = deliveryContainer.querySelectorAll(DELIVERY_OPTION_SELECTOR);
  if (deliveryOptions.length === 0 || deliveryContainer.querySelector(`${DELIVERY_OPTION_SELECTOR}:checked`)) {
    return true;
  }

  renderSectionValidationAlert(
    [getConfiguredOpcMessage('missingCarrierSelection') || 'Please select a delivery method.'],
    OPC_SELECTORS.opc.deliveryMethodTitle,
    DELIVERY_VALIDATION_ALERT_ID
  );

  const firstDeliveryOption = deliveryOptions[0];
  if (firstDeliveryOption instanceof HTMLInputElement) {
    firstDeliveryOption.focus();
  }

  return false;
}

function resolvePaymentSelection(form) {
  const paymentContainer = getPaymentContainer();

  if (!(paymentContainer instanceof HTMLElement) || !isElementVisible(paymentContainer)) {
    return {
      isValid: true,
      paymentRadio: null,
    };
  }

  const paymentRadios = paymentContainer.querySelectorAll(PAYMENT_OPTION_SELECTOR);
  if (paymentRadios.length === 0) {
    return {
      isValid: true,
      paymentRadio: null,
    };
  }

  const selectedPayment = findCheckedPaymentOption(form);
  if (selectedPayment instanceof HTMLInputElement) {
    return {
      isValid: true,
      paymentRadio: selectedPayment,
    };
  }

  renderSectionValidationAlert(
    [getConfiguredOpcMessage('missingPaymentSelection') || 'Please select a payment method.'],
    OPC_SELECTORS.opc.paymentMethodTitle,
    PAYMENT_VALIDATION_ALERT_ID
  );

  const firstPayment = paymentRadios[0];
  if (firstPayment instanceof HTMLInputElement) {
    firstPayment.focus();
  }

  return {
    isValid: false,
    paymentRadio: null,
  };
}

function isDeliverySelectionValid() {
  const deliveryContainer = document.querySelector(OPC_SELECTORS.opc.deliveryMethods);

  if (!(deliveryContainer instanceof HTMLElement) || !isElementVisible(deliveryContainer)) {
    return true;
  }

  const deliveryOptions = deliveryContainer.querySelectorAll(DELIVERY_OPTION_SELECTOR);

  return deliveryOptions.length === 0
    || Boolean(deliveryContainer.querySelector(`${DELIVERY_OPTION_SELECTOR}:checked`));
}

function isPaymentSelectionValid(form) {
  const paymentContainer = getPaymentContainer();

  if (!(paymentContainer instanceof HTMLElement) || !isElementVisible(paymentContainer)) {
    return true;
  }

  if (paymentContainer.querySelectorAll(PAYMENT_OPTION_SELECTOR).length === 0) {
    return true;
  }

  return findCheckedPaymentOption(form) instanceof HTMLInputElement;
}

function ajaxCheckCartStillOrderable() {
  return $.post(`${window.prestashop.urls.pages.order}`, {
    ajax: 1,
    action: 'checkCartStillOrderable',
  });
}

function emitSubmitFailure(response) {
  const normalizedResponse = normalizeErrorEventResponse(response);
  prestashop.emit('handleError', {
    eventType: 'opcSubmit',
    resp: normalizedResponse,
  });
}

function emitSubmitRuntimeError(message) {
  emitSubmitFailure(normalizeErrorEventResponse(null, message));
}

function submitPaymentModuleForm(paymentRadio) {
  const optionId = paymentRadio.id;
  const wrapper = document.querySelector(`#pay-with-${optionId}-form`);
  const innerForm = wrapper ? wrapper.querySelector('form') : null;

  prestashop.emit(OPC_EVENTS.opcFinalSubmitStarted);

  if (innerForm instanceof HTMLFormElement) {
    HTMLFormElement.prototype.submit.call(innerForm);

    return;
  }

  emitSubmitRuntimeError(
    getConfiguredOpcMessage('missingPaymentForm', 'Unable to initialize the selected payment method.')
  );
}

function buildSubmitPayload(form, paymentRadio) {
  const payload = new FormData();
  const addressPayload = buildSelectAddressPayload(form);

  // Address ids can exist as hidden technical fields while the visible flow is inline.
  // Rebuild only those ids from the same visible selection logic used by address refreshes.
  Array.from(new FormData(form).entries()).forEach(([fieldName, value]) => {
    if (fieldName === 'id_address_delivery' || fieldName === 'id_address_invoice') {
      return;
    }

    payload.append(fieldName, value);
  });

  ['id_address_delivery', 'id_address_invoice'].forEach((fieldName) => {
    if (addressPayload[fieldName]) {
      payload.set(fieldName, addressPayload[fieldName]);
    }
  });

  appendConditionsToApproveToFormData(payload);

  if (!payload.has('static_token')) {
    const staticToken = window.prestashop?.static_token || window.prestashop?.token || '';

    if (staticToken !== '') {
      payload.set('static_token', staticToken);
    }
  }

  if (paymentRadio instanceof HTMLInputElement && paymentRadio.dataset.moduleName) {
    payload.set('paymentMethod', paymentRadio.dataset.moduleName);
  }

  return payload;
}

function markFieldsValidity(form) {
  Array.from(form.elements).forEach((element) => {
    if (!(element instanceof HTMLInputElement
      || element instanceof HTMLSelectElement
      || element instanceof HTMLTextAreaElement)) {
      return;
    }

    // Skip controls the browser never validates (disabled, hidden, buttons, etc.).
    if (!element.willValidate) {
      return;
    }

    if (element.dataset.opcFieldError === '1') {
      element.classList.add('is-invalid');

      return;
    }

    element.classList.toggle('is-invalid', !element.checkValidity());
  });
}

function hasFlaggedFieldErrors(form) {
  return form.querySelector('.is-invalid, [data-opc-field-error="1"]') !== null;
}

function clearStaleFieldError(event) {
  const element = event.target;

  if (!(element instanceof HTMLInputElement
    || element instanceof HTMLSelectElement
    || element instanceof HTMLTextAreaElement)) {
    return;
  }

  // Server-issued field errors (data-opc-field-error="1") persist while the buyer edits: only the
  // next conclusive server verdict (autosave or final submit) may clear or replace them. Native
  // validity feedback and the global alert still clear as soon as the buyer resumes editing.
  if (element.dataset.opcFieldError !== '1') {
    clearFieldError(element);
  }

  clearValidationAlert();
}

function validateNativeForm(form) {
  markFieldsValidity(form);

  if (!form.checkValidity()) {
    reportFirstInvalidNativeField(form, isElementVisible);

    return false;
  }

  return true;
}

function ensureSubmitPreconditions(form) {
  if (!validateNativeForm(form)) {
    return {
      isValid: false,
      paymentRadio: null,
    };
  }

  if (!reportRequiredConditionsValidity()) {
    return {
      isValid: false,
      paymentRadio: null,
    };
  }

  if (!arePaymentMethodsReady()) {
    validateForm();

    return {
      isValid: false,
      paymentRadio: null,
    };
  }

  const carrierOptionsState = document.querySelector(OPC_SELECTORS.opc.deliveryMethods)?.dataset.opcCarriersState || '';
  const paymentOptionsState = document.querySelector(PAYMENT_METHODS_SELECTOR)?.dataset.opcPaymentMethodsState || '';
  if (
    PAY_BUTTON_DISABLED_OPTION_LIST_STATES.includes(carrierOptionsState)
    || PAY_BUTTON_DISABLED_OPTION_LIST_STATES.includes(paymentOptionsState)
  ) {
    validateForm();

    return {
      isValid: false,
      paymentRadio: null,
    };
  }

  if (!resolveDeliverySelection()) {
    return {
      isValid: false,
      paymentRadio: null,
    };
  }

  return resolvePaymentSelection(form);
}

function getOpcSubmitUrl() {
  const submitUrl = getConfiguredOpcUrl(OPC_SUBMIT_URL_KEY);

  if (submitUrl) {
    return submitUrl;
  }

  emitSubmitRuntimeError(getConfiguredOpcMessage('missingSubmitUrl', 'Unable to submit checkout.'));

  return '';
}

async function fetchOpcSubmitResponse(submitUrl, payload) {
  const rawResponse = await fetch(submitUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
    },
    body: payload,
  });

  return rawResponse.json().catch(() => ({}));
}

function handleOpcSubmitFailure(response) {
  if (response && response.reload) {
    window.location.href = response.checkout_url || window.location.href;

    return true;
  }

  const form = getCheckoutForm();
  const fieldErrors = getFieldErrors(response);
  if (form instanceof HTMLFormElement && renderFieldErrors(form, fieldErrors)) {
    return true;
  }

  if (renderValidationAlert(getFieldErrorMessages(fieldErrors), form)) {
    return true;
  }

  const normalizedResponse = normalizeErrorEventResponse(response);
  if (normalizedResponse.errors.length === 0) {
    emitSubmitRuntimeError(getConfiguredOpcMessage('submitFailed', 'Unable to submit checkout.'));

    return true;
  }

  emitSubmitFailure(response);

  return true;
}

async function continueSuccessfulSubmit(response, paymentRadio) {
  const checkResponse = await ajaxCheckCartStillOrderable();
  const checkoutHandler = prestashop.checkout?.onCheckOrderableCartResponse;

  if (typeof checkoutHandler === 'function') {
    if (checkoutHandler(checkResponse, {})) {
      return;
    }
  } else if (checkResponse && checkResponse.errors === true && checkResponse.cartUrl) {
    window.location.href = checkResponse.cartUrl;

    return;
  }

  if (paymentRadio instanceof HTMLInputElement) {
    submitPaymentModuleForm(paymentRadio);

    return;
  }

  window.location.href = response.checkout_url || window.prestashop.urls.pages.order;
}

  async function submitBeforePayment(form) {
    if (isFinalSubmitInFlight) {
      return;
    }

    const paymentSelection = ensureSubmitPreconditions(form);
    if (!paymentSelection.isValid) {
      return;
    }

    const submitUrl = getOpcSubmitUrl();
    if (!submitUrl) {
      return;
    }

    const payButton = getPayButton();
    const payload = buildSubmitPayload(form, paymentSelection.paymentRadio);

    isFinalSubmitInFlight = true;
    if (payButton instanceof HTMLButtonElement) {
      payButton.disabled = true;
    }

    try {
      const response = await fetchOpcSubmitResponse(submitUrl, payload);

      if (!response || response.success !== true) {
        handleOpcSubmitFailure(response);
      }
    } catch (error) {
      prestashop.emit('handleError', {
        eventType: 'opcSubmit',
        resp: {},
      });
      prestashop.emit(OPC_EVENTS.opcSubmitFailed, {error});
    } finally {
      isFinalSubmitInFlight = false;
      validateForm();
    }
  }

async function submitOpcPay(form) {
  if (isFinalSubmitInFlight) {
    return;
  }

  const paymentSelection = ensureSubmitPreconditions(form);
  if (!paymentSelection.isValid) {
    return;
  }

  const submitUrl = getOpcSubmitUrl();
  if (!submitUrl) {
    return;
  }

  const payButton = getPayButton();
  const payload = buildSubmitPayload(form, paymentSelection.paymentRadio);

  isFinalSubmitInFlight = true;
  if (payButton instanceof HTMLButtonElement) {
    payButton.disabled = true;
  }

  try {
    const response = await fetchOpcSubmitResponse(submitUrl, payload);

    if (!response || response.success !== true) {
      handleOpcSubmitFailure(response);
      return;
    }

    await continueSuccessfulSubmit(response, paymentSelection.paymentRadio);
  } catch (error) {
    emitSubmitFailure(
      normalizeErrorEventResponse(
        null,
        getConfiguredOpcMessage('submitFailed', 'Unable to submit checkout.')
      )
    );
  } finally {
    isFinalSubmitInFlight = false;
    validateForm();
  }
}

function bindValidationListeners(form, payButton) {
  if (!form.dataset.opcSubmitInputBound) {
    form.addEventListener('input', clearStaleFieldError);
    form.addEventListener('input', validateForm);
    form.dataset.opcSubmitInputBound = '1';
  }

  if (!form.dataset.opcSubmitChangeBound) {
    form.addEventListener('change', clearStaleFieldError);
    form.addEventListener('change', validateForm);
    form.dataset.opcSubmitChangeBound = '1';
  }

  if (!form.dataset.opcSubmitHandlerBound) {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      hasAttemptedSubmit = true;
      submitOpcPay(form);
    });
    form.dataset.opcSubmitHandlerBound = '1';
  }

  if (!payButton.dataset.opcSubmitHandlerBound) {
    payButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();

      if (isFinalSubmitInFlight || payButton.disabled) {
        return;
      }

      hasAttemptedSubmit = true;

      submitOpcPay(form);
    });
    payButton.dataset.opcSubmitHandlerBound = '1';
  }

}

function bindChangeValidationListeners(root, selector, datasetKey) {
  if (!(root instanceof HTMLElement || root instanceof HTMLFormElement)) {
    return;
  }

  root.querySelectorAll(selector).forEach((element) => {
    if (!(element instanceof HTMLInputElement) && !(element instanceof HTMLSelectElement) && !(element instanceof HTMLTextAreaElement)) {
      return;
    }

    if (element.dataset[datasetKey] === '1') {
      return;
    }

    element.addEventListener('change', () => {
      if (selector === PAYMENT_OPTION_SELECTOR) {
        clearSectionValidationAlert(PAYMENT_VALIDATION_ALERT_ID);
      }

      validateForm();
    });
    element.dataset[datasetKey] = '1';
  });
}

function bindScopedValidationListeners(form) {
  bindChangeValidationListeners(form, DELIVERY_OPTION_SELECTOR, 'opcDeliveryValidationBound');

  const paymentContainer = getPaymentContainer();
  if (paymentContainer instanceof HTMLElement) {
    bindChangeValidationListeners(paymentContainer, PAYMENT_OPTION_SELECTOR, 'opcPaymentValidationBound');
  }

  const checkoutFooter = getCheckoutFooter();
  if (checkoutFooter instanceof HTMLElement || checkoutFooter instanceof Document) {
    bindChangeValidationListeners(
      checkoutFooter instanceof Document ? checkoutFooter.documentElement : checkoutFooter,
      'input[name^="conditions_to_approve"]',
      'opcConditionsValidationBound',
    );
  }
}

function bindCheckoutValidation() {
  const form = getCheckoutForm();
  const payButton = getPayButton();

  if (!(form instanceof HTMLFormElement) || !(payButton instanceof HTMLButtonElement)) {
    return;
  }

  bindValidationListeners(form, payButton);
  bindScopedValidationListeners(form);
}

$(document).ready(() => {
  const form = getCheckoutForm();
  const payButton = getPayButton();

  if (!(form instanceof HTMLFormElement) || !(payButton instanceof HTMLButtonElement)) {
    return;
  }

  prestashop.on(OPC_EVENTS.opcFinalSubmitStarted, () => {
    isFinalSubmitInFlight = true;
  });

  bindCheckoutValidation();
  initBillingToggle();
  validateForm();

  window.ps_onepagecheckout.submitBeforePayment = () => {
    const form = getCheckoutForm();

    if (!(form instanceof HTMLFormElement)) {
      return Promise.reject(new Error('OPC form not found.'));
    }

    return submitBeforePayment(form);
  };

  prestashop.on(OPC_EVENTS.opcPaymentMethodsLoading, () => {
    paymentMethodsState = OPC_OPTION_LIST_STATE.LOADING;
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcPaymentMethodsFailed, () => {
    paymentMethodsState = OPC_OPTION_LIST_STATE.FAILED;
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcPaymentMethodsUpdated, () => {
    paymentMethodsState = 'ready';
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcPaymentMethodsRefreshed, () => {
    paymentMethodsState = 'ready';
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcAddressesLoading, () => {
    addressState = OPC_OPTION_LIST_STATE.LOADING;
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcAddressesUpdated, () => {
    addressState = 'ready';
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcAddressesFailed, () => {
    addressState = 'failed';
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcDeliveryAddressUpdated, () => {
    initBillingToggle();
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcBillingAddressUpdated, () => {
    initBillingToggle();
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarriersLoading, () => {
    carrierSelectionState = 'ready';
    carriersState = OPC_OPTION_LIST_STATE.LOADING;
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarriersUpdated, () => {
    carriersState = 'ready';
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarriersFailed, () => {
    carriersState = OPC_OPTION_LIST_STATE.FAILED;
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarriersAwaiting, () => {
    // The carrier section withheld its options (awaiting a usable address) — a SETTLED result, not a
    // loader. A deferred inline country change emits opcCarriersLoading (which also puts the payment
    // section into LOADING via opcPaymentMethodsLoading) and then resolves to awaiting WITHOUT an
    // opcCarriersUpdated / opcPaymentMethodsUpdated. Without clearing both here the LOADING state would
    // stick and the Pay button would spin forever. The button stays disabled via the missing
    // carrier/payment selection, not a spinner.
    carriersState = OPC_OPTION_LIST_STATE.AWAITING_ADDRESS;
    paymentMethodsState = OPC_OPTION_LIST_STATE.AWAITING_ADDRESS;
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarrierSelectionLoading, () => {
    carrierSelectionState = OPC_OPTION_LIST_STATE.LOADING;
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarrierSelected, () => {
    carrierSelectionState = 'ready';
    clearSectionValidationAlert(DELIVERY_VALIDATION_ALERT_ID);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarrierSelectionFailed, () => {
    carrierSelectionState = 'failed';
    validateForm();
  });
  prestashop.on('updatedCart', () => {
    const totals = prestashop.cart && prestashop.cart.totals;
    if (totals) {
      updatePayAmount(totals);
      return;
    }
    // prestashop.cart is not available (e.g. voucher removal goes through a raw fetch,
    // so resp.cart is undefined). Fetch totals from the dedicated endpoint instead.
    const cartTotalsUrl = getConfiguredOpcUrl('cartTotals');
    if (!cartTotalsUrl) {
      return;
    }
    fetch(cartTotalsUrl, {credentials: 'same-origin'})
      .then((r) => r.json())
      .then((data) => {
        if (data && data.success && data.totals) {
          updatePayAmount(data.totals);
        }
      })
      .catch(() => {});
  });
});
}());
