<?php

/**
 * AJAX endpoint that persists the selected checkout address context (delivery/invoice, honouring
 * "use same address") and returns a fresh cart summary so the live totals/tax stay consistent.
 * This is the dedicated writer used by billing-side refreshes; the totals endpoint (carttotals)
 * stays strictly read-only.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutAddressContextUpdater;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutAjaxResponse;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OpcTempAddress;
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutSelectAddressModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        if (!Validate::isLoadedObject($this->context->cart)) {
            return [
                'success' => false,
                'errors' => ['' => [$this->trans('Unable to resolve checkout cart.', [], 'Modules.Onepagecheckout.Shop')]],
            ];
        }

        $requestParameters = Tools::getAllValues();

        try {
            (new CheckoutAddressContextUpdater($this->context))->updateFromRequest($requestParameters);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return CheckoutAjaxResponse::error(
                $this->trans($exception->getMessage(), [], 'Modules.Onepagecheckout.Shop')
            );
        }

        $cartPreview = $this->presentCartPreview($requestParameters);

        return [
            'success' => true,
            'preview' => $this->render(
                'checkout/_partials/cart-summary',
                [
                    'cart' => $cartPreview,
                    'static_token' => Tools::getToken(false),
                ]
            ),
            'totals' => $cartPreview['totals'],
        ];
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function presentCartPreview(array $requestParameters): CartLazyArray
    {
        $cartPresenterHelper = $this->createCartPresenterHelper();

        if (!$this->needsTemporaryAddressPreview($requestParameters)) {
            return $cartPresenterHelper->presentCart();
        }

        $tempAddress = new OpcTempAddress($this->context);
        $tempAddressId = 0;
        $originalAddressId = (int) $this->context->cart->id_address_delivery;

        try {
            $tempAddressId = $tempAddress->createFromRequest(
                $requestParameters,
                true
            );

            return $cartPresenterHelper->presentCart();
        } finally {
            $tempAddress->cleanup($tempAddressId, $originalAddressId);
        }
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function needsTemporaryAddressPreview(array $requestParameters): bool
    {
        $hasInlineDeliveryAddress = empty($requestParameters['id_address_delivery'])
            && (int) ($requestParameters['id_country'] ?? $requestParameters['delivery_id_country'] ?? 0) > 0;

        $hasInlineInvoiceAddress = (string) ($requestParameters['use_same_address'] ?? '1') === '0'
            && empty($requestParameters['id_address_invoice'])
            && (int) ($requestParameters['invoice_id_country'] ?? 0) > 0;

        return $hasInlineDeliveryAddress || $hasInlineInvoiceAddress;
    }

    protected function createCartPresenterHelper(): CartPresenterHelper
    {
        return new CartPresenterHelper($this->context);
    }
}
