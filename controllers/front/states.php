<?php

/**
 * AJAX endpoint for module-owned OPC states list.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutStatesHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutStatesModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        $handler = new OnePageCheckoutStatesHandler();

        return $handler->handle(Tools::getAllValues());
    }
}
