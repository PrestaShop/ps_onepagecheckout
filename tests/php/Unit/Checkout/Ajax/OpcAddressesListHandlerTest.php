<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressesListHandler;

class OpcAddressesListHandlerTest extends TestCase
{
    public function testItReturnsSelectedCustomerAddress(): void
    {
        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->language = new class extends \Language {
            public function __construct()
            {
            }
        };
        $context->language->id = 1;

        $customer = $this->createMock(\Customer::class);
        $customer->method('getAddresses')->with(1)->willReturn([
            ['id_address' => 10, 'alias' => 'Home'],
            ['id_address' => 12, 'alias' => 'Office'],
        ]);

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolve')->willReturn($customer);

        $handler = new OnePageCheckoutAddressesListHandler($context, $resolver);
        $response = $handler->handle(['id_address' => 12]);

        self::assertTrue($response['success']);
        self::assertCount(2, $response['addresses']);
        self::assertSame(12, $response['address']['id_address']);
    }
}
