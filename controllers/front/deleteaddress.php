<?php

/**
 * AJAX endpoint for module-owned OPC address delete.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutDeleteAddressHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutDeleteAddressModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        $handler = new OnePageCheckoutDeleteAddressHandler(
            $this->context,
            $this->module->getTranslator(),
            new CheckoutCustomerContextResolver($this->context)
        );

        return $handler->handle(Tools::getAllValues());
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleDeleteAddress(): array
    {
        return $this->handleOpcRequest();
    }
}
