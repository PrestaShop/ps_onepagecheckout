<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\PaymentSelectionKeyBuilder;

class PaymentSelectionKeyBuilderTest extends TestCase
{
    public function testItIgnoresRenderOnlyOptionIdWhenBuildingSelectionKey(): void
    {
        $builder = new PaymentSelectionKeyBuilder();

        $baseOption = [
            'id' => 'payment-option-1',
            'module_name' => 'ps_wirepayment',
            'action' => '/module/ps_wirepayment/validation',
            'call_to_action_text' => 'Wire payment',
            'inputs' => [
                ['name' => 'token', 'type' => 'hidden'],
            ],
        ];

        $firstKey = $builder->buildSelectionKey($baseOption);
        $secondKey = $builder->buildSelectionKey(array_merge($baseOption, ['id' => 'payment-option-999']));

        self::assertSame($firstKey, $secondKey);
    }

    public function testItChangesSelectionKeyWhenBusinessSignatureChanges(): void
    {
        $builder = new PaymentSelectionKeyBuilder();

        $firstKey = $builder->buildSelectionKey([
            'module_name' => 'ps_wirepayment',
            'action' => '/module/ps_wirepayment/validation',
            'call_to_action_text' => 'Wire payment',
            'inputs' => [
                ['name' => 'token', 'type' => 'hidden'],
            ],
        ]);

        $secondKey = $builder->buildSelectionKey([
            'module_name' => 'ps_wirepayment',
            'action' => '/module/ps_wirepayment/alternate',
            'call_to_action_text' => 'Wire payment',
            'inputs' => [
                ['name' => 'token', 'type' => 'hidden'],
            ],
        ]);

        self::assertNotSame($firstKey, $secondKey);
    }
}
