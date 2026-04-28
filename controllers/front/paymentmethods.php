<?php

/**
 * AJAX endpoint for module-owned OPC payment methods list.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutPaymentMethodsHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutPaymentMethodsModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        try {
            $handler = new OnePageCheckoutPaymentMethodsHandler($this->context);
            $response = $handler->handle(Tools::getAllValues());

            if (!empty($response['success'])) {
                $response['payment_html'] = $this->renderModuleTemplate(
                    'checkout/_partials/one-page-checkout/payment-methods',
                    [
                        'payment_options' => $response['payment_options'] ?? [],
                        'is_free' => $response['is_free'] ?? false,
                        'selected_payment_module' => $response['selected_payment_module'] ?? '',
                        'selected_payment_selection_key' => $response['selected_payment_selection_key'] ?? '',
                    ]
                );
                unset($response['payment_options']);
            }

            return $response;
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                sprintf('ps_onepagecheckout paymentMethods runtime exception: %s', $exception->getMessage()),
                3,
                null,
                'Module',
                (int) $this->module->id,
                true
            );

            return $this->buildTechnicalErrorResponse();
        }
    }
}
