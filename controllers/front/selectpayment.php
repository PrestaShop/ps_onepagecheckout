<?php

/**
 * AJAX endpoint for module-owned OPC payment selection.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSelectPaymentHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutSelectPaymentModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        $handler = new OnePageCheckoutSelectPaymentHandler($this->context);

        return $handler->handle(Tools::getAllValues());
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleSelectPayment(): array
    {
        return $this->handleOpcRequest();
    }
}
