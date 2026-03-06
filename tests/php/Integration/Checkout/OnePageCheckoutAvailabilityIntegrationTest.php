<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutAvailability;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OnePageCheckoutAvailabilityIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    private const CONFIG_KEY = 'PS_ONE_PAGE_CHECKOUT_ENABLED';

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables(['configuration']);
        \Configuration::loadConfiguration();
    }

    public function testItReturnsTrueWhenConfigurationFlagIsEnabled(): void
    {
        \Configuration::updateValue(self::CONFIG_KEY, true);
        $availability = new OnePageCheckoutAvailability(self::CONFIG_KEY);

        self::assertTrue($availability->isEnabled());
    }

    public function testItReturnsFalseWhenConfigurationFlagIsDisabled(): void
    {
        \Configuration::updateValue(self::CONFIG_KEY, false);
        $availability = new OnePageCheckoutAvailability(self::CONFIG_KEY);

        self::assertFalse($availability->isEnabled());
    }
}
