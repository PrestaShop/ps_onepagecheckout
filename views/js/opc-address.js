import {OPC_EVENTS} from './events';
import {emitAddressUpdate} from './address-events';
import OPC_SELECTORS from './selectors';
import {getConfiguredOpcMessage} from './runtime/opc-runtime';
import {getConfiguredOpcUrl} from './runtime/opc-runtime';
import {getOpcRuntimeConfiguration} from './runtime/opc-runtime';
import {normalizeErrorEventResponse} from './runtime/opc-runtime';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
(function psOpcAddressRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

const MODULE_ADDRESS_FORM_URL_KEY = 'addressForm';
const SAVE_DRAFT_URL_KEY = 'saveDraft';
const DRAFT_AUTOSAVE_DEBOUNCE_MS = 700;
const OPC_ADDRESSES_SECTION_SELECTOR = OPC_SELECTORS.opc.addressesSection;
const BILLING_SECTION_SELECTOR = OPC_SELECTORS.opc.billingSection;
const DELIVERY_SECTION_SELECTOR = OPC_SELECTORS.opc.deliverySection;
const DELIVERY_FIELDS_SELECTOR = OPC_SELECTORS.opc.deliveryFields;
const BILLING_FIELDS_SELECTOR = OPC_SELECTORS.opc.billingFields;
const ADDRESS_MODAL_SELECTOR = OPC_SELECTORS.modals.address;
const SAME_ADDRESS_SELECTOR = '[name="use_same_address"]';
const DISABLED_BY_SAME_ADDRESS_ATTRIBUTE = 'data-opc-disabled-by-same-address';
let addressFormGeneration = 0;

const SERVER_MANAGED_FIELDS = new Set([
  'id_country',
  'invoice_id_country',
  'id_state',
  'invoice_id_state',
  'use_same_address',
]);

function isServerManagedField(name) {
  return !name || SERVER_MANAGED_FIELDS.has(name);
}

function getFieldType($field) {
  return String($field.prop('type') || '').toLowerCase();
}

const NON_PRESERVABLE_FIELD_TYPES = new Set(['hidden', 'file', 'submit', 'button', 'image', 'reset']);

function isClientPreservableField(name, $field) {
  if (isServerManagedField(name)) {
    return false;
  }

  if ($field.closest(ADDRESS_MODAL_SELECTOR).length > 0) {
    return false;
  }

  return !NON_PRESERVABLE_FIELD_TYPES.has(getFieldType($field));
}

function preserveFieldValue(preservedFields, name, $field) {
  const type = getFieldType($field);

  if (type === 'checkbox') {
    if (!Array.isArray(preservedFields[name])) {
      preservedFields[name] = [];
    }

    if ($field.is(':checked')) {
      preservedFields[name].push(String($field.val()));
    }

    return;
  }

  if (type === 'radio') {
    if ($field.is(':checked')) {
      preservedFields[name] = String($field.val());
    }

    return;
  }

  preservedFields[name] = $field.val();
}

function restoreFieldValue($field, preservedValue) {
  const type = getFieldType($field);

  if (typeof preservedValue === 'undefined') {
    return;
  }

  if (type === 'checkbox') {
    const checkedValues = Array.isArray(preservedValue) ? preservedValue : [];
    $field.prop('checked', checkedValues.includes(String($field.val())));

    return;
  }

  if (type === 'radio') {
    $field.prop('checked', String($field.val()) === String(preservedValue));

    return;
  }

  $field.val(preservedValue);
}

/**
 * Capture current field values before replacing OPC address form HTML.
 * Server-managed fields are excluded on purpose because backend re-renders them from request payload.
 *
 * @param {string} formFieldsSelector
 *
 * @returns {Object<string, (string|Array<string>|number|undefined)>}
 */
function preserveAddressFormFields(formFieldsSelector) {
  const preservedFields = Object.create(null);

  $(formFieldsSelector).each(function () {
    const $field = $(this);
    const name = $field.prop('name');

    if (!isClientPreservableField(name, $field)) {
      return;
    }

    preserveFieldValue(preservedFields, name, $field);
  });

  return preservedFields;
}

/**
 * Restore previously captured field values after OPC address form re-render.
 * Server-managed fields stay untouched.
 *
 * @param {string} formFieldsSelector
 * @param {Object<string, (string|Array<string>|number|undefined)>} preservedFields
 *
 * @returns {void}
 */
function restoreAddressFormFields(formFieldsSelector, preservedFields) {
  $(formFieldsSelector).each(function () {
    const $field = $(this);
    const name = $field.prop('name');

    if (!isClientPreservableField(name, $field)) {
      return;
    }

    restoreFieldValue($field, preservedFields[name]);
  });
}

function syncBillingSectionConstraints(addressContainer, useSameAddress) {
  const billingSection = addressContainer.find(BILLING_SECTION_SELECTOR);

  if (!billingSection.length) {
    return;
  }

  billingSection.toggle(!useSameAddress);

  billingSection.find('input, select, textarea').each((_, field) => {
    const $field = $(field);

    if (useSameAddress) {
      if (!$field.prop('disabled')) {
        $field.attr(DISABLED_BY_SAME_ADDRESS_ATTRIBUTE, '1');
        $field.prop('disabled', true);
      }

      return;
    }

    if ($field.attr(DISABLED_BY_SAME_ADDRESS_ATTRIBUTE) === '1') {
      $field.prop('disabled', false);
      $field.removeAttr(DISABLED_BY_SAME_ADDRESS_ATTRIBUTE);
    }
  });
}

function getUseSameAddressState(addressContainer) {
  return addressContainer.find(SAME_ADDRESS_SELECTOR).first().is(':checked');
}

function setUseSameAddressState(addressContainer, useSameAddress) {
  addressContainer.find(SAME_ADDRESS_SELECTOR).first().prop('checked', useSameAddress);
}

function getAddressSection(addressContainer, sectionSelector, fieldsSelector) {
  const $fields = addressContainer.find(fieldsSelector).first();
  if ($fields.length) {
    return $fields;
  }

  return addressContainer.find(sectionSelector).first();
}

function getAddressSectionFieldValue(addressContainer, sectionSelector, fieldsSelector, fieldName) {
  const $section = getAddressSection(addressContainer, sectionSelector, fieldsSelector);
  if (!$section.length) {
    return '';
  }

  const $field = $section.find(`[name="${fieldName}"]`).first();
  if (!$field.length) {
    return '';
  }

  return String($field.val() || '');
}

function setAddressSectionFieldValue(addressContainer, sectionSelector, fieldsSelector, fieldName, value) {
  if (typeof value === 'undefined' || value === null || String(value) === '') {
    return;
  }

  const $section = getAddressSection(addressContainer, sectionSelector, fieldsSelector);
  if (!$section.length) {
    return;
  }

  const $field = $section.find(`[name="${fieldName}"]`).first();
  if (!$field.length) {
    return;
  }

  $field.val(String(value));
}

function hasMeaningfulFieldValue($field) {
  const type = getFieldType($field);

  if (type === 'checkbox' || type === 'radio') {
    return $field.is(':checked');
  }

  return String($field.val() || '').trim() !== '';
}

function hasSeparateBillingDraft(addressContainer) {
  if (getAddressSectionFieldValue(addressContainer, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'id_address_invoice') !== '') {
    return true;
  }

  const billingSection = getAddressSection(addressContainer, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR);

  if (!billingSection.length) {
    return false;
  }

  let hasDraft = false;

  billingSection.find('input, select, textarea').each(function () {
    const $field = $(this);
    const name = String($field.prop('name') || '');

    if (!isClientPreservableField(name, $field)) {
      return;
    }

    if (!hasMeaningfulFieldValue($field)) {
      return;
    }

    hasDraft = true;

    return false;
  });

  return hasDraft;
}

function seedBillingFromDelivery(addressContainer) {
  const deliveryCountryValue = getAddressSectionFieldValue(
    addressContainer,
    DELIVERY_SECTION_SELECTOR,
    DELIVERY_FIELDS_SELECTOR,
    'id_country'
  );
  const deliveryStateValue = getAddressSectionFieldValue(
    addressContainer,
    DELIVERY_SECTION_SELECTOR,
    DELIVERY_FIELDS_SELECTOR,
    'id_state'
  );
  const billingCountryField = getAddressSection(
    addressContainer,
    BILLING_SECTION_SELECTOR,
    BILLING_FIELDS_SELECTOR
  ).find('[name="invoice_id_country"]').first();
  const previousBillingCountryValue = billingCountryField.length
    ? String(billingCountryField.val() || '')
    : '';

  setAddressSectionFieldValue(
    addressContainer,
    BILLING_SECTION_SELECTOR,
    BILLING_FIELDS_SELECTOR,
    'invoice_id_country',
    deliveryCountryValue
  );

  if (billingCountryField.length && deliveryCountryValue !== '' && previousBillingCountryValue !== deliveryCountryValue) {
    billingCountryField.val(deliveryCountryValue).trigger('change');
    return;
  }

  setAddressSectionFieldValue(
    addressContainer,
    BILLING_SECTION_SELECTOR,
    BILLING_FIELDS_SELECTOR,
    'invoice_id_state',
    deliveryStateValue
  );
}

function bindBillingToggleListener(selectors) {
  $('body').on('change', `${selectors.address} ${SAME_ADDRESS_SELECTOR}`, (event) => {
    const addressContainer = $(event.target).closest(selectors.address);

    if (!addressContainer.length) {
      return;
    }

    const useSameAddress = getUseSameAddressState(addressContainer);

    syncBillingSectionConstraints(addressContainer, useSameAddress);

    // When "use same address" is enabled again, billing must stop using its
    // previous country (for example US) and go back to the delivery country
    // so invoice-based payment filtering is recalculated from delivery.
    if (useSameAddress) {
      seedBillingFromDelivery(addressContainer);
      return;
    }

    if (!useSameAddress && !hasSeparateBillingDraft(addressContainer)) {
      seedBillingFromDelivery(addressContainer);
    }
  });
}

function initializeBillingSectionConstraints(selectors) {
  const addressContainer = $(selectors.address).first();

  if (!addressContainer.length) {
    return;
  }

  syncBillingSectionConstraints(addressContainer, getUseSameAddressState(addressContainer));
}

/**
 * When country changes, refresh address form from backend and keep user-entered values.
 *
 * @param selectors
 */
function refreshOpcAddressFormForCountryChange(target, selectors) {
  if (!target.length) {
    return;
  }

  if (target.closest(ADDRESS_MODAL_SELECTOR).length > 0) {
    return;
  }

  if ($(ADDRESS_MODAL_SELECTOR).filter('.show').length > 0) {
    return;
  }

  const addressContainer = target.closest(selectors.address);

  if (!addressContainer.length) {
    return;
  }

  // Send both country values so backend rebuilds delivery and billing sections consistently.
  const targetName = String(target.attr('name') || '');
  const deliveryCountryValue = targetName === 'id_country'
    ? String(target.val() || '')
    : getAddressSectionFieldValue(addressContainer, DELIVERY_SECTION_SELECTOR, DELIVERY_FIELDS_SELECTOR, 'id_country');
  const invoiceCountryValue = targetName === 'invoice_id_country'
    ? String(target.val() || '')
    : getAddressSectionFieldValue(addressContainer, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'invoice_id_country');
  const requestData = {
    id_address_delivery: getAddressSectionFieldValue(addressContainer, DELIVERY_SECTION_SELECTOR, DELIVERY_FIELDS_SELECTOR, 'id_address_delivery'),
    id_address_invoice: getAddressSectionFieldValue(addressContainer, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'id_address_invoice'),
    id_country: deliveryCountryValue,
    invoice_id_country: invoiceCountryValue,
    use_same_address: getUseSameAddressState(addressContainer) ? '1' : '0',
  };
  const formFieldsSelector = `${selectors.address} input, ${selectors.address} select, ${selectors.address} textarea`;

  const addressFormUrl = getConfiguredOpcUrl(MODULE_ADDRESS_FORM_URL_KEY);
  const fallbackMessage = getConfiguredOpcMessage('missingAddressFormUrl', 'Unable to refresh addresses.');
  if (addressFormUrl === '') {
    prestashop.emit('handleError', {
      eventType: 'updateOpcAddressForm',
      resp: normalizeErrorEventResponse(null, fallbackMessage),
    });

    return;
  }
  const generation = ++addressFormGeneration;

  $.post(
    addressFormUrl,
    requestData,
  ).then((resp) => {
    if (generation !== addressFormGeneration) {
      return;
    }

    if (!resp || typeof resp.addresses_section !== 'string') {
      prestashop.emit('handleError', {
        eventType: 'updateOpcAddressForm',
        resp: normalizeErrorEventResponse(resp, fallbackMessage),
      });

      return;
    }

    const preservedFields = preserveAddressFormFields(formFieldsSelector);

    addressContainer.html(resp.addresses_section);

    restoreAddressFormFields(formFieldsSelector, preservedFields);

    // Backend template resets billing toggle; re-apply user choice.
    const useSameAddress = requestData.use_same_address !== '0';
    setUseSameAddressState(addressContainer, useSameAddress);
    syncBillingSectionConstraints(addressContainer, useSameAddress);

    prestashop.emit(OPC_EVENTS.updatedOpcAddressForm, {target: addressContainer, resp});
    const changedFieldName = String(target.attr('name') || '');

    if (changedFieldName === 'id_country') {
      emitAddressUpdate('delivery', {target: addressContainer, resp});
      return;
    }

    if (changedFieldName === 'invoice_id_country') {
      emitAddressUpdate('billing', {target: addressContainer, resp});
    }
  }).fail((resp) => {
    if (generation !== addressFormGeneration) {
      return;
    }

    prestashop.emit('handleError', {
      eventType: 'updateOpcAddressForm',
      resp: normalizeErrorEventResponse(resp && resp.responseJSON, fallbackMessage),
    });
  });
}

function shouldPersistAddressDraft() {
  const configuration = getOpcRuntimeConfiguration();

  return Boolean(configuration && configuration.persistAddressDraft);
}

/**
 * Serialize the address-section fields for the guest draft autosave.
 * Server-side whitelisting decides what is actually stored, so over-sending is harmless;
 * fields inside the address modal and non-data inputs (file/submit/buttons/hidden) are skipped.
 *
 * @param {jQuery} addressContainer
 *
 * @returns {Object<string, string>}
 */
function collectAddressDraftPayload(addressContainer) {
  const payload = Object.create(null);

  addressContainer.find('input, select, textarea').each(function () {
    const $field = $(this);

    if ($field.closest(ADDRESS_MODAL_SELECTOR).length > 0) {
      return;
    }

    const name = String($field.prop('name') || '');
    if (!name) {
      return;
    }

    const type = getFieldType($field);
    if (NON_PRESERVABLE_FIELD_TYPES.has(type)) {
      return;
    }

    if (type === 'checkbox') {
      payload[name] = $field.is(':checked') ? '1' : '0';

      return;
    }

    if (type === 'radio') {
      if ($field.is(':checked')) {
        payload[name] = String($field.val() || '');
      }

      return;
    }

    payload[name] = String($field.val() || '');
  });

  return payload;
}

/**
 * Autosave the guest's in-progress address fields (debounced) so they survive
 * leaving and returning to checkout. Best-effort: failures are silently ignored.
 *
 * @param selectors
 */
function bindAddressDraftAutosave(selectors) {
  if (!shouldPersistAddressDraft()) {
    return;
  }

  const draftUrl = getConfiguredOpcUrl(SAVE_DRAFT_URL_KEY);
  if (draftUrl === '') {
    return;
  }

  let debounceTimer = null;
  let pendingContainer = null;

  const buildDraftPayload = (addressContainer) => {
    const payload = collectAddressDraftPayload(addressContainer);
    // The endpoint validates the static front token (CSRF protection).
    payload.token = String(prestashop.static_token || '');

    return payload;
  };

  // Flush the latest pending draft. On page teardown a regular async XHR is unreliable, so
  // prefer sendBeacon (a keepalive POST) which still carries the token and form fields.
  const flushPendingDraft = (useBeacon) => {
    if (debounceTimer) {
      clearTimeout(debounceTimer);
      debounceTimer = null;
    }

    if (!pendingContainer || !pendingContainer.length) {
      pendingContainer = null;

      return;
    }

    const payload = buildDraftPayload(pendingContainer);
    pendingContainer = null;

    if (useBeacon && typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
      const formData = new FormData();
      Object.keys(payload).forEach((key) => formData.append(key, payload[key]));
      navigator.sendBeacon(draftUrl, formData);

      return;
    }

    $.post(draftUrl, payload);
  };

  const scheduleAutosave = (event) => {
    const target = $(event.target);

    if (target.closest(ADDRESS_MODAL_SELECTOR).length > 0) {
      return;
    }

    const addressContainer = target.closest(selectors.address);
    if (!addressContainer.length) {
      return;
    }

    pendingContainer = addressContainer;

    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }

    debounceTimer = setTimeout(() => flushPendingDraft(false), DRAFT_AUTOSAVE_DEBOUNCE_MS);
  };

  $('body').on('input', `${selectors.address} input, ${selectors.address} textarea`, scheduleAutosave);
  $('body').on('change', `${selectors.address} select, ${selectors.address} input[type="checkbox"]`, scheduleAutosave);
  // Don't lose the last edits if the customer leaves before the debounce timer fires.
  $(window).on('pagehide', () => flushPendingDraft(true));
}

function handleOpcCountryChange(selectors) {
  $('body').on('change', `${OPC_SELECTORS.opc.deliveryFields} select[name="id_country"], ${OPC_SELECTORS.opc.billingFields} select[name="invoice_id_country"]`, (event) => {
    refreshOpcAddressFormForCountryChange($(event.target), selectors);
  });
}

$(() => {
  const selectors = {
    address: OPC_ADDRESSES_SECTION_SELECTOR,
  };

  handleOpcCountryChange(selectors);
  bindBillingToggleListener(selectors);
  initializeBillingSectionConstraints(selectors);
  bindAddressDraftAutosave(selectors);
});
})();
