<?php

/**
 * AJAX endpoint for module-owned OPC payment selection.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSelectPaymentHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutSelectPaymentModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    protected function needsThemePageAssembly(): bool
    {
        return false;
    }

    protected function refreshesCartTotals(): bool
    {
        return false;
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        $handler = new OnePageCheckoutSelectPaymentHandler($this->context, $this->module->getTranslator());

        return $handler->handle(Tools::getAllValues());
    }
}
