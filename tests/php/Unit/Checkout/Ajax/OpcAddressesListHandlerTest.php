<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressesListHandler;
use Tests\Fixtures\CheckoutTestFixtures;

class OpcAddressesListHandlerTest extends TestCase
{
    public function testItReturnsRenderableAddressListContext(): void
    {
        $context = CheckoutTestFixtures::context([
            'language' => CheckoutTestFixtures::language(1),
            'cart' => CheckoutTestFixtures::cart([
                'id_address_delivery' => 12,
                'id_address_invoice' => 10,
            ]),
        ]);

        $customer = CheckoutTestFixtures::customer([
            ['id_address' => 10, 'alias' => 'Home'],
            ['id_address' => 12, 'alias' => 'Office'],
        ], true);

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolve')->willReturn($customer);

        $handler = new OnePageCheckoutAddressesListHandler($context, $resolver);
        $response = $handler->handle();

        self::assertTrue($response['success']);
        self::assertSame(2, $response['address_count']);
        self::assertSame(12, $response['selected_delivery_address']);
        self::assertSame(10, $response['selected_invoice_address']);
        self::assertCount(2, $response['customer']['addresses']);
    }
}
