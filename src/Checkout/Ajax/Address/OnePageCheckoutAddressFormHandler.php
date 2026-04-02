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
    private \Context $context;
    private CheckoutCustomerContextResolver $customerResolver;

    public function __construct(
        OnePageCheckoutForm $opcForm,
        ?\Context $context = null,
        ?CheckoutCustomerContextResolver $customerResolver = null,
    ) {
        $this->opcForm = $opcForm;
        $this->context = $context ?? \Context::getContext();
        $this->customerResolver = $customerResolver ?? new CheckoutCustomerContextResolver($this->context);
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function getTemplateVariables(array $requestParameters): array
    {
        $ownedDeliveryAddress = null;
        if (isset($requestParameters['id_address']) && (int) $requestParameters['id_address'] > 0) {
            $ownedDeliveryAddress = $this->loadOwnedAddress((int) $requestParameters['id_address']);
            if ($ownedDeliveryAddress instanceof \Address) {
                $this->opcForm->fillFromAddress($ownedDeliveryAddress);
            }
        }

        $formParams = [];

        foreach (['id_country', 'invoice_id_country', 'use_same_address', 'id_address', 'id_address_invoice'] as $name) {
            if (!isset($requestParameters[$name])) {
                continue;
            }

            if (in_array($name, ['id_address', 'id_address_invoice'], true) && (int) $requestParameters[$name] <= 0) {
                continue;
            }

            if ($name === 'id_address' && !$ownedDeliveryAddress instanceof \Address) {
                continue;
            }

            if ($name === 'id_address_invoice' && !$this->isOwnedAddressId((int) $requestParameters[$name])) {
                continue;
            }

            $formParams[$name] = $requestParameters[$name];
        }

        if (!empty($formParams)) {
            $this->opcForm->fillWith($formParams);
        }

        return $this->opcForm->getTemplateVariables();
    }

    private function loadOwnedAddress(int $addressId): ?\Address
    {
        $customerId = $this->customerResolver->resolveId();
        if ($customerId <= 0) {
            return null;
        }

        $address = new \Address($addressId, (int) $this->context->language->id);
        if (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== $customerId) {
            return null;
        }

        return $address;
    }

    private function isOwnedAddressId(int $addressId): bool
    {
        return $this->loadOwnedAddress($addressId) instanceof \Address;
    }
}
