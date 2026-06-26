import {CORE_EVENTS, OPC_EVENTS} from './events';
import OPC_SELECTORS from './selectors';
import OPC_OPTION_LIST_STATE from './runtime/opc-option-list-state';
import {
  AJAX_READY_STATE_DONE,
  AJAX_STATUS_ABORT,
  getAjaxErrorResponse,
  getConfiguredOpcMessage,
  getConfiguredOpcUrl,
  normalizeErrorResponse,
  updateCartSummary,
} from './runtime/opc-runtime';
import {
  collectVisibleAddressContext,
  getUseSameAddressValue,
  INVOICE_ADDRESS_CONTEXT_FIELDS,
} from './runtime/address/opc-address-context';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */
(function psOpcCarrierListRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

if (!$) {
  return;
}

const CONTAINER_SELECTOR = OPC_SELECTORS.opc.deliveryMethods;
const URL_KEY = 'carriers';
const CHECKOUT_FORM_SELECTOR = OPC_SELECTORS.opc.checkout;
const FAILED_EVENT_NAME = OPC_EVENTS.opcCarriersFailed;
let selectedDeliveryAddressId = null;
let fetchCarriersGeneration = 0;
let activeFetchCarriersRequest = null;

function setCarrierOptionsState(state) {
  const container = document.querySelector(CONTAINER_SELECTOR);

  if (container instanceof HTMLElement) {
    container.dataset.opcCarriersState = state;
  }
}

function refreshCarrierOptionsState() {
  const container = document.querySelector(CONTAINER_SELECTOR);

  if (!(container instanceof HTMLElement)) {
    return;
  }

  container.dataset.opcCarriersState = container.querySelector(OPC_SELECTORS.inputs.deliveryOption)
    ? OPC_OPTION_LIST_STATE.AVAILABLE
    : OPC_OPTION_LIST_STATE.EMPTY;
}

function getTemplateHtml(templateId) {
  const template = document.querySelector(`#${templateId}`);

  return template ? template.innerHTML : '';
}

function getCheckoutForm() {
  return document.querySelector(CHECKOUT_FORM_SELECTOR);
}

function getSelectedSavedDeliveryAddressId() {
  const selectedRadio = document.querySelector(
    `${OPC_SELECTORS.opc.deliveryList} ${OPC_SELECTORS.opc.addressRadio}[name="id_address_delivery"]:checked`
  );
  const selectedAddressId = selectedRadio ? String(selectedRadio.getAttribute('value') || '') : '';

  if (!selectedAddressId || selectedAddressId === 'new_address') {
    return '';
  }

  return selectedAddressId;
}

function getFormValue(form, name) {
  const direct = form.querySelector(`[name="${name}"]`);
  const prefixed = form.querySelector(`[name="delivery_${name}"]`);

  return ((direct && direct.value) || (prefixed && prefixed.value) || '').trim();
}

function buildCarriersUrl(baseUrl) {
  const form = getCheckoutForm();

  if (!form || !baseUrl) {
    return '';
  }

  const useSameAddress = getUseSameAddressValue();
  const idCountry = getFormValue(form, 'id_country');
  const selectedSavedDeliveryAddressId = selectedDeliveryAddressId || getSelectedSavedDeliveryAddressId();
  if (!selectedSavedDeliveryAddressId && !idCountry) {
    return '';
  }

  const url = new URL(baseUrl, window.location.origin);

  if (idCountry) {
    url.searchParams.set('id_country', idCountry);
  }

  if (useSameAddress === '0') {
    Object.entries(
      collectVisibleAddressContext(form, OPC_SELECTORS.opc.billingFields, INVOICE_ADDRESS_CONTEXT_FIELDS)
    ).forEach(([field, value]) => {
      url.searchParams.set(field, value);
    });
  }

  if (selectedSavedDeliveryAddressId) {
    url.searchParams.set('id_address_delivery', selectedSavedDeliveryAddressId);
  }

  const postcode = getFormValue(form, 'postcode');
  const idState = getFormValue(form, 'id_state');
  const city = getFormValue(form, 'city');

  if (postcode) {
    url.searchParams.set('postcode', postcode);
  }
  if (idState) {
    url.searchParams.set('id_state', idState);
  }
  if (city) {
    url.searchParams.set('city', city);
  }

  // Let the server honour the "use same address" choice when syncing the invoice address.
  url.searchParams.set('use_same_address', useSameAddress);

  return url.toString();
}

function syncSelectedDeliveryAddressContext() {
  const deliveryMethodsContainer = document.querySelector(CONTAINER_SELECTOR);
  const selectedSavedDeliveryAddressId = getSelectedSavedDeliveryAddressId();

  if (selectedSavedDeliveryAddressId) {
    selectedDeliveryAddressId = selectedSavedDeliveryAddressId;
    if (deliveryMethodsContainer) {
      deliveryMethodsContainer.setAttribute('data-id-address', selectedSavedDeliveryAddressId);
    }

    return;
  }

  selectedDeliveryAddressId = null;
  if (deliveryMethodsContainer) {
    deliveryMethodsContainer.removeAttribute('data-id-address');
  }
}

function fetchCarriers() {
  const carriersUrl = buildCarriersUrl(getConfiguredOpcUrl(URL_KEY));
  const $container = $(CONTAINER_SELECTOR);
  const fallbackMessage = getConfiguredOpcMessage('loadCarriersFailed', 'Unable to load delivery methods.');

  if (!carriersUrl || !$container.length) {
    return;
  }

  const generation = ++fetchCarriersGeneration;
  if (activeFetchCarriersRequest && activeFetchCarriersRequest.readyState !== AJAX_READY_STATE_DONE) {
    activeFetchCarriersRequest.abort();
  }

  $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierLoader.replace('#', '')));
  setCarrierOptionsState(OPC_OPTION_LIST_STATE.LOADING);
  prestashop.emit(OPC_EVENTS.opcCarriersLoading, {});

  const request = $.get(carriersUrl);
  activeFetchCarriersRequest = request;

  request
    .done((response) => {
      if (generation !== fetchCarriersGeneration) {
        return;
      }

      if (!response || response.success === false) {
        const resp = normalizeErrorResponse(response, fallbackMessage);
        $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierError.replace('#', '')));
        setCarrierOptionsState(OPC_OPTION_LIST_STATE.FAILED);
        prestashop.emit(FAILED_EVENT_NAME, {resp});
        prestashop.emit('handleError', {eventType: 'opcCarriers', resp});
        return;
      }

      $container.html(response.carriers_html || '');
      refreshCarrierOptionsState();
      if (typeof response.id_address_delivery !== 'undefined') {
        if (response.id_address_delivery) {
          $container.attr('data-id-address', String(response.id_address_delivery));
        } else {
          $container.removeAttr('data-id-address');
        }
      }
      if (response.preview) {
        updateCartSummary(response.preview, response.totals);
      }
      prestashop.emit(OPC_EVENTS.opcCarriersUpdated, response);

      const checkedCarrier = $container.find(`${OPC_SELECTORS.inputs.deliveryOption}:checked`).get(0);
      if (checkedCarrier) {
        const selectedDeliveryOption = String(checkedCarrier.value || '');

        $container.attr('data-confirmed-delivery-option', selectedDeliveryOption);
        prestashop.emit(OPC_EVENTS.opcCarrierSelected, {
          selectedDeliveryOption,
          response,
        });
      } else {
        $container.removeAttr('data-confirmed-delivery-option');
      }
    })
    .fail((jqXHR, textStatus) => {
      if (generation !== fetchCarriersGeneration) {
        return;
      }

      if (textStatus === AJAX_STATUS_ABORT) {
        return;
      }

      const resp = getAjaxErrorResponse(jqXHR, fallbackMessage);
      $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierError.replace('#', '')));
      setCarrierOptionsState(OPC_OPTION_LIST_STATE.FAILED);
      prestashop.emit(FAILED_EVENT_NAME, {resp});
      prestashop.emit('handleError', {eventType: 'opcCarriers', resp});
    })
    .always(() => {
      if (activeFetchCarriersRequest === request) {
        activeFetchCarriersRequest = null;
      }
    });
}

$(function () {
  const form = getCheckoutForm();

  if (!form) {
    return;
  }

  fetchCarriers();
});

$(document).on('click', '[data-opc-action="retry-carriers"]', (event) => {
  event.preventDefault();
  fetchCarriers();
});

prestashop.on(OPC_EVENTS.opcCarriersRetry, fetchCarriers);

prestashop.on(OPC_EVENTS.opcDeliveryAddressUpdated, () => {
  syncSelectedDeliveryAddressContext();
  fetchCarriers();
});

prestashop.on(OPC_EVENTS.opcDeliveryAddressSelected, ({idAddress}) => {
  selectedDeliveryAddressId = String(idAddress || '');
  fetchCarriers();
});

prestashop.on(OPC_EVENTS.opcGuestInitSuccess, () => {
  syncSelectedDeliveryAddressContext();
  fetchCarriers();
});

prestashop.on(CORE_EVENTS.updatedCart, () => {
  // Cart mutations can change shipping eligibility and carrier prices.
  fetchCarriers();
});

const deliveryMethodsContainer = document.querySelector(CONTAINER_SELECTOR);
const initiallySelectedSavedDeliveryAddressId = getSelectedSavedDeliveryAddressId();
if (deliveryMethodsContainer && initiallySelectedSavedDeliveryAddressId) {
  selectedDeliveryAddressId = initiallySelectedSavedDeliveryAddressId;
}
}());
