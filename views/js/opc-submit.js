import OPC_EVENTS from './events';
import OPC_SELECTORS from './selectors';
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL/3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 */
(function psOpcSubmitRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || null;

if (!$) {
  return;
}

const OPC_FORM_SELECTOR = OPC_SELECTORS.opc.checkout;
const OPC_FORM_ID_SELECTOR = OPC_SELECTORS.opc.form;
const PAY_BUTTON_SELECTOR = OPC_SELECTORS.opc.payButton;
const DELIVERY_OPTION_SELECTOR = OPC_SELECTORS.inputs.deliveryOption;
const PAYMENT_OPTION_SELECTOR = OPC_SELECTORS.inputs.paymentOption;
const CONDITIONS_SELECTOR = OPC_SELECTORS.inputs.conditions;
const BILLING_SECTION_SELECTOR = OPC_SELECTORS.opc.billingSection;
const DELIVERY_METHODS_SELECTOR = OPC_SELECTORS.opc.deliveryMethods;
const PAYMENT_METHODS_SELECTOR = OPC_SELECTORS.opc.paymentMethods;
let finalSubmitStarted = false;
let paymentMethodsState = 'idle';

function getCheckoutConditionCheckboxes() {
  return Array.from(document.querySelectorAll(CONDITIONS_SELECTOR))
    .filter((checkbox) => checkbox instanceof HTMLInputElement);
}

function isCheckoutConditionField(field) {
  return field instanceof HTMLInputElement
    && field.matches(CONDITIONS_SELECTOR);
}

function getCheckoutForm() {
  return document.querySelector(OPC_FORM_ID_SELECTOR) || document.querySelector(OPC_FORM_SELECTOR);
}

function getPayButton() {
  return document.querySelector(PAY_BUTTON_SELECTOR);
}

function isVisibleField(field) {
  if (!(field instanceof HTMLElement)) {
    return false;
  }

  const hiddenModal = field.closest('.modal');
  if (hiddenModal instanceof HTMLElement && !hiddenModal.classList.contains('show')) {
    return false;
  }

  const billingSection = field.closest(BILLING_SECTION_SELECTOR);
  if (billingSection instanceof HTMLElement && window.getComputedStyle(billingSection).display === 'none') {
    return false;
  }

  const computedStyle = window.getComputedStyle(field);

  return computedStyle.display !== 'none'
    && computedStyle.visibility !== 'hidden'
    && field.getClientRects().length > 0;
}

function isRequiredFieldValid(field) {
  if (!(field instanceof HTMLElement) || !isVisibleField(field)) {
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

function hasSelectedCarrier() {
  const deliveryMethods = document.querySelector(DELIVERY_METHODS_SELECTOR);
  const deliveryOptions = document.querySelectorAll(DELIVERY_OPTION_SELECTOR);

  if (!(deliveryMethods instanceof HTMLElement) || !isVisibleField(deliveryMethods)) {
    return true;
  }

  if (deliveryOptions.length === 0) {
    return false;
  }

  return Boolean(document.querySelector(`${DELIVERY_OPTION_SELECTOR}:checked`));
}

function hasSelectedPayment() {
  const paymentMethods = document.querySelector(PAYMENT_METHODS_SELECTOR);
  const paymentOptions = document.querySelectorAll(PAYMENT_OPTION_SELECTOR);

  if (!(paymentMethods instanceof HTMLElement) || !isVisibleField(paymentMethods)) {
    return true;
  }

  if (paymentOptions.length === 0) {
    return false;
  }

  return Boolean(document.querySelector(`${PAYMENT_OPTION_SELECTOR}:checked`));
}

function isPaymentMethodsReady() {
  const paymentMethods = document.querySelector(PAYMENT_METHODS_SELECTOR);

  if (!(paymentMethods instanceof HTMLElement) || !isVisibleField(paymentMethods)) {
    return true;
  }

  return paymentMethodsState !== 'loading' && paymentMethodsState !== 'failed';
}

function areCheckoutConditionsAccepted() {
  const checkboxes = getCheckoutConditionCheckboxes();

  if (checkboxes.length === 0) {
    return true;
  }

  return checkboxes.every((checkbox) => checkbox.checked);
}

function validateCheckoutState() {
  const form = getCheckoutForm();
  const payButton = getPayButton();

  if (!(form instanceof HTMLFormElement) || !(payButton instanceof HTMLButtonElement)) {
    return;
  }

  const requiredFields = Array.from(form.querySelectorAll('[required]'))
    .filter((field) => !isCheckoutConditionField(field));
  const allRequiredFieldsValid = requiredFields.every(isRequiredFieldValid);
  const isValid = allRequiredFieldsValid
    && hasSelectedCarrier()
    && isPaymentMethodsReady()
    && hasSelectedPayment()
    && areCheckoutConditionsAccepted();

  payButton.disabled = !isValid;
}

function emitFinalSubmitStarted() {
  if (finalSubmitStarted) {
    return;
  }

  finalSubmitStarted = true;

  if (prestashop && typeof prestashop.emit === 'function') {
    prestashop.emit(OPC_EVENTS.opcFinalSubmitStarted);
  }
}

$(function () {
  const form = getCheckoutForm();
  const payButton = getPayButton();

  if (
    !(form instanceof HTMLFormElement)
    || !(payButton instanceof HTMLButtonElement)
    || !prestashop
    || typeof prestashop.on !== 'function'
  ) {
    return;
  }

  prestashop.on(OPC_EVENTS.opcFinalSubmitStarted, () => {
    finalSubmitStarted = true;
  });

  form.addEventListener('input', validateCheckoutState);
  form.addEventListener('change', validateCheckoutState);
  form.addEventListener('submit', emitFinalSubmitStarted);
  $(document).on('change', `${PAYMENT_OPTION_SELECTOR}, ${DELIVERY_OPTION_SELECTOR}, ${CONDITIONS_SELECTOR}, ${OPC_SELECTORS.opc.useSameAddress}`, validateCheckoutState);

  prestashop.on(OPC_EVENTS.opcCarriersUpdated, validateCheckoutState);
  prestashop.on(OPC_EVENTS.opcCarriersFailed, validateCheckoutState);
  prestashop.on(OPC_EVENTS.opcPaymentMethodsLoading, () => {
    paymentMethodsState = 'loading';
    validateCheckoutState();
  });
  prestashop.on(OPC_EVENTS.opcPaymentMethodsFailed, () => {
    paymentMethodsState = 'failed';
    validateCheckoutState();
  });
  prestashop.on(OPC_EVENTS.opcPaymentMethodsUpdated, () => {
    paymentMethodsState = 'ready';
    validateCheckoutState();
  });
  prestashop.on(OPC_EVENTS.opcPaymentMethodSelected, validateCheckoutState);
  prestashop.on(OPC_EVENTS.opcDeliveryAddressUpdated, validateCheckoutState);
  prestashop.on(OPC_EVENTS.opcBillingAddressUpdated, validateCheckoutState);

  validateCheckoutState();
});
})();
