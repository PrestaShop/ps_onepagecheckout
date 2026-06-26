import OPC_SELECTORS from '../../selectors';
import {OPC_EVENTS} from '../../events';
import {
  getConfiguredOpcMessage,
  getConfiguredOpcUrl,
  normalizeErrorEventResponse,
  updateCartSummary,
  AJAX_READY_STATE_DONE,
  AJAX_STATUS_ABORT,
} from '../opc-runtime';
import {
  buildSelectAddressPayload,
  carrierPricesDependOnBillingAddress,
  hasDeliveryMethodsSection,
} from './opc-address-context';

let selectAddressGeneration = 0;
let activeSelectAddressRequest = null;
const prestashop = window.prestashop || {};

export function selectCurrentAddress() {
  const $ = window.$ || window.jQuery;
  const selectAddressUrl = getConfiguredOpcUrl('selectAddress');
  const form = document.querySelector(OPC_SELECTORS.opc.checkout);

  if (!$ || !selectAddressUrl || !form) {
    return $.Deferred().resolve().promise();
  }

  const generation = ++selectAddressGeneration;
  if (activeSelectAddressRequest && activeSelectAddressRequest.readyState !== AJAX_READY_STATE_DONE) {
    activeSelectAddressRequest.abort();
  }

  prestashop.emit(OPC_EVENTS.opcAddressesLoading, {});

  const request = $.post(selectAddressUrl, buildSelectAddressPayload(form));
  activeSelectAddressRequest = request;

  return request.then((response) => {
    if (generation !== selectAddressGeneration) {
      return $.Deferred().reject({stale: true}).promise();
    }

    if (!response || response.success === false) {
      prestashop.emit(OPC_EVENTS.opcAddressesFailed, response);

      return $.Deferred().reject(response).promise();
    }

    if (response.preview) {
      updateCartSummary(response.preview, response.totals);
    }

    prestashop.emit(OPC_EVENTS.opcAddressesUpdated, response);

    return response;
  }, (jqXHR, textStatus) => {
    if (generation !== selectAddressGeneration || textStatus === AJAX_STATUS_ABORT) {
      return $.Deferred().reject({stale: true}).promise();
    }

    prestashop.emit(OPC_EVENTS.opcAddressesFailed, jqXHR && jqXHR.responseJSON);

    return $.Deferred().reject(jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : jqXHR).promise();
  }).always(() => {
    if (activeSelectAddressRequest === request) {
      activeSelectAddressRequest = null;
    }
  });
}

function handleSelectAddressFailure(response) {
  if (response && response.stale) {
    return;
  }

  prestashop.emit('handleError', {
    eventType: 'opcSelectAddress',
    resp: normalizeErrorEventResponse(
      response,
      getConfiguredOpcMessage('refreshAddressesFailed', 'Unable to refresh addresses.')
    ),
  });
}

export function refreshAfterBillingAddressChange() {
  selectCurrentAddress()
    .done(() => {
      if (carrierPricesDependOnBillingAddress() && hasDeliveryMethodsSection()) {
        prestashop.emit(OPC_EVENTS.opcCarriersRetry);
        return;
      }

      prestashop.emit(OPC_EVENTS.opcPaymentMethodsRetry);
    })
    .fail(handleSelectAddressFailure);
}

export function refreshAfterVirtualDeliveryAddressChange() {
  if (hasDeliveryMethodsSection()) {
    return;
  }

  selectCurrentAddress()
    .done(() => prestashop.emit(OPC_EVENTS.opcPaymentMethodsRetry))
    .fail(handleSelectAddressFailure);
}
