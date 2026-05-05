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
        $deliveryAddressId = (int) ($requestParameters['id_address_delivery'] ?? 0);
        $ownedDeliveryAddress = null;
        $customer = $this->customerResolver->resolve();
        $useSameAddress = array_key_exists('use_same_address', $requestParameters)
            && (string) $requestParameters['use_same_address'] !== '0';

        if ($customer instanceof \Customer) {
            $this->opcForm->fillFromCustomer($customer);
        }

        if ($deliveryAddressId > 0) {
            $ownedDeliveryAddress = $this->loadOwnedAddress($deliveryAddressId);
            if ($ownedDeliveryAddress instanceof \Address) {
                $this->opcForm->fillFromAddress($ownedDeliveryAddress);
            }
        }

        $formParams = [];

        foreach (['id_country', 'invoice_id_country', 'use_same_address', 'id_address_delivery', 'id_address_invoice'] as $name) {
            if (!isset($requestParameters[$name])) {
                continue;
            }

            if (in_array($name, ['id_address_delivery', 'id_address_invoice'], true) && (int) $requestParameters[$name] <= 0) {
                continue;
            }

            if ($name === 'id_address_delivery' && !$ownedDeliveryAddress instanceof \Address) {
                continue;
            }

            if ($name === 'id_address_invoice' && !$this->isOwnedAddressId((int) $requestParameters[$name])) {
                continue;
            }

            $formParams[$name] = $requestParameters[$name];
        }

        if (
            $this->shouldFallbackToDefaultDeliveryCountry($customer, $requestParameters, $deliveryAddressId)
            && !isset($formParams['id_country'])
        ) {
            $formParams['id_country'] = (string) \Configuration::get('PS_COUNTRY_DEFAULT');
        }

        if (!empty($formParams)) {
            $this->opcForm->fillWith($formParams);
        }

        return $this->buildAddressesSectionTemplateVariables();
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function shouldFallbackToDefaultDeliveryCountry(
        ?\Customer $customer,
        array $requestParameters,
        int $deliveryAddressId,
    ): bool {
        if (!$customer instanceof \Customer) {
            return false;
        }

        if ($deliveryAddressId > 0 || isset($requestParameters['id_country'])) {
            return false;
        }

        return count($customer->getSimpleAddresses((int) $this->context->language->id)) === 0;
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

    /**
     * The refreshed addresses partial needs the same top-level checkout variables
     * as the initial step render, not just the form payload.
     *
     * @return array<string,mixed>
     */
    private function buildAddressesSectionTemplateVariables(): array
    {
        return $this->opcForm->getTemplateVariables() + [
            'is_virtual_cart' => $this->context->cart->isVirtualCart(),
            'cart' => [
                'id_address_delivery' => (int) ($this->context->cart->id_address_delivery ?? 0),
                'id_address_invoice' => (int) ($this->context->cart->id_address_invoice ?? 0),
            ],
        ];
    }
}
