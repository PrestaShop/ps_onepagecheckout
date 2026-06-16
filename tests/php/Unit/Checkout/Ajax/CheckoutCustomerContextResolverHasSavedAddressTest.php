<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;

class CheckoutCustomerContextResolverHasSavedAddressTest extends TestCase
{
    public function testItReturnsTrueWhenTheResolvedCustomerOwnsAnAddress(): void
    {
        $customer = $this->createMock(\Customer::class);
        $customer->id = 7;
        $customer->method('getSimpleAddresses')->willReturn([10 => ['id_address' => 10]]);

        self::assertTrue($this->resolverForCustomer($customer)->hasSavedAddress());
    }

    public function testItReturnsFalseWhenTheResolvedCustomerHasNoAddress(): void
    {
        $customer = $this->createMock(\Customer::class);
        $customer->id = 7;
        $customer->method('getSimpleAddresses')->willReturn([]);

        self::assertFalse($this->resolverForCustomer($customer)->hasSavedAddress());
    }

    public function testItReturnsFalseWhenThereIsNoResolvableCustomer(): void
    {
        $context = new \Context();
        $context->language = $this->language();

        self::assertFalse((new CheckoutCustomerContextResolver($context))->hasSavedAddress());
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
