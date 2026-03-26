<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class OnePageCheckoutAddressesListHandler
{
    private \Context $context;
    private CheckoutCustomerContextResolver $customerResolver;

    public function __construct(\Context $context, CheckoutCustomerContextResolver $customerResolver)
    {
        $this->context = $context;
        $this->customerResolver = $customerResolver;
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        $customer = $this->customerResolver->resolve();
        if (!$customer instanceof \Customer) {
            return CheckoutAjaxResponse::error('Unable to resolve checkout customer.');
        }

        $addresses = $customer->getAddresses((int) $this->context->language->id);
        $selectedAddressId = (int) ($requestParameters['id_address'] ?? 0);
        $selectedAddress = null;

        foreach ($addresses as $address) {
            if ((int) ($address['id_address'] ?? 0) === $selectedAddressId) {
                $selectedAddress = $address;
                break;
            }
        }

        return [
            'success' => true,
            'addresses' => $addresses,
            'address' => $selectedAddress,
        ];
    }
}
