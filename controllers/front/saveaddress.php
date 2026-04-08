<?php

/**
 * AJAX endpoint for module-owned OPC address save.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSaveAddressHandler;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutSaveAddressModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        $handler = new OnePageCheckoutSaveAddressHandler(
            $this->context,
            $this->module->getTranslator(),
            new CheckoutCustomerContextResolver($this->context)
        );

        return $handler->handle(Tools::getAllValues());
    }
}
