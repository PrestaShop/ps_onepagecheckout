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
 * Event tracking is best-effort and must never break checkout flows.
 */
final class Analytics
{
    /**
     * Shared user identifier used by module events to avoid any customer/session identifiers (no PII).
     */
    private const SHARED_USER_ID = 'ps_onepagecheckout';

    /**
     * Segment PHP source write keys env vars — single source of truth (not stored in configuration).
     */
    public const SEGMENT_PREPROD_KEY = 'SEGMENT_PREPROD_KEY';
    public const SEGMENT_PROD_KEY = 'SEGMENT_PROD_KEY';

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

    /**
     * Generic Segment tracking helper.
     *
     * - Initializes Segment client on-demand (no dependency on PrestaShop hooks).
     * - Best effort: never throws and must never block checkout.
     *
     * @param array<string, mixed> $properties
     */
    public static function trackEvent(string $eventName, array $properties): void
    {
        self::bootstrap(true);
        if (!self::$clientInitialized) {
            return;
        }

        try {
            Segment::track([
                'userId' => self::SHARED_USER_ID,
                'event' => $eventName,
                'properties' => $properties,
            ]);
            Segment::flush();
        } catch (\Throwable) {
            // Never block checkout because of analytics.
        }
    }

    private static function getWriteKey(): string
    {
        $isDevMode = defined('_PS_MODE_DEV_') && (bool) _PS_MODE_DEV_;
        $writeKeyEnvVar = $isDevMode ? self::SEGMENT_PREPROD_KEY : self::SEGMENT_PROD_KEY;

        return self::getEnv($writeKeyEnvVar);
    }

    private static function getEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? '';
        }

        return trim((string) $value);
    }
}
