<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class OnePageCheckoutSelectPaymentHandler
{
    private \Context $context;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        $paymentOption = $requestParameters['payment_option'] ?? null;
        $paymentModule = $requestParameters['payment_module'] ?? null;
        $paymentSelectionKey = $requestParameters['payment_selection_key'] ?? null;

        if ($this->hasMissingPaymentSelectionPayload($paymentOption, $paymentModule, $paymentSelectionKey)) {
            return CheckoutAjaxResponse::error('Missing payment selection payload');
        }

        $this->context->cookie->__set('opc_selected_payment_option', $paymentOption);
        $this->context->cookie->__set('opc_selected_payment_module', $paymentModule);
        $this->context->cookie->__set('opc_selected_payment_selection_key', $paymentSelectionKey);
        $this->context->cookie->write();

        return [
            'success' => true,
            'payment_option' => $paymentOption,
            'payment_module' => $paymentModule,
            'payment_selection_key' => $paymentSelectionKey,
        ];
    }

    private function hasMissingPaymentSelectionPayload($paymentOption, $paymentModule, $paymentSelectionKey): bool
    {
        return !is_string($paymentOption)
            || $paymentOption === ''
            || !is_string($paymentModule)
            || $paymentModule === ''
            || !is_string($paymentSelectionKey)
            || $paymentSelectionKey === '';
    }
}
