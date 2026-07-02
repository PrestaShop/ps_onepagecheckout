<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit;

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\AddressDraftStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;

class OnePageCheckoutSubmitHandler
{
    private \Context $context;
    private CheckoutSessionFactory $checkoutSessionFactory;
    private OnePageCheckoutSubmitProcessor $submitProcessor;
    private OnePageCheckoutSubmitValidationStateStorage $submitValidationStateStorage;
    private AddressDraftStorage $addressDraftStorage;

    public function __construct(
        \Context $context,
        CheckoutSessionFactory $checkoutSessionFactory,
        OnePageCheckoutSubmitProcessor $submitProcessor,
        OnePageCheckoutSubmitValidationStateStorage $submitValidationStateStorage,
        AddressDraftStorage $addressDraftStorage,
    ) {
        $this->context = $context;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->submitProcessor = $submitProcessor;
        $this->submitValidationStateStorage = $submitValidationStateStorage;
        $this->addressDraftStorage = $addressDraftStorage;
    }

    /**
     * @param array<string, mixed> $requestParameters
     * @param array<string, mixed> $reloadErrorResponse
     *
     * @return array<string, mixed>
     */
    public function handle(array $requestParameters, array $reloadErrorResponse = []): array
    {
        try {
            return $this->processSubmit($requestParameters);
        } catch (\Throwable $exception) {
            $this->persistErrorsForNextReload($requestParameters, $reloadErrorResponse);

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $requestParameters
     *
     * @return array<string, mixed>
     */
    private function processSubmit(array $requestParameters): array
    {
        $checkoutSession = $this->checkoutSessionFactory->create();
        $this->persistSelectedPaymentModule($requestParameters);
        $processingResult = $this->submitProcessor->process($checkoutSession, $requestParameters);
        if (($processingResult['success'] ?? false) === true) {
            $this->submitValidationStateStorage->clear();
            $this->addressDraftStorage->clear();

            return [
                'success' => true,
                'reload' => false,
                'checkout_url' => $checkoutSession->getCheckoutURL(),
            ];
        }

        $persistedState = isset($processingResult['persisted_state']) && is_array($processingResult['persisted_state'])
            ? $processingResult['persisted_state']
            : [
                'validation_errors' => isset($processingResult['validation_errors']) && is_array($processingResult['validation_errors'])
                    ? $processingResult['validation_errors']
                    : [],
                'form_errors' => isset($processingResult['form_errors']) && is_array($processingResult['form_errors'])
                    ? $processingResult['form_errors']
                    : [],
                'submitted_values' => isset($processingResult['submitted_values']) && is_array($processingResult['submitted_values'])
                    ? $processingResult['submitted_values']
                    : [],
            ];

        $shouldRenderInline = $this->shouldReturnInlineFieldErrors($persistedState);
        if (!$shouldRenderInline) {
            $this->persistFailedSubmitState($persistedState);
        }

        return [
            'success' => false,
            'reload' => !$shouldRenderInline,
            'checkout_url' => $checkoutSession->getCheckoutURL(),
            'form_errors' => isset($persistedState['form_errors']) && is_array($persistedState['form_errors'])
                ? $persistedState['form_errors']
                : [],
            'validation_errors' => isset($persistedState['validation_errors']) && is_array($persistedState['validation_errors'])
                ? $persistedState['validation_errors']
                : [],
        ];
    }

    /**
     * @param array<string, mixed> $requestParameters
     * @param array<string, mixed> $response
     */
    private function persistErrorsForNextReload(array $requestParameters, array $response): void
    {
        if (empty($response['reload']) || empty($response['errors'])) {
            return;
        }

        try {
            $this->persistFailedSubmitState([
                'validation_errors' => [],
                'form_errors' => $response['errors'],
                'submitted_values' => $requestParameters,
            ]);
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                sprintf('ps_onepagecheckout opcSubmit technical error state could not be persisted: %s', $exception->getMessage()),
                3
            );
        }
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

    /**
     * @param array<string, mixed> $state
     */
    private function persistFailedSubmitState(array $state): void
    {
        $this->submitValidationStateStorage->save([
            'cart_id' => $this->getRequiredCurrentCartId(),
        ] + $state);

        // The submitted values are now carried by the one-shot failed-submit state above.
        // Drop the older address draft so once that state is consumed a later reload cannot
        // restore stale fields and silently roll the address back.
        $this->addressDraftStorage->clear();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function shouldReturnInlineFieldErrors(array $state): bool
    {
        // Field errors can stay inline; global or non-address errors need the legacy reload fallback.
        $formErrors = isset($state['form_errors']) && is_array($state['form_errors'])
            ? $state['form_errors']
            : [];

        if ($formErrors === [] || !empty($formErrors[''])) {
            return false;
        }

        $fieldErrors = array_filter(
            $formErrors,
            static fn ($errors, $fieldName): bool => $fieldName !== '' && is_array($errors) && $errors !== [],
            ARRAY_FILTER_USE_BOTH
        );

        if ($fieldErrors === []) {
            return false;
        }

        $validationErrors = isset($state['validation_errors']) && is_array($state['validation_errors'])
            ? $state['validation_errors']
            : [];

        return array_diff(array_keys($validationErrors), ['address']) === [];
    }

    private function getRequiredCurrentCartId(): int
    {
        $cart = $this->context->cart ?? null;
        $cartId = (int) ($cart->id ?? 0);

        if ($cartId <= 0) {
            throw new \RuntimeException('Cannot persist one-page checkout submit state without an active cart.');
        }

        return $cartId;
    }
}
