<?php

/**
 * AJAX endpoint for module-owned OPC states list.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutStatesHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutStatesModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
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
        $handler = new OnePageCheckoutStatesHandler();

        return $handler->handle(Tools::getAllValues());
    }
}
