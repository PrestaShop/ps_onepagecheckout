const OPC_SELECTORS = {
  opc: {
    checkout: '.one-page-checkout',
    form: '#opc-form',
    payButton: '#opc-pay-button',
    payAmount: '#opc-pay-amount',
    addressesSection: '.js-opc-addresses-section',
    deliveryMethods: '#opc-delivery-methods',
    paymentMethods: '#opc-payment-methods',
    deliverySection: '#opc-delivery-address',
    deliveryFields: '#opc-delivery-address-fields',
    billingSection: '#opc-billing-section',
    billingFields: '#opc-billing-address-fields',
    useSameAddress: '#opc-use-same-address',
    contactSection: '.js-opc-contact-section',
  },
  templates: {
    carrierLoader: '#opc-template-loader',
    carrierError: '#opc-template-carriers-error',
    paymentLoader: '#opc-template-payment-loader',
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
