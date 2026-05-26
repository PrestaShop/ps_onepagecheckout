<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Translation\ModuleTranslation;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutSelectPaymentHandler
{
    private \Context $context;
    private TranslatorInterface $translator;

    public function __construct(\Context $context, TranslatorInterface $translator)
    {
        $this->context = $context;
        $this->translator = $translator;
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
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Missing payment selection payload.', [], ModuleTranslation::SHOP_DOMAIN)
            );
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
