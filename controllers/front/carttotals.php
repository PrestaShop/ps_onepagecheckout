<?php

/**
 * AJAX endpoint returning current cart totals for the OPC pay button.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutCartTotalsModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    protected function needsThemePageAssembly(): bool
    {
        return false;
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        $cartPresenterHelper = new CartPresenterHelper($this->context);
        $cartPreview = $cartPresenterHelper->presentCart();

        return [
            'success' => true,
            'totals' => $cartPreview['totals'],
        ];
    }
}
