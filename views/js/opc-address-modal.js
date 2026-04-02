import OPC_EVENTS from './events';
import OPC_SELECTORS from './selectors';
import {getConfiguredOpcUrl, getOpcRuntimeConfiguration} from './runtime/opc-runtime';

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
const OPEN_SELECTOR = '[data-opc-action="open-address-modal"], [data-bs-target="#modal-delivery"], [data-bs-target="#modal-invoice"]';
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

function isNonSubmittableField($field) {
  return $field.is(':button, [type="button"], [type="submit"], [type="reset"], [type="image"], [type="file"]');
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

function getOpcRuntimeI18n(key, fallback = '') {
  const runtimeConfiguration = getOpcRuntimeConfiguration();

  if (runtimeConfiguration && runtimeConfiguration.i18n && runtimeConfiguration.i18n[key]) {
    return String(runtimeConfiguration.i18n[key]);
  }

  return String(fallback);
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

  return typeof field.checkValidity !== 'function' || field.checkValidity();
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

function ensureDeleteConfirmModal() {
  let $modal = $(`#${DELETE_CONFIRM_MODAL_ID}`);

  if ($modal.length) {
    return $modal;
  }

  $modal = $(`
    <div id="${DELETE_CONFIRM_MODAL_ID}" class="modal fade" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <p class="h2 modal-title"></p>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="mb-0 js-opc-delete-address-confirm-message"></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-primary js-opc-delete-address-cancel" data-bs-dismiss="modal"></button>
            <button type="button" class="btn btn-danger js-opc-delete-address-confirm"></button>
          </div>
        </div>
      </div>
    </div>
  `);

  $('body').append($modal);

  return $modal;
}

function showDeleteConfirmation() {
  const deferred = $.Deferred();
  const $modal = ensureDeleteConfirmModal();
  const title = getOpcRuntimeI18n('deleteAddressConfirmTitle', 'Delete this address?');
  const message = getOpcRuntimeI18n('deleteAddressConfirmMessage', 'This action will remove the selected address from your checkout.');
  const confirmLabel = getOpcRuntimeI18n('deleteAddressConfirmLabel', 'Delete');
  const cancelLabel = getOpcRuntimeI18n('deleteAddressCancelLabel', 'Cancel');
  const closeLabel = getOpcRuntimeI18n('deleteAddressCancelLabel', 'Close');
  let confirmed = false;

  $modal.find('.modal-title').text(title);
  $modal.find('.js-opc-delete-address-confirm-message').text(message);
  $modal.find('.js-opc-delete-address-confirm').text(confirmLabel);
  $modal.find('.js-opc-delete-address-cancel').text(cancelLabel);
  $modal.find('.btn-close').attr('aria-label', closeLabel);

  const handleConfirm = () => {
    confirmed = true;
    hideModal($modal);
  };

  const handleCancel = () => {
    hideModal($modal);
  };

  const handleHidden = () => {
    $modal.off('click', '.js-opc-delete-address-confirm', handleConfirm);
    $modal.off('click', '.js-opc-delete-address-cancel, .btn-close', handleCancel);
    $modal.off('hidden.bs.modal', handleHidden);
    deferred.resolve(confirmed);
  };

  $modal.on('click', '.js-opc-delete-address-confirm', handleConfirm);
  $modal.on('click', '.js-opc-delete-address-cancel, .btn-close', handleCancel);
  $modal.on('hidden.bs.modal', handleHidden);
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

  const $sameAddressCheckbox = $(OPC_SELECTORS.opc.useSameAddress);
  serializedFields.push({
    name: 'use_same_address',
    value: $sameAddressCheckbox.length && $sameAddressCheckbox.is(':checked') ? '1' : '0',
  });

  return $.param(serializedFields);
}

function showModal($modal) {
  if (!$modal.length) {
    return;
  }

  const modalElement = $modal.get(0);
  if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();

    return;
  }

  if (typeof $modal.modal === 'function') {
    $modal.modal('show');

    return;
  }

  $modal
    .addClass('show')
    .attr('aria-hidden', 'false')
    .css('display', 'block');

  $modal.trigger('shown.bs.modal');
}

function hideModal($modal) {
  if (!$modal.length) {
    return;
  }

  const modalElement = $modal.get(0);
  if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
    const instance = window.bootstrap.Modal.getInstance(modalElement);
    if (instance) {
      instance.hide();

      return;
    }
  }

  if (typeof $modal.modal === 'function') {
    $modal.modal('hide');

    return;
  }

  $modal
    .removeClass('show')
    .attr('aria-hidden', 'true')
    .css('display', 'none');

  $modal.trigger('hidden.bs.modal');
}

function refreshAddressesSection() {
  const addressFormUrl = getConfiguredOpcUrl(URL_KEYS.addressForm);
  const $addressForm = $(OPC_ADDRESSES_SECTION_SELECTOR).first();

  if (!addressFormUrl || !$addressForm.length) {
    return $.Deferred().resolve().promise();
  }

  const payload = {
    id_address: getAddressSectionFieldValue($addressForm, DELIVERY_SECTION_SELECTOR, DELIVERY_FIELDS_SELECTOR, 'id_address'),
    id_address_invoice: getAddressSectionFieldValue($addressForm, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'id_address_invoice'),
    id_country: getAddressSectionFieldValue($addressForm, DELIVERY_SECTION_SELECTOR, DELIVERY_FIELDS_SELECTOR, 'id_country'),
    invoice_id_country: getAddressSectionFieldValue($addressForm, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'invoice_id_country'),
    use_same_address: $addressForm.find('[name="use_same_address"]').is(':checked') ? '1' : '0',
  };
  const useSameAddress = payload.use_same_address !== '0';

  return $.post(addressFormUrl, payload).done((response) => {
    if (!response || typeof response.addresses_section !== 'string') {
      return;
    }

    $addressForm.html(response.addresses_section);
    $addressForm.find('[name="use_same_address"]').prop('checked', useSameAddress);
    syncBillingSectionConstraints($addressForm, useSameAddress);
    setAddressSectionFieldValue($addressForm, DELIVERY_SECTION_SELECTOR, DELIVERY_FIELDS_SELECTOR, 'id_country', payload.id_country);
    setAddressSectionFieldValue($addressForm, BILLING_SECTION_SELECTOR, BILLING_FIELDS_SELECTOR, 'invoice_id_country', payload.invoice_id_country);
    prestashop.emit(OPC_EVENTS.updatedOpcAddressForm, {target: $addressForm, resp: response});
    prestashop.emit(OPC_EVENTS.opcDeliveryAddressUpdated, {resp: response});
    prestashop.emit(OPC_EVENTS.opcBillingAddressUpdated, {resp: response});
  });
}

$(document).on('click', OPEN_SELECTOR, (event) => {
  const $trigger = $(event.currentTarget);
  const modalTarget = String($trigger.attr('data-bs-target') || '');
  const $modal = modalTarget !== '' ? $(modalTarget).first() : $(MODAL_SELECTOR).first();
  let triggerAddress = null;

  if (!$modal.length) {
    return;
  }

  const modalType = getModalType($trigger);
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
  refreshStates($modal, selectedCountryId, selectedStateId);
});

$(document).on('show.bs.modal', MODAL_SELECTOR, (event) => {
  const $modal = $(event.currentTarget);
  setModalFieldsDisabled($modal, false);
});

$(document).on('shown.bs.modal', MODAL_SELECTOR, (event) => {
  const $modal = $(event.currentTarget);
  setModalFieldsDisabled($modal, false);
  updateModalSaveState($modal);
});

$(document).on('hidden.bs.modal', MODAL_SELECTOR, (event) => {
  const $modal = $(event.currentTarget);
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

  $button.prop('disabled', true).text(loadingText);

  $.post(saveAddressUrl, payload)
    .done((response) => {
      if (!response || response.success === false) {
        renderValidationErrors($modal, response && response.errors ? response.errors : {});
        prestashop.emit('handleError', {eventType: 'opcSaveAddress', resp: response || {}});

        return;
      }

      clearValidationErrors($modal);
      hideModal($modal);
      refreshAddressesSection();
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

        refreshAddressesSection();
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
  retriggerCheckoutValidation();
});

prestashop.on(OPC_EVENTS.updatedOpcAddressForm, () => {
  disableClosedModalFields();
  $(MODAL_SELECTOR).each((_, modal) => updateModalSaveState($(modal)));
  retriggerCheckoutValidation();
});
}());
