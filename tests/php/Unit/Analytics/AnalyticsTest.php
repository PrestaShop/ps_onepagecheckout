<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Analytics\Analytics;

class AnalyticsTest extends TestCase
{
    public function testBootstrapDoesNothingWhenModuleSegmentDisabled(): void
    {
        Analytics::bootstrap(false);

        self::assertTrue(true);
    }

    public function testBootstrapDoesNothingWhenWriteKeyConstantEmpty(): void
    {
        if (trim((string) Analytics::SEGMENT_CLIENT_KEY_PHP) !== '') {
            self::markTestSkipped('SEGMENT_CLIENT_KEY_PHP is set; empty-key path not exercised.');
        }

        Analytics::bootstrap(true);

        self::assertTrue(true);
    }
}
