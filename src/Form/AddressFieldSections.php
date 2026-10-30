<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShop\Module\PsOnePageCheckout\Form;

/**
 * Trims the inline address form to the fields a buyer needs, on top of the rows AddressFieldRows built.
 *
 * - The alias is left out: a first-time buyer has no basis for naming an address, and both save
 *   paths default an empty alias. It stays in the address modals, which do not use these sections.
 * - The address complement, and the company and VAT number, move behind a disclosure each.
 *
 * Rows keep the configured order: a disclosure only wraps rows that are already next to each other,
 * so company and VAT number share one only when the format puts them side by side. Nothing required,
 * filled or invalid is hidden: such a field stays visible, or its disclosure renders open.
 */
class AddressFieldSections
{
    public const DISCLOSURE_COMPANY = 'company';
    public const DISCLOSURE_COMPLEMENT = 'complement';

    private const KIND_ALIAS = 'alias';
    private const COMPANY_FIELDS = ['company', 'vat_number'];

    /**
     * @param array<int, array<int, array<string, mixed>>> $rows Rows from AddressFieldRows::build()
     * @param string $prefix Field-name prefix for this section ('' or 'invoice_')
     *
     * @return array<int, array{disclosure: string|null, open: bool, rows: array<int, array<int, array<string, mixed>>>}>
     */
    public static function build(array $rows, $prefix = '')
    {
        $sections = [];

        foreach ($rows as $row) {
            $kind = self::kindOf($row, (string) $prefix);
            if ($kind === self::KIND_ALIAS) {
                // Kept only when it needs the buyer: required by a module, filled or invalid.
                if (!self::isRequired($row) && !self::needsAttention($row)) {
                    continue;
                }
                $kind = null;
            }

            $last = count($sections) - 1;
            if ($kind === self::DISCLOSURE_COMPANY && $last >= 0 && $sections[$last]['disclosure'] === $kind) {
                $sections[$last]['rows'][] = $row;
                continue;
            }

            $sections[] = ['disclosure' => $kind, 'open' => true, 'rows' => [$row]];
        }

        foreach ($sections as $index => $section) {
            if ($section['disclosure'] === null) {
                continue;
            }
            $required = false;
            $attention = false;
            foreach ($section['rows'] as $row) {
                $required = $required || self::isRequired($row);
                $attention = $attention || self::needsAttention($row);
            }
            if ($required) {
                $sections[$index]['disclosure'] = null;
            } else {
                $sections[$index]['open'] = $attention;
            }
        }

        return self::mergePlainSections($sections);
    }

    /**
     * @param array<int, array<string, mixed>> $row
     * @param string $prefix
     *
     * @return string|null
     */
    private static function kindOf(array $row, $prefix)
    {
        $bases = array_map(
            static function (array $field) use ($prefix): string {
                return self::baseName((string) ($field['name'] ?? ''), $prefix);
            },
            $row
        );

        if ($bases === ['alias']) {
            return self::KIND_ALIAS;
        }

        if ($bases === ['address2']) {
            return self::DISCLOSURE_COMPLEMENT;
        }

        if (array_diff($bases, self::COMPANY_FIELDS) === []) {
            return self::DISCLOSURE_COMPANY;
        }

        return null;
    }

    /**
     * Consecutive sections without a disclosure render the same way, so they become one.
     *
     * @param array<int, array{disclosure: string|null, open: bool, rows: array<int, array<int, array<string, mixed>>>}> $sections
     *
     * @return array<int, array{disclosure: string|null, open: bool, rows: array<int, array<int, array<string, mixed>>>}>
     */
    private static function mergePlainSections(array $sections)
    {
        $merged = [];
        foreach ($sections as $section) {
            $last = count($merged) - 1;
            if ($section['disclosure'] === null && $last >= 0 && $merged[$last]['disclosure'] === null) {
                $merged[$last]['rows'] = array_merge($merged[$last]['rows'], $section['rows']);
                continue;
            }
            $merged[] = $section;
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $row
     */
    private static function isRequired(array $row): bool
    {
        foreach ($row as $field) {
            if (!empty($field['required'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A field that already carries a value (persisted draft, saved address) or an error.
     *
     * @param array<int, array<string, mixed>> $row
     */
    private static function needsAttention(array $row): bool
    {
        foreach ($row as $field) {
            if ((string) ($field['value'] ?? '') !== '' || !empty($field['errors'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $name
     * @param string $prefix
     *
     * @return string
     */
    private static function baseName($name, $prefix)
    {
        if ($prefix !== '' && strpos($name, $prefix) === 0) {
            return substr($name, strlen($prefix));
        }

        return $name;
    }
}
