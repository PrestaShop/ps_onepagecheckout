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

use Isolated\Symfony\Component\Finder\Finder;

/*
 * php-scoper configuration used ONLY to (re)generate the committed, namespace-prefixed
 * Segment SDK under src/Vendor/Segment.
 *
 * This is a manual maintenance step, run via `make scope-segment` when bumping
 * segmentio/analytics-php. It is intentionally NOT part of the release build: the
 * scoped SDK is committed to the repository so every install path (release zip,
 * Packagist/Composer source, Core bundling) ships runnable, conflict-free code.
 *
 * The SDK is prefixed with PrestaShop\Module\PsOnePageCheckout\Vendor so two modules
 * bundling different Segment versions cannot collide on the global Segment\ namespace.
 */

return [
    'prefix' => 'PrestaShop\\Module\\PsOnePageCheckout\\Vendor',

    'output-dir' => '',

    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->in('vendor/segmentio/analytics-php/lib'),
    ],

    'exclude-files' => [],
    'patchers' => [],

    'exclude-namespaces' => [
        '~^Composer~',
        // Polyfills can't be scoped by essence.
        'Symfony\\Polyfill\\Apcu\\',
        'Symfony\\Polyfill\\Ctype\\',
        'Symfony\\Polyfill\\IntlIdn\\',
        'Symfony\\Polyfill\\IntlNormalizer\\',
        'Symfony\\Polyfill\\Mbstring\\',
        'Symfony\\Polyfill\\Php70\\',
        'Symfony\\Polyfill\\Php72\\',
    ],
    'exclude-classes' => [],
    'exclude-functions' => [],
    'exclude-constants' => [],

    // Leave the global namespace (e.g. the $SEGMENT_VERSION global) untouched.
    'expose-global-constants' => true,
    'expose-global-classes' => true,
    'expose-global-functions' => true,
    'expose-namespaces' => [],
    'expose-classes' => [],
    'expose-functions' => [],
    'expose-constants' => [],
];
