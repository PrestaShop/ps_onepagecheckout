import OPC_EVENTS from './events';
import OPC_SELECTORS from './selectors';
import {getAjaxErrorResponse, getConfiguredOpcUrl, normalizeErrorResponse, updateCartSummary} from './runtime/opc-runtime';

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

  if (selectedDeliveryAddressId) {
    const url = new URL(baseUrl, window.location.origin);

    url.searchParams.set('id_address_delivery', selectedDeliveryAddressId);

    return url.toString();
  }

  const deliveryMethodsContainer = document.querySelector(CONTAINER_SELECTOR);
  const idCountry = getFormValue(form, 'id_country');
  if (!idCountry) {
    return '';
  }

  const url = new URL(baseUrl, window.location.origin);
  const selectedSavedDeliveryAddressId = getSelectedSavedDeliveryAddressId();

  url.searchParams.set('id_country', idCountry);

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

  return url.toString();
}

function fetchCarriers() {
  const carriersUrl = buildCarriersUrl(getConfiguredOpcUrl(URL_KEY));
  const $container = $(CONTAINER_SELECTOR);

  if (!carriersUrl || !$container.length) {
    return;
  }

  $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierLoader.replace('#', '')));
  prestashop.emit(OPC_EVENTS.opcCarriersLoading, {});

  $.get(carriersUrl)
    .done((response) => {
      if (!response || response.success === false) {
        const resp = normalizeErrorResponse(response, 'Unable to load delivery methods.');
        $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierError.replace('#', '')));
        prestashop.emit(FAILED_EVENT_NAME, {resp});
        prestashop.emit('handleError', {eventType: 'opcCarriers', resp});
        return;
      }

      $container.html(response.carriers_html || '');
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
    .fail((jqXHR) => {
      const resp = getAjaxErrorResponse(jqXHR, 'Unable to load delivery methods.');
      $container.html(getTemplateHtml(OPC_SELECTORS.templates.carrierError.replace('#', '')));
      prestashop.emit(FAILED_EVENT_NAME, {resp});
      prestashop.emit('handleError', {eventType: 'opcCarriers', resp});
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
  const deliveryMethodsContainer = document.querySelector(CONTAINER_SELECTOR);
  const selectedSavedDeliveryAddressId = getSelectedSavedDeliveryAddressId();

  if (selectedSavedDeliveryAddressId) {
    selectedDeliveryAddressId = selectedSavedDeliveryAddressId;
    if (deliveryMethodsContainer) {
      deliveryMethodsContainer.setAttribute('data-id-address', selectedSavedDeliveryAddressId);
    }

    fetchCarriers();
    return;
  }

  selectedDeliveryAddressId = null;
  if (deliveryMethodsContainer) {
    deliveryMethodsContainer.removeAttribute('data-id-address');
  }

  fetchCarriers();
});

prestashop.on(OPC_EVENTS.opcDeliveryAddressSelected, ({idAddress}) => {
  selectedDeliveryAddressId = String(idAddress || '');
  fetchCarriers();
});

const deliveryMethodsContainer = document.querySelector(CONTAINER_SELECTOR);
const initiallySelectedSavedDeliveryAddressId = getSelectedSavedDeliveryAddressId();
if (deliveryMethodsContainer && initiallySelectedSavedDeliveryAddressId) {
  selectedDeliveryAddressId = initiallySelectedSavedDeliveryAddressId;
}
}());
