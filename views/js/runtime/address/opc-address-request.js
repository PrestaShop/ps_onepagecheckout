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
  hasPendingInlineDraft,
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

// Persist-first: while an inline edit awaits its autosave, a preview request would leave
// with raw fields (or a stale persisted id) — hold it and re-run once a persist
// confirms. Inconclusive autosaves (incomplete address mid-typing) keep the hold armed:
// section readiness owns the retract meanwhile, and the eventual successful persist is
// the one that re-runs the refresh.
let billingRefreshDeferredOnPersist = false;

export function refreshAfterBillingAddressChange() {
  if (
    hasPendingInlineDraft(OPC_SELECTORS.opc.deliveryFields)
    || hasPendingInlineDraft(OPC_SELECTORS.opc.billingFields)
  ) {
    billingRefreshDeferredOnPersist = true;

    return;
  }

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

prestashop.on(OPC_EVENTS.opcAddressPersisted, (response) => {
  if (billingRefreshDeferredOnPersist && response && response.address_persisted) {
    billingRefreshDeferredOnPersist = false;
    // Re-runs the pending checks: a still-dirty other address type re-arms the hold.
    refreshAfterBillingAddressChange();
  }
});

export function refreshAfterVirtualDeliveryAddressChange() {
  if (hasDeliveryMethodsSection()) {
    return;
  }

  selectCurrentAddress()
    .done(() => prestashop.emit(OPC_EVENTS.opcPaymentMethodsRetry))
    .fail(handleSelectAddressFailure);
}
