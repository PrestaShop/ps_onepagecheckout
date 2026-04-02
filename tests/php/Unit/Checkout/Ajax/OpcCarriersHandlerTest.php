<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutCarriersHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcCarriersHandlerTest extends TestCase
{
    public function testItRejectsUnownedPersistedDeliveryAddress(): void
    {
        $context = new \Context();
        $context->cart = new class extends \Cart {
            public bool $saved = false;

            public function __construct()
            {
            }

            public function save($nullValues = false, $autoDate = true)
            {
                $this->saved = true;

                return true;
            }
        };
        $context->cart->id = 42;
        $context->cart->id_address_delivery = 10;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturn('Invalid delivery address.');

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver
            ->method('resolveId')
            ->willReturn(123);

        $handler = new class($context, $translator, null, $resolver) extends OnePageCheckoutCarriersHandler {
            protected function isOwnedCheckoutAddress(int $addressId): bool
            {
                return false;
            }
        };

        $response = $handler->handle([
            'id_address_delivery' => '999',
        ]);

        self::assertFalse($response['success']);
        self::assertSame(['Invalid delivery address.'], $response['errors']['id_address_delivery']);
        self::assertSame(10, (int) $context->cart->id_address_delivery);
        self::assertFalse($context->cart->saved);
    }
}
