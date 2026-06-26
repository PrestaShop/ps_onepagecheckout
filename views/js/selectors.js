const OPC_SELECTORS = {
  opc: {
    checkout: '.one-page-checkout',
    form: '#opc-form',
    payButton: '#opc-pay-button',
    payAmount: '#opc-pay-amount',
    addressesSection: '.js-opc-addresses-section',
    deliveryMethods: '#opc-delivery-methods',
    deliveryMethodTitle: '#opc-delivery-section-title',
    paymentMethods: '#opc-payment-methods',
    paymentMethodTitle: '#opc-payment-section-title',
    deliverySection: '#opc-delivery-address',
    deliveryFields: '#opc-delivery-address-fields',
    billingSection: '#opc-billing-section',
    billingFields: '#opc-billing-address-fields',
    deliveryList: '#opc-delivery-address-content-list',
    billingList: '#opc-billing-address-content-list',
    useSameAddress: '[name="use_same_address"]',
    checkoutFooter: '.one-page-checkout__footer',
    contactSection: '.js-opc-contact-section',
    addressRadio: '.js-opc-address-radio',
    addressItem: '.opc-address-item',
    addressLabel: '.form-check-label',
  },
  templates: {
    carrierLoader: '#opc-template-loader',
    carrierError: '#opc-template-carriers-error',
    paymentError: '#opc-template-payment-error',
  },
  inputs: {
    deliveryOption: 'input[name="delivery_option"]',
    paymentOption: 'input[name="payment-option"]',
    email: 'input[name="email"]',
    conditions: '.one-page-checkout input[name^="conditions_to_approve["][required]',
  },
  modals: {
    address: '#opc-address-modal, #modal-delivery, #modal-invoice',
  },
};

export default OPC_SELECTORS;
