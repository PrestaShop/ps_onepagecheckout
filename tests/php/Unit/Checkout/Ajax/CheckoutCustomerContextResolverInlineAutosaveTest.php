<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;

/**
 * `usesInlineAddressAutosave` decides the `persistAddressDraft` runtime flag: whether the inline
 * address form is still the buyer's ACTIVE editing surface, so the savedraft autosave must keep
 * judging and persisting their edits.
 *
 * The regression this locks: a GUEST keeps the inline form even once an address is persisted
 * (CheckoutCustomerTemplateBuilder never shows a guest the saved-address list), so a persisted
 * address must NOT disarm their autosave — computing the flag as bare `!hasSavedAddress()` handed
 * a reloading guest a prefilled inline form that no longer validated or saved anything, while the
 * option sections refreshed against a country never persisted onto the cart.
 */
class CheckoutCustomerContextResolverInlineAutosaveTest extends TestCase
{
    public function testAGuestWithASavedAddressKeepsTheInlineAutosave(): void
    {
        $customer = $this->customerWithAddresses(isGuest: true, addresses: [10 => ['id_address' => 10]]);

        self::assertTrue($this->resolverForCustomer($customer)->usesInlineAddressAutosave());
    }

    public function testARegisteredCustomerWithASavedAddressLeavesTheInlineAutosaveOff(): void
    {
        $customer = $this->customerWithAddresses(isGuest: false, addresses: [10 => ['id_address' => 10]]);

        self::assertFalse($this->resolverForCustomer($customer)->usesInlineAddressAutosave());
    }

    public function testARegisteredCustomerWithoutAddressKeepsTheInlineAutosave(): void
    {
        $customer = $this->customerWithAddresses(isGuest: false, addresses: []);

        self::assertTrue($this->resolverForCustomer($customer)->usesInlineAddressAutosave());
    }

    public function testNoResolvableCustomerKeepsTheInlineAutosave(): void
    {
        $context = new \Context();
        $context->language = $this->language();

        self::assertTrue((new CheckoutCustomerContextResolver($context))->usesInlineAddressAutosave());
    }

    /**
     * @param array<int,array<string,mixed>> $addresses
     */
    private function customerWithAddresses(bool $isGuest, array $addresses): \Customer
    {
        $customer = $this->createMock(\Customer::class);
        $customer->id = 7;
        $customer->method('isGuest')->willReturn($isGuest);
        $customer->method('getSimpleAddresses')->willReturn($addresses);

        return $customer;
    }

    private function resolverForCustomer(\Customer $customer): CheckoutCustomerContextResolver
    {
        $context = new \Context();
        $context->customer = $customer;
        $context->language = $this->language();

        return new CheckoutCustomerContextResolver($context);
    }

    private function language(): \Language
    {
        $language = $this->createMock(\Language::class);
        $language->id = 1;

        return $language;
    }
}
