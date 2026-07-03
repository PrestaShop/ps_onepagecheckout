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
import {enqueueOpcRequest} from '../opc-request-queue';

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

  prestashop.emit(OPC_EVENTS.opcAddressesLoading, {});

  // Global OPC queue: payload built at SEND time; a queued selection superseded by a
  // newer one is discarded and settles as stale (same contract as the old abort).
  const outcome = $.Deferred();
  enqueueOpcRequest('selectaddress', () => {
    if (generation !== selectAddressGeneration) {
      outcome.reject({stale: true});

      return null;
    }

    const request = $.post(selectAddressUrl, buildSelectAddressPayload(form));
    request.then(outcome.resolve, outcome.reject);

    return request;
  }, () => outcome.reject({stale: true}));

  return outcome.then((response) => {
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
    if (generation !== selectAddressGeneration || textStatus === AJAX_STATUS_ABORT || (jqXHR && jqXHR.stale)) {
      return $.Deferred().reject({stale: true}).promise();
    }

    prestashop.emit(OPC_EVENTS.opcAddressesFailed, jqXHR && jqXHR.responseJSON);

    return $.Deferred().reject(jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : jqXHR).promise();
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
