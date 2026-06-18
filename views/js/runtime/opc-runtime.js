import {OPC_EVENTS} from '../events';
import OPC_SELECTORS from '../selectors';

export function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function normalizeErrorResponse(response, fallbackMessage) {
  return normalizeErrorEventResponse(response, fallbackMessage);
}

export function getAjaxErrorResponse(jqXHR, fallbackMessage) {
  return normalizeErrorResponse(jqXHR && jqXHR.responseJSON, fallbackMessage);
}

function normalizeErrorMessage(message) {
  if (typeof message !== 'string') {
    return '';
  }

  return message.trim();
}

function extractMessages(source) {
  if (Array.isArray(source)) {
    return source;
  }

  if (isPlainObject(source)) {
    return Object.values(source).flatMap((value) => (Array.isArray(value) ? value : [value]));
  }

  return [source];
}

export function getErrorMessages(response, fallbackMessage = '') {
  const fallback = normalizeErrorMessage(fallbackMessage);

  const messages = isPlainObject(response)
    ? [...extractMessages(response.errors), response.message]
    : [response];

  const normalizedMessages = messages
    .map(normalizeErrorMessage)
    .filter(Boolean);

  if (normalizedMessages.length > 0) {
    return [...new Set(normalizedMessages)];
  }

  return fallback ? [fallback] : [];
}

export function normalizeErrorEventResponse(response, fallbackMessage = '') {
  const normalizedResponse = isPlainObject(response)
    ? {...response}
    : {};

  normalizedResponse.errors = getErrorMessages(response, fallbackMessage);

  return normalizedResponse;
}

export function getAjaxErrorEventResponse(jqXHR, fallbackMessage = '') {
  return normalizeErrorEventResponse(jqXHR && jqXHR.responseJSON, fallbackMessage);
}

export function getConfiguredOpcMessage(messageKey, fallbackMessage = '') {
  const runtimeConfiguration = getOpcRuntimeConfiguration();

  if (
    runtimeConfiguration
    && runtimeConfiguration.messages
    && typeof runtimeConfiguration.messages[messageKey] === 'string'
  ) {
    return String(runtimeConfiguration.messages[messageKey]);
  }

  return fallbackMessage;
}

export function getOpcRuntimeConfiguration() {
  if (!window || typeof window.ps_onepagecheckout !== 'object' || !window.ps_onepagecheckout) {
    return null;
  }

  return window.ps_onepagecheckout;
}

/**
 * Keep the address-draft persistence flag in sync with the live saved-address count.
 * Autosave only helps while no saved address exists, so this is flipped on as soon as the
 * last address is deleted (inline form becomes the active flow) and off once one is saved.
 *
 * @param {boolean} shouldPersist
 */
export function setOpcRuntimePersistAddressDraft(shouldPersist) {
  const runtimeConfiguration = getOpcRuntimeConfiguration();

  if (runtimeConfiguration) {
    runtimeConfiguration.persistAddressDraft = Boolean(shouldPersist);
  }
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
