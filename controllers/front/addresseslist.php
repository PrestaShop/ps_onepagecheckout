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

        $handler = new OnePageCheckoutAddressesListHandler(
            $this->context,
            new CheckoutCustomerContextResolver($this->context)
        );

        return $handler->handle(Tools::getAllValues());
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleAddressesList(): array
    {
        return $this->handleOpcRequest();
    }
}
