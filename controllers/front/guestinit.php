<?php

/**
 * AJAX endpoint for module-owned OPC guest initialization.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutGuestInitHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutGuestInitModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
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
        $opcFormFactory = $this->getOpcFormFactory();
        $handler = $this->createGuestInitHandler($opcFormFactory);

        return $handler->handle(Tools::getAllValues());
    }

    protected function getOpcFormFactory(): OnePageCheckoutFormFactory
    {
        assert($this->module instanceof Ps_Onepagecheckout);

        return new OnePageCheckoutFormFactory($this->context, $this->module);
    }

    protected function createGuestInitHandler(OnePageCheckoutFormFactory $opcFormFactory): OnePageCheckoutGuestInitHandler
    {
        return new OnePageCheckoutGuestInitHandler(
            $this->context,
            $opcFormFactory->create(),
            $this->getTranslator(),
            $opcFormFactory->createCustomerPersister(),
            true
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function getTechnicalErrorResponseExtra(): array
    {
        return [
            'customer_created' => false,
            'id_customer' => 0,
            'token' => Tools::getToken(false),
            'static_token' => Tools::getToken(false),
        ];
    }
}
