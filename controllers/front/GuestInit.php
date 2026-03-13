<?php

/**
 * AJAX endpoint for module-owned OPC guest initialization.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutGuestInitHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;

class Ps_OnepagecheckoutGuestInitModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $response = $this->handleGuestInit();
        $this->renderJsonResponse($response);
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleGuestInit(): array
    {
        if (!$this->module instanceof Ps_Onepagecheckout || !$this->module->isOnePageCheckoutEnabled()) {
            return $this->buildTechnicalErrorResponse();
        }

        try {
            $opcFormFactory = $this->getOpcFormFactory();
            $handler = $this->createGuestInitHandler($opcFormFactory);

            return $handler->handle(Tools::getAllValues());
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                sprintf('ps_onepagecheckout guestInit runtime exception: %s', $exception->getMessage()),
                3,
                null,
                'Module',
                (int) $this->module->id,
                true
            );

            return $this->buildTechnicalErrorResponse();
        }
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
    protected function buildTechnicalErrorResponse(): array
    {
        return [
            'success' => false,
            'customer_created' => false,
            'id_customer' => 0,
            'errors' => [
                '' => [
                    $this->trans('One-page checkout is currently unavailable.', [], 'Shop.Notifications.Error'),
                ],
            ],
            'token' => Tools::getToken(false),
            'static_token' => Tools::getToken(false),
        ];
    }

    /**
     * @param array<string,mixed> $response
     */
    protected function renderJsonResponse(array $response): void
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        $this->ajaxRender(json_encode($response));
    }
}
