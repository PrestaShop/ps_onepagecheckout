import OPC_EVENTS from './events';
import OPC_SELECTORS from './selectors';
import {getAjaxErrorResponse, getConfiguredOpcUrl, normalizeErrorResponse, updateCartSummary} from './runtime/opc-runtime';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */
(function psOpcCarrierSelectRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

if (!$) {
  return;
}

const CONTAINER_SELECTOR = OPC_SELECTORS.opc.deliveryMethods;
const URL_KEY = 'selectCarrier';
const CHECKOUT_FORM_SELECTOR = OPC_SELECTORS.opc.checkout;
const DELIVERY_ADDRESS_SECTION_SELECTOR = OPC_SELECTORS.opc.deliverySection;
const CONFIRMED_DELIVERY_OPTION_ATTRIBUTE = 'data-confirmed-delivery-option';

function getDeliveryAddressSection() {
  return document.querySelector(DELIVERY_ADDRESS_SECTION_SELECTOR);
}

function collectAddressFields() {
  const form = document.querySelector(CHECKOUT_FORM_SELECTOR);
  if (!form) {
    return {};
  }

  const scope = getDeliveryAddressSection() || form;

  return ['id_country', 'postcode', 'id_state', 'city'].reduce((payload, field) => {
    const direct = scope.querySelector(`[name="${field}"]`);
    const prefixed = scope.querySelector(`[name="delivery_${field}"]`);
    const value = ((direct && direct.value) || (prefixed && prefixed.value) || '').trim();

    if (value === '') {
      return payload;
    }

    return {
      ...payload,
      [field]: value,
    };
  }, {});
}

function findDeliveryOptionRadio($container, deliveryOption) {
  return $container.find(OPC_SELECTORS.inputs.deliveryOption).filter((_, input) => {
    return String($(input).val() || '') === deliveryOption;
  }).first();
}

function setConfirmedDeliveryOption($container, deliveryOption) {
  if (!deliveryOption) {
    $container.removeAttr(CONFIRMED_DELIVERY_OPTION_ATTRIBUTE);

    return;
  }

  $container.attr(CONFIRMED_DELIVERY_OPTION_ATTRIBUTE, deliveryOption);
}

function restoreConfirmedDeliveryOption($container) {
  const confirmedDeliveryOption = String($container.attr(CONFIRMED_DELIVERY_OPTION_ATTRIBUTE) || '');

  if (!confirmedDeliveryOption) {
    return;
  }

  const $confirmedRadio = findDeliveryOptionRadio($container, confirmedDeliveryOption);
  if (!$confirmedRadio.length) {
    return;
  }

  $confirmedRadio.prop('checked', true);
}

$(document).on('change', `${CONTAINER_SELECTOR} ${OPC_SELECTORS.inputs.deliveryOption}`, (event) => {
  const $radio = $(event.currentTarget);
  const $container = $(CONTAINER_SELECTOR);
  const selectCarrierUrl = getConfiguredOpcUrl(URL_KEY);
  const deliveryOption = String($radio.val() || '');

  if (!selectCarrierUrl || !deliveryOption) {
    prestashop.emit('handleError', {
      eventType: 'opcSelectCarrier',
      resp: normalizeErrorResponse(null, 'Missing OPC carrier selection payload.'),
    });
    return;
  }

  const payload = {
    delivery_option: deliveryOption,
    ...($container.attr('data-id-address') ? {} : collectAddressFields()),
  };

  $.post(selectCarrierUrl, payload)
    .done((response) => {
      if (!response || response.success === false) {
        restoreConfirmedDeliveryOption($container);
        prestashop.emit('handleError', {
          eventType: 'opcSelectCarrier',
          resp: normalizeErrorResponse(response, 'Unable to select the delivery method.'),
        });
        return;
      }

      if (response.preview) {
        updateCartSummary(response.preview, response.totals);
      }
      setConfirmedDeliveryOption($container, deliveryOption);
      prestashop.emit(OPC_EVENTS.opcCarrierSelected, response);
    })
    .fail((jqXHR) => {
      restoreConfirmedDeliveryOption($container);
      prestashop.emit('handleError', {
        eventType: 'opcSelectCarrier',
        resp: getAjaxErrorResponse(jqXHR, 'Unable to select the delivery method.'),
      });
    });
});
}());
