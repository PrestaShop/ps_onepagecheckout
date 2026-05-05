<?php

/**
 * AJAX endpoint for module-owned OPC carrier selection.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSelectCarrierHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutSelectCarrierModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        $handler = new OnePageCheckoutSelectCarrierHandler($this->context, $this->module->getTranslator());
        $response = $handler->handle(Tools::getAllValues());

        if (!empty($response['success']) && isset($response['cart_preview'])) {
            $response['preview'] = $this->render(
                'checkout/_partials/cart-summary',
                [
                    'cart' => $response['cart_preview'],
                    'static_token' => Tools::getToken(false),
                ]
            );
            unset($response['cart_preview']);
        }

        return $response;
    }
}
