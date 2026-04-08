<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutPaymentMethodsHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\PaymentSelectionKeyBuilder;
use Tests\Fixtures\CheckoutTestFixtures;

class OpcPaymentMethodsHandlerTest extends TestCase
{
    public function testItReturnsPaymentOptionsAndSelectedModule(): void
    {
        $paymentOption = [
            'module_name' => 'ps_wirepayment',
            'call_to_action_text' => 'Wire payment',
            'action' => '/module/ps_wirepayment/validation',
        ];
        $selectionKey = (new PaymentSelectionKeyBuilder())->buildSelectionKey($paymentOption);

        $context = CheckoutTestFixtures::context([
            'cart' => CheckoutTestFixtures::pricedCart(42.0, ['id' => 1]),
            'country' => CheckoutTestFixtures::country(),
            'shop' => CheckoutTestFixtures::shop(1),
        ]);
        $context->cookie = new class($selectionKey) {
            /** @var array<string,string> */
            private array $values;

            public function __construct(string $selectionKey)
            {
                $this->values = [
                    'opc_selected_payment_module' => 'ps_wirepayment',
                    'opc_selected_payment_selection_key' => $selectionKey,
                ];
            }

            public function __get(string $name)
            {
                return $this->values[$name] ?? null;
            }

            public function __unset(string $name): void
            {
                unset($this->values[$name]);
            }

            public function write(): void
            {
            }
        };

        $finder = $this->getMockBuilder(\PaymentOptionsFinder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['present'])
            ->getMock();
        $finder->expects($this->once())->method('present')->with(false)->willReturn([
            'ps_wirepayment' => [
                0 => $paymentOption,
            ],
        ]);

        $handler = new OnePageCheckoutPaymentMethodsHandler($context, $finder);
        $response = $handler->handle();

        self::assertTrue($response['success']);
        self::assertSame('ps_wirepayment', $response['selected_payment_module']);
        self::assertSame($selectionKey, $response['selected_payment_selection_key']);
        self::assertSame('ps_wirepayment', $response['payment_options']['ps_wirepayment'][0]['module_name']);
        self::assertNotSame('', $response['payment_options']['ps_wirepayment'][0]['selection_key']);
        self::assertFalse($response['is_free']);
    }

    public function testItReturnsStructuredErrorWhenCartIsMissing(): void
    {
        $context = CheckoutTestFixtures::context([
            'cart' => null,
            'country' => CheckoutTestFixtures::country(),
        ]);

        $handler = new OnePageCheckoutPaymentMethodsHandler($context);
        $response = $handler->handle();

        self::assertFalse($response['success']);
        self::assertArrayHasKey('errors', $response);
    }
}
