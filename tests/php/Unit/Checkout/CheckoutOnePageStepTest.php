<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\CheckoutOnePageStep;

class CheckoutOnePageStepTest extends TestCase
{
    public function testItKeepsStableIdentifierForPersistedCheckoutData(): void
    {
        $reflection = new \ReflectionClass(CheckoutOnePageStep::class);
        /** @var CheckoutOnePageStep $step */
        $step = $reflection->newInstanceWithoutConstructor();

        self::assertSame('checkout-one-page-step', $step->getIdentifier());
    }
}
