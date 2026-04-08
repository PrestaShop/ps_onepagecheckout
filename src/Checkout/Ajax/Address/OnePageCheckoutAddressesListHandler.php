<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class OnePageCheckoutAddressesListHandler
{
    private \Context $context;
    private CheckoutCustomerContextResolver $customerResolver;
    private CheckoutCustomerTemplateBuilder $customerTemplateBuilder;

    public function __construct(
        \Context $context,
        CheckoutCustomerContextResolver $customerResolver,
        ?CheckoutCustomerTemplateBuilder $customerTemplateBuilder = null,
    ) {
        $this->context = $context;
        $this->customerResolver = $customerResolver;
        $this->customerTemplateBuilder = $customerTemplateBuilder ?? new CheckoutCustomerTemplateBuilder(
            $context,
            $customerResolver
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function handle(): array
    {
        $customer = $this->customerResolver->resolve();
        if (!$customer instanceof \Customer) {
            return CheckoutAjaxResponse::error('Unable to resolve checkout customer.');
        }

        $customerTemplate = $this->customerTemplateBuilder->build();

        return [
            'success' => true,
            'customer' => $customerTemplate,
            'address_count' => count($customerTemplate['addresses'] ?? []),
            'selected_delivery_address' => (int) ($this->context->cart->id_address_delivery ?? 0),
            'selected_invoice_address' => (int) ($this->context->cart->id_address_invoice ?? 0),
        ];
    }
}
