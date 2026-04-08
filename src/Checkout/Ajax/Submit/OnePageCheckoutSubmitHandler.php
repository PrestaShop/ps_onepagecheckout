<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit;

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;

class OnePageCheckoutSubmitHandler
{
    private \Context $context;
    private CheckoutSessionFactory $checkoutSessionFactory;
    private OnePageCheckoutSubmitProcessor $submitProcessor;
    private OnePageCheckoutSubmitValidationStateStorage $submitValidationStateStorage;

    public function __construct(
        \Context $context,
        CheckoutSessionFactory $checkoutSessionFactory,
        OnePageCheckoutSubmitProcessor $submitProcessor,
        OnePageCheckoutSubmitValidationStateStorage $submitValidationStateStorage,
    ) {
        $this->context = $context;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->submitProcessor = $submitProcessor;
        $this->submitValidationStateStorage = $submitValidationStateStorage;
    }

    /**
     * @param array<string, mixed> $requestParameters
     *
     * @return array<string, mixed>
     */
    public function handle(array $requestParameters): array
    {
        $checkoutSession = $this->checkoutSessionFactory->create();
        $this->persistSelectedPaymentModule($requestParameters);
        $processingResult = $this->submitProcessor->process($checkoutSession, $requestParameters);
        if (($processingResult['success'] ?? false) === true) {
            $this->submitValidationStateStorage->clear();

            return [
                'success' => true,
                'reload' => false,
                'checkout_url' => $checkoutSession->getCheckoutURL(),
            ];
        }

        $this->submitValidationStateStorage->save([
            'validation_errors' => isset($processingResult['validation_errors']) && is_array($processingResult['validation_errors'])
                ? $processingResult['validation_errors']
                : [],
            'form_errors' => isset($processingResult['form_errors']) && is_array($processingResult['form_errors'])
                ? $processingResult['form_errors']
                : [],
            'submitted_values' => isset($processingResult['submitted_values']) && is_array($processingResult['submitted_values'])
                ? $processingResult['submitted_values']
                : [],
        ]);

        return [
            'success' => false,
            'reload' => true,
            'checkout_url' => $checkoutSession->getCheckoutURL(),
        ];
    }

    /**
     * @param array<string, mixed> $requestParameters
     */
    private function persistSelectedPaymentModule(array $requestParameters): void
    {
        $paymentMethod = isset($requestParameters['paymentMethod']) ? trim((string) $requestParameters['paymentMethod']) : '';

        if ($paymentMethod === '' || !isset($this->context->cookie)) {
            return;
        }

        $this->context->cookie->__set('opc_selected_payment_module', $paymentMethod);
        $this->context->cookie->write();
    }
}
