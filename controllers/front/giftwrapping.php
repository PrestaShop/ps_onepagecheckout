<?php

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OrderOptions\OnePageCheckoutGiftWrappingHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutGiftwrappingModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        $handler = new OnePageCheckoutGiftWrappingHandler($this->context, $this->module->getTranslator());
        $result = $handler->handle(Tools::getAllValues());

        if (empty($result['success']) || !isset($result['cart'])) {
            return $result;
        }

        return [
            'success' => true,
            'preview' => $this->render(
                'checkout/_partials/cart-summary',
                [
                    'cart' => $result['cart'],
                    'static_token' => Tools::getToken(false),
                ]
            ),
            'totals' => $result['totals'],
        ];
    }
}
