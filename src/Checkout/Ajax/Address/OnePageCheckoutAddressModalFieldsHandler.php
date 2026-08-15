<?php

/**
 * Module AJAX handler for re-rendering a single address modal's fields.
 */

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;

class OnePageCheckoutAddressModalFieldsHandler
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
     * Build the template variables for one modal's address fields, rebuilt for the
     * requested country so the per-country structure (postcode length/required, state
     * field, identification number, field order) matches the selection. Field values
     * themselves are preserved client-side, so no customer/address prefill happens here.
     *
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function getTemplateVariables(array $requestParameters): array
    {
        $addressType = ((string) ($requestParameters['address_type'] ?? 'delivery')) === 'invoice'
            ? 'invoice'
            : 'delivery';
        $prefix = $addressType === 'invoice' ? 'invoice_' : '';
        $countryId = (int) ($requestParameters['id_country'] ?? 0);

        if ($countryId > 0) {
            $this->opcForm->fillWith([$prefix . 'id_country' => $countryId]);
        }

        $templateVariables = $this->opcForm->getTemplateVariables();
        $fieldsKey = $addressType === 'invoice' ? 'invoiceFields' : 'deliveryFields';
        $rowsKey = $addressType === 'invoice' ? 'invoiceFieldRows' : 'deliveryFieldRows';

        return [
            'formFields' => $templateVariables[$fieldsKey] ?? [],
            // Same rows as the inline form, so the modal and the page never drift apart.
            'fieldRows' => $templateVariables[$rowsKey] ?? [],
            'prefix' => $prefix,
            'modal_id' => $addressType === 'invoice' ? 'modal-invoice' : 'modal-delivery',
            'address_type' => $addressType,
        ];
    }
}
