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
        if (\Validate::isLoadedObject($this->context->customer) && (int) $this->context->customer->id > 0) {
            return $this->context->customer;
        }

        return $this->resolvePersistedCartOwner();
    }

    public function resolveId(): int
    {
        $customer = $this->resolve();

        return $customer instanceof \Customer ? (int) $customer->id : 0;
    }

    /**
     * Whether the resolved checkout customer already owns at least one saved address.
     * Used to decide if the typed-address draft still helps (guests and brand-new accounts
     * have none) or if the saved-address flow has taken over.
     */
    public function hasSavedAddress(): bool
    {
        $customer = $this->resolve();
        if (!$customer instanceof \Customer || (int) $customer->id <= 0) {
            return false;
        }

        $addresses = $customer->getSimpleAddresses((int) ($this->context->language->id ?? 0));

        return is_array($addresses) && $addresses !== [];
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
