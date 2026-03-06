<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutAvailability;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutProcess;

class OnePageCheckoutProcessTest extends TestCase
{
    public function testItReturnsTrueWhenModuleFlagIsEnabled(): void
    {
        $process = $this->createProcessWithoutConstructor();
        $this->setAvailability($process, true);

        self::assertTrue($process->isOnePageCheckoutEnabled());
    }

    public function testItReturnsFalseWhenModuleFlagIsDisabled(): void
    {
        $process = $this->createProcessWithoutConstructor();
        $this->setAvailability($process, false);

        self::assertFalse($process->isOnePageCheckoutEnabled());
    }

    private function createProcessWithoutConstructor(): OnePageCheckoutProcess
    {
        $reflection = new \ReflectionClass(OnePageCheckoutProcess::class);

        /** @var OnePageCheckoutProcess $process */
        $process = $reflection->newInstanceWithoutConstructor();

        return $process;
    }

    private function setAvailability(OnePageCheckoutProcess $process, bool $isEnabled): void
    {
        $availability = new class($isEnabled) extends OnePageCheckoutAvailability {
            /**
             * @var bool
             */
            private $isEnabled;

            public function __construct(bool $isEnabled)
            {
                parent::__construct('PS_ONE_PAGE_CHECKOUT_ENABLED');
                $this->isEnabled = $isEnabled;
            }

            protected function getConfigurationValue(): bool
            {
                return $this->isEnabled;
            }
        };

        $reflection = new \ReflectionClass(OnePageCheckoutProcess::class);
        $property = $reflection->getProperty('opcAvailability');
        $property->setValue($process, $availability);
    }
}
