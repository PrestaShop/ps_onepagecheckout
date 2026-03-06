<?php

/**
 * Module AJAX handler for OPC address form refresh.
 */

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;

class OnePageCheckoutAddressFormHandler
{
    /**
     * @var OnePageCheckoutForm
     */
    private $opcForm;

    public function __construct(OnePageCheckoutForm $opcForm)
    {
        $this->opcForm = $opcForm;
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function getTemplateVariables(array $requestParameters): array
    {
        if (isset($requestParameters['id_address']) && (int) $requestParameters['id_address'] > 0) {
            $this->opcForm->fillFromAddress(new \Address((int) $requestParameters['id_address'], \Context::getContext()->language->id));
        }

        $formParams = [];

        foreach (['id_country', 'invoice_id_country', 'use_same_address'] as $name) {
            if (isset($requestParameters[$name])) {
                $formParams[$name] = $requestParameters[$name];
            }
        }

        if (!empty($formParams)) {
            $this->opcForm->fillWith($formParams);
        }

        return $this->opcForm->getTemplateVariables();
    }
}
