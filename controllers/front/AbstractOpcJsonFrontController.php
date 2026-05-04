<?php

use PrestaShop\Module\PsOnePageCheckout\Analytics\Analytics;
use PrestaShop\Module\PsOnePageCheckout\Translation\ModuleTranslation;

abstract class Ps_OnepagecheckoutAbstractOpcJsonFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $this->renderJsonResponse($this->handleOpcRequest());
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        try {
            return $this->handleAvailableOpcRequest();
        } catch (Throwable $exception) {
            return $this->handleRuntimeException($exception);
        }
    }

    /**
     * @return array<string,mixed>
     */
    abstract protected function handleAvailableOpcRequest(): array;

    protected function isOpcAvailable(): bool
    {
        assert($this->module instanceof Ps_Onepagecheckout);

        return $this->module->isOnePageCheckoutEnabled();
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildTechnicalErrorResponse(): array
    {
        return $this->getTechnicalErrorResponseExtra() + [
            'success' => false,
            'errors' => [
                '' => [
                    $this->trans('One-page checkout is currently unavailable.', [], ModuleTranslation::SHOP_DOMAIN),
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function getTechnicalErrorResponseExtra(): array
    {
        return [];
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleRuntimeException(Throwable $exception): array
    {
        PrestaShopLogger::addLog(
            sprintf('ps_onepagecheckout runtime exception: %s', $exception->getMessage()),
            3,
            null,
            'Module',
            (int) $this->module->id,
            true
        );

        Analytics::trackOpcCriticalError(
            'unknown',
            (bool) Configuration::get('PS_GUEST_CHECKOUT_ENABLED') ? 'yes' : 'no',
            (string) $this->module->version
        );

        return $this->buildTechnicalErrorResponse();
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
        exit;
    }
}
