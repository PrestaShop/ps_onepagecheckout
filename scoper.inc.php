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
 * php-scoper configuration for ps_onepagecheckout.
 *
 * Goal: ship the Segment analytics SDK under a module-specific namespace so it
 * is never a runtime dependency of PrestaShop Core (see require-dev in
 * composer.json) and never collides with another module bundling Segment.
 *
 * Same intent as PrestaShopCorp/ps_accounts and PrestaShopCorp/ps_metrics, which
 * scope their bundled SDKs the same way. Those modules could not scope Segment
 * because they ship Segment ^1.8 (PSR-0); this module ships Segment ^3.0, which
 * is PSR-4 and therefore scopes cleanly.
 *
 * Two trees are scoped:
 *   - vendor/segmentio/analytics-php : the SDK itself (namespace Segment\)
 *   - src/Analytics                  : the only module code that imports Segment\
 *
 * The module's own namespace is excluded from prefixing, so scoping src/Analytics
 * only rewrites `use Segment\Segment;` into the prefixed namespace and leaves the
 * module classes untouched. This lets the committed source keep `use Segment\Segment;`
 * (resolved from require-dev in local dev) while the released bundle resolves the
 * prefixed `use PrestaShop\Module\PsOnePageCheckout\Vendor\Segment\Segment;`.
 */

$scopedTrees = [
    'vendor/segmentio/analytics-php',
    'src/Analytics',
];

$fileExcludes = '/LICENSE|.*\\.md|.*\\.dist|Makefile|composer\\.json|composer\\.lock/';

return [
    'prefix' => 'PrestaShop\\Module\\PsOnePageCheckout\\Vendor',

    // The base output directory is provided on the command line (--output-dir).
    'output-dir' => '',

    'finders' => array_map(static function ($tree) use ($fileExcludes) {
        return Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName($fileExcludes)
            ->exclude(['test', 'tests', 'Test', 'Tests'])
            ->in($tree);
    }, $scopedTrees),

    'exclude-files' => [],

    'patchers' => [],

    // Namespaces left untouched. The module's own namespace must NOT be prefixed:
    // scoping src/Analytics should only rewrite the imported Segment\ namespace.
    'exclude-namespaces' => [
        '~^PrestaShop\\\\Module\\\\PsOnePageCheckout~',
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

    // Leave the global namespace (PrestaShop legacy classes, _PS_VERSION_, etc.) untouched.
    'expose-global-constants' => true,
    'expose-global-classes' => true,
    'expose-global-functions' => true,
    'expose-namespaces' => [],
    'expose-classes' => [],
    'expose-functions' => [],
    'expose-constants' => [],
];
