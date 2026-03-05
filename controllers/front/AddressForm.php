<?php
/**
 * AJAX endpoint for module-owned OPC address form refresh.
 */

use PrestaShop\Module\PsOnepagecheckout\Checkout\Ajax\OpcAddressFormHandler;
use PrestaShop\Module\PsOnepagecheckout\Form\OnePageCheckoutFormFactory;

class Ps_OnepagecheckoutAddressFormModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $response = $this->handleAddressFormRefresh();
        $this->renderJsonResponse($response);
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleAddressFormRefresh(): array
    {
        if (!$this->module instanceof Ps_Onepagecheckout || !$this->module->isOnePageCheckoutEnabled()) {
            return $this->buildTechnicalErrorResponse();
        }

        try {
            $opcFormFactory = $this->getOpcFormFactory();
            $handler = $this->createAddressFormHandler($opcFormFactory);
            $templateVariables = $handler->getTemplateVariables(Tools::getAllValues());

            return [
                'address_form' => $this->render(
                    'checkout/_partials/one-page-checkout-form',
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

    protected function getOpcFormFactory(): OnePageCheckoutFormFactory
    {
        return new OnePageCheckoutFormFactory($this->context, $this->module);
    }

    protected function createAddressFormHandler(OnePageCheckoutFormFactory $opcFormFactory): OpcAddressFormHandler
    {
        return new OpcAddressFormHandler($opcFormFactory->create());
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildTechnicalErrorResponse(): array
    {
        return [
            'success' => false,
            'errors' => [
                '' => [
                    $this->trans('One-page checkout is currently unavailable.', [], 'Shop.Notifications.Error'),
                ],
            ],
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
