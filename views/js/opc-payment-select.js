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
const CONFIRMED_OPTION_ATTRIBUTE = 'data-confirmed-payment-option-id';
const CONFIRMED_MODULE_ATTRIBUTE = 'data-confirmed-payment-module';
const CONFIRMED_SELECTION_KEY_ATTRIBUTE = 'data-confirmed-payment-selection-key';

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

function clearPaymentPanels($container) {
  $container.find('.js-additional-information, .js-payment-option-form').hide();
  $container.find('.js-payment-option-input').prop('disabled', true);
  $container.find(OPC_SELECTORS.inputs.paymentOption).prop('checked', false);
}

function setConfirmedPaymentSelection($container, paymentOptionId, paymentModuleName, paymentSelectionKey) {
  if (paymentOptionId) {
    $container.attr(CONFIRMED_OPTION_ATTRIBUTE, paymentOptionId);
  } else {
    $container.removeAttr(CONFIRMED_OPTION_ATTRIBUTE);
  }

  if (paymentModuleName) {
    $container.attr(CONFIRMED_MODULE_ATTRIBUTE, paymentModuleName);
  } else {
    $container.removeAttr(CONFIRMED_MODULE_ATTRIBUTE);
  }

  if (paymentSelectionKey) {
    $container.attr(CONFIRMED_SELECTION_KEY_ATTRIBUTE, paymentSelectionKey);
  } else {
    $container.removeAttr(CONFIRMED_SELECTION_KEY_ATTRIBUTE);
  }
}

function restoreConfirmedPaymentSelection($container) {
  const confirmedPaymentOptionId = String($container.attr(CONFIRMED_OPTION_ATTRIBUTE) || '');

  if (confirmedPaymentOptionId) {
    togglePaymentPanels($container, confirmedPaymentOptionId);

    return;
  }

  clearPaymentPanels($container);
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
        restoreConfirmedPaymentSelection($container);
        prestashop.emit('handleError', {
          eventType: 'opcSelectPayment',
          resp: normalizeErrorResponse(response, 'Unable to select the payment method.'),
        });

        return;
      }

      setConfirmedPaymentSelection($container, paymentOptionId, paymentModuleName, paymentSelectionKey);

      prestashop.emit(EVENT_NAME, {
        paymentOptionId,
        paymentModule: paymentModuleName,
        paymentSelectionKey,
        resp: response,
      });
    })
    .fail((jqXHR) => {
      restoreConfirmedPaymentSelection($container);
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
      const selectedPaymentOptionId = String($selectedRadio.val() || '');
      const selectedModuleName = String($selectedRadio.data('moduleName') || $selectedRadio.data('module-name') || '');

      togglePaymentPanels($container, selectedPaymentOptionId);
      setConfirmedPaymentSelection($container, selectedPaymentOptionId, selectedModuleName, selectedSelectionKey);

      return;
    }
  }

  if (!selectedModule) {
    return;
  }

  const $selectedRadio = $container.find(`${OPC_SELECTORS.inputs.paymentOption}[data-module-name="${selectedModule}"]`).first();

  if (!$selectedRadio.length) {
    setConfirmedPaymentSelection($container, '', '', '');

    return;
  }

  const selectedPaymentOptionId = String($selectedRadio.val() || '');

  togglePaymentPanels($container, selectedPaymentOptionId);
  setConfirmedPaymentSelection($container, selectedPaymentOptionId, selectedModule, String($selectedRadio.data('selectionKey') || $selectedRadio.data('selection-key') || ''));
});
}());
