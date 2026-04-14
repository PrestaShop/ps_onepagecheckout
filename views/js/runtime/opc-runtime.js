import OPC_EVENTS from '../events';
import OPC_SELECTORS from '../selectors';

export function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function normalizeErrorResponse(response, fallbackMessage) {
  if (isPlainObject(response)) {
    return response;
  }

  return {
    errors: {
      '': [fallbackMessage],
    },
  };
}

export function getAjaxErrorResponse(jqXHR, fallbackMessage) {
  return normalizeErrorResponse(jqXHR && jqXHR.responseJSON, fallbackMessage);
}

export function getOpcRuntimeConfiguration() {
  if (!window || typeof window.ps_onepagecheckout !== 'object' || !window.ps_onepagecheckout) {
    return null;
  }

  return window.ps_onepagecheckout;
}

export function getConfiguredOpcUrl(urlKey) {
  const runtimeConfiguration = getOpcRuntimeConfiguration();

  if (runtimeConfiguration && runtimeConfiguration.urls && runtimeConfiguration.urls[urlKey]) {
    return String(runtimeConfiguration.urls[urlKey]);
  }

  return '';
}

export function updateCartSummary(preview, totals) {
  if (typeof preview !== 'string' || preview === '') {
    return;
  }

  const prestashop = window.prestashop || null;
  const summarySelector = prestashop && prestashop.selectors
    && prestashop.selectors.checkout
    && prestashop.selectors.checkout.summarySelector
    ? String(prestashop.selectors.checkout.summarySelector)
    : '';

  if (!summarySelector) {
    return;
  }

  if (prestashop && typeof prestashop.emit === 'function') {
    prestashop.emit(OPC_EVENTS.opcCartSummaryBeforeUpdate, {selector: summarySelector});
  }

  const currentSummary = document.querySelector(summarySelector);
  if (currentSummary instanceof HTMLElement) {
    currentSummary.outerHTML = preview;
  }

  if (prestashop && typeof prestashop.emit === 'function') {
    prestashop.emit(OPC_EVENTS.opcCartSummaryUpdated, {selector: summarySelector});
  }

  updatePayAmount(totals);
}

export function updatePayAmount(totals) {
  const prestashop = window.prestashop || null;

  const totalValue = totals && typeof totals === 'object'
    ? (totals.total && totals.total.value) || (totals.total_including_tax && totals.total_including_tax.value) || ''
    : '';
  const configuredPayAmountSelector = prestashop && prestashop.selectors
    && prestashop.selectors.opc
    && prestashop.selectors.opc.payAmount
    ? String(prestashop.selectors.opc.payAmount)
    : '';
  const payAmountSelector = configuredPayAmountSelector || OPC_SELECTORS.opc.payAmount;

  if (!totalValue || !payAmountSelector) {
    return;
  }

  const payAmount = document.querySelector(payAmountSelector);
  if (payAmount instanceof HTMLElement) {
    payAmount.textContent = String(totalValue);
  }
}
