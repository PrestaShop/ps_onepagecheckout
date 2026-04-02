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
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        try {
            $opcFormFactory = $this->getOpcFormFactory();
            $handler = $this->createAddressFormHandler($opcFormFactory);
            $templateVariables = $handler->getTemplateVariables(Tools::getAllValues());
            $this->context->smarty->assign('customer', $this->buildCheckoutCustomerTemplateVar());

            return [
                'addresses_section' => $this->render(
                    'checkout/_partials/one-page-checkout/addresses-section',
                    $templateVariables
                ),
            ];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                sprintf('ps_onepagecheckout addressForm runtime exception: %s', $exception->getMessage()),
                3,
                null,
                'Module',
                (int) $this->module->id,
                true
            );

            return $this->buildTechnicalErrorResponse();
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleAddressFormRefresh(): array
    {
        return $this->handleOpcRequest();
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

    /**
     * Rebuild the checkout customer template variable so AJAX refreshes do not
     * keep stale address lists from the initial page render.
     *
     * @return array<string,mixed>
     */
    private function buildCheckoutCustomerTemplateVar(): array
    {
        $customer = (new CheckoutCustomerContextResolver($this->context))->resolve();

        if (!$customer instanceof Customer || !Validate::isLoadedObject($customer)) {
            $customer = $this->context->customer;
        }

        if (!$customer instanceof Customer) {
            return [];
        }

        $originalCustomer = $this->context->customer;
        $this->context->customer = $customer;

        try {
            return $this->getTemplateVarCustomer($customer);
        } finally {
            $this->context->customer = $originalCustomer;
        }
    }
}
