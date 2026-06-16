<?php

use PrestaShop\Module\PsOnePageCheckout\Analytics\Analytics;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\AddressDraftStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitProcessor;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitValidationStateStorage;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutOpcSubmitModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        $requestParameters = Tools::getAllValues();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            header('Allow: POST');

            return [
                'success' => false,
                'reload' => true,
                'checkout_url' => $this->getCheckoutUrl(),
            ];
        }

        $result = $this->createSubmitHandler()->handle($requestParameters, $this->buildTechnicalErrorResponse());
        if (($result['success'] ?? false) === true) {
            Analytics::trackCheckoutSubmitted(
                (bool) Configuration::get('PS_GUEST_CHECKOUT_ENABLED') ? 'yes' : 'no',
                trim((string) (Tools::getValue('paymentMethod') ?? '')),
                (string) $this->module->version
            );
        }

        return $result;
    }

    protected function createSubmitHandler(): OnePageCheckoutSubmitHandler
    {
        /** @var Ps_Onepagecheckout $module */
        $module = $this->module;
        $translator = $module->getTranslator();
        $checkoutSessionFactory = new CheckoutSessionFactory($this->context, $translator);
        $formFactory = new OnePageCheckoutFormFactory($this->context, $module);

        return new OnePageCheckoutSubmitHandler(
            $this->context,
            $checkoutSessionFactory,
            new OnePageCheckoutSubmitProcessor(
                $this->context,
                $translator,
                $formFactory->create(),
                new PaymentOptionsFinder(),
                new ConditionsToApproveFinder($this->context, $translator)
            ),
            $this->createSubmitValidationStateStorage(),
            new AddressDraftStorage($this->context)
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function getTechnicalErrorResponseExtra(): array
    {
        return [
            'reload' => true,
            'checkout_url' => $this->getCheckoutUrl(),
        ];
    }

    private function getCheckoutUrl(): string
    {
        return isset($this->context->link)
            ? (string) $this->context->link->getPageLink('order')
            : '';
    }

    protected function createSubmitValidationStateStorage(): OnePageCheckoutSubmitValidationStateStorage
    {
        return new OnePageCheckoutSubmitValidationStateStorage($this->context);
    }
}
