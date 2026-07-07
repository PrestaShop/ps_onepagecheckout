import OPC_SELECTORS from '../../selectors';
import {hasUncheckedRequiredConsent, isRequiredConsentMissing} from './opc-contact-consent';
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
  const list = document.querySelector(listSelector);

  // A saved-address radio can stay :checked in the DOM after the buyer switches to the inline form —
  // e.g. a LOGGED-IN customer editing a new-country inline address: their previously-saved address
  // stays selected in the saved-address picker, which is now HIDDEN (d-none). A hidden picker's
  // selection is NOT active. Counting it as a usable saved address makes readiness treat an
  // incomplete/invalid inline address (US missing its state, or an invalid postcode) as "ready", so the
  // debounced readiness sync re-shows the carrier loader and waits for a persist confirmation that never
  // comes = infinite loader. Only honour the selection when the picker is actually visible.
  if (!list || list.classList.contains('d-none')) {
    return '';
  }

  const radio = list.querySelector(
    `${OPC_SELECTORS.opc.addressRadio}[name="${radioName}"]:checked`
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

// --- Section readiness -------------------------------------------------------
// A section (carriers, payment) is "ready" once a usable address exists for it. Readiness is computed
// from the RENDERED `[required]` fields (so it follows the per-country address format automatically),
// or from a selected saved address. The client cannot read the hidden persisted id, so readiness is
// completeness-based; the inline-country-wins server logic covers the complete-but-unpersisted window.

function getRenderedRequiredFields(fieldsSelector) {
  const fields = document.querySelector(fieldsSelector);

  if (!fields || fields.classList.contains('d-none')) {
    return [];
  }

  return Array.from(fields.querySelectorAll('[required]')).filter((field) => (
    !field.disabled
    && !(field instanceof HTMLInputElement && field.type === 'hidden')
    && field.offsetParent !== null
  ));
}

function isFieldFilled(field) {
  if (field instanceof HTMLInputElement && (field.type === 'checkbox' || field.type === 'radio')) {
    return field.checked;
  }

  return String(field.value || '').trim() !== '';
}

function getFieldLabelText(field) {
  const id = field.getAttribute('id');
  let label = id ? document.querySelector(`label[for="${id}"]`) : null;

  if (!label) {
    label = field.closest('label');
  }

  if (!label) {
    const group = field.closest('.form-group, .mb-3');
    label = group ? group.querySelector('label') : null;
  }

  const text = label ? String(label.textContent || '').replace(/[*:]/g, '').trim() : '';

  return text || String(field.getAttribute('placeholder') || field.getAttribute('name') || '').trim();
}

export function getMissingRequiredFieldLabels(fieldsSelector) {
  return [...new Set(
    getRenderedRequiredFields(fieldsSelector)
      .filter((field) => !isFieldFilled(field))
      .map((field) => getFieldLabelText(field))
      .filter(Boolean)
  )];
}

export function areRenderedRequiredFieldsComplete(fieldsSelector) {
  const required = getRenderedRequiredFields(fieldsSelector);

  return required.length > 0 && required.every((field) => isFieldFilled(field));
}

// The autosave marks the inline fields invalid (data-opc-fields-invalid) when a COMPLETE address is
// rejected by per-country validation, so the readiness suppresses the reveal until it is fixed.
function areInlineFieldsMarkedInvalid(fieldsSelector) {
  const fields = document.querySelector(fieldsSelector);

  return Boolean(fields) && fields.dataset.opcFieldsInvalid === '1';
}

// A successfully persisted inline address writes its id into the hidden id_address_* field
// (rememberPersistedInlineAddresses in opc-address.js) — only on a successful persist, never on a
// reject/incomplete autosave, and it is never cleared. So once an address is persisted it stays a
// usable address: editing a field to a TRANSIENTLY incomplete/invalid value must NOT retract the
// carrier/payment sections (a valid address is still on the cart), matching the email/consent
// behaviour. The forward journey is unaffected: with no successful persist the hidden id stays 0.
function hasPersistedInlineAddress(fieldsSelector, fieldName) {
  const field = document.querySelector(`${fieldsSelector} [name="${fieldName}"]`);

  return Boolean(field) && parseInt(field.value, 10) > 0;
}

// The persisted inline address id, readable ONLY while the inline form is visible — a hidden form
// (buyer switched back to the saved-address list) must not leak its id. Deliberately separate from
// getVisibleInlineAddressId: that one excludes hidden inputs to keep the "typed fields are
// authoritative" contract of the final submit, while this one exists precisely to hand the
// carrier endpoints the same persisted address the autosave already put on the cart, so shipping
// is computed against the real row instead of a temp placeholder.
export function getPersistedInlineAddressId(fieldsSelector, fieldName) {
  const fields = document.querySelector(fieldsSelector);

  if (!fields || fields.classList.contains('d-none')) {
    return '';
  }

  const field = fields.querySelector(`[name="${fieldName}"]`);
  const id = field ? parseInt(field.value, 10) : 0;

  return id > 0 ? String(id) : '';
}

export function hasUsableDeliveryAddress() {
  if (getSelectedAddressId(OPC_SELECTORS.opc.deliveryList, 'id_address_delivery') !== '') {
    return true;
  }

  if (hasPersistedInlineAddress(OPC_SELECTORS.opc.deliveryFields, 'id_address_delivery')) {
    return true;
  }

  return areRenderedRequiredFieldsComplete(OPC_SELECTORS.opc.deliveryFields)
    && !areInlineFieldsMarkedInvalid(OPC_SELECTORS.opc.deliveryFields);
}

export function hasUsableInvoiceAddress() {
  if (getSelectedAddressId(OPC_SELECTORS.opc.billingList, 'id_address_invoice') !== '') {
    return true;
  }

  if (hasPersistedInlineAddress(OPC_SELECTORS.opc.billingFields, 'id_address_invoice')) {
    return true;
  }

  return areRenderedRequiredFieldsComplete(OPC_SELECTORS.opc.billingFields)
    && !areInlineFieldsMarkedInvalid(OPC_SELECTORS.opc.billingFields);
}

// Whether the inline address autosave is active (no saved address yet). When active, the option
// sections wait for the autosave's persist+validation confirmation before revealing their options;
// otherwise (e.g. a selected saved address already in the cart) they can reveal directly.
export function isInlineAutosaveActive() {
  if (!getOpcRuntimeConfiguration()?.persistAddressDraft) {
    return false;
  }

  // Stale-flag guard: a delete re-arms the flag so a billing re-typed inline can persist, but a
  // later toggle/re-render can hide that inline form again (e.g. re-checking "use same address").
  // With no inline form VISIBLE there is nothing the autosave could ever persist — the readiness
  // must reveal directly instead of holding a loader for a confirmation that can never come.
  const deliveryInline = document.querySelector(OPC_SELECTORS.opc.deliveryFields);
  if (deliveryInline && !deliveryInline.classList.contains('d-none')) {
    return true;
  }

  const billingInline = document.querySelector(OPC_SELECTORS.opc.billingFields);

  return getUseSameAddressValue() === '0'
    && Boolean(billingInline)
    && !billingInline.classList.contains('d-none');
}

// A SEPARATE billing address (use_same_address off) that the buyer chose to enter is itself part of the
// order — it is billed, and it is the tax address when PS_TAX_ADDRESS_TYPE=id_address_invoice — so it
// must be usable before the option sections proceed, exactly like the delivery address. It therefore
// gates BOTH the carrier and payment sections whenever "use same address" is off, independently of the
// tax-address configuration: an incomplete/invalid separate billing withholds the options until it is
// corrected, so a refresh is never triggered against a billing address that is not valid yet. With "use
// same address" on the invoice mirrors the delivery address, so there is nothing extra to require.
function hasUsableSeparateBillingAddress() {
  return getUseSameAddressValue() === '1' || hasUsableInvoiceAddress();
}

export function isCarrierSectionReady() {
  return hasUsableDeliveryAddress() && hasUsableSeparateBillingAddress();
}

export function isPaymentSectionReady() {
  return hasUsableDeliveryAddress() && hasUsableSeparateBillingAddress();
}

// The awaiting-address hint lists the missing required fields for the section that is not ready yet.
export function getAwaitingAddressFieldLabels() {
  if (!hasUsableDeliveryAddress()) {
    return getMissingRequiredFieldLabels(OPC_SELECTORS.opc.deliveryFields);
  }

  // Delivery is usable, so a section still gated is waiting on the separate billing address.
  if (getUseSameAddressValue() === '0') {
    return getMissingRequiredFieldLabels(OPC_SELECTORS.opc.billingFields);
  }

  return [];
}

// Whether the buyer has an identity the server can attach a persisted address to: a logged-in
// customer, or a guest whose checkout customer has been initialised (email entered → guest-init).
// Kept as a flag because guest-init happens client-side after page load; the getter also reads the
// page's logged/guest context so a reload mid-checkout is already identified. Without this signal a
// guest who types a COMPLETE address BEFORE entering their email would sit behind an option-section
// loader that can never resolve (there is no customer to persist the address to).
// Cross-bundle state lives on the DOM, NOT in a module variable: each webpack entry (guest-init,
// address, carrier-list, payment-list) bundles its OWN copy of this module, so a module-level flag
// set in one bundle is invisible to the others. The OPC form (#opc-form) is a stable root that the
// inline address re-renders never replace — mirroring how the codebase shares the opcFieldsInvalid
// flag on the DOM. Set when the inline address could NOT be persisted (guest-init failed, or the
// autosave POST failed) so the option sections leave their loader for an actionable error instead of
// spinning forever. Cleared on a successful persist/identity.
const ADDRESS_PERSIST_FAILED_DATASET_KEY = 'opcAddressPersistFailed';

function getOpcRoot() {
  return document.querySelector(OPC_SELECTORS.opc.form);
}

export function markAddressPersistFailed() {
  const root = getOpcRoot();

  if (root) {
    root.dataset[ADDRESS_PERSIST_FAILED_DATASET_KEY] = '1';
  }
}

export function clearAddressPersistFailed() {
  const root = getOpcRoot();

  if (root) {
    root.dataset[ADDRESS_PERSIST_FAILED_DATASET_KEY] = '0';
  }
}

export function hasAddressPersistFailed() {
  const root = getOpcRoot();

  return Boolean(root) && root.dataset[ADDRESS_PERSIST_FAILED_DATASET_KEY] === '1';
}

// A successful guest-init / identity clears the failure so the sections may reveal again.
export function markBuyerIdentified() {
  clearAddressPersistFailed();
}

// A valid contact email already entered means guest-init is done or imminent (it runs on the email
// input, debounced), so the buyer should see a loader — not be re-prompted for the email they gave.
function hasValidContactEmail() {
  if (typeof document === 'undefined') {
    return false;
  }

  const field = document.querySelector(`${OPC_SELECTORS.opc.contactSection} ${OPC_SELECTORS.inputs.email}`);
  const value = field ? String(field.value || '').trim() : '';

  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

export function isBuyerIdentified() {
  const customer = (typeof window !== 'undefined' && window.prestashop) ? window.prestashop.customer : null;
  if (customer && (customer.is_logged || customer.is_guest)) {
    return true;
  }

  // Server-rendered identity: the cart already has a checkout customer attached (a logged-in customer,
  // or a guest whose guest-init created one). This survives a reload / a leave-and-return where the
  // re-rendered contact step shows an empty email field and a reset consent box — the buyer is still
  // identified and their address/carrier are persisted, so the option sections must NOT drop back to
  // the awaiting-identity hint. On the first visit (no customer yet) this is false, so an unidentified
  // visitor typing a complete address is still gated until guest-init runs.
  if (getOpcRuntimeConfiguration()?.buyerIdentified) {
    return true;
  }

  // Mirror guest-init's own gate: it only creates the customer when the email is valid AND the
  // required consent is satisfied (uses the shared opc-contact-consent rule), so the sections agree
  // with it and never spin a loader for a guest-init that will not run.
  return hasValidContactEmail() && !isRequiredConsentMissing();
}

// Why a section is still gated, so the awaiting-address hint can be actionable instead of generic:
//  - 'incomplete-address': required fields are still empty → list them.
//  - 'awaiting-identity':  the address is complete but the guest has no identity yet → point them at
//                          the contact step (their email) rather than spinning an endless loader.
//  - 'pending':            complete & identified, the persist/validation is still settling.
// Whether the address that gates the section is COMPLETE but was rejected by per-country validation
// (so its fields look filled, yet it cannot be used). Mirrors the delivery-then-billing precedence of
// getAwaitingAddressFieldLabels so the hint matches whichever address is actually blocking.
function isRelevantAddressMarkedInvalid() {
  if (areInlineFieldsMarkedInvalid(OPC_SELECTORS.opc.deliveryFields)) {
    return true;
  }

  // A separate billing address (use_same_address off) also gates the sections, so a complete-but-invalid
  // billing is the relevant blocker whenever "use same address" is off.
  if (getUseSameAddressValue() === '0') {
    return areInlineFieldsMarkedInvalid(OPC_SELECTORS.opc.billingFields);
  }

  return false;
}

export function getAwaitingAddressHint() {
  const labels = getAwaitingAddressFieldLabels();

  if (labels.length) {
    return {reason: 'incomplete-address', labels};
  }

  // Complete but rejected: the fields are filled, so "complete your address" would be misleading.
  // Point the buyer at the highlighted field errors shown above the section instead.
  if (isRelevantAddressMarkedInvalid()) {
    return {reason: 'invalid-address', labels: []};
  }

  // Complete & valid, but persistence is blocked (guest-init or autosave failed): the buyer would be
  // stuck behind a loader with no way forward, so surface a recoverable error instead.
  if (hasAddressPersistFailed()) {
    return {reason: 'persist-failed', labels: []};
  }

  if (!isBuyerIdentified()) {
    // Distinguish "email still missing" from "email given but a required consent box is unchecked",
    // so the prompt names the actual blocker rather than re-asking for the email already entered.
    if (hasValidContactEmail() && hasUncheckedRequiredConsent()) {
      return {reason: 'awaiting-consent', labels: []};
    }

    return {reason: 'awaiting-identity', labels: []};
  }

  return {reason: 'pending', labels: []};
}
