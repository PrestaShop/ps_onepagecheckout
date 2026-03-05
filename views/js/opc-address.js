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
const BILLING_SECTION_SELECTOR = '#opc-billing-section';
const SAME_ADDRESS_SELECTOR = '[name="use_same_address"]';
const DISABLED_BY_SAME_ADDRESS_ATTRIBUTE = 'data-opc-disabled-by-same-address';

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

function getOpcRuntimeConfiguration() {
  if (!window || typeof window.ps_onepagecheckout !== 'object' || !window.ps_onepagecheckout) {
    return null;
  }

  return window.ps_onepagecheckout;
}

function getConfiguredOpcUrl(urlKey) {
  const runtimeConfiguration = getOpcRuntimeConfiguration();

  if (runtimeConfiguration && runtimeConfiguration.urls && runtimeConfiguration.urls[urlKey]) {
    return String(runtimeConfiguration.urls[urlKey]);
  }

  return '';
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
  return addressContainer.find(SAME_ADDRESS_SELECTOR).is(':checked');
}

function bindBillingToggleListener(selectors) {
  $('body').on('change', `${selectors.address} ${SAME_ADDRESS_SELECTOR}`, (event) => {
    const addressContainer = $(event.target).closest(selectors.address);

    if (!addressContainer.length) {
      return;
    }

    syncBillingSectionConstraints(addressContainer, getUseSameAddressState(addressContainer));
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
function handleOpcCountryChange(selectors) {
  $('body').on('change', selectors.country, (event) => {
    const target = $(event.target);
    const addressContainer = target.closest(selectors.address);

    if (!addressContainer.length) {
      return;
    }

    // Send both country values so backend rebuilds delivery and billing sections consistently.
    const requestData = {
      id_country: addressContainer.find('[name="id_country"]').val(),
      invoice_id_country: addressContainer.find('[name="invoice_id_country"]').val(),
      use_same_address: addressContainer.find('[name="use_same_address"]').is(':checked') ? '1' : '0',
    };
    const formFieldsSelector = `${selectors.address} input, ${selectors.address} select, ${selectors.address} textarea`;

    const addressFormUrl = getConfiguredOpcUrl(MODULE_ADDRESS_FORM_URL_KEY);
    if (addressFormUrl === '') {
      prestashop.emit('handleError', {
        eventType: 'updateOpcAddressForm',
        resp: {errors: {'': ['Missing OPC address form URL.']}},
      });

      return;
    }

    $.post(
      addressFormUrl,
      requestData,
    ).then((resp) => {
      if (!resp || typeof resp.address_form !== 'string') {
        prestashop.emit('handleError', {eventType: 'updateOpcAddressForm', resp});

        return;
      }

      const preservedFields = preserveAddressFormFields(formFieldsSelector);

      addressContainer.html(resp.address_form);

      restoreAddressFormFields(formFieldsSelector, preservedFields);

      // Backend template resets billing toggle; re-apply user choice.
      const useSameAddress = requestData.use_same_address !== '0';
      addressContainer.find('[name="use_same_address"]').prop('checked', useSameAddress);
      syncBillingSectionConstraints(addressContainer, useSameAddress);

      prestashop.emit('updatedOpcAddressForm', {target: $(selectors.address), resp});
    }).fail((resp) => {
      prestashop.emit('handleError', {eventType: 'updateOpcAddressForm', resp});
    });
  });
}

$(() => {
  const selectors = {
    country: '.js-opc-address-form .js-country',
    address: '.js-opc-address-form',
  };

  handleOpcCountryChange(selectors);
  bindBillingToggleListener(selectors);
  initializeBillingSectionConstraints(selectors);
});
})();
