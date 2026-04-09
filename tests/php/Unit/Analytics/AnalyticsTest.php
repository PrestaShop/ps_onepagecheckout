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
        $previousAppEnv = getenv(Analytics::APP_ENV_ENV_VAR);
        $previousPreprodKey = getenv(Analytics::SEGMENT_PREPROD_WRITE_KEY_ENV_VAR);
        $previousProdKey = getenv(Analytics::SEGMENT_PROD_WRITE_KEY_ENV_VAR);

        putenv(Analytics::APP_ENV_ENV_VAR . '=preprod');
        putenv(Analytics::SEGMENT_PREPROD_WRITE_KEY_ENV_VAR . '=');
        putenv(Analytics::SEGMENT_PROD_WRITE_KEY_ENV_VAR . '=');

        Analytics::bootstrap(true);

        if ($previousAppEnv === false) {
            putenv(Analytics::APP_ENV_ENV_VAR);
        } else {
            putenv(Analytics::APP_ENV_ENV_VAR . '=' . $previousAppEnv);
        }

        if ($previousPreprodKey === false) {
            putenv(Analytics::SEGMENT_PREPROD_WRITE_KEY_ENV_VAR);
        } else {
            putenv(Analytics::SEGMENT_PREPROD_WRITE_KEY_ENV_VAR . '=' . $previousPreprodKey);
        }

        if ($previousProdKey === false) {
            putenv(Analytics::SEGMENT_PROD_WRITE_KEY_ENV_VAR);
        } else {
            putenv(Analytics::SEGMENT_PROD_WRITE_KEY_ENV_VAR . '=' . $previousProdKey);
        }

        self::assertTrue(true);
    }
}
