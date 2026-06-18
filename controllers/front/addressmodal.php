<?php

/**
 * AJAX endpoint for re-rendering a single address modal's fields for a country.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressModalFieldsHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutAddressModalModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        assert($this->module instanceof Ps_Onepagecheckout);

        $opcFormFactory = new OnePageCheckoutFormFactory($this->context, $this->module);
        $handler = new OnePageCheckoutAddressModalFieldsHandler($opcFormFactory->create());
        $templateVariables = $handler->getTemplateVariables(Tools::getAllValues());

        return [
            'fields_html' => $this->renderModuleTemplate(
                'checkout/_partials/one-page-checkout/address-modal-fields',
                $templateVariables
            ),
        ];
    }
}
