import OPC_SELECTORS from '../../selectors';
import {getOpcRuntimeConfiguration} from '../opc-runtime';

/**
 * Resolve the "use same address" choice as the server expects it: '1' means the invoice
 * address mirrors the delivery address, '0' means a separate billing address is used.
 *
 * When the checkbox is absent the billing section is not rendered, so we default to '1'
 * (mirror delivery), matching the server-side default for a missing use_same_address flag.
 *
 * Shared by the carrier and payment list runtimes so the value cannot drift between them.
 */
export function getUseSameAddressValue() {
  const checkbox = document.querySelector(OPC_SELECTORS.opc.useSameAddress);

  if (!checkbox) {
    return '1';
  }

  return checkbox.checked ? '1' : '0';
}

export const DELIVERY_ADDRESS_CONTEXT_FIELDS = [
  'id_country',
  'id_state',
  'postcode',
  'city',
];

export const INVOICE_ADDRESS_CONTEXT_FIELDS = [
  'invoice_id_country',
  'invoice_id_state',
  'invoice_postcode',
  'invoice_city',
];

export function hasDeliveryMethodsSection() {
  return document.querySelector(OPC_SELECTORS.opc.deliveryMethods) instanceof HTMLElement;
}

export function carrierPricesDependOnBillingAddress() {
  return String(getOpcRuntimeConfiguration()?.taxAddressType || '') === 'id_address_invoice';
}

export function collectVisibleAddressContext(form, fieldsSelector, fieldNames) {
  if (!form) {
    return {};
  }

  const fields = form.querySelector(fieldsSelector);
  if (!fields || fields.classList.contains('d-none')) {
    return {};
  }

  return fieldNames.reduce((payload, field) => {
    const input = fields.querySelector(`[name="${field}"]`);
    const value = input && !input.disabled ? (input.value || '').trim() : '';

    if (value !== '') {
      payload[field] = value;
    }

    return payload;
  }, {});
}

export function collectBillingAddressContext(form) {
  const useSameAddress = getUseSameAddressValue();

  return {
    use_same_address: useSameAddress,
    ...(useSameAddress === '0'
      ? collectVisibleAddressContext(form, OPC_SELECTORS.opc.billingFields, INVOICE_ADDRESS_CONTEXT_FIELDS)
      : {}),
  };
}

export function getSelectedAddressId(listSelector, radioName) {
  const radio = document.querySelector(
    `${listSelector} ${OPC_SELECTORS.opc.addressRadio}[name="${radioName}"]:checked`
  );
  const value = radio ? String(radio.value || '') : '';

  return value && value !== 'new_address' ? value : '';
}

export function getVisibleInlineAddressId(fieldsSelector, fieldName) {
  const fields = document.querySelector(fieldsSelector);

  if (!fields || fields.classList.contains('d-none')) {
    return '';
  }

  const input = fields.querySelector(`[name="${fieldName}"]`);
  if (!(input instanceof HTMLInputElement) || input.type === 'hidden' || input.disabled) {
    return '';
  }

  return input.value || '';
}

export function getSelectedOrInlineAddressId(listSelector, fieldsSelector, fieldName) {
  return getSelectedAddressId(listSelector, fieldName)
    || getVisibleInlineAddressId(fieldsSelector, fieldName);
}

export function buildSelectAddressPayload(form) {
  const useSameAddress = getUseSameAddressValue();
  const deliveryAddressId = getSelectedOrInlineAddressId(
    OPC_SELECTORS.opc.deliveryList,
    OPC_SELECTORS.opc.deliveryFields,
    'id_address_delivery'
  );
  const invoiceAddressId = useSameAddress === '1'
    ? deliveryAddressId
    : getSelectedOrInlineAddressId(
      OPC_SELECTORS.opc.billingList,
      OPC_SELECTORS.opc.billingFields,
      'id_address_invoice'
    );

  const payload = {use_same_address: useSameAddress};

  if (deliveryAddressId) {
    payload.id_address_delivery = deliveryAddressId;
  } else {
    Object.assign(
      payload,
      collectVisibleAddressContext(form, OPC_SELECTORS.opc.deliveryFields, DELIVERY_ADDRESS_CONTEXT_FIELDS)
    );
  }

  if (invoiceAddressId) {
    payload.id_address_invoice = invoiceAddressId;
  } else if (useSameAddress === '0') {
    Object.assign(
      payload,
      collectVisibleAddressContext(form, OPC_SELECTORS.opc.billingFields, INVOICE_ADDRESS_CONTEXT_FIELDS)
    );
  }

  return payload;
}
