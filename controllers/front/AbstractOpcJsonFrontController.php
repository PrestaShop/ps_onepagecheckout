<?php

use PrestaShop\Module\PsOnePageCheckout\Checkout\Context\OpcContextRefreshBuilder;

abstract class Ps_OnepagecheckoutAbstractOpcJsonFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $response = $this->handleOpcRequest();

        // OPC never reloads the page, so the page-load window.prestashop snapshot
        // (country/currency/cart) and the body classes go stale after each AJAX update.
        // Attach the fresh context so the front helper can re-sync them before emitting
        // OPC events. A handler that resolves a more precise country (e.g. an inline-typed
        // address) provides its own 'context_refresh', which we never overwrite.
        if (!array_key_exists('context_refresh', $response)) {
            $response['context_refresh'] = (new OpcContextRefreshBuilder())->build($this->context);
        }

        $this->renderJsonResponse($response);
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
                    $this->trans('One-page checkout is currently unavailable.', [], 'Modules.Onepagecheckout.Shop'),
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
     * Render a module-owned Smarty template by relative path under views/templates/front/.
     * Goes through the `module:` Smarty resource so theme overrides under
     * `themes/<active>/modules/ps_onepagecheckout/...` still take precedence.
     *
     * @param array<string,mixed> $params
     */
    protected function renderModuleTemplate(string $relativePath, array $params): string
    {
        $this->context->smarty->assign($params);

        return $this->context->smarty->fetch(
            'module:ps_onepagecheckout/views/templates/front/' . ltrim($relativePath, '/') . '.tpl'
        );
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
