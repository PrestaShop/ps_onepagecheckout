import {OPC_EVENTS} from './events';
import {emitAddressUpdate} from './address-events';
import OPC_SELECTORS from './selectors';
import {
  getConfiguredOpcMessage,
  getConfiguredOpcUrl,
  normalizeErrorEventResponse,
  setOpcRuntimePersistAddressDraft,
} from './runtime/opc-runtime';

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */
(function psOpcAddressModalRuntime() {
const $ = window.$ || window.jQuery;
const prestashop = window.prestashop || {};

if (!$) {
  return;
}

const MODAL_SELECTOR = OPC_SELECTORS.modals.address;
const SAVE_SELECTOR = '#submit-address-modal, .js-opc-save-address';
const COUNTRY_SELECTOR = '[name="id_country"], [name$="id_country"]';
const MODAL_FIELDS_CONTAINER_SELECTOR = '.js-opc-address-modal-fields';
const MODAL_SCOPES = MODAL_SELECTOR.split(',').map((selector) => selector.trim());
const SAVE_TARGETS = SAVE_SELECTOR.split(',').map((selector) => selector.trim());
const COUNTRY_TARGETS = COUNTRY_SELECTOR.split(',').map((selector) => selector.trim());
const MODAL_SAVE_SELECTOR = MODAL_SCOPES.flatMap((modalSelector) => {
  return SAVE_TARGETS.map((targetSelector) => `${modalSelector} ${targetSelector}`);
}).join(', ');
const MODAL_COUNTRY_SELECTOR = MODAL_SCOPES.flatMap((modalSelector) => {
  return COUNTRY_TARGETS.map((targetSelector) => `${modalSelector} ${targetSelector}`);
}).join(', ');
const MODAL_FIELD_SELECTOR = MODAL_SCOPES.flatMap((modalSelector) => {
  return ['input', 'select', 'textarea'].map((fieldSelector) => `${modalSelector} ${fieldSelector}`);
}).join(', ');
const URL_KEYS = {
  addressesList: 'addressesList',
  addressModal: 'addressModal',
  saveAddress: 'saveAddress',
  deleteAddress: 'deleteAddress',
  addressForm: 'addressForm',
};
const OPC_ADDRESSES_SECTION_SELECTOR = OPC_SELECTORS.opc.addressesSection;
const DELIVERY_SECTION_SELECTOR = OPC_SELECTORS.opc.deliverySection;
const DELIVERY_FIELDS_SELECTOR = OPC_SELECTORS.opc.deliveryFields;
const BILLING_SECTION_SELECTOR = OPC_SELECTORS.opc.billingSection;
const BILLING_FIELDS_SELECTOR = OPC_SELECTORS.opc.billingFields;
const DISABLED_BY_SAME_ADDRESS_ATTRIBUTE = 'data-opc-disabled-by-same-address';
const SERVER_MANAGED_FIELDS = new Set([
  'id_country',
  'invoice_id_country',
  'id_state',
  'invoice_id_state',
  'use_same_address',
]);
const NON_PRESERVABLE_FIELD_TYPES = new Set(['hidden', 'file', 'submit', 'button', 'image', 'reset']);

const ADDRESS_FIELDS = [
  'id_address',
  'alias',
  'firstname',
  'lastname',
  'company',
  'vat_number',
  'address1',
  'address2',
  'city',
  'postcode',
  'id_state',
  'id_country',
  'phone',
];
const FIELD_ERROR_CLASS = 'js-opc-field-error';
const GLOBAL_ERROR_CLASS = 'js-opc-address-modal-error';
const DELETE_CONFIRM_MODAL_ID = 'opc-delete-address-confirm-modal';
const RESTORE_SELECTION_ID_ATTRIBUTE = 'data-opc-restore-address-id';
const RESTORE_SELECTION_RADIO_NAME_ATTRIBUTE = 'data-opc-restore-radio-name';
const SKIP_RESTORE_SELECTION_ATTRIBUTE = 'data-opc-skip-restore-selection';
const ADDRESS_LIST_CONFIG = {
  delivery: {
    listSelector: OPC_SELECTORS.opc.deliveryList,
    loaderTemplateSelector: '#opc-delivery-address-loader',
    errorTemplateSelector: '#opc-delivery-address-error',
  },
  billing: {
    listSelector: OPC_SELECTORS.opc.billingList,
    loaderTemplateSelector: '#opc-billing-address-loader',
    errorTemplateSelector: '#opc-billing-address-error',
  },
};
let pendingAddressListRefreshOptions = null;
let addressListGeneration = 0;

function emitHandleError(eventType, response, fallbackMessage = '') {
  prestashop.emit('handleError', {
    eventType,
    resp: normalizeErrorEventResponse(response, fallbackMessage),
  });
}

function isNonSubmittableField($field) {
  return $field.is(':button, [type="button"], [type="submit"], [type="reset"], [type="image"], [type="file"]');
}

function getFieldType($field) {
  return String($field.prop('type') || '').toLowerCase();
}

function isServerManagedField(name) {
  return !name || SERVER_MANAGED_FIELDS.has(name);
}

function isClientPreservableField(name, $field) {
  if (isServerManagedField(name)) {
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

function preserveAddressesSectionFields($addressForm) {
  const preservedFields = Object.create(null);

  $addressForm.find('input, select, textarea').each((_, field) => {
    const $field = $(field);
    const name = String($field.prop('name') || '');

    if (!isClientPreservableField(name, $field)) {
      return;
    }

    preserveFieldValue(preservedFields, name, $field);
  });

  return preservedFields;
}

function restoreAddressesSectionFields($addressForm, preservedFields) {
  $addressForm.find('input, select, textarea').each((_, field) => {
    const $field = $(field);
    const name = String($field.prop('name') || '');

    if (!isClientPreservableField(name, $field)) {
      return;
    }

    restoreFieldValue($field, preservedFields[name]);
  });
}

function setModalFieldsDisabled($modal, disabled) {
  $modal.find('input, select, textarea').each((_, field) => {
    const $field = $(field);

    if ($field.is('[type="hidden"]') || isNonSubmittableField($field)) {
      return;
    }

    $field.prop('disabled', disabled);
  });
}

function disableClosedModalFields() {
  $(MODAL_SELECTOR).each((_, modal) => {
    const $modal = $(modal);

    if (!$modal.hasClass('show')) {
      setModalFieldsDisabled($modal, true);
    }
  });
}

function retriggerCheckoutValidation() {
  const form = document.querySelector(OPC_SELECTORS.opc.checkout);

  if (!form) {
    return;
  }

  form.dispatchEvent(new Event('change', {bubbles: true}));
}

function getModalField($modal, fieldName) {
  const $exactField = $modal.find(`[name="${fieldName}"]`).first();
  if ($exactField.length) {
    return $exactField;
  }

  return $modal.find(`[name$="${fieldName}"]`).first();
}

function getAddressSection($addressForm, sectionSelector, fieldsSelector) {
  const $fields = $addressForm.find(fieldsSelector).first();
  if ($fields.length) {
    return $fields;
  }

  return $addressForm.find(sectionSelector).first();
}

function getAddressSectionFieldValue($addressForm, sectionSelector, fieldsSelector, fieldName) {
  const $section = getAddressSection($addressForm, sectionSelector, fieldsSelector);
  if (!$section.length) {
    return '';
  }

  const $field = $section.find(`[name="${fieldName}"]`).first();
  if (!$field.length) {
    return '';
  }

  return String($field.val() || '');
}

function setAddressSectionFieldValue($addressForm, sectionSelector, fieldsSelector, fieldName, value) {
  if (typeof value === 'undefined' || value === null || String(value) === '') {
    return;
  }

  const $section = getAddressSection($addressForm, sectionSelector, fieldsSelector);
  if (!$section.length) {
    return;
  }

  const $field = $section.find(`[name="${fieldName}"]`).first();
  if (!$field.length) {
    return;
  }

  $field.val(String(value));
}

function getUseSameAddressField($scope) {
  return $scope.find(OPC_SELECTORS.opc.useSameAddress).first();
}

function getUseSameAddressState($scope) {
  const $field = getUseSameAddressField($scope);

  if (!$field.length) {
    return false;
  }

  return $field.is(':checked');
}

function setUseSameAddressState($scope, useSameAddress) {
  getUseSameAddressField($scope).prop('checked', useSameAddress);
}

function syncBillingSectionConstraints($addressForm, useSameAddress) {
  const $billingSection = $addressForm.find(BILLING_SECTION_SELECTOR).first();

  if (!$billingSection.length) {
    return;
  }

  $billingSection.toggle(!useSameAddress);

  $billingSection.find('input, select, textarea').each((_, field) => {
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

function resetModalFields($modal) {
  const knownFields = new Set(ADDRESS_FIELDS);

  ADDRESS_FIELDS.forEach((fieldName) => {
    if (fieldName === 'id_address') {
      getModalField($modal, fieldName).val('');

      return;
    }

    const $field = getModalField($modal, fieldName);
    if (!$field.length) {
      return;
    }

    $field.val('');
  });


  $modal.find('input, select, textarea').each((_, field) => {
    const $field = $(field);
    const name = String($field.attr('name') || '');

    if (!name || knownFields.has(name) || isServerManagedField(name)) {
      return;
    }

    const type = getFieldType($field);

    if (type === 'hidden' || NON_PRESERVABLE_FIELD_TYPES.has(type)) {
      return;
    }

    if (type === 'checkbox' || type === 'radio') {
      $field.prop('checked', false);

      return;
    }

    $field.val('');
  });
}

function clearValidationErrors($modal) {
  $modal.find(`.${FIELD_ERROR_CLASS}`).remove();
  $modal.find(`.${GLOBAL_ERROR_CLASS}`).remove();
  $modal.find('.is-invalid').removeClass('is-invalid');
}

function isVisibleModalField(field) {
  if (!(field instanceof HTMLElement)) {
    return false;
  }

  const computedStyle = window.getComputedStyle(field);

  return computedStyle.display !== 'none'
    && computedStyle.visibility !== 'hidden'
    && field.getClientRects().length > 0;
}

function isModalFieldValid(field) {
  if (
    !(field instanceof HTMLInputElement)
    && !(field instanceof HTMLSelectElement)
    && !(field instanceof HTMLTextAreaElement)
  ) {
    return true;
  }

  if (field.disabled || !isVisibleModalField(field)) {
    return true;
  }

  if (!field.checkValidity()) {
    return false;
  }

  // checkValidity() ignores minLength/maxLength for values set programmatically (e.g. restored
  // after a country re-render), because the HTML "dirty value" flag is only set by user typing.
  // Enforce them manually so a value that is too short/long for the newly selected country
  // disables Save immediately, instead of only after a failed save round-trip.
  const value = String(field.value || '');
  if (value !== '') {
    const minLength = parseInt(field.getAttribute('minlength') || '', 10);
    if (!Number.isNaN(minLength) && value.length < minLength) {
      return false;
    }

    const maxLength = parseInt(field.getAttribute('maxlength') || '', 10);
    if (!Number.isNaN(maxLength) && value.length > maxLength) {
      return false;
    }
  }

  return true;
}

function updateModalSaveState($modal) {
  const $saveButtons = $modal.find(SAVE_SELECTOR);

  if (!$saveButtons.length) {
    return;
  }

  const modalElement = $modal.get(0);
  const isOpen = modalElement instanceof HTMLElement && $modal.hasClass('show');
  if (!isOpen) {
    $saveButtons.prop('disabled', true);

    return;
  }

  const isValid = $modal
    .find('input, select, textarea')
    .toArray()
    .every((field) => isModalFieldValid(field));

  $saveButtons.prop('disabled', !isValid);
}

function updateModalTitle($modal, type) {
  const $title = $modal.find('.modal-header h2').first();

  if (!$title.length) {
    return;
  }

  const nextTitle = type === 'edit'
    ? String($modal.attr('data-title-edit') || '')
    : String($modal.attr('data-title-new') || '');

  if (nextTitle !== '') {
    $title.text(nextTitle);
  }
}

function populateForm($modal, address) {
  if (!address || typeof address !== 'object') {
    return;
  }

  Object.keys(address).forEach((fieldName) => {
    const value = typeof address[fieldName] === 'undefined' ? '' : address[fieldName];
    const $field = getModalField($modal, fieldName);

    if (!$field.length) {
      return;
    }

    $field.val(value);
  });
}

function getAddressFromTrigger($trigger) {
  const jsonAttr = $trigger.attr('data-address');
  let address = {};

  if (typeof jsonAttr === 'string' && jsonAttr !== '') {
    try {
      const parsed = JSON.parse(jsonAttr);

      if (parsed && typeof parsed === 'object') {
        address = parsed;

        // Remove text-based labels to prevent conflicts with ID fields when using partial selectors (name$="")
        const keysToExclude = ['country', 'state'];
        keysToExclude.forEach(key => {
          delete address[key];
        });
      }
    } catch (e) {
    }
  }

  ADDRESS_FIELDS.forEach((fieldName) => {
    if (address[fieldName] === undefined) {
      const attributeValue = $trigger.attr(`data-${fieldName}`);

      if (typeof attributeValue !== 'undefined') {
        address[fieldName] = attributeValue;
      }
    }
  });

  return address;
}

function getModalType($trigger) {
  return String($trigger.attr('data-type') || 'create');
}

function getAddressTypeForModal($modal) {
  return $modal.is('#modal-invoice') ? 'invoice' : 'delivery';
}

/**
 * Apply a saved address (flat name => value map) onto the freshly rendered modal fields.
 * Used in edit mode, where the address country may differ from the page-rendered modal.
 * The country select is skipped so the server-selected country (and its state list) wins.
 */
function applyAddressValuesToContainer($scope, address) {
  if (!address) {
    return;
  }

  Object.keys(address).forEach((name) => {
    if (/id_country$/.test(name)) {
      return;
    }

    const $field = $scope.find(`[name="${name}"], [name$="${name}"]`).first();
    if (!$field.length) {
      return;
    }

    restoreFieldValue($field, address[name]);
  });
}

/**
 * Re-render the modal fields for the selected country so per-country rules (postcode
 * length/required, state field, identification number, field order) stay in sync.
 *
 * In-progress values are preserved across the swap using the shared preserve/restore
 * helpers (so grouped checkbox/radio hook fields keep their values). When `addressValues`
 * is provided (edit mode), the saved address is applied on top once the new country's
 * fields - including its state list - exist.
 *
 * A per-modal refresh generation guards against out-of-order responses: a slower earlier
 * response is ignored once a newer country change has been requested.
 */
function refreshModalFields($modal, countryId, addressValues) {
  const addressModalUrl = getConfiguredOpcUrl(URL_KEYS.addressModal);
  const $container = $modal.find(MODAL_FIELDS_CONTAINER_SELECTOR).first();

  if (!addressModalUrl || !$container.length || String(countryId || '') === '') {
    updateModalSaveState($modal);

    return $.Deferred().resolve().promise();
  }

  const generation = (Number($modal.data('opcRefreshGeneration')) || 0) + 1;
  $modal.data('opcRefreshGeneration', generation);
  const isStale = () => Number($modal.data('opcRefreshGeneration')) !== generation;
  const preservedFields = preserveAddressesSectionFields($container);

  return $.post(addressModalUrl, {
    id_country: String(countryId),
    address_type: getAddressTypeForModal($modal),
  }).done((response) => {
    if (isStale()) {
      return;
    }

    if (!response || response.success === false || typeof response.fields_html !== 'string') {
      emitHandleError('opcAddressModalFields', response, getConfiguredOpcMessage('addressFieldsLoadFailed', 'Unable to load address fields.'));
      updateModalSaveState($modal);

      return;
    }

    $container.html(response.fields_html);
    restoreAddressesSectionFields($container, preservedFields);
    applyAddressValuesToContainer($container, addressValues);
    setModalFieldsDisabled($modal, false);
    updateModalSaveState($modal);
  }).fail((jqXHR) => {
    if (isStale()) {
      return;
    }

    emitHandleError('opcAddressModalFields', jqXHR && jqXHR.responseJSON, getConfiguredOpcMessage('addressFieldsLoadFailed', 'Unable to load address fields.'));
    updateModalSaveState($modal);
  });
}

function showDeleteConfirmation() {
  const deferred = $.Deferred();
  const $modal = $(`#${DELETE_CONFIRM_MODAL_ID}`);
  let confirmed = false;

  if (!$modal.length) {
    deferred.resolve(false);

    return deferred.promise();
  }

  const handleConfirm = () => {
    confirmed = true;
    hideModal($modal);
  };

  const handleHidden = () => {
    $modal.off('.opcDeleteConfirm');
    deferred.resolve(confirmed);
  };

  $modal.on('click.opcDeleteConfirm', '.js-opc-delete-address-confirm', handleConfirm);
  $modal.on('hidden.bs.modal.opcDeleteConfirm', handleHidden);
  showModal($modal);

  return deferred.promise();
}

function appendFieldError($field, message) {
  if (!$field.length || !message) {
    return;
  }

  $field.addClass('is-invalid');

  const $target = $field.closest('.form-group').length ? $field.closest('.form-group') : $field.parent();
  $target.append($('<div>', {
    class: `invalid-feedback d-block ${FIELD_ERROR_CLASS}`,
    text: message,
  }));
}

function renderValidationErrors($modal, errors) {
  clearValidationErrors($modal);

  if (!errors || typeof errors !== 'object') {
    return;
  }

  let $firstInvalidField = $();

  Object.entries(errors).forEach(([fieldName, fieldErrors]) => {
    const messages = Array.isArray(fieldErrors) ? fieldErrors.filter(Boolean) : [];
    if (messages.length === 0) {
      return;
    }

    if (fieldName === '') {
      $modal.find('.modal-body').first().prepend($('<div>', {
        class: `alert alert-danger ${GLOBAL_ERROR_CLASS}`,
        text: messages.join(' '),
      }));

      return;
    }

    const $field = getModalField($modal, fieldName);
    appendFieldError($field, messages[0]);

    if (!$firstInvalidField.length && $field.length) {
      $firstInvalidField = $field;
    }
  });

  if ($firstInvalidField.length) {
    $firstInvalidField.trigger('focus');
  }
}

function getAddressListSelectorForRadioName(radioName) {
  if (radioName === 'id_address_delivery') {
    return OPC_SELECTORS.opc.deliveryList;
  }

  if (radioName === 'id_address_invoice') {
    return OPC_SELECTORS.opc.billingList;
  }

  return '';
}

function getAddressModalForRadioName(radioName) {
  if (radioName === 'id_address_delivery') {
    return $('#modal-delivery');
  }

  if (radioName === 'id_address_invoice') {
    return $('#modal-invoice');
  }

  return $();
}

function clearRememberedSelection($modal) {
  $modal.removeAttr(RESTORE_SELECTION_ID_ATTRIBUTE);
  $modal.removeAttr(RESTORE_SELECTION_RADIO_NAME_ATTRIBUTE);
  $modal.removeAttr(SKIP_RESTORE_SELECTION_ATTRIBUTE);
}

function rememberSelectedSavedAddressBeforeCreate(radioName) {
  const listSelector = getAddressListSelectorForRadioName(radioName);
  const $modal = getAddressModalForRadioName(radioName);

  if (!listSelector || !$modal.length) {
    return;
  }

  const previousSelection = getSelectedSavedAddress(listSelector, radioName);

  clearRememberedSelection($modal);

  if (!previousSelection.idAddress) {
    return;
  }

  $modal.attr(RESTORE_SELECTION_ID_ATTRIBUTE, previousSelection.idAddress);
  $modal.attr(RESTORE_SELECTION_RADIO_NAME_ATTRIBUTE, radioName);
}

function restoreRememberedSelection($modal) {
  if ($modal.attr(SKIP_RESTORE_SELECTION_ATTRIBUTE) === '1') {
    clearRememberedSelection($modal);

    return;
  }

  const radioName = String($modal.attr(RESTORE_SELECTION_RADIO_NAME_ATTRIBUTE) || '');
  const addressId = String($modal.attr(RESTORE_SELECTION_ID_ATTRIBUTE) || '');
  const listSelector = getAddressListSelectorForRadioName(radioName);

  clearRememberedSelection($modal);

  if (!radioName || !addressId || !listSelector) {
    return;
  }

  const $radio = $(listSelector)
    .find(`${OPC_SELECTORS.opc.addressRadio}[name="${radioName}"][value="${addressId}"]`)
    .first();

  if (!$radio.length) {
    return;
  }

  if ($radio.is(':checked')) {
    syncAddressItemStyles($radio.closest(OPC_SELECTORS.opc.addressItem).parent());

    return;
  }

  $radio.prop('checked', true);
  syncAddressItemStyles($radio.closest(OPC_SELECTORS.opc.addressItem).parent());
}

function getTemplateHtml(templateSelector) {
  const template = document.querySelector(templateSelector);

  return template ? template.innerHTML : '';
}

function getAddressListConfig(listType) {
  if (!Object.prototype.hasOwnProperty.call(ADDRESS_LIST_CONFIG, listType)) {
    return null;
  }

  return ADDRESS_LIST_CONFIG[listType];
}

function getAddressListTypes(options = {}) {
  const requestedListTypes = Array.isArray(options.listTypes) && options.listTypes.length > 0
    ? options.listTypes
    : Object.keys(ADDRESS_LIST_CONFIG);

  const matchedListTypes = requestedListTypes
    .map((listType) => ({
      listType,
      config: getAddressListConfig(listType),
    }))
    .filter(({config}) => config !== null && $(config.listSelector).length > 0);

  const visibleListTypes = matchedListTypes
    .filter(({config}) => $(config.listSelector).is(':visible'))
    .map(({listType}) => listType);

  return visibleListTypes.length > 0
    ? visibleListTypes
    : matchedListTypes.map(({listType}) => listType);
}

function renderAddressListsState(listTypes, templateKey) {
  listTypes.forEach((listType) => {
    const config = getAddressListConfig(listType);

    if (!config) {
      return;
    }

    const $list = $(config.listSelector);
    const templateHtml = getTemplateHtml(config[templateKey]);

    if ($list.length && templateHtml) {
      $list.html(templateHtml);
    }
  });
}

function getListTypesForAddressType(addressType) {
  return addressType === 'invoice' ? ['billing'] : ['delivery'];
}

function serializeModalFields($modal) {
  const serializedFields = [];

  $modal.find('input, select, textarea').each((_, field) => {
    const $field = $(field);
    const name = String($field.attr('name') || '');

    if (name === '' || $field.prop('disabled')) {
      return;
    }

    if (($field.is(':checkbox') || $field.is(':radio')) && !$field.is(':checked')) {
      return;
    }

    serializedFields.push({
      name,
      value: String($field.val() || ''),
    });
  });

  serializedFields.push({
    name: 'use_same_address',
    value: getUseSameAddressState($(OPC_ADDRESSES_SECTION_SELECTOR).first()) ? '1' : '0',
  });

  return $.param(serializedFields);
}

function showModal($modal) {
  if (!$modal.length) {
    return;
  }

  if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
    window.bootstrap.Modal.getOrCreateInstance($modal.get(0)).show();

    return;
  }

  if (typeof $modal.modal === 'function') {
    $modal.modal('show');
  }
}

function hideModal($modal) {
  if (!$modal.length) {
    return;
  }

  if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
    const instance = window.bootstrap.Modal.getInstance($modal.get(0));

    if (instance) {
      instance.hide();

      return;
    }
  }

  if (typeof $modal.modal === 'function') {
    $modal.modal('hide');
  }
}

function syncAddressItemStyles($scope) {
  if (!$scope.length) {
    return;
  }

  $scope.find(OPC_SELECTORS.opc.addressItem).each((_, item) => {
    const $item = $(item);
    const isSelected = $item.find(OPC_SELECTORS.opc.addressRadio).first().is(':checked');

    $item.toggleClass('border-primary selected z-1', isSelected);
    $item.find(OPC_SELECTORS.opc.addressLabel).first().toggleClass('fw-semibold', isSelected);
  });
}

function syncAllSavedAddressItemStyles() {
  syncAddressItemStyles($(OPC_SELECTORS.opc.deliveryList));
  syncAddressItemStyles($(OPC_SELECTORS.opc.billingList));
}

function refreshAddressesSection(options = {}) {
  const addressFormUrl = getConfiguredOpcUrl(URL_KEYS.addressForm);
  const $addressForm = $(OPC_ADDRESSES_SECTION_SELECTOR).first();
  const resetInlineAddressState = Boolean(options.resetInlineAddressState);

  if (!addressFormUrl || !$addressForm.length) {
    return $.Deferred().resolve().promise();
  }

  const payload = {
    use_same_address: getUseSameAddressState($addressForm) ? '1' : '0',
  };

  if (!resetInlineAddressState) {
    payload.id_address_delivery = getAddressSectionFieldValue($addressForm, DELIVERY_SECTION_SELECTOR, DELIVERY_FIELDS_SELECTOR, 'id_address_delivery');
    payload.id_address_invoice = getAddressSectionFieldValue($addressForm, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'id_address_invoice');
    payload.id_country = getAddressSectionFieldValue($addressForm, DELIVERY_SECTION_SELECTOR, DELIVERY_FIELDS_SELECTOR, 'id_country');
    payload.invoice_id_country = getAddressSectionFieldValue($addressForm, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'invoice_id_country');
  }

  const useSameAddress = payload.use_same_address !== '0';
  const preservedFields = preserveAddressesSectionFields($addressForm);

  return $.post(addressFormUrl, payload).done((response) => {
    if (!response || typeof response.addresses_section !== 'string') {
      return;
    }

    $addressForm.html(response.addresses_section);
    restoreAddressesSectionFields($addressForm, preservedFields);
    setUseSameAddressState($addressForm, useSameAddress);
    syncBillingSectionConstraints($addressForm, useSameAddress);
    if (!resetInlineAddressState) {
      setAddressSectionFieldValue($addressForm, DELIVERY_SECTION_SELECTOR, DELIVERY_FIELDS_SELECTOR, 'id_country', payload.id_country);
      setAddressSectionFieldValue($addressForm, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'invoice_id_country', payload.invoice_id_country);
    }

    pendingAddressListRefreshOptions = null;
    syncAllSavedAddressItemStyles();
    prestashop.emit(OPC_EVENTS.updatedOpcAddressForm, {target: $addressForm, resp: response});

    if (options.listTypes?.includes('delivery')) {
      emitAddressUpdate('delivery', {resp: response});
      return;
    }

    if (options.listTypes?.includes('billing')) {
      emitAddressUpdate('billing', {resp: response});
    }
  });
}

function renderAddressListsLoadingState(listTypes) {
  renderAddressListsState(listTypes, 'loaderTemplateSelector');
}

function renderAddressListsErrorState(listTypes) {
  renderAddressListsState(listTypes, 'errorTemplateSelector');
}

function applyAddressListsResponse(response, options = {}) {
  const previousDeliverySelection = getSelectedSavedAddress(OPC_SELECTORS.opc.deliveryList, 'id_address_delivery');
  const previousBillingSelection = getSelectedSavedAddress(OPC_SELECTORS.opc.billingList, 'id_address_invoice');
  const addressCount = parseInt(response.address_count, 10) || 0;
  const $deliveryList = $(OPC_SELECTORS.opc.deliveryList);
  const $deliveryFields = $(DELIVERY_FIELDS_SELECTOR);
  const $billingList = $(OPC_SELECTORS.opc.billingList);
  const $billingFields = $(BILLING_FIELDS_SELECTOR);

  if ($deliveryList.length && typeof response.delivery_html === 'string') {
    $deliveryList.html(response.delivery_html);
  }

  if ($billingList.length && typeof response.billing_html === 'string') {
    $billingList.html(response.billing_html);
  }

  $deliveryList.toggleClass('d-none', addressCount <= 0);
  $deliveryFields.toggleClass('d-none', addressCount > 0);
  $deliveryFields.find('input, select, textarea').prop('disabled', addressCount > 0);

  $billingList.toggleClass('d-none', addressCount <= 1);
  $billingFields.toggleClass('d-none', addressCount > 1);
  $billingFields.find('input, select, textarea').prop('disabled', addressCount > 1);

  const $addressForm = $(OPC_ADDRESSES_SECTION_SELECTOR).first();
  if ($addressForm.length) {
    syncBillingSectionConstraints($addressForm, getUseSameAddressState($addressForm));
  }

  syncAllSavedAddressItemStyles();

  emitSavedAddressSelectionIfNeeded(
    OPC_EVENTS.opcDeliveryAddressSelected,
    previousDeliverySelection,
    getSelectedSavedAddress(OPC_SELECTORS.opc.deliveryList, 'id_address_delivery'),
    Boolean(options.refreshDeliverySelection)
  );
  emitSavedAddressSelectionIfNeeded(
    OPC_EVENTS.opcBillingAddressSelected,
    previousBillingSelection,
    getSelectedSavedAddress(OPC_SELECTORS.opc.billingList, 'id_address_invoice'),
    Boolean(options.refreshBillingSelection)
  );
}

function refreshAddressLists(options = {}) {
  const addressesListUrl = getConfiguredOpcUrl(URL_KEYS.addressesList);
  const listTypes = getAddressListTypes(options);
  const fallbackMessage = getConfiguredOpcMessage('refreshAddressesFailed', 'Unable to refresh addresses.');
  const generation = ++addressListGeneration;

  pendingAddressListRefreshOptions = {
    ...options,
    listTypes,
  };

  if (!addressesListUrl) {
    return refreshAddressesSection(options);
  }

  renderAddressListsLoadingState(listTypes);

  return $.post(addressesListUrl)
    .then((response) => {
      if (generation !== addressListGeneration) {
        return response;
      }

      if (!response || response.success === false || typeof response.address_count === 'undefined') {
        renderAddressListsErrorState(listTypes);
        emitHandleError('opcAddressesList', response, fallbackMessage);

        return response;
      }

      const addressCount = parseInt(response.address_count, 10) || 0;

      // Once the count is known, keep draft autosave aligned with it: enabled while no saved
      // address remains (e.g. after deleting the last one), disabled once one exists.
      setOpcRuntimePersistAddressDraft(addressCount <= 0);

      if (addressCount <= 0) {
        return refreshAddressesSection(options);
      }

      pendingAddressListRefreshOptions = null;
      applyAddressListsResponse(response, options);

      return response;
    })
    .fail((jqXHR) => {
      if (generation !== addressListGeneration) {
        return;
      }

      renderAddressListsErrorState(listTypes);
      emitHandleError('opcAddressesList', jqXHR && jqXHR.responseJSON, fallbackMessage);
    });
}

function getSelectedSavedAddress(listSelector, radioName) {
  const $radio = $(listSelector).find(`${OPC_SELECTORS.opc.addressRadio}[name="${radioName}"]:checked`).first();
  const addressId = String($radio.val() || '');

  if (!$radio.length || addressId === '' || addressId === 'new_address') {
    return {
      idAddress: '',
      target: null,
    };
  }

  const $item = $radio.closest(OPC_SELECTORS.opc.addressItem);

  return {
    idAddress: addressId,
    target: $item.length ? $item.get(0) : $radio.get(0),
  };
}

function emitSavedAddressSelectionIfNeeded(eventName, previousSelection, currentSelection, forceRefresh = false) {
  if (!currentSelection.idAddress) {
    return;
  }

  if (!forceRefresh && previousSelection.idAddress === currentSelection.idAddress) {
    return;
  }

  prestashop.emit(eventName, currentSelection);
}

$(document).on('show.bs.modal', MODAL_SELECTOR, (event) => {
  const $modal = $(event.currentTarget);
  const $trigger = $(event.relatedTarget);
  const modalType = getModalType($trigger);
  let triggerAddress = null;

  $modal.removeAttr(SKIP_RESTORE_SELECTION_ATTRIBUTE);
  if (modalType !== 'create') {
    $modal.removeAttr(RESTORE_SELECTION_ID_ATTRIBUTE);
    $modal.removeAttr(RESTORE_SELECTION_RADIO_NAME_ATTRIBUTE);
  }
  updateModalTitle($modal, modalType);
  clearValidationErrors($modal);
  resetModalFields($modal);
  setModalFieldsDisabled($modal, false);

  if (modalType === 'edit') {
    triggerAddress = getAddressFromTrigger($trigger);
    populateForm($modal, triggerAddress);
    // Rebuild the fields for the edited address country, then re-apply its values:
    // the server-rendered modal reflects the checkout country, which may differ.
    refreshModalFields($modal, String(triggerAddress.id_country || ''), triggerAddress);

    return;
  }

  updateModalSaveState($modal);
});

$(document).on('shown.bs.modal', MODAL_SELECTOR, (event) => {
  const $modal = $(event.currentTarget);
  setModalFieldsDisabled($modal, false);
  updateModalSaveState($modal);
});

$(document).on('hidden.bs.modal', MODAL_SELECTOR, (event) => {
  const $modal = $(event.currentTarget);
  restoreRememberedSelection($modal);
  clearValidationErrors($modal);
  setModalFieldsDisabled($modal, true);
  updateModalSaveState($modal);
});

$(document).on('change', MODAL_COUNTRY_SELECTOR, (event) => {
  const $modal = $(event.currentTarget).closest(MODAL_SELECTOR);
  const countryId = String($(event.currentTarget).val() || '');

  refreshModalFields($modal, countryId);
});

$(document).on('input change', MODAL_FIELD_SELECTOR, (event) => {
  updateModalSaveState($(event.currentTarget).closest(MODAL_SELECTOR));
});

$(document).on('click', OPC_SELECTORS.opc.addressItem, (event) => {
  const $target = $(event.target);
  const $item = $target.closest(OPC_SELECTORS.opc.addressItem);

  if (!$item.length || $target.closest('.opc-address-card__actions').length) {
    return;
  }

  const $radio = $item.find(OPC_SELECTORS.opc.addressRadio).first();
  if (!$radio.length) {
    return;
  }

  if (!$radio.is(':checked')) {
    if (String($radio.val() || '') === 'new_address') {
      rememberSelectedSavedAddressBeforeCreate(String($radio.attr('name') || ''));
    }

    $radio.prop('checked', true).trigger('change');

    return;
  }

  syncAddressItemStyles($item.parent());
});

$(document).on('change', OPC_SELECTORS.opc.addressRadio, (event) => {
  const $radio = $(event.currentTarget);
  const $item = $radio.closest(OPC_SELECTORS.opc.addressItem);
  const $scope = $item.parent();
  const selectedAddressId = String($radio.val() || '');
  const radioName = String($radio.attr('name') || '');

  syncAddressItemStyles($scope);

  if (selectedAddressId === '' || selectedAddressId === 'new_address') {
    return;
  }

  if (radioName === 'id_address_delivery') {
    prestashop.emit(OPC_EVENTS.opcDeliveryAddressSelected, {
      idAddress: selectedAddressId,
      target: $item.get(0),
    });

    return;
  }

  if (radioName === 'id_address_invoice') {
    prestashop.emit(OPC_EVENTS.opcBillingAddressSelected, {
      idAddress: selectedAddressId,
      target: $item.get(0),
    });
  }
});

$(document).on('click', '[data-opc-action="retry-addresses"]', (event) => {
  event.preventDefault();
  refreshAddressLists(pendingAddressListRefreshOptions || {});
});

$(document).on('click', MODAL_SAVE_SELECTOR, (event) => {
  event.preventDefault();

  const saveAddressUrl = getConfiguredOpcUrl(URL_KEYS.saveAddress);
  const $button = $(event.currentTarget);
  const $modal = $button.closest(MODAL_SELECTOR);

  if (!saveAddressUrl || !$modal.length) {
    emitHandleError(
      'opcSaveAddress',
      null,
      getConfiguredOpcMessage('missingSaveAddressUrl', 'Missing OPC save address URL.')
    );

    return;
  }

  setModalFieldsDisabled($modal, false);
  const initialText = String($button.attr('data-text') || $button.text());
  const loadingText = String($button.attr('data-loading-text') || initialText);
  const payload = serializeModalFields($modal);
  const payloadParams = new URLSearchParams(payload);
  const addressType = String(payloadParams.get('address_type') || ($modal.is('#modal-invoice') ? 'invoice' : 'delivery'));

  $button.prop('disabled', true).text(loadingText);

  $.post(saveAddressUrl, payload)
    .done((response) => {
      if (!response || response.success === false) {
        if (response && response.errors) {
          renderValidationErrors($modal, response.errors);
        }

        emitHandleError(
          'opcSaveAddress',
          response,
          getConfiguredOpcMessage('saveAddressFailed', 'Unable to save address.')
        );

        return;
      }

      clearValidationErrors($modal);
      $modal.attr(SKIP_RESTORE_SELECTION_ATTRIBUTE, '1');
      hideModal($modal);
      refreshAddressLists({
        listTypes: getListTypesForAddressType(addressType),
        refreshDeliverySelection: addressType === 'delivery',
        refreshBillingSelection: addressType === 'invoice',
      });
    })
    .fail((jqXHR) => {
      emitHandleError(
        'opcSaveAddress',
        jqXHR && jqXHR.responseJSON,
        getConfiguredOpcMessage('saveAddressFailed', 'Unable to save address.')
      );
    })
    .always(() => {
      $button.prop('disabled', false).text(initialText);
    });
});

$(document).on('click', '.js-delete-address', (event) => {
  event.preventDefault();

  const deleteAddressUrl = getConfiguredOpcUrl(URL_KEYS.deleteAddress);
  const $button = $(event.currentTarget);

  if (!deleteAddressUrl || !$button.length) {
    emitHandleError(
      'opcDeleteAddress',
      null,
      getConfiguredOpcMessage('missingDeleteAddressUrl', 'Missing OPC delete address URL.')
    );

    return;
  }

  showDeleteConfirmation().then((confirmed) => {
    if (!confirmed) {
      return;
    }

    const addressType = String($button.attr('data-address-type') || 'delivery');
    $button.prop('disabled', true);

    $.post(deleteAddressUrl, {
      id_address: String($button.attr('data-id-address') || ''),
    })
      .done((response) => {
        if (!response || response.success === false) {
          emitHandleError(
            'opcDeleteAddress',
            response,
            getConfiguredOpcMessage('deleteAddressFailed', 'Unable to delete address.')
          );
          $button.prop('disabled', false);

          return;
        }

        refreshAddressLists({
          listTypes: getListTypesForAddressType(addressType),
          resetInlineAddressState: true,
        });
      })
      .fail((jqXHR) => {
        emitHandleError(
          'opcDeleteAddress',
          jqXHR && jqXHR.responseJSON,
          getConfiguredOpcMessage('deleteAddressFailed', 'Unable to delete address.')
        );
        $button.prop('disabled', false);
      });
  });
});

function disableHiddenInlineAddressFields() {
  [DELIVERY_FIELDS_SELECTOR, BILLING_FIELDS_SELECTOR].forEach((selector) => {
    const $section = $(selector);

    if ($section.length && $section.hasClass('d-none')) {
      $section.find('input, select, textarea').prop('disabled', true);
    }
  });
}

$(function () {
  disableClosedModalFields();
  disableHiddenInlineAddressFields();
  $(MODAL_SELECTOR).each((_, modal) => updateModalSaveState($(modal)));
  syncAllSavedAddressItemStyles();
  retriggerCheckoutValidation();
});

prestashop.on(OPC_EVENTS.updatedOpcAddressForm, () => {
  disableClosedModalFields();
  disableHiddenInlineAddressFields();
  $(MODAL_SELECTOR).each((_, modal) => updateModalSaveState($(modal)));
  retriggerCheckoutValidation();
});
}());
