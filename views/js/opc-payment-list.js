import {CORE_EVENTS, OPC_EVENTS} from './events';
import OPC_SELECTORS from './selectors';
import {getAjaxErrorResponse, getConfiguredOpcMessage, getConfiguredOpcUrl, normalizeErrorResponse} from './runtime/opc-runtime';
import {
  collectVisibleAddressContext,
  getUseSameAddressValue,
  getSelectedOrInlineAddressId,
  INVOICE_ADDRESS_CONTEXT_FIELDS,
} from './runtime/address/opc-address-context';

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
let lastFetchedPaymentListDom = null;

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

function hasSelectedCarrier() {
  const deliveryMethods = document.querySelector(OPC_SELECTORS.opc.deliveryMethods);

  if (!(deliveryMethods instanceof HTMLElement)) {
    return false;
  }

  return Boolean(
    deliveryMethods.querySelector(`${OPC_SELECTORS.inputs.deliveryOption}:checked`)
  );
}

function getLoaderOverlay() {
  return document.getElementById('opc-payment-methods-loader');
}

function showLoader() {
  const overlay = getLoaderOverlay();
  if (!overlay) {
    return;
  }

  const wasHidden = overlay.classList.contains('d-none');
  overlay.classList.remove('d-none');
  overlay.setAttribute('aria-hidden', 'false');

  if (wasHidden) {
    prestashop.emit(OPC_EVENTS.opcPaymentMethodsLoading, {});
  }
}

function hideLoader() {
  const overlay = getLoaderOverlay();
  if (!overlay) {
    return;
  }
  overlay.classList.add('d-none');
  overlay.setAttribute('aria-hidden', 'true');
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
  const useSameAddress = getUseSameAddressValue();
  const invoiceContext = useSameAddress === '0'
    ? collectVisibleAddressContext(form, OPC_SELECTORS.opc.billingFields, INVOICE_ADDRESS_CONTEXT_FIELDS)
    : {};
  const invoiceIdCountry = invoiceContext.invoice_id_country || '';
  const deliveryAddressId = getSelectedOrInlineAddressId(
    OPC_SELECTORS.opc.deliveryList,
    OPC_SELECTORS.opc.deliveryFields,
    'id_address_delivery'
  );
  // When billing mirrors delivery ("use same address"), the billing radio/hidden field is not
  // re-rendered on a delivery change and would carry a stale invoice address. Mirror delivery
  // explicitly so country-restricted payment methods are evaluated against the right address.
  const invoiceAddressId = useSameAddress === '1'
    ? deliveryAddressId
    : getSelectedOrInlineAddressId(
      OPC_SELECTORS.opc.billingList,
      OPC_SELECTORS.opc.billingFields,
      'id_address_invoice'
    );

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
  const fallbackMessage = getConfiguredOpcMessage('loadPaymentMethodsFailed', 'Unable to load payment methods.');

  if (!$container.length || !paymentMethodsUrl) {
    hideLoader();
    return;
  }

  const generation = ++fetchGeneration;
  showLoader();

  $.get(paymentMethodsUrl)
    .done((response) => {
      if (generation !== fetchGeneration) {
        return;
      }

      if (!response || response.success === false) {
        const error = normalizeErrorResponse(response, fallbackMessage);
        $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
        lastFetchedPaymentListDom = null;
        hideLoader();
        prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error});
        prestashop.emit('handleError', {eventType: 'opcPaymentMethods', resp: error});

        return;
      }

      const responsePaymentHtml = response.payment_html || '';

      if (lastFetchedPaymentListDom !== responsePaymentHtml) {
        $container.html(responsePaymentHtml);
        lastFetchedPaymentListDom = responsePaymentHtml;
        prestashop.emit(OPC_EVENTS.opcPaymentMethodsUpdated, response);
      } else {
        prestashop.emit(OPC_EVENTS.opcPaymentMethodsRefreshed, response);
      }

      hideLoader();
    })
    .fail((jqXHR) => {
      if (generation !== fetchGeneration) {
        return;
      }

      const error = getAjaxErrorResponse(jqXHR, fallbackMessage);
      $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
      lastFetchedPaymentListDom = null;
      hideLoader();
      prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error});
      prestashop.emit('handleError', {eventType: 'opcPaymentMethods', resp: error});
    });
}

$(fetchPaymentMethods);
$(document).on('click', '[data-opc-action="retry-payment"]', (event) => {
  event.preventDefault();
  fetchPaymentMethods();
});

prestashop.on(CORE_EVENTS.updatedCart, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcCarrierSelected, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcCarriersUpdated, () => {
  if (!hasSelectedCarrier()) {
    fetchPaymentMethods();
  }
});
prestashop.on(OPC_EVENTS.opcGuestInitSuccess, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcPaymentMethodsRetry, fetchPaymentMethods);
prestashop.on(OPC_EVENTS.opcCarriersLoading, () => {
  fetchGeneration += 1;
  showLoader();
});

prestashop.on(OPC_EVENTS.opcCarriersFailed, () => {
  const $container = getContainer();

  fetchGeneration += 1;
  if ($container.length) {
    $container.html(getTemplateHtml(OPC_SELECTORS.templates.paymentError.replace('#', '')));
    lastFetchedPaymentListDom = null;
  }
  hideLoader();

  prestashop.emit(OPC_EVENTS.opcPaymentMethodsFailed, {error: 'carrier fetch failed'});
});
}());
