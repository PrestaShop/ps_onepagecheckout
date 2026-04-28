<?php

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
    abstract protected function handleOpcRequest(): array;

    protected function isOpcAvailable(): bool
    {
        return $this->module instanceof Ps_Onepagecheckout
            && $this->module->isOnePageCheckoutEnabled();
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
                    $this->trans('One-page checkout is currently unavailable.', [], 'Shop.Notifications.Error'),
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
