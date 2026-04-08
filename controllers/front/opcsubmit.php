<?php

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
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            header('Allow: POST');

            return [
                'success' => false,
                'reload' => true,
                'checkout_url' => $this->getCheckoutUrl(),
            ];
        }

        try {
            return $this->createSubmitHandler()->handle(Tools::getAllValues());
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                sprintf('ps_onepagecheckout opcSubmit runtime exception: %s', $exception->getMessage()),
                3,
                null,
                'Module',
                (int) $this->module->id,
                true
            );

            return $this->buildTechnicalErrorResponse();
        }
    }

    protected function createSubmitHandler(): OnePageCheckoutSubmitHandler
    {
        $module = $this->module;
        if (!$module instanceof Ps_Onepagecheckout) {
            throw new LogicException('ps_onepagecheckout module is not available.');
        }

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
            new OnePageCheckoutSubmitValidationStateStorage($this->context)
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
}
