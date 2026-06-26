import {OPC_EVENTS} from './events';
import OPC_SELECTORS from './selectors';
import {collectBillingAddressContext} from './runtime/address/opc-address-context';
import {
  AJAX_READY_STATE_DONE,
  AJAX_STATUS_ABORT,
  getAjaxErrorEventResponse,
  getConfiguredOpcMessage,
  getConfiguredOpcUrl,
  normalizeErrorEventResponse,
  updateCartSummary,
} from './runtime/opc-runtime';

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
const OPC_FORM_SELECTOR = OPC_SELECTORS.opc.form;
const DELIVERY_ADDRESS_SECTION_SELECTOR = OPC_SELECTORS.opc.deliverySection;
const DELIVERY_OPTION_SELECTOR = '.js-delivery-option';
const CONFIRMED_DELIVERY_OPTION_ATTRIBUTE = 'data-confirmed-delivery-option';
let selectCarrierGeneration = 0;
let activeSelectCarrierRequest = null;

function getDeliveryAddressSection() {
  return document.querySelector(DELIVERY_ADDRESS_SECTION_SELECTOR);
}

function collectAddressFields() {
  const form = document.querySelector(CHECKOUT_FORM_SELECTOR);
  if (!form) {
    return {};
  }

  const scope = getDeliveryAddressSection() || form;

  const payload = ['id_country', 'postcode', 'id_state', 'city'].reduce((payload, field) => {
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

  return payload;
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
  const missingPayloadMessage = getConfiguredOpcMessage('missingCarrierSelectionPayload', 'Missing OPC carrier selection payload.');
  const selectionFailedMessage = getConfiguredOpcMessage('selectCarrierFailed', 'Unable to select the delivery method.');

  if (!selectCarrierUrl || !deliveryOption) {
    const resp = normalizeErrorEventResponse(null, missingPayloadMessage);
    prestashop.emit('handleError', {
      eventType: 'opcSelectCarrier',
      resp,
    });
    return;
  }

  const form = document.querySelector(CHECKOUT_FORM_SELECTOR);
  const payload = {
    delivery_option: deliveryOption,
    ...collectBillingAddressContext(form),
    ...($container.attr('data-id-address') ? {} : collectAddressFields()),
  };
  const generation = ++selectCarrierGeneration;
  if (activeSelectCarrierRequest && activeSelectCarrierRequest.readyState !== AJAX_READY_STATE_DONE) {
    activeSelectCarrierRequest.abort();
  }

  prestashop.emit(OPC_EVENTS.opcCarrierSelectionLoading, {});

  const request = $.post(selectCarrierUrl, payload);
  activeSelectCarrierRequest = request;

  request
    .done((response) => {
      if (generation !== selectCarrierGeneration) {
        return;
      }

      if (!response || response.success === false) {
        restoreConfirmedDeliveryOption($container);
        prestashop.emit(OPC_EVENTS.opcCarrierSelectionFailed, {});
        prestashop.emit('handleError', {
          eventType: 'opcSelectCarrier',
          resp: normalizeErrorEventResponse(response, selectionFailedMessage),
        });
        return;
      }

      if (response.preview) {
        updateCartSummary(response.preview, response.totals);
      }
      setConfirmedDeliveryOption($container, deliveryOption);
      // Keep existing themes compatible with the 4-step checkout carrier lifecycle.
      prestashop.emit('updatedDeliveryForm', {
        dataForm: $(OPC_FORM_SELECTOR).serializeArray(),
        deliveryOption: $radio.closest(DELIVERY_OPTION_SELECTOR),
        resp: response,
      });
      prestashop.emit(OPC_EVENTS.opcCarrierSelected, {
        selectedDeliveryOption: deliveryOption,
        response,
      });
    })
    .fail((jqXHR, textStatus) => {
      if (generation !== selectCarrierGeneration) {
        return;
      }

      if (textStatus === AJAX_STATUS_ABORT) {
        return;
      }

      restoreConfirmedDeliveryOption($container);
      prestashop.emit(OPC_EVENTS.opcCarrierSelectionFailed, {});
      prestashop.emit('handleError', {
        eventType: 'opcSelectCarrier',
        resp: getAjaxErrorEventResponse(jqXHR, selectionFailedMessage),
      });
    })
    .always(() => {
      if (activeSelectCarrierRequest === request) {
        activeSelectCarrierRequest = null;
      }
    });
});

prestashop.on(OPC_EVENTS.opcCarriersLoading, () => {
  selectCarrierGeneration += 1;
});
}());
