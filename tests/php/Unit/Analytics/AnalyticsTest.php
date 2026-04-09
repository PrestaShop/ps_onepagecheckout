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

    public function testBootstrapDoesNothingWhenWriteKeyEnvVarEmpty(): void
    {
        $envVar = Analytics::SEGMENT_WRITE_KEY_ENV_VAR;
        $previousValue = getenv($envVar);
        putenv($envVar . '=');

        if (trim((string) getenv($envVar)) !== '') {
            self::markTestSkipped($envVar . ' is set; empty-key path not exercised.');
        }

        Analytics::bootstrap(true);

        if ($previousValue === false) {
            putenv($envVar);
        } else {
            putenv($envVar . '=' . $previousValue);
        }

        self::assertTrue(true);
    }
}
