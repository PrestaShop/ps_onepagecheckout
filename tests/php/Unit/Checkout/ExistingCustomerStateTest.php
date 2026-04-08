<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\ExistingCustomerState;

class ExistingCustomerStateTest extends TestCase
{
    public function testItBuildsAnEmptyState(): void
    {
        $state = ExistingCustomerState::empty();

        self::assertSame(0, $state->getId());
        self::assertNull($state->getCustomer());
        self::assertFalse($state->hasCustomer());
        self::assertFalse($state->isGuestCustomer());
    }

    public function testItBuildsAGuestStateFromCustomer(): void
    {
        $customer = new class extends \Customer {
            public function __construct()
            {
            }

            public function isGuest(): bool
            {
                return (bool) $this->is_guest;
            }
        };
        $customer->id = 42;
        $customer->is_guest = 1;

        $state = ExistingCustomerState::fromCustomer($customer);

        self::assertSame(42, $state->getId());
        self::assertSame($customer, $state->getCustomer());
        self::assertTrue($state->hasCustomer());
        self::assertTrue($state->isGuestCustomer());
    }

    public function testItBuildsARegisteredStateFromCustomer(): void
    {
        $customer = new class extends \Customer {
            public function __construct()
            {
            }

            public function isGuest(): bool
            {
                return (bool) $this->is_guest;
            }
        };
        $customer->id = 99;
        $customer->is_guest = 0;

        $state = ExistingCustomerState::fromCustomer($customer);

        self::assertSame(99, $state->getId());
        self::assertTrue($state->hasCustomer());
        self::assertFalse($state->isGuestCustomer());
    }
}
