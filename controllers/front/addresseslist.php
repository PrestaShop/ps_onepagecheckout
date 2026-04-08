<?php

/**
 * AJAX endpoint for module-owned OPC addresses list.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressesListHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutAddressesListModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
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
            $handler = new OnePageCheckoutAddressesListHandler(
                $this->context,
                new CheckoutCustomerContextResolver($this->context)
            );
            $response = $handler->handle();
            if (empty($response['success'])) {
                return $response;
            }

            return [
                'success' => true,
                'address_count' => (int) ($response['address_count'] ?? 0),
                'delivery_html' => $this->render(
                    'checkout/_partials/one-page-checkout/address-list',
                    [
                        'customer' => $response['customer'] ?? [],
                        'prefix' => '',
                        'selected_address' => (int) ($response['selected_delivery_address'] ?? 0),
                    ]
                ),
                'billing_html' => $this->render(
                    'checkout/_partials/one-page-checkout/address-list',
                    [
                        'customer' => $response['customer'] ?? [],
                        'prefix' => 'invoice_',
                        'selected_address' => (int) ($response['selected_invoice_address'] ?? 0),
                    ]
                ),
            ];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                sprintf('ps_onepagecheckout addressesList runtime exception: %s', $exception->getMessage()),
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
