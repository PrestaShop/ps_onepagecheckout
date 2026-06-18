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
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            header('Allow: POST');

            return ['success' => false];
        }

        if (!$this->isTokenValid()) {
            header('HTTP/1.1 403 Forbidden');

            return ['success' => false];
        }

        // The draft only helps customers who have no saved address yet (guests, and brand-new
        // accounts created mid-checkout). Once an address is saved, the saved-address flow takes
        // over and there is nothing to keep in a cookie.
        if ((new CheckoutCustomerContextResolver($this->context))->hasSavedAddress()) {
            return ['success' => true];
        }

        // Non-scalar values (e.g. `address1[]=x`) are never legitimate address form input.
        $this->persistAddressDraft(array_filter(Tools::getAllValues(), 'is_scalar'));

        return ['success' => true];
    }

    /**
     * @param array<string,scalar> $requestParameters
     */
    protected function persistAddressDraft(array $requestParameters): void
    {
        /** @var Ps_Onepagecheckout $module */
        $module = $this->module;

        // Build the form from the submitted data so the allowed field names reflect the
        // configured per-country address format and any module-added custom address fields.
        $form = (new OnePageCheckoutFormFactory($this->context, $module))->create();
        $form->fillWith($requestParameters);

        (new AddressDraftStorage($this->context))->saveFromRequest(
            $requestParameters,
            $form->getAddressDraftFieldNames()
        );
    }
}
