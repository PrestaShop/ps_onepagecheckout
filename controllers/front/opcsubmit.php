<?php

use PrestaShop\Module\PsOnePageCheckout\Analytics\Analytics;
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
        $requestParameters = Tools::getAllValues();

        if (!$this->isOpcAvailable()) {
            $response = $this->buildTechnicalErrorResponse();
            $this->persistReloadErrorStateIfNeeded($response, $requestParameters);

            return $response;
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
            $response = $this->createSubmitHandler()->handle($requestParameters);
            $this->persistReloadErrorStateIfNeeded($response, $requestParameters);
            if (($response['success'] ?? false) === true && $this->module instanceof Ps_Onepagecheckout) {
                Analytics::trackCheckoutCompleted(
                    (bool) Configuration::get('PS_GUEST_CHECKOUT_ENABLED') ? 'yes' : 'no',
                    trim((string) (Tools::getValue('paymentMethod') ?? '')),
                    (string) $this->module->version
                );
            }

            return $response;
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                sprintf('ps_onepagecheckout opcSubmit runtime exception: %s', $exception->getMessage()),
                3,
                null,
                'Module',
                (int) $this->module->id,
                true
            );

            $response = $this->buildTechnicalErrorResponse();
            $this->persistReloadErrorStateIfNeeded($response, $requestParameters);

            return $response;
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
            $this->createSubmitValidationStateStorage()
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

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $requestParameters
     */
    private function persistReloadErrorStateIfNeeded(array $response, array $requestParameters): void
    {
        if (empty($response['reload']) || !isset($response['errors']) || !is_array($response['errors'])) {
            return;
        }

        $this->createSubmitValidationStateStorage()->save([
            'cart_id' => $this->getCurrentCartId(),
            'validation_errors' => [],
            'form_errors' => $response['errors'],
            'submitted_values' => $requestParameters,
        ]);
    }

    protected function createSubmitValidationStateStorage(): OnePageCheckoutSubmitValidationStateStorage
    {
        return new OnePageCheckoutSubmitValidationStateStorage($this->context);
    }

    private function getCurrentCartId(): int
    {
        return isset($this->context->cart) ? (int) ($this->context->cart->id ?? 0) : 0;
    }
}
