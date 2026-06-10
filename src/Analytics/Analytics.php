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
    private const EVENT_OPC_CHECKOUT_STARTED = '[OPC] Checkout Started';
    private const EVENT_OPC_CHECKOUT_COMPLETED = '[OPC] Checkout Completed';

    /**
     * Segment event name emitted on critical OPC errors (technical / blocking errors, no PII).
     */
    public const EVENT_MODULE_ENABLED = '[OPC] Module Enabled';
    public const EVENT_MODULE_DISABLED = '[OPC] Module Disabled';
    public const EVENT_MODULE_UNINSTALLED = '[OPC] Module Uninstalled';
    public const EVENT_MODULE_UPDATED = '[OPC] Module Updated';
    public const EVENT_MODULE_CONFIGURED = '[OPC] Module Configured';
    public const EVENT_CHECKOUT_LAYOUT_SELECTED = '[OPC] Checkout Layout Selected';
    public const EVENT_CHECKOUT_LAYOUT_PUBLISHED = '[OPC] Checkout Layout Published';
    public const EVENT_OPC_CRITICAL_ERROR = '[OPC] Checkout Error Occurred';

    /**
     * Shared user identifier used by module events to avoid any customer/session identifiers (no PII).
     */
    private const SHARED_USER_ID = 'ps_onepagecheckout';

    private const DEVICE_TYPE_DESKTOP = 'desktop';
    private const DEVICE_TYPE_TABLET = 'tablet';
    private const DEVICE_TYPE_MOBILE = 'mobile';

    /**
     * Segment PHP source write keys env vars — single source of truth (not stored in configuration).
     */
    public const PS_OPC_SEGMENT_PREPROD_KEY = 'PS_OPC_SEGMENT_PREPROD_KEY';
    public const PS_OPC_SEGMENT_PROD_KEY = 'PS_OPC_SEGMENT_PROD_KEY';

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

    public static function trackCheckoutStarted(string $guestCheckoutActive, string $moduleVersion): void
    {
        self::trackEvent(self::EVENT_OPC_CHECKOUT_STARTED, [
            'guest_checkout_active' => $guestCheckoutActive,
        ], $moduleVersion);
    }

    public static function trackCheckoutCompleted(string $guestCheckoutActive, string $paymentMethod, string $moduleVersion): void
    {
        self::trackEvent(self::EVENT_OPC_CHECKOUT_COMPLETED, [
            'guest_checkout_active' => $guestCheckoutActive,
            'payment_method' => $paymentMethod,
        ], $moduleVersion);
    }

    /**
     * Track a critical error encountered in the OPC checkout tunnel.
     *
     * Required event properties (payload-specific):
     * - error_type: string enum (e.g. payment_failure, shipping_unavailable, api_timeout, unknown)
     * - guest_checkout_active: yes|no
     */
    public static function trackOpcCriticalError(string $errorType, string $guestCheckoutActive, string $moduleVersion): void
    {
        self::trackEvent(self::EVENT_OPC_CRITICAL_ERROR, [
            'error_type' => $errorType,
            'guest_checkout_active' => $guestCheckoutActive,
        ], $moduleVersion);
    }

    /**
     * Generic Segment tracking helper. Common props (device_type, prestashop_version,
     * module_version) are auto-enriched from $moduleVersion.
     *
     * Best effort: never throws and must never block checkout.
     *
     * @param array<string, mixed> $properties
     */
    public static function trackEvent(string $eventName, array $properties, string $moduleVersion): void
    {
        self::bootstrap(true);
        if (!self::$clientInitialized) {
            return;
        }

        try {
            Segment::track([
                'userId' => self::SHARED_USER_ID,
                'event' => $eventName,
                'properties' => array_merge($properties, self::buildCommonProps($moduleVersion)),
            ]);
            Segment::flush();
        } catch (\Throwable) {
            // Never block checkout because of analytics.
        }
    }

    /**
     * Common analytics props auto-enriched for all events emitted by this module.
     *
     * @return array<string, string>
     */
    private static function buildCommonProps(string $moduleVersion): array
    {
        return [
            'device_type' => self::detectDeviceType(),
            'prestashop_version' => defined('_PS_VERSION_') ? (string) _PS_VERSION_ : '',
            'module_version' => $moduleVersion,
        ];
    }

    private static function getWriteKey(): string
    {
        $isDevMode = defined('_PS_MODE_DEV_') && (bool) _PS_MODE_DEV_;
        $writeKeyEnvVar = $isDevMode ? self::PS_OPC_SEGMENT_PREPROD_KEY : self::PS_OPC_SEGMENT_PROD_KEY;

        return self::getEnv($writeKeyEnvVar);
    }

    private static function detectDeviceType(): string
    {
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $userAgentLower = strtolower($userAgent);

        if ($userAgentLower === '') {
            return self::DEVICE_TYPE_DESKTOP;
        }

        // Basic heuristics (no dependency on core helpers, safe in CLI/tests).
        if (str_contains($userAgentLower, 'ipad') || str_contains($userAgentLower, 'tablet')) {
            return self::DEVICE_TYPE_TABLET;
        }

        if (str_contains($userAgentLower, 'mobi') || str_contains($userAgentLower, 'android')) {
            return self::DEVICE_TYPE_MOBILE;
        }

        return self::DEVICE_TYPE_DESKTOP;
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
