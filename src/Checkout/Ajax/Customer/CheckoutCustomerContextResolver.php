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

        return !empty($customer->getSimpleAddresses((int) ($this->context->language->id ?? 0)));
    }

    /**
     * Whether the inline address form is still the resolved customer's ACTIVE editing surface,
     * so the typed-address autosave (savedraft) must keep judging and persisting their edits.
     * A guest always edits inline — CheckoutCustomerTemplateBuilder never shows them the
     * saved-address list — so a persisted address must not disarm their autosave: a reload
     * would hand them a prefilled form that no longer validates or saves anything. Registered
     * customers switch to the saved-address list on their next page load, where edits go
     * through the address modal instead.
     */
    public function usesInlineAddressAutosave(): bool
    {
        $customer = $this->resolve();
        if ($customer instanceof \Customer && (int) $customer->id > 0 && $customer->isGuest()) {
            return true;
        }

        return !$this->hasSavedAddress();
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
