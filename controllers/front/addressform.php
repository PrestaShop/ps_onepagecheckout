<?php

/**
 * AJAX endpoint for module-owned OPC address form refresh.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressFormHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutAddressFormModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        $opcFormFactory = $this->getOpcFormFactory();
        $handler = $this->createAddressFormHandler($opcFormFactory);
        $templateVariables = $handler->getTemplateVariables(Tools::getAllValues());

        return [
            'addresses_section' => $this->render(
                'checkout/_partials/one-page-checkout/addresses-section',
                $templateVariables
            ),
        ];
    }

    protected function getOpcFormFactory(): OnePageCheckoutFormFactory
    {
        assert($this->module instanceof Ps_Onepagecheckout);

        return new OnePageCheckoutFormFactory($this->context, $this->module);
    }

    protected function createAddressFormHandler(OnePageCheckoutFormFactory $opcFormFactory): OnePageCheckoutAddressFormHandler
    {
        return new OnePageCheckoutAddressFormHandler(
            $opcFormFactory->create(),
            $this->context,
            new CheckoutCustomerContextResolver($this->context)
        );
    }
}
