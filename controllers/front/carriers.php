<?php

/**
 * AJAX endpoint for module-owned OPC carriers list.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutCarriersHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutCarriersModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        $handler = new OnePageCheckoutCarriersHandler($this->context, $this->module->getTranslator());
        $response = $handler->handle(Tools::getAllValues());

        if (!empty($response['success'])) {
            $response['carriers_html'] = $this->renderModuleTemplate(
                'checkout/_partials/one-page-checkout/carriers',
                [
                    'delivery_options' => $response['delivery_options'] ?? [],
                    'delivery_option' => $response['delivery_option'] ?? '',
                ]
            );
            if (isset($response['cart_preview'])) {
                $response['preview'] = $this->render(
                    'checkout/_partials/cart-summary',
                    [
                        'cart' => $response['cart_preview'],
                        'static_token' => Tools::getToken(false),
                    ]
                );
                unset($response['cart_preview']);
            }
        }

        return $response;
    }
}
