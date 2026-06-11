<?php

/**
 * AJAX endpoint that persists the guest's in-progress address form to the visitor cookie,
 * so the typed data survives navigating away from checkout and back.
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\AddressDraftStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutSaveDraftModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        // The draft only helps customers who have no saved address yet (guests, and brand-new
        // accounts created mid-checkout). Once an address is saved, the saved-address flow takes
        // over and there is nothing to keep in a cookie.
        if ((new CheckoutCustomerContextResolver($this->context))->hasSavedAddress()) {
            return ['success' => true];
        }

        /** @var Ps_Onepagecheckout $module */
        $module = $this->module;
        $requestParameters = Tools::getAllValues();

        // Build the form from the submitted data so the allowed field names reflect the
        // configured per-country address format and any module-added custom address fields.
        $form = (new OnePageCheckoutFormFactory($this->context, $module))->create();
        $form->fillWith($requestParameters);

        (new AddressDraftStorage($this->context))->saveFromRequest(
            $requestParameters,
            $form->getAddressDraftFieldNames()
        );

        return ['success' => true];
    }
}
