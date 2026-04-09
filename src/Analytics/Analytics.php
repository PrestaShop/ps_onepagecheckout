<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email to
 * license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace PrestaShop\Module\PsOnePageCheckout\Analytics;

use Segment\Segment;

/**
 * Segment PHP SDK bootstrap, same approach as
 * {@link https://github.com/PrestaShop/autoupgrade/blob/dev/classes/Analytics.php PrestaShop\Module\AutoUpgrade\Analytics}.
 *
 * Event tracking (track) will be added in follow-up work.
 */
final class Analytics
{
    /**
     * Segment PHP source write key env var — single source of truth (not stored in configuration).
     */
    public const SEGMENT_WRITE_KEY_ENV_VAR = 'PS_OPC_SEGMENT_WRITE_KEY';

    private static bool $clientInitialized = false;

    public static function bootstrap(bool $moduleSegmentEnabled): void
    {
        if (!$moduleSegmentEnabled || self::$clientInitialized) {
            return;
        }

        $writeKey = self::getWriteKey();
        if ($writeKey === '') {
            return;
        }

        Segment::init($writeKey);
        self::$clientInitialized = true;
    }

    private static function getWriteKey(): string
    {
        $value = getenv(self::SEGMENT_WRITE_KEY_ENV_VAR);
        if ($value === false) {
            $value = $_ENV[self::SEGMENT_WRITE_KEY_ENV_VAR] ?? '';
        }

        return trim((string) $value);
    }
}
