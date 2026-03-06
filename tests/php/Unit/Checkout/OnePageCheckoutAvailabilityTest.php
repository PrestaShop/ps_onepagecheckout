<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutAvailability;

class OnePageCheckoutAvailabilityTest extends TestCase
{
    public function testItReturnsTrueWhenConfigurationIsEnabled(): void
    {
        $availability = new SpyOnePageCheckoutAvailability('PS_ONE_PAGE_CHECKOUT_ENABLED');
        $availability->configurationValue = true;

        $isEnabled = $availability->isEnabled();

        self::assertTrue($isEnabled);
        self::assertSame(1, $availability->readCalls);
    }

    public function testItReturnsFalseWhenConfigurationIsDisabled(): void
    {
        $availability = new SpyOnePageCheckoutAvailability('PS_ONE_PAGE_CHECKOUT_ENABLED');
        $availability->configurationValue = false;

        $isEnabled = $availability->isEnabled();

        self::assertFalse($isEnabled);
        self::assertSame(1, $availability->readCalls);
    }
}

class SpyOnePageCheckoutAvailability extends OnePageCheckoutAvailability
{
    public bool $configurationValue = false;
    public int $readCalls = 0;

    protected function getConfigurationValue(): bool
    {
        ++$this->readCalls;

        return $this->configurationValue;
    }
}
