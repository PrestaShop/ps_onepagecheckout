<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class CheckoutCustomerContextResolver
{
    private \Context $context;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    public function resolve(): ?\Customer
    {
        $persistedOwner = $this->resolvePersistedCartOwner();
        if ($persistedOwner instanceof \Customer) {
            return $persistedOwner;
        }

        if (\Validate::isLoadedObject($this->context->customer) && (int) $this->context->customer->id > 0) {
            return $this->context->customer;
        }

        return null;
    }

    public function resolveId(): int
    {
        $customer = $this->resolve();

        return $customer instanceof \Customer ? (int) $customer->id : 0;
    }

    private function resolvePersistedCartOwner(): ?\Customer
    {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return null;
        }

        $cartId = (int) $this->context->cart->id;
        if ($cartId <= 0) {
            return null;
        }

        $freshCart = new \Cart($cartId);
        if (!\Validate::isLoadedObject($freshCart) || (int) $freshCart->id_customer <= 0) {
            return null;
        }

        $customer = new \Customer((int) $freshCart->id_customer);

        return \Validate::isLoadedObject($customer) ? $customer : null;
    }
}
