<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutDeleteAddressHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcDeleteAddressHandlerTest extends TestCase
{
    public function testItErrorsWhenTheCheckoutCustomerCannotBeResolved(): void
    {
        $resolver = $this->createConfiguredMock(CheckoutCustomerContextResolver::class, ['resolve' => null]);

        $response = $this->handler($resolver)->handle(['id_address' => 5]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
    }

    public function testItErrorsWhenNoAddressIdIsRequested(): void
    {
        // A resolved customer but a non-positive id short-circuits in loadOwnedAddress
        // before any Address is loaded from the database.
        $customer = new class extends \Customer {
            public function __construct()
            {
            }
        };
        $customer->id = 7;
        $resolver = $this->createConfiguredMock(CheckoutCustomerContextResolver::class, ['resolve' => $customer]);

        $response = $this->handler($resolver)->handle(['id_address' => 0]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
    }

    private function handler(CheckoutCustomerContextResolver $resolver): OnePageCheckoutDeleteAddressHandler
    {
        $context = new class extends \Context {
            public function __construct()
            {
            }
        };

        return new OnePageCheckoutDeleteAddressHandler(
            $context,
            $this->createConfiguredMock(TranslatorInterface::class, ['trans' => 'translated message']),
            $resolver,
            $this->createMock(CheckoutSessionFactory::class)
        );
    }
}
