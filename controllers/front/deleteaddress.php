<?php

/**
 * AJAX endpoint for module-owned OPC address delete.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutDeleteAddressHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutDeleteAddressModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    protected function needsThemePageAssembly(): bool
    {
        return false;
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        $handler = new OnePageCheckoutDeleteAddressHandler(
            $this->context,
            $this->module->getTranslator(),
            new CheckoutCustomerContextResolver($this->context)
        );

        return $handler->handle(Tools::getAllValues());
    }
}
