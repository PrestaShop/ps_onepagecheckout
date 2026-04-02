<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\CheckoutOnePageStep;

class CheckoutOnePageStepSpe7Test extends TestCase
{
    public function testItReadsSelectedPaymentModuleFromCookie(): void
    {
        $reflection = new \ReflectionClass(CheckoutOnePageStep::class);
        /** @var CheckoutOnePageStep $step */
        $step = $reflection->newInstanceWithoutConstructor();

        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->cookie = new class {
            public function __get(string $name)
            {
                return $name === 'opc_selected_payment_module' ? 'ps_wirepayment' : null;
            }
        };

        $contextProperty = new \ReflectionProperty(\AbstractCheckoutStep::class, 'context');
        $contextProperty->setAccessible(true);
        $contextProperty->setValue($step, $context);

        $method = $reflection->getMethod('getSelectedPaymentModule');
        $method->setAccessible(true);

        self::assertSame('ps_wirepayment', $method->invoke($step));
    }
}
