<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSelectPaymentHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcSelectPaymentHandlerTest extends TestCase
{
    public function testItPersistsSelectedPaymentOnCookie(): void
    {
        $cookie = new class {
            /** @var array<string,mixed> */
            public array $values = [];

            public function __set(string $name, $value): void
            {
                $this->values[$name] = $value;
            }

            public function __get(string $name)
            {
                return $this->values[$name] ?? null;
            }

            public function write(): void
            {
            }
        };
        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->cookie = $cookie;

        $handler = new OnePageCheckoutSelectPaymentHandler(
            $context,
            $this->createConfiguredMock(TranslatorInterface::class, ['trans' => 'translated'])
        );
        $response = $handler->handle([
            'payment_option' => 'payment-option-1',
            'payment_module' => 'ps_wirepayment',
            'payment_selection_key' => 'ps_wirepayment::selection',
        ]);

        self::assertTrue($response['success']);
        self::assertSame('payment-option-1', $cookie->values['opc_selected_payment_option']);
        self::assertSame('ps_wirepayment', $cookie->values['opc_selected_payment_module']);
        self::assertSame('ps_wirepayment::selection', $cookie->values['opc_selected_payment_selection_key']);
    }
}
