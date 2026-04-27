import OPC_EVENTS from './events';
import OPC_SELECTORS from './selectors';
import {getAjaxErrorResponse, getConfiguredOpcUrl, normalizeErrorResponse} from './runtime/opc-runtime';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */
(function psOpcPaymentListRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

if (!$) {
  return;
}

const CONTAINER_SELECTOR = OPC_SELECTORS.opc.paymentMethods;
const CHECKOUT_FORM_SELECTOR = OPC_SELECTORS.opc.checkout;
const URL_KEY = 'paymentMethods';
let fetchGeneration = 0;

function getTemplateHtml(templateId) {
  const template = document.querySelector(`#${templateId}`);

  return template ? template.innerHTML : '';
}

function getContainer() {
  return $(CONTAINER_SELECTOR);
}

function getCheckoutForm() {
  return document.querySelector(CHECKOUT_FORM_SELECTOR);
}

function getSelectedSavedAddressId(listSelector, radioName) {
  const selectedRadio = document.querySelector(
    `${listSelector} ${OPC_SELECTORS.opc.addressRadio}[name="${radioName}"]:checked`
  );
  const selectedAddressId = selectedRadio ? String(selectedRadio.getAttribute('value') || '') : '';

  if (!selectedAddressId || selectedAddressId === 'new_address') {
    return '';
  }

  return selectedAddressId;
}

function setLoading() {
  const $container = getContainer();
  if (!$container.length) {
    return;
  }

  $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentLoader.replace('#', '')));
  prestashop.emit(OPC_EVENTS.opcPaymentMethodsLoading, {});
}

function buildPaymentMethodsUrl(baseUrl) {
  const form = getCheckoutForm();

  if (!form || !baseUrl) {
    return '';
  }

  const url = new URL(baseUrl, window.location.origin);
  const idCountry = form.querySelector('[name="id_country"]')?.value
    || form.querySelector('[name="delivery_id_country"]')?.value
    || '';
  const billingSection = form.querySelector(OPC_SELECTORS.opc.billingFields);
  const invoiceIdCountry = (billingSection ? billingSection.querySelector('[name="id_country"]') : null)?.value || '';
  const deliveryAddressId = getSelectedSavedAddressId(OPC_SELECTORS.opc.deliveryList, 'id_address_delivery')
    || form.querySelector('[name="id_address_delivery"]')?.value
    || '';
  const invoiceAddressId = getSelectedSavedAddressId(OPC_SELECTORS.opc.billingList, 'id_address_invoice')
    || form.querySelector('[name="id_address_invoice"]')?.value
    || '';

  if (idCountry) {
    url.searchParams.set('id_country', idCountry);
  }

  if (invoiceIdCountry) {
    url.searchParams.set('invoice_id_country', invoiceIdCountry);
  }

  if (deliveryAddressId) {
    url.searchParams.set('id_address_delivery', deliveryAddressId);
  }

  if (invoiceAddressId) {
    url.searchParams.set('id_address_invoice', invoiceAddressId);
  }

  return url.toString();
}

function fetchPaymentMethods() {
  const $container = getContainer();
  const paymentMethodsUrl = buildPaymentMethodsUrl(getConfiguredOpcUrl(URL_KEY));

  if (!$container.length || !paymentMethodsUrl) {
    return;
  }

  const generation = ++fetchGeneration;
  setLoading();

  $.get(paymentMethodsUrl)
    .done((response) => {
      if (generation !== fetchGeneration) {
        return;
      }

      if (!response || response.success === false) {
        const error = normalizeErrorResponse(response, 'Unable to load payment methods.');
        $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
        prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error});
        prestashop.emit('handleError', {eventType: 'opcPaymentMethods', resp: error});

        return;
      }

      $container.html(response.payment_html || '');
      prestashop.emit(OPC_EVENTS.opcPaymentMethodsUpdated, response);
    })
    .fail((jqXHR) => {
      if (generation !== fetchGeneration) {
        return;
      }

      const error = getAjaxErrorResponse(jqXHR, 'Unable to load payment methods.');
      $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
      prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error});
      prestashop.emit('handleError', {eventType: 'opcPaymentMethods', resp: error});
    });
}

$(fetchPaymentMethods);
$(document).on('click', '[data-opc-action="retry-payment"]', (event) => {
  event.preventDefault();
  fetchPaymentMethods();
});

prestashop.on(OPC_EVENTS.opcCarrierSelected, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcBillingAddressUpdated, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcGuestInitSuccess, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcPaymentMethodsRetry, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcCarriersLoading, () => {
  fetchGeneration += 1;
  setLoading();
});

prestashop.on(OPC_EVENTS.opcCarriersFailed, () => {
  const $container = getContainer();

  fetchGeneration += 1;
  if ($container.length) {
    $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
  }

  prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error: 'carrier fetch failed'});
});
}());
