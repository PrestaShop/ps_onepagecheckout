import OPC_EVENTS from './events';
import OPC_SELECTORS from './selectors';
import {getAjaxErrorResponse, getConfiguredOpcUrl, normalizeErrorResponse} from './runtime/opc-runtime';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */
(function psOpcPaymentSelectRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

if (!$) {
  return;
}

const CONTAINER_SELECTOR = OPC_SELECTORS.opc.paymentMethods;
const URL_KEY = 'selectPayment';
const EVENT_NAME = OPC_EVENTS.opcPaymentMethodSelected;

function togglePaymentPanels($container, paymentOptionId) {
  $container.find('.js-additional-information, .js-payment-option-form').hide();
  $container.find('.js-payment-option-input').prop('disabled', true);
  $container.find(OPC_SELECTORS.inputs.paymentOption).each((_, input) => {
    const $input = $(input);
    const isSelectedOption = String($input.val() || '') === paymentOptionId;

    $input.prop('checked', isSelectedOption);

    if (isSelectedOption) {
      $container.find(`#${paymentOptionId}-additional-information`).show();
      $container.find(`#pay-with-${paymentOptionId}-form`).show();
      $container.find(`#pay-with-${paymentOptionId}-form .js-payment-option-input`).prop('disabled', false);
    }
  });
}

$(document).on('change', `${CONTAINER_SELECTOR} ${OPC_SELECTORS.inputs.paymentOption}`, (event) => {
  const $radio = $(event.currentTarget);
  const paymentOptionId = String($radio.val() || '');
  const paymentModuleName = String($radio.data('moduleName') || $radio.data('module-name') || '');
  const paymentSelectionKey = String($radio.data('selectionKey') || $radio.data('selection-key') || '');
  const selectPaymentUrl = getConfiguredOpcUrl(URL_KEY);
  const $container = $(CONTAINER_SELECTOR);

  if (!selectPaymentUrl || !paymentOptionId || !paymentModuleName || !paymentSelectionKey) {
    prestashop.emit('handleError', {
      eventType: 'opcSelectPayment',
      resp: normalizeErrorResponse(null, 'Missing OPC payment selection payload.'),
    });

    return;
  }

  togglePaymentPanels($container, paymentOptionId);

  $.post(selectPaymentUrl, {
    payment_option: paymentOptionId,
    payment_module: paymentModuleName,
    payment_selection_key: paymentSelectionKey,
  })
    .done((response) => {
      if (!response || response.success === false) {
        prestashop.emit('handleError', {
          eventType: 'opcSelectPayment',
          resp: normalizeErrorResponse(response, 'Unable to select the payment method.'),
        });

        return;
      }

      prestashop.emit(EVENT_NAME, {
        paymentOptionId,
        paymentModule: paymentModuleName,
        paymentSelectionKey,
        resp: response,
      });
    })
    .fail((jqXHR) => {
      prestashop.emit('handleError', {
        eventType: 'opcSelectPayment',
        resp: getAjaxErrorResponse(jqXHR, 'Unable to select the payment method.'),
      });
    });
});

prestashop.on(OPC_EVENTS.opcPaymentMethodsUpdated, (response) => {
  const selectedSelectionKey = String((response && response.selected_payment_selection_key) || '');
  const selectedModule = String((response && response.selected_payment_module) || '');
  const $container = $(CONTAINER_SELECTOR);

  if (selectedSelectionKey) {
    const $selectedRadio = $container.find(`${OPC_SELECTORS.inputs.paymentOption}[data-selection-key="${selectedSelectionKey}"]`).first();

    if ($selectedRadio.length) {
      togglePaymentPanels($container, String($selectedRadio.val() || ''));

      return;
    }
  }

  if (!selectedModule) {
    return;
  }

  const $selectedRadio = $container.find(`${OPC_SELECTORS.inputs.paymentOption}[data-module-name="${selectedModule}"]`).first();

  if (!$selectedRadio.length) {
    return;
  }

  togglePaymentPanels($container, String($selectedRadio.val() || ''));
});
}());
