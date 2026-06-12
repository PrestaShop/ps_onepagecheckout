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
        $previousPreprodKey = getenv(Analytics::PS_OPC_SEGMENT_PREPROD_KEY);
        $previousProdKey = getenv(Analytics::PS_OPC_SEGMENT_PROD_KEY);

        putenv(Analytics::PS_OPC_SEGMENT_PREPROD_KEY . '=');
        putenv(Analytics::PS_OPC_SEGMENT_PROD_KEY . '=');

        Analytics::bootstrap(true);

        if ($previousPreprodKey === false) {
            putenv(Analytics::PS_OPC_SEGMENT_PREPROD_KEY);
        } else {
            putenv(Analytics::PS_OPC_SEGMENT_PREPROD_KEY . '=' . $previousPreprodKey);
        }

        if ($previousProdKey === false) {
            putenv(Analytics::PS_OPC_SEGMENT_PROD_KEY);
        } else {
            putenv(Analytics::PS_OPC_SEGMENT_PROD_KEY . '=' . $previousProdKey);
        }

        self::assertTrue(true);
    }

    public function testTrackOpcCriticalErrorDoesNothingWhenWriteKeyEnvVarsEmpty(): void
    {
        $previousPreprodKey = getenv(Analytics::PS_OPC_SEGMENT_PREPROD_KEY);
        $previousProdKey = getenv(Analytics::PS_OPC_SEGMENT_PROD_KEY);

        putenv(Analytics::PS_OPC_SEGMENT_PREPROD_KEY . '=');
        putenv(Analytics::PS_OPC_SEGMENT_PROD_KEY . '=');

        Analytics::trackOpcCriticalError('unknown', 'yes', '1.0.0');

        if ($previousPreprodKey === false) {
            putenv(Analytics::PS_OPC_SEGMENT_PREPROD_KEY);
        } else {
            putenv(Analytics::PS_OPC_SEGMENT_PREPROD_KEY . '=' . $previousPreprodKey);
        }

        if ($previousProdKey === false) {
            putenv(Analytics::PS_OPC_SEGMENT_PROD_KEY);
        } else {
            putenv(Analytics::PS_OPC_SEGMENT_PROD_KEY . '=' . $previousProdKey);
        }

        self::assertTrue(true);
    }
}
