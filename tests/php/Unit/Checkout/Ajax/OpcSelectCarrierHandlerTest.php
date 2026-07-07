<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutAddressRequestGuard;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSelectCarrierHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressCarrierSelectionStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcSelectCarrierHandlerTest extends TestCase
{
    public function testItErrorsWhenNoDeliveryOptionIsSent(): void
    {
        $response = $this->handler($this->context($this->cart(5, 10)))->handle([]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('delivery_option', $response['errors']);
    }

    public function testItErrorsWhenTheCartIsNotLoaded(): void
    {
        $response = $this->handler($this->context($this->cart(0, 0)))->handle([
            'delivery_option' => '2,',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
    }

    public function testItRejectsADeliveryAddressTheCustomerDoesNotOwn(): void
    {
        $guard = $this->createMock(CheckoutAddressRequestGuard::class);
        $guard->method('isOwnedCheckoutAddress')->with(77)->willReturn(false);

        $response = $this->handler($this->context($this->cart(5, 10)), $guard)->handle([
            'delivery_option' => '2,',
            'id_address_delivery' => 77,
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('id_address_delivery', $response['errors']);
    }

    private function handler(
        \Context $context,
        ?CheckoutAddressRequestGuard $guard = null,
    ): OnePageCheckoutSelectCarrierHandler {
        return new OnePageCheckoutSelectCarrierHandler(
            $context,
            $this->createConfiguredMock(TranslatorInterface::class, ['trans' => 'translated message']),
            null,
            $this->createMock(CheckoutSessionFactory::class),
            $this->createMock(CartPresenterHelper::class),
            $this->createMock(TempAddressCarrierSelectionStorage::class),
            $this->createMock(TempAddressStorage::class),
            $guard ?? $this->createMock(CheckoutAddressRequestGuard::class)
        );
    }

    private function context(\Cart $cart): \Context
    {
        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->cart = $cart;

        return $context;
    }

    private function cart(int $id, int $idAddressDelivery): \Cart
    {
        $cart = new class extends \Cart {
            public function __construct()
            {
            }
        };
        $cart->id = $id;
        $cart->id_address_delivery = $idAddressDelivery;

        return $cart;
    }
}
