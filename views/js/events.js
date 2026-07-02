export const CORE_EVENTS = {
  updatedCart: 'updatedCart',
};

export const OPC_EVENTS = {
  opcCarrierSelected: 'opcCarrierSelected',
  opcCarrierSelectionLoading: 'opcCarrierSelectionLoading',
  opcCarrierSelectionFailed: 'opcCarrierSelectionFailed',
  opcCarriersUpdated: 'opcCarriersUpdated',
  opcCarriersFailed: 'opcCarriersFailed',
  opcCarriersLoading: 'opcCarriersLoading',
  // The carrier section withheld its options (no usable delivery address) after a fetch was triggered
  // by an address/cart change — e.g. the customer deleted their last saved address. Dependent sections
  // (payment) chain off this to re-evaluate AFTER the carrier, preserving the carrier -> payment order.
  opcCarriersAwaiting: 'opcCarriersAwaiting',
  opcPaymentMethodsLoading: 'opcPaymentMethodsLoading',
  opcPaymentMethodsUpdated: 'opcPaymentMethodsUpdated',
  opcPaymentMethodsRefreshed: 'opcPaymentMethodsRefreshed',
  opcPaymentMethodsFailed: 'opcPaymentMethodsFailed',
  opcPaymentMethodSelected: 'opcPaymentMethodSelected',
  opcGuestInitSuccess: 'opcGuestInitSuccess',
  opcGuestInitFailed: 'opcGuestInitFailed',
  opcAddressPersistFailed: 'opcAddressPersistFailed',
  opcFinalSubmitStarted: 'opcFinalSubmitStarted',
  opcFormValidated: 'opcFormValidated',
  opcBillingSectionToggled: 'opcBillingSectionToggled',
  opcSubmitFailed: 'opcSubmitFailed',
  opcDeliveryAddressUpdated: 'opcDeliveryAddressUpdated',
  // Fired synchronously when an INLINE delivery country change starts (before the form re-render and
  // the autosave persist). The carrier/payment sections hold on a loader until the new country is
  // persisted onto the cart, so they refresh against the persisted cart (fresh country for modules).
  opcDeliveryCountryChanging: 'opcDeliveryCountryChanging',
  // Same as opcDeliveryCountryChanging, for an inline INVOICE (billing) country change on a SEPARATE
  // billing address (use_same_address off). The payment section holds until the new invoice country is
  // persisted, so a module reading the invoice/tax address gets the fresh country (PS_TAX_ADDRESS_TYPE
  // = id_address_invoice).
  opcBillingCountryChanging: 'opcBillingCountryChanging',
  opcBillingAddressUpdated: 'opcBillingAddressUpdated',
  opcDeliveryAddressSelected: 'opcDeliveryAddressSelected',
  opcBillingAddressSelected: 'opcBillingAddressSelected',
  opcAddressesLoading: 'opcAddressesLoading',
  opcAddressesUpdated: 'opcAddressesUpdated',
  opcAddressesFailed: 'opcAddressesFailed',
  opcCartSummaryBeforeUpdate: 'opcCartSummaryBeforeUpdate',
  opcCartSummaryUpdated: 'opcCartSummaryUpdated',
  updatedOpcAddressForm: 'updatedOpcAddressForm',
  opcAddressPersisted: 'opcAddressPersisted',
  // An autosave that produced NO definitive result (incomplete address, no checkout customer yet, or a
  // caught save exception). Distinct from opcAddressPersistFailed (which flags a real save failure): it
  // lets a section deferring on a pending country-change persist clear its loader without showing a
  // misleading "couldn't save" hint.
  opcAddressPersistInconclusive: 'opcAddressPersistInconclusive',
  opcCarriersRetry: 'opcCarriersRetry',
  opcPaymentMethodsRetry: 'opcPaymentMethodsRetry',
  opcGiftWrapping: 'opcGiftWrapping',
};
