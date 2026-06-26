<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class CheckoutCustomerTemplateBuilder
{
    private \Context $context;
    private CheckoutCustomerContextResolver $customerResolver;

    public function __construct(
        \Context $context,
        ?CheckoutCustomerContextResolver $customerResolver = null,
    ) {
        $this->context = $context;
        $this->customerResolver = $customerResolver ?? new CheckoutCustomerContextResolver($context);
    }

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $templateCustomer = $this->resolveTemplateCustomer();
        $customer = $this->customerResolver->resolve();

        if (!$customer instanceof \Customer) {
            return array_merge($templateCustomer, [
                'is_logged' => false,
                'addresses' => [],
            ]);
        }

        $addresses = array_filter(
            (array) $customer->getSimpleAddresses((int) ($this->context->language->id ?? 0)),
            [$this, 'isCustomerVisibleAddress']
        );

        return array_merge($templateCustomer, [
            'id' => (int) $customer->id,
            'firstname' => (string) $customer->firstname,
            'lastname' => (string) $customer->lastname,
            'email' => (string) $customer->email,
            'is_guest' => (bool) $customer->isGuest(),
            'is_logged' => (bool) $customer->isLogged(),
            'addresses' => array_map(
                [$this, 'normalizeAddress'],
                array_values($addresses)
            ),
        ]);
    }

    /**
     * @param array<string,mixed> $address
     */
    private function isCustomerVisibleAddress(array $address): bool
    {
        return strpos((string) ($address['alias'] ?? ''), OpcTempAddress::TEMPORARY_ADDRESS_ALIAS_PREFIX) !== 0;
    }

    /**
     * @param array<string,mixed> $address
     *
     * @return array<string,mixed>
     */
    private function normalizeAddress(array $address): array
    {
        if (!array_key_exists('id', $address) && array_key_exists('id_address', $address)) {
            $address['id'] = $address['id_address'];
        }

        return $address;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveTemplateCustomer(): array
    {
        if (!isset($this->context->smarty)) {
            return [];
        }

        $templateCustomer = $this->context->smarty->getTemplateVars('customer');

        return is_array($templateCustomer) ? $templateCustomer : [];
    }
}
