<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSelectPaymentHandler;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcSelectPaymentHandlerIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables([
            'configuration',
        ]);
        \Configuration::loadConfiguration();
    }

    public function testItPersistsSelectedPaymentValuesOnCookie(): void
    {
        $context = self::getContext();
        $handler = new OnePageCheckoutSelectPaymentHandler($context);

        $response = $handler->handle([
            'payment_option' => 'payment-option-1',
            'payment_module' => 'ps_wirepayment',
            'payment_selection_key' => 'ps_wirepayment::selection',
        ]);

        self::assertTrue($response['success']);
        self::assertSame('payment-option-1', (string) $context->cookie->__get('opc_selected_payment_option'));
        self::assertSame('ps_wirepayment', (string) $context->cookie->__get('opc_selected_payment_module'));
        self::assertSame('ps_wirepayment::selection', (string) $context->cookie->__get('opc_selected_payment_selection_key'));
    }
}
