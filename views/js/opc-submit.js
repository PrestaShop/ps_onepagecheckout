import {OPC_EVENTS} from './events';
import OPC_SELECTORS from './selectors';
import {getConfiguredOpcUrl, normalizeErrorEventResponse, getConfiguredOpcMessage, updatePayAmount} from './runtime/opc-runtime';
import {buildSelectAddressPayload} from './runtime/address/opc-address-context';

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
const DELIVERY_METHODS_SELECTOR = OPC_SELECTORS.opc.deliveryMethods;
const CHECKOUT_FOOTER_SELECTOR = OPC_SELECTORS.opc.checkoutFooter;
const USE_SAME_ADDRESS_SELECTOR = OPC_SELECTORS.opc.useSameAddress;
const DELIVERY_OPTION_SELECTOR = OPC_SELECTORS.inputs.deliveryOption;
const PAYMENT_OPTION_SELECTOR = OPC_SELECTORS.inputs.paymentOption;
const PAYMENT_CONDITIONS_SELECTOR = OPC_SELECTORS.inputs.conditions;
const OPC_SUBMIT_URL_KEY = 'opcSubmit';

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

function isRequiredFieldValid(field) {
  if (!(field instanceof HTMLElement) || !isElementVisible(field)) {
    return true;
  }

  if (field instanceof HTMLInputElement) {
    if (field.type === 'checkbox') {
      return field.checked;
    }

    if (field.type === 'radio') {
      if (!field.name) {
        return field.checked;
      }

      return Boolean(document.querySelector(`input[type="radio"][name="${field.name}"]:checked`));
    }

    return Boolean(field.value.trim());
  }

  if (field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
    return Boolean(String(field.value || '').trim());
  }

  return true;
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

  return paymentMethodsState !== 'loading' && paymentMethodsState !== 'failed';
}

function isCheckoutRefreshing() {
  return addressState === 'loading'
    || carriersState === 'loading'
    || carrierSelectionState === 'loading'
    || paymentMethodsState === 'loading';
}

function updatePayButtonLoadingState(payButton) {
  const spinner = payButton.querySelector('[data-opc-pay-spinner]');

  if (spinner instanceof HTMLElement) {
    spinner.classList.toggle('d-none', !isCheckoutRefreshing());
  }
}

function hasSelectedCarrier() {
  const deliveryMethods = document.querySelector(DELIVERY_METHODS_SELECTOR);
  const deliveryOptions = document.querySelectorAll(DELIVERY_OPTION_SELECTOR);

  if (!(deliveryMethods instanceof HTMLElement) || !isElementVisible(deliveryMethods)) {
    return true;
  }

  if (deliveryOptions.length === 0) {
    return false;
  }

  return Boolean(deliveryMethods.querySelector(`${DELIVERY_OPTION_SELECTOR}:checked`));
}

function hasSelectedPayment() {
  const paymentContainer = getPaymentContainer();

  if (!(paymentContainer instanceof HTMLElement) || !isElementVisible(paymentContainer)) {
    return true;
  }

  const paymentRadios = paymentContainer.querySelectorAll(PAYMENT_OPTION_SELECTOR);
  if (paymentRadios.length === 0) {
    return false;
  }

  return Boolean(paymentContainer.querySelector(`${PAYMENT_OPTION_SELECTOR}:checked`));
}

function validateForm() {
  const form = getCheckoutForm();
  const payButton = getPayButton();

  if (!(form instanceof HTMLFormElement) || !(payButton instanceof HTMLButtonElement)) {
    return;
  }

  const formFieldsAreValid = Array.from(form.querySelectorAll('[required]'))
    .filter((field) => !(field instanceof HTMLInputElement && field.matches(PAYMENT_CONDITIONS_SELECTOR)))
    .every((field) => isRequiredFieldValid(field));
  const conditionsAreValid = getConditionsToApproveInputs().every((input) => {
    if (!(input instanceof HTMLInputElement) || !input.required) {
      return true;
    }

    if (input.type === 'checkbox') {
      return input.checked;
    }

    return Boolean(String(input.value || '').trim());
  });
  const isValid = formFieldsAreValid
    && conditionsAreValid
    && hasSelectedCarrier()
    && !isCheckoutRefreshing()
    && arePaymentMethodsReady()
    && hasSelectedPayment();

  payButton.disabled = !isValid;
  updatePayButtonLoadingState(payButton);

  // Once the customer has tried to submit, keep the invalid-field highlights in sync even
  // while Pay stays disabled (e.g. empty required fields never reach validateNativeForm).
  if (hasAttemptedSubmit) {
    markFieldsValidity(form);
  }

  prestashop.emit(OPC_EVENTS.opcFormValidated, {isValid});
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
    if (!(input instanceof HTMLInputElement) || !input.required) {
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

  paymentContainer.scrollIntoView({block: 'center', behavior: 'smooth'});
  const firstPayment = paymentRadios[0];
  if (firstPayment instanceof HTMLInputElement) {
    firstPayment.focus();
  }

  return {
    isValid: false,
    paymentRadio: null,
  };
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

    element.classList.toggle('is-invalid', !element.checkValidity());
  });
}

function clearFieldValidityOnFix(event) {
  const element = event.target;

  if (!(element instanceof HTMLInputElement
    || element instanceof HTMLSelectElement
    || element instanceof HTMLTextAreaElement)) {
    return;
  }

  // Only clear the highlight as the user fixes a field; marking happens at submit time.
  if (element.classList.contains('is-invalid') && element.checkValidity()) {
    element.classList.remove('is-invalid');
  }
}

function validateNativeForm(form) {
  markFieldsValidity(form);

  if (!form.checkValidity()) {
    form.reportValidity();

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
    form.addEventListener('input', validateForm);
    form.addEventListener('input', clearFieldValidityOnFix);
    form.dataset.opcSubmitInputBound = '1';
  }

  if (!form.dataset.opcSubmitChangeBound) {
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

  // A disabled button fires no click, so the disabled Pay button is set to pointer-events:none
  // and the click falls through to this wrapper. Run the same precondition feedback as a real
  // submit so blockers outside #opc-form (unchecked terms, missing payment) are surfaced too,
  // not just the empty required fields inside the form.
  const payButtonWrapper = payButton.closest('.js-payment-confirmation') || payButton.parentElement;
  if (payButtonWrapper instanceof HTMLElement && !payButtonWrapper.dataset.opcSubmitAttemptBound) {
    payButtonWrapper.addEventListener('click', () => {
      if (!payButton.disabled) {
        return;
      }

      hasAttemptedSubmit = true;
      ensureSubmitPreconditions(form);
    });
    payButtonWrapper.dataset.opcSubmitAttemptBound = '1';
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

    element.addEventListener('change', validateForm);
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
    paymentMethodsState = 'loading';
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcPaymentMethodsFailed, () => {
    paymentMethodsState = 'failed';
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
    addressState = 'loading';
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
    carriersState = 'loading';
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarriersUpdated, () => {
    carriersState = 'ready';
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarriersFailed, () => {
    carriersState = 'failed';
    bindScopedValidationListeners(form);
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarrierSelectionLoading, () => {
    carrierSelectionState = 'loading';
    validateForm();
  });
  prestashop.on(OPC_EVENTS.opcCarrierSelected, () => {
    carrierSelectionState = 'ready';
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
