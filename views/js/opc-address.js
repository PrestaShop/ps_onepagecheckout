import {OPC_EVENTS} from './events';
import {emitAddressUpdate} from './address-events';
import OPC_SELECTORS from './selectors';
import {getConfiguredOpcMessage} from './runtime/opc-runtime';
import {getConfiguredOpcUrl} from './runtime/opc-runtime';
import {getOpcRuntimeConfiguration} from './runtime/opc-runtime';
import {normalizeErrorEventResponse} from './runtime/opc-runtime';
import {AJAX_STATUS_ABORT} from './runtime/opc-runtime';
import {setOpcRuntimePersistAddressDraft} from './runtime/opc-runtime';
import {emitWithContext} from './runtime/opc-context-sync';
import {areRenderedRequiredFieldsComplete, clearAddressPersistFailed, hasDeliveryMethodsSection, isInlineAutosaveActive, markAddressPersistFailed} from './runtime/address/opc-address-context';
import {
  refreshAfterBillingAddressChange,
  refreshAfterVirtualDeliveryAddressChange,
} from './runtime/address/opc-address-request';
import {clearEditedFieldErrors, getFieldErrors, renderFieldErrors} from './runtime/form/opc-field-errors';

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
// Persist-on-complete: once the required fields are all filled, the option sections are only waiting
// on this save to reveal — so coalesce far less aggressively to cut the perceived reveal latency.
const COMPLETE_ADDRESS_AUTOSAVE_DEBOUNCE_MS = 150;
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
      prestashop.emit(OPC_EVENTS.opcBillingSectionToggled, {visible: false});
      return;
    }

    if (!useSameAddress && !hasSeparateBillingDraft(addressContainer)) {
      seedBillingFromDelivery(addressContainer);
    }

    // Un-checking "use same address" can expose the INLINE billing form (a single saved address
    // hides the billing list), but the autosave flag is armed at page load only for the
    // no-saved-address flow — a billing typed here would never persist and be lost on reload.
    // ARM the flag when the billing inline is actually visible; never DISARM here (readiness has
    // its own visibility guard in isInlineAutosaveActive, and a fragile disarm can race the
    // re-render and kill the delivery-inline autosave).
    if (addressContainer.find(OPC_SELECTORS.opc.billingFields).not('.d-none').length > 0) {
      setOpcRuntimePersistAddressDraft(true);
    }

    prestashop.emit(OPC_EVENTS.opcBillingSectionToggled, {visible: true});
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
 * Re-assert INLINE mode after the country-change re-render, when the user was editing inline.
 *
 * The addressform partial decides list-vs-inline purely from the customer's saved-address count
 * (addresses-section.tpl). Once the inline autosave persists a complete address that count flips
 * 0 -> 1 mid-edit, so the re-rendered section would default back to the saved-address list and bump
 * the customer out of the inline form they are still editing. The country-change handler is bound
 * only to the inline selects (which are d-none + disabled in list mode), so this refresh always runs
 * in inline mode — re-applying inline mode here is the correct intent. The `listWasHidden` guard makes
 * it a no-op whenever a genuine saved-address list is showing, so save/select/delete flows are untouched.
 *
 * @param addressContainer
 * @param listSelector
 * @param fieldsSelector
 * @param listWasHidden
 */
function reapplyInlineDisplayMode(addressContainer, listSelector, fieldsSelector, listWasHidden) {
  if (!listWasHidden) {
    return;
  }

  const $fields = addressContainer.find(fieldsSelector);
  if (!$fields.length) {
    return;
  }

  addressContainer.find(listSelector).addClass('d-none');
  $fields.removeClass('d-none');
  $fields.find('input, select, textarea').prop('disabled', false);
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
  // Inline DELIVERY country change: signal the carrier/payment sections to HOLD (loader) now — before
  // the form re-render and before the autosave persist — so they refresh only once the new country is
  // persisted onto the cart (fresh delivery country for third-party carrier/payment modules). Emitting
  // synchronously here, at change start, arms the loader strictly before any persist confirmation can
  // arrive, so there is no ordering race. Delivery only; the billing country does not gate carriers.
  if (targetName === 'id_country') {
    prestashop.emit(OPC_EVENTS.opcDeliveryCountryChanging, {});
  }
  if (targetName === 'invoice_id_country') {
    prestashop.emit(OPC_EVENTS.opcBillingCountryChanging, {});
  }
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
    // Capture the inline-vs-list display state BEFORE the swap so it survives the re-render: the
    // template re-decides it from the (now possibly 0 -> 1) saved-address count, which must not bump
    // an in-progress inline edit into the saved-address list.
    const deliveryListWasHidden = addressContainer.find(OPC_SELECTORS.opc.deliveryList).hasClass('d-none');
    const billingListWasHidden = addressContainer.find(OPC_SELECTORS.opc.billingList).hasClass('d-none');
    // Re-read the use_same choice NOW (right before the swap), not from requestData captured when the
    // country change STARTED: this async re-render can land after the buyer toggled use_same (or after an
    // earlier delivery-country re-render), and re-applying the stale start value would clobber the
    // current choice back — hiding the billing address the buyer is editing, or re-showing it.
    const useSameAddress = getUseSameAddressState(addressContainer);

    addressContainer.html(resp.addresses_section);

    restoreAddressFormFields(formFieldsSelector, preservedFields);

    // Keep the customer in inline mode across the re-render (delivery + billing). Done before the
    // billing-toggle re-apply below so syncBillingSectionConstraints keeps the final say on disabling
    // the billing fields when use_same_address is on.
    reapplyInlineDisplayMode(addressContainer, OPC_SELECTORS.opc.deliveryList, DELIVERY_FIELDS_SELECTOR, deliveryListWasHidden);
    reapplyInlineDisplayMode(addressContainer, OPC_SELECTORS.opc.billingList, BILLING_FIELDS_SELECTOR, billingListWasHidden);

    // Backend template resets the billing toggle; re-apply the buyer's (current) choice.
    setUseSameAddressState(addressContainer, useSameAddress);
    syncBillingSectionConstraints(addressContainer, useSameAddress);

    prestashop.emit(OPC_EVENTS.updatedOpcAddressForm, {target: addressContainer, resp});
    const changedFieldName = String(target.attr('name') || '');

    if (changedFieldName === 'id_country') {
      // The persisted inline address belonged to the PREVIOUS country; clear its stored id so section
      // readiness stops treating it as a usable (persisted) address until the new-country address is
      // re-persisted. Without this the stale persisted-id keeps the section "ready" and masks the
      // new-country form's invalid/unpersisted state (stale carrier/payment options for a country that
      // is not on the cart). Applied to BOTH physical AND virtual carts so they behave identically: an
      // invalid/incomplete new country retracts the options to the awaiting hint (a module never runs
      // against a stale/mismatched country), and a valid new country reveals only once it is persisted.
      // (Virtual used to keep the id because its OLD immediate opcPaymentMethodsRetry relied on it to
      // stay "ready"; that premature refresh is now suppressed by the virtual persist-before-refresh
      // defer, so clearing is safe. Same-country field edits keep their id — persisted-address-stays.)
      $(`${DELIVERY_FIELDS_SELECTOR} [name="id_address_delivery"]`).first().val('');
      emitAddressUpdate('delivery', {target: addressContainer, resp});
      return;
    }

    if (changedFieldName === 'invoice_id_country') {
      // Clear the persisted invoice id so section readiness reflects the NEW-country billing form
      // (retracts to awaiting for an invalid/incomplete new billing country instead of masking it with
      // the stale persisted id), symmetric with the delivery id-clear above.
      $(`${BILLING_FIELDS_SELECTOR} [name="id_address_invoice"]`).first().val('');
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
 * Store the ids of the address(es) the backend just saved into the inline form's hidden fields, so
 * later edits update the same addresses and the final submit reuses them instead of creating
 * duplicates. Delivery and billing are handled with the same logic.
 *
 * @param {Object} response savedraft response ({address_persisted, id_address_delivery, id_address_invoice})
 */
function rememberPersistedInlineAddresses(response) {
  if (!response || !response.address_persisted) {
    return;
  }

  [
    [DELIVERY_FIELDS_SELECTOR, 'id_address_delivery'],
    [BILLING_FIELDS_SELECTOR, 'id_address_invoice'],
  ].forEach(([fieldsSelector, fieldName]) => {
    const id = parseInt(response[fieldName], 10);
    if (!Number.isInteger(id) || id <= 0) {
      return;
    }

    const $field = $(`${fieldsSelector} [name="${fieldName}"]`).first();
    if ($field.length) {
      $field.val(String(id));
    }
  });
}

function setInlineFieldsInvalid(fieldsSelector, invalid) {
  const fields = document.querySelector(fieldsSelector);

  if (!fields) {
    return;
  }

  if (invalid) {
    fields.dataset.opcFieldsInvalid = '1';
  } else {
    delete fields.dataset.opcFieldsInvalid;
  }
}

// Drop a section's invalid marker once none of its fields carries a rendered error anymore, so the
// readiness hint keeps tracking what is actually highlighted. Setting the marker stays verdict-driven
// (applyAutosaveValidation).
function syncInlineFieldsInvalidMarkers() {
  [OPC_SELECTORS.opc.deliveryFields, OPC_SELECTORS.opc.billingFields].forEach((fieldsSelector) => {
    const fields = document.querySelector(fieldsSelector);

    if (fields && fields.dataset.opcFieldsInvalid === '1' && !fields.querySelector('[data-opc-field-error="1"]')) {
      delete fields.dataset.opcFieldsInvalid;
    }
  });
}

/**
 * Render the inline field errors the autosave returns for a COMPLETE-but-invalid address (this
 * replaces the old save-button validation) and mark the affected inline fields invalid so the section
 * readiness suppresses the carrier/payment reveal until they are fixed. A valid address clears both.
 *
 * @param {Object} response   savedraft response (may carry validation_errors)
 * @param {JQuery} $container the edited address container
 */
function applyAutosaveValidation(response, $container) {
  const errors = getFieldErrors(response);
  const fieldNames = Object.keys(errors);

  setInlineFieldsInvalid(OPC_SELECTORS.opc.deliveryFields, fieldNames.some((name) => name.indexOf('invoice_') !== 0));
  setInlineFieldsInvalid(OPC_SELECTORS.opc.billingFields, fieldNames.some((name) => name.indexOf('invoice_') === 0));

  if ($container && $container.length) {
    const root = $container.get(0);
    if (root instanceof HTMLElement) {
      // The buyer did not ask for this validation (it is an autosave): render the errors in
      // place, without scrolling the page or stealing the focus from the field they are editing.
      renderFieldErrors(root, errors, {focusFirstInvalid: false});
    }
  }
}

// One place to handle an autosave response: remember the persisted ids and surface validation errors.
// The option sections are only asked to re-evaluate on a DEFINITIVE result — the address was persisted
// (reveal) or rejected by validation (retract). An inconclusive autosave (no checkout customer yet, or
// an incomplete address) leaves the sections in their current loading/awaiting state, so it never
// triggers a premature fetch (which would also race the guest-init that creates the customer).
// Rendered field errors follow the same rule: only a conclusive verdict may update them — an
// inconclusive autosave says nothing about the fields, so it must not wipe feedback the buyer is
// still correcting.
function handleDraftResponse(response, $container) {
  rememberPersistedInlineAddresses(response);

  const wasRejected = Boolean(response)
    && response.validation_errors
    && typeof response.validation_errors === 'object'
    && Object.keys(response.validation_errors).length > 0;

  if (response && (response.address_persisted || wasRejected)) {
    applyAutosaveValidation(response, $container);

    // The save endpoint answered definitively (persisted, or rejected with field errors): the persist
    // mechanism works, so clear any earlier failure flag before the sections re-evaluate readiness.
    clearAddressPersistFailed();
    emitWithContext(OPC_EVENTS.opcAddressPersisted, response);
  } else {
    // Inconclusive: the server said nothing about the fields, so untouched errors stay. But a field
    // the buyer EDITED since its error rendered gets the benefit of the doubt — its fix cannot be
    // verified here, and leaving a stale error on a corrected value reads as "still wrong". The next
    // conclusive verdict restores the truth if the fix was wrong.
    const root = $container && $container.length ? $container.get(0) : null;
    if (root instanceof HTMLElement) {
      clearEditedFieldErrors(root);
      syncInlineFieldsInvalidMarkers();
    }

    // Inconclusive autosave (incomplete address, no checkout customer yet, or a caught save exception):
    // no definitive persist result. Emit a terminal signal — WITHOUT marking a persist failure (that
    // would surface a misleading "couldn't save" hint) — so a section deferring on a pending
    // country-change persist clears its loader (to awaiting) instead of spinning forever.
    prestashop.emit(OPC_EVENTS.opcAddressPersistInconclusive, {});
  }
}

// The autosave POST itself failed (network / 5xx) — not a validation result. Surface it so the option
// sections leave their loader for a recoverable error instead of waiting on a confirmation that the
// failed request can never deliver. Aborted (superseded) requests are ignored.
function handleDraftFailure(_response, textStatus) {
  if (textStatus === AJAX_STATUS_ABORT) {
    return;
  }

  markAddressPersistFailed();
  prestashop.emit(OPC_EVENTS.opcAddressPersistFailed, {});
}

/**
 * Autosave the guest's in-progress address fields (debounced) so they survive
 * leaving and returning to checkout. Best-effort: failures are silently ignored.
 *
 * @param selectors
 */
function bindAddressDraftAutosave(selectors) {
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

    // collectAddressDraftPayload strips hidden inputs, but the ids of the addresses the autosave already
    // persisted live in hidden fields (written by rememberPersistedInlineAddresses). Send them so the
    // server updates the SAME address in place instead of creating a duplicate on every edit — including
    // after a use_same toggle, where the cart's invoice pointer has reverted to the delivery address and
    // can no longer identify the in-progress inline billing address. The invoice id is sourced only from
    // the inline billing fields' hidden input (never a saved-address pick), so the server can safely
    // treat it as the auto-created inline address.
    const deliveryId = String(addressContainer.find(`${DELIVERY_FIELDS_SELECTOR} [name="id_address_delivery"]`).val() || '');
    const invoiceId = String(addressContainer.find(`${BILLING_FIELDS_SELECTOR} [name="id_address_invoice"]`).val() || '');
    if (deliveryId) {
      payload.id_address_delivery = deliveryId;
    }
    if (invoiceId) {
      payload.id_address_invoice = invoiceId;
    }

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

    const addressContainer = pendingContainer;
    const payload = buildDraftPayload(pendingContainer);
    pendingContainer = null;

    if (useBeacon && typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
      const formData = new FormData();
      Object.keys(payload).forEach((key) => formData.append(key, payload[key]));
      navigator.sendBeacon(draftUrl, formData);

      return;
    }

    // The savedraft endpoint saves a complete & valid address as a real address attached to the cart
    // (when a customer exists). Keep the inline form intact (no list switch); just remember the saved
    // address id(s) so later edits update the same address and the final submit reuses it instead of
    // creating a duplicate. The saved-address list appears naturally on the next full page load.
    $.post(draftUrl, payload)
      .done((response) => handleDraftResponse(response, addressContainer))
      .fail(handleDraftFailure);
  };

  const scheduleAutosave = (event) => {
    // Checked per event (not once at bind time) so autosave starts working the moment the
    // flow becomes no-saved-address — e.g. after the customer deletes their last address.
    if (!shouldPersistAddressDraft()) {
      return;
    }

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

    // Persist-on-complete: when the delivery address is already complete, the carriers/payment are
    // only waiting on this save — flush quickly instead of sitting on the full 700ms debounce.
    const debounceMs = areRenderedRequiredFieldsComplete(OPC_SELECTORS.opc.deliveryFields)
      ? COMPLETE_ADDRESS_AUTOSAVE_DEBOUNCE_MS
      : DRAFT_AUTOSAVE_DEBOUNCE_MS;

    debounceTimer = setTimeout(() => flushPendingDraft(false), debounceMs);
  };

  // A guest's typed address is only persisted once a checkout customer exists. Guest-init creates
  // that customer from the contact email, which can complete AFTER the address was already typed and
  // autosaved (that autosave then ran with no customer and only kept the cookie draft). Re-run the
  // save when guest-init succeeds so a complete address typed first is persisted retroactively. The
  // server still no-ops on incomplete input; the completeness pre-check avoids a useless request.
  // Completeness comes from the RENDERED per-country required fields (the same source as the
  // debounce decision above): a hard-coded field list would demand fields some countries never
  // render (e.g. a postcode for a need_zip_code=0 country) and silently skip the retroactive
  // persist for them, leaving the option sections stuck awaiting a persist that never comes.

  const persistCompletedAddressOnGuestInit = () => {
    if (!shouldPersistAddressDraft()) {
      return;
    }

    const addressContainer = $(selectors.address).first();
    if (!addressContainer.length || !areRenderedRequiredFieldsComplete(OPC_SELECTORS.opc.deliveryFields)) {
      return;
    }

    $.post(draftUrl, buildDraftPayload(addressContainer))
      .done((response) => handleDraftResponse(response, addressContainer))
      .fail(handleDraftFailure);
  };

  prestashop.on(OPC_EVENTS.opcGuestInitSuccess, persistCompletedAddressOnGuestInit);

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

// VIRTUAL-cart STRICT persist-before-refresh parity (physical carts have their own carrier-driven
// defer; refreshAfterVirtualDeliveryAddressChange is a no-op when a delivery-methods section exists). An
// inline country change re-renders and emits opcDeliveryAddressUpdated BEFORE the debounced autosave
// persists the new country onto the cart. A payment module reads context->cart->...->Address INSIDE its
// payment hook, so refreshing then would run the module against the OLD (still-persisted) country while
// the buyer is on the new one — and for an incomplete new country (no valid persist ever) that mismatch
// would be PERMANENT. So while an inline country change is pending its persist we SUPPRESS the refresh
// entirely, and re-refresh ONLY once the new country is actually persisted (opcAddressPersisted with
// address_persisted). Requirement: the modules always run against the last-persisted / up-to-date cart
// address. An incomplete/invalid new country never persists -> the payment readiness retracts to
// awaiting (opc-payment-list) and no module ever renders against a mismatched country. Armed only for a
// virtual cart on an inline country change; stays armed across rejected intermediate persists (e.g. a
// country typed before its required state) so the eventual valid persist is the one that refreshes.
let virtualCountryChangePendingPersist = false;
// Symmetric persist-before-refresh for a SEPARATE inline BILLING address (use_same_address off): an
// invoice-country change re-renders and emits opcBillingAddressUpdated BEFORE the autosave persists the
// new billing country, so refreshing then feeds the payment modules — which read the cart's invoice/tax
// address (context->cart->id_address_invoice->Country) when PS_TAX_ADDRESS_TYPE=id_address_invoice — the
// STALE previous billing country. Suppress that refresh until the new billing country is persisted; an
// invalid/incomplete new billing country never persists, so readiness (isPaymentSectionReady ->
// hasUsableInvoiceAddress, with the invoice id cleared above) retracts to awaiting instead.
let billingCountryChangePendingPersist = false;

prestashop.on(OPC_EVENTS.opcDeliveryCountryChanging, () => {
  if (!hasDeliveryMethodsSection() && isInlineAutosaveActive()) {
    virtualCountryChangePendingPersist = true;
  }
});

prestashop.on(OPC_EVENTS.opcBillingCountryChanging, () => {
  if (isInlineAutosaveActive()) {
    billingCountryChangePendingPersist = true;
  }
});

prestashop.on(OPC_EVENTS.opcBillingAddressSelected, refreshAfterBillingAddressChange);
prestashop.on(OPC_EVENTS.opcBillingAddressUpdated, () => {
  // Suppress the premature refresh while an inline billing-country change waits for its persist — the
  // opcAddressPersisted handler below refreshes once the new billing country is on the cart. A modal
  // billing save persists BEFORE emitting and never arms the flag, so it keeps refreshing immediately.
  if (billingCountryChangePendingPersist) {
    return;
  }

  refreshAfterBillingAddressChange();
});
prestashop.on(OPC_EVENTS.opcBillingSectionToggled, refreshAfterBillingAddressChange);
prestashop.on(OPC_EVENTS.opcDeliveryAddressSelected, refreshAfterVirtualDeliveryAddressChange);
prestashop.on(OPC_EVENTS.opcDeliveryAddressUpdated, () => {
  // Suppress the premature refresh while a virtual inline country change waits for its persist — the
  // opcAddressPersisted handler below refreshes once the new country is actually on the cart. Saved-
  // address / modal saves never arm the flag (no opcDeliveryCountryChanging; already persisted).
  if (virtualCountryChangePendingPersist) {
    return;
  }

  refreshAfterVirtualDeliveryAddressChange();
});
prestashop.on(OPC_EVENTS.opcAddressPersisted, (response) => {
  // Only a SUCCESSFUL persist means the new country is actually on the cart. Fire the pending refresh
  // once (so modules read the fresh address), then disarm. Rejected/incomplete intermediate persists
  // keep the flag armed so the eventual valid persist is the one that refreshes.
  const persisted = Boolean(response && response.address_persisted);

  // Disarm each defer only when the persist covers ITS address type (the response carries the
  // persisted ids). An INTERMEDIATE autosave can legitimately persist only the OTHER type — e.g.
  // during a billing FR -> US change, a debounced save fires before the US state is picked: the
  // invoice is incomplete (skipped) but the delivery persists, so address_persisted is true.
  // Disarming on that save would consume the single deferred refresh against the STALE invoice
  // country, and the eventual valid persist would never trigger one (the module keeps reading the
  // old country until an unrelated refresh).
  if (virtualCountryChangePendingPersist && persisted && Number(response && response.id_address_delivery) > 0) {
    virtualCountryChangePendingPersist = false;
    refreshAfterVirtualDeliveryAddressChange();
  }

  if (billingCountryChangePendingPersist && persisted && Number(response && response.id_address_invoice) > 0) {
    billingCountryChangePendingPersist = false;
    refreshAfterBillingAddressChange();
  }
});
})();
