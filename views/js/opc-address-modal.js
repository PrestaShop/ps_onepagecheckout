import OPC_EVENTS from './events';
import OPC_SELECTORS from './selectors';
import {getConfiguredOpcUrl} from './runtime/opc-runtime';

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
const STATE_SELECTOR = '[name="id_state"], [name$="id_state"]';
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
  states: 'states',
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
  'id_state',
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
  ADDRESS_FIELDS.forEach((fieldName) => {
    if (fieldName === 'id_address') {
      getModalField($modal, fieldName).val('');

      return;
    }

    const $field = getModalField($modal, fieldName);
    if (!$field.length) {
      return;
    }

    if ($field.is('select')) {
      $field.val('');

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

  return field.checkValidity();
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

  ADDRESS_FIELDS.forEach((fieldName) => {
    const value = typeof address[fieldName] === 'undefined' ? '' : address[fieldName];
    const $field = getModalField($modal, fieldName);

    if (!$field.length) {
      return;
    }

    $field.val(value);
  });
}

function getAddressFromTrigger($trigger) {
  const address = {};

  ADDRESS_FIELDS.forEach((fieldName) => {
    const attributeValue = $trigger.attr(`data-${fieldName}`);

    if (typeof attributeValue !== 'undefined') {
      address[fieldName] = attributeValue;
    }
  });

  return address;
}

function getModalType($trigger) {
  return String($trigger.attr('data-type') || 'create');
}

function readStateUiTargets($modal) {
  return {
    $wrapper: $modal.find('.state-field-wrapper, #state-field-wrapper').first(),
    $select: $modal.find(STATE_SELECTOR).first(),
    $row: $modal.find('.address-country-row, #address-country-row').first(),
  };
}

function updateStateFieldUi($modal, response, selectedStateId) {
  const {$wrapper, $select, $row} = readStateUiTargets($modal);

  if (!$wrapper.length || !$select.length) {
    return;
  }

  const states = response && Array.isArray(response.states) ? response.states : [];
  const hasStates = Boolean(response && response.hasStates) || states.length > 0;

  if (!hasStates) {
    $wrapper.hide();
    $select.prop('required', false).val('');
    if ($row.length) {
      $row.removeClass('form-fields-row--3').addClass('form-fields-row--2');
    }

    return;
  }

  const placeholder = String($select.attr('data-select-placeholder') || '');
  $select.empty();
  $select.append($('<option>', {value: '', text: placeholder}));

  states.forEach((state) => {
    const stateId = String(state.id_state || '');
    $select.append($('<option>', {
      value: stateId,
      text: String(state.name || ''),
      selected: selectedStateId !== '' && stateId === selectedStateId,
    }));
  });

  $wrapper.show();
  $select.prop('required', true);
  if ($row.length) {
    $row.removeClass('form-fields-row--2').addClass('form-fields-row--3');
  }
}

function refreshStates($modal, countryId, selectedStateId) {
  const statesUrl = getConfiguredOpcUrl(URL_KEYS.states);

  if (!statesUrl || countryId === '') {
    updateStateFieldUi($modal, {hasStates: false, states: []}, '');

    return $.Deferred().resolve().promise();
  }

  return $.get(statesUrl, {id_country: countryId}).done((response) => {
    updateStateFieldUi($modal, response || {}, selectedStateId);
    updateModalSaveState($modal);
  }).fail((jqXHR) => {
    updateStateFieldUi($modal, {hasStates: false, states: []}, '');
    updateModalSaveState($modal);
    prestashop.emit('handleError', {
      eventType: 'opcAddressStates',
      resp: jqXHR.responseJSON || {errors: {'': ['Unable to load states.']}},
    });
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

    appendFieldError(getModalField($modal, fieldName), messages[0]);
  });
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

function showSuccessMessage(message) {
  const normalizedMessage = String(message || '').trim();
  if (normalizedMessage === '') {
    return;
  }

  if (window.Theme && window.Theme.components && typeof window.Theme.components.useToast === 'function') {
    const toast = window.Theme.components.useToast(normalizedMessage, {type: 'success'});

    if (toast && typeof toast.show === 'function') {
      toast.show();
    }

    return;
  }

  $('body').append($('<div>', {
    class: 'alert alert-success',
    text: normalizedMessage,
  }));
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
    payload.invoice_id_country = getAddressSectionFieldValue($addressForm, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'id_country');
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
      setAddressSectionFieldValue($addressForm, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'id_country', payload.invoice_id_country);
    }

    syncAllSavedAddressItemStyles();
    prestashop.emit(OPC_EVENTS.updatedOpcAddressForm, {target: $addressForm, resp: response});
    prestashop.emit(OPC_EVENTS.opcDeliveryAddressUpdated, {resp: response});
    prestashop.emit(OPC_EVENTS.opcBillingAddressUpdated, {resp: response});
  });
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

function renderAddressListsLoadingState() {
  [
    [OPC_SELECTORS.opc.deliveryList, '#opc-delivery-address-loader'],
    [OPC_SELECTORS.opc.billingList, '#opc-billing-address-loader'],
  ].forEach(([listSelector, templateSelector]) => {
    const $list = $(listSelector);
    const templateHtml = $(templateSelector).html();

    if ($list.length && templateHtml) {
      $list.html(templateHtml);
    }
  });
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

  $billingList.toggleClass('d-none', addressCount <= 1);
  $billingFields.toggleClass('d-none', addressCount > 1);

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

  if (!addressesListUrl) {
    return refreshAddressesSection(options);
  }

  renderAddressListsLoadingState();

  return $.post(addressesListUrl)
    .then((response) => {
      if (!response || response.success === false || typeof response.address_count === 'undefined') {
        return refreshAddressesSection(options);
      }

      const addressCount = parseInt(response.address_count, 10) || 0;

      if (addressCount <= 0) {
        return refreshAddressesSection(options);
      }

      applyAddressListsResponse(response, options);

      return response;
    })
    .fail((jqXHR) => {
      prestashop.emit('handleError', {eventType: 'opcAddressesList', resp: jqXHR.responseJSON || {}});

      return refreshAddressesSection(options);
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

  if (modalType === 'edit') {
    triggerAddress = getAddressFromTrigger($trigger);
    populateForm($modal, triggerAddress);
  }

  const selectedCountryId = modalType === 'edit' && triggerAddress
    ? String(triggerAddress.id_country || '')
    : String(getModalField($modal, 'id_country').val() || '');
  const selectedStateId = modalType === 'edit' && triggerAddress
    ? String(triggerAddress.id_state || '')
    : String(getModalField($modal, 'id_state').val() || '');

  setModalFieldsDisabled($modal, false);
  refreshStates($modal, selectedCountryId, selectedStateId);
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

  refreshStates($modal, countryId, '');
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

$(document).on('click', MODAL_SAVE_SELECTOR, (event) => {
  event.preventDefault();

  const saveAddressUrl = getConfiguredOpcUrl(URL_KEYS.saveAddress);
  const $button = $(event.currentTarget);
  const $modal = $button.closest(MODAL_SELECTOR);

  if (!saveAddressUrl || !$modal.length) {
    prestashop.emit('handleError', {eventType: 'opcSaveAddress', resp: {errors: {'': ['Missing OPC save address URL.']}}});

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
        renderValidationErrors($modal, response && response.errors ? response.errors : {});
        prestashop.emit('handleError', {eventType: 'opcSaveAddress', resp: response || {}});

        return;
      }

      clearValidationErrors($modal);
      $modal.attr(SKIP_RESTORE_SELECTION_ATTRIBUTE, '1');
      hideModal($modal);
      refreshAddressLists({
        refreshDeliverySelection: addressType === 'delivery',
        refreshBillingSelection: addressType === 'invoice',
      });
      showSuccessMessage(response.message || '');
    })
    .fail((jqXHR) => {
      prestashop.emit('handleError', {eventType: 'opcSaveAddress', resp: jqXHR.responseJSON || {}});
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
    prestashop.emit('handleError', {eventType: 'opcDeleteAddress', resp: {errors: {'': ['Missing OPC delete address URL.']}}});

    return;
  }

  showDeleteConfirmation().then((confirmed) => {
    if (!confirmed) {
      return;
    }

    $button.prop('disabled', true);

    $.post(deleteAddressUrl, {
      id_address: String($button.attr('data-id-address') || ''),
    })
      .done((response) => {
        if (!response || response.success === false) {
          prestashop.emit('handleError', {eventType: 'opcDeleteAddress', resp: response || {}});
          $button.prop('disabled', false);

          return;
        }

        refreshAddressLists({
          resetInlineAddressState: true,
        });
        showSuccessMessage(response.message || '');
      })
      .fail((jqXHR) => {
        prestashop.emit('handleError', {eventType: 'opcDeleteAddress', resp: jqXHR.responseJSON || {}});
        $button.prop('disabled', false);
      });
  });
});

$(function () {
  disableClosedModalFields();
  $(MODAL_SELECTOR).each((_, modal) => updateModalSaveState($(modal)));
  syncAllSavedAddressItemStyles();
  retriggerCheckoutValidation();
});

prestashop.on(OPC_EVENTS.updatedOpcAddressForm, () => {
  disableClosedModalFields();
  $(MODAL_SELECTOR).each((_, modal) => updateModalSaveState($(modal)));
  retriggerCheckoutValidation();
});
}());
