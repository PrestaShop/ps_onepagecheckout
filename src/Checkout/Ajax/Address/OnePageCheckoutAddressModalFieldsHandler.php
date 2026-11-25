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

    private \Context $context;

    public function __construct(OnePageCheckoutForm $opcForm)
    {
        $this->opcForm = $opcForm;
        $this->context = \Context::getContext();
    }

    /**
     * Build the template variables for one modal's address fields, rebuilt for the
     * requested country so the per-country structure (postcode length/required, state
     * field, identification number, field order) matches the selection. When editing an
     * existing address, its extra properties prefill the module-provided fields. Other
     * field values are preserved client-side.
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

        $addressId = (int) ($requestParameters['id_address'] ?? 0);
        if ($addressId > 0) {
            $customer = $this->context->customer;
            if (
                !$customer instanceof \Customer
                || (int) $customer->id <= 0
                || !\Customer::customerHasAddress((int) $customer->id, $addressId)
            ) {
                // Never expose another customer's address through this endpoint.
                throw new \InvalidArgumentException('Address not available for this customer.');
            }

            $address = new \Address($addressId, (int) $this->context->language->id);
            if (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== (int) $customer->id || (bool) $address->deleted) {
                throw new \InvalidArgumentException('Address not available for this customer.');
            }
        }

        // Initialize address-hook context before fillWith() can build the fields.
        // Only the currently edited address is exposed, with the correct type.
        if ($addressType === 'invoice') {
            $this->opcForm->prepareAddressContext(null, $address ?? null);
        } else {
            $this->opcForm->prepareAddressContext($address ?? null);
        }

        // The explicit country selection takes precedence over the saved address.
        if ($countryId <= 0 && isset($address)) {
            $countryId = (int) $address->id_country;
        }

        if ($countryId > 0) {
            $this->opcForm->fillWith([$prefix . 'id_country' => $countryId]);
        }

        // Apply extra properties after country-specific fields have been rebuilt.
        if (isset($address)) {
            $this->opcForm->fillAddressExtraProperties($address, $prefix);
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
