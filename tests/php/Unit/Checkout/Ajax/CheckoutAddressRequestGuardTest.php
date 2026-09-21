<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutAddressRequestGuard;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OpcTempAddress;

class CheckoutAddressRequestGuardTest extends TestCase
{
    public function testIsOwnedCheckoutAddressRejectsNonPositiveAddressIdBeforeResolvingCustomer(): void
    {
        // resolveId would return a valid customer, yet a non-positive address id short-circuits first.
        $resolver = $this->createConfiguredMock(CheckoutCustomerContextResolver::class, ['resolveId' => 42]);
        $resolver->expects(self::never())->method('resolveId');

        $guard = new CheckoutAddressRequestGuard($this->fakeContext(), $resolver);

        self::assertFalse($guard->isOwnedCheckoutAddress(0));
        self::assertFalse($guard->isOwnedCheckoutAddress(-5));
    }

    public function testIsOwnedCheckoutAddressRejectsWhenNoCustomerIsResolved(): void
    {
        $resolver = $this->createConfiguredMock(CheckoutCustomerContextResolver::class, ['resolveId' => 0]);

        $guard = new CheckoutAddressRequestGuard($this->fakeContext(), $resolver);

        self::assertFalse($guard->isOwnedCheckoutAddress(10));
    }

    public function testApplyTemporaryInlineInvoiceAddressSkipsWhenBillingMirrorsDelivery(): void
    {
        $tempAddress = $this->createMock(OpcTempAddress::class);
        $tempAddress->expects(self::never())->method('createFromRequest');

        $this->guard()->applyTemporaryInlineInvoiceAddress($tempAddress, [
            'use_same_address' => '1',
            'invoice_id_country' => 8,
        ]);
    }

    public function testApplyTemporaryInlineInvoiceAddressSkipsWhenAPersistedInvoiceAddressExists(): void
    {
        $tempAddress = $this->createMock(OpcTempAddress::class);
        $tempAddress->expects(self::never())->method('createFromRequest');

        $this->guard()->applyTemporaryInlineInvoiceAddress($tempAddress, [
            'use_same_address' => '0',
            'id_address_invoice' => 99,
            'invoice_id_country' => 8,
        ]);
    }

    public function testApplyTemporaryInlineInvoiceAddressSkipsWithoutABillingCountry(): void
    {
        $tempAddress = $this->createMock(OpcTempAddress::class);
        $tempAddress->expects(self::never())->method('createFromRequest');

        $this->guard()->applyTemporaryInlineInvoiceAddress($tempAddress, [
            'use_same_address' => '0',
            'invoice_id_country' => 0,
        ]);
    }

    public function testApplyTemporaryInlineInvoiceAddressMountsTheInlineBillingCountry(): void
    {
        $tempAddress = $this->createMock(OpcTempAddress::class);
        $tempAddress->expects(self::once())
            ->method('createFromRequest')
            ->with(
                [
                    'use_same_address' => '0',
                    'invoice_id_country' => 8,
                    'invoice_id_state' => 3,
                    'invoice_postcode' => '75001',
                    'invoice_city' => 'Paris',
                ],
                true
            );

        $this->guard()->applyTemporaryInlineInvoiceAddress($tempAddress, [
            'use_same_address' => '0',
            'invoice_id_country' => 8,
            'invoice_id_state' => 3,
            'invoice_postcode' => '75001',
            'invoice_city' => 'Paris',
        ]);
    }

    private function guard(): CheckoutAddressRequestGuard
    {
        return new CheckoutAddressRequestGuard(
            $this->fakeContext(),
            $this->createMock(CheckoutCustomerContextResolver::class)
        );
    }

    private function fakeContext(): \Context
    {
        return new class extends \Context {
            public function __construct()
            {
            }
        };
    }
}
