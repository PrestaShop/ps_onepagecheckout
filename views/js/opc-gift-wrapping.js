import {OPC_EVENTS} from './events';
import {getAjaxErrorEventResponse, getConfiguredOpcUrl, normalizeErrorEventResponse, updateCartSummary} from './runtime/opc-runtime';

(function psOpcGiftWrappingRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

if (!$) {
  return;
}

const URL_KEY = 'giftWrapping';
const GIFT_INPUT_SELECTOR = '#input_gift';
const GIFT_MESSAGE_SELECTOR = '#gift_message';
const FALLBACK_MESSAGE = 'Unable to refresh the cart total.';

function syncGiftWrappingSummary() {
  const giftWrappingUrl = getConfiguredOpcUrl(URL_KEY);
  const giftInput = document.querySelector(GIFT_INPUT_SELECTOR);
  const giftMessage = document.querySelector(GIFT_MESSAGE_SELECTOR);

  if (!(giftInput instanceof HTMLInputElement) || !giftWrappingUrl) {
    return;
  }

  const previousChecked = !giftInput.checked;

  $.post(giftWrappingUrl, {
    gift: giftInput.checked ? 1 : 0,
    gift_message: giftMessage instanceof HTMLTextAreaElement ? giftMessage.value : '',
  })
    .done((response) => {
      if (!response || response.success === false) {
        giftInput.checked = previousChecked;
        prestashop.emit('handleError', {
          eventType: OPC_EVENTS.opcGiftWrapping,
          resp: normalizeErrorEventResponse(response, FALLBACK_MESSAGE),
        });
        return;
      }

      if (response.preview) {
        updateCartSummary(response.preview, response.totals);
      }

      prestashop.emit(OPC_EVENTS.opcCarriersRetry, { eventType: OPC_EVENTS.opcGiftWrapping });
      prestashop.emit(OPC_EVENTS.opcPaymentMethodsRetry, { eventType: OPC_EVENTS.opcGiftWrapping });
    })
    .fail((jqXHR) => {
      giftInput.checked = previousChecked;
      prestashop.emit('handleError', {
        eventType: OPC_EVENTS.opcGiftWrapping,
        resp: getAjaxErrorEventResponse(jqXHR, FALLBACK_MESSAGE),
      });
    });
}

$(document).on('change', GIFT_INPUT_SELECTOR, syncGiftWrappingSummary);
}());
