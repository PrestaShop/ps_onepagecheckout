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
 * Splits one address section (delivery or invoice) into the rows the checkout renders.
 *
 * The section arrives in the order the formatter emitted, which is the address format configured
 * for the selected country in International > Locations > Countries. That order is authoritative;
 * this class only decides which neighbours share a row.
 *
 * Some fields read better side by side (first/last name, city/state/postcode). They share a row
 * ONLY when the configured format puts them next to each other, so a merchant who moves a field
 * into the middle of one of those groups still gets the layout they asked for.
 */
class AddressFieldRows
{
    /**
     * Fields that may share a row, as sets. Adjacency in the configured format decides whether a
     * set actually becomes a row; the order within the row follows the format, not this list.
     *
     * The sizes (2 and 3) match the `opc-form-fields-row--2` / `--3` CSS modifiers.
     */
    private const ROW_GROUPS = [
        ['firstname', 'lastname'],
        ['city', 'postcode', 'id_state'],
    ];

    /**
     * @param array<string, array<string, mixed>> $fields Section fields keyed as the formatter
     *                                                    emitted them, in configured order
     * @param string $prefix Field-key prefix for this section ('' or 'invoice_')
     *
     * @return array<int, array<int, array<string, mixed>>> Rows of one or more fields
     */
    public static function build(array $fields, $prefix = '')
    {
        // Keyed off the ARRAY KEY, not the field name: the formatter keys a module-added field
        // `<module>_<name>` while the field keeps its raw name, so two modules adding a field with
        // the same name are only distinguishable by key.
        $bases = [];
        foreach (array_keys($fields) as $key) {
            $bases[] = self::baseName((string) $key, (string) $prefix);
        }

        $values = array_values($fields);
        $total = count($values);
        $rows = [];
        $cursor = 0;

        while ($cursor < $total) {
            $length = self::rowLengthAt($bases, $cursor, $total);
            $rows[] = array_slice($values, $cursor, $length);
            $cursor += $length;
        }

        return $rows;
    }

    /**
     * Longest run of adjacent fields starting at $cursor that all belong to the same row group,
     * each group member counting once. 1 when the field shares a row with nobody.
     *
     * @param array<int, string> $bases
     * @param int $cursor
     * @param int $total
     *
     * @return int
     */
    private static function rowLengthAt(array $bases, $cursor, $total)
    {
        $group = self::rowGroupFor($bases[$cursor]);
        if ($group === []) {
            return 1;
        }

        $taken = [$bases[$cursor]];
        $length = 1;

        while (
            ($cursor + $length) < $total
            && in_array($bases[$cursor + $length], $group, true)
            && !in_array($bases[$cursor + $length], $taken, true)
        ) {
            $taken[] = $bases[$cursor + $length];
            ++$length;
        }

        return $length;
    }

    /**
     * @param string $base
     *
     * @return array<int, string>
     */
    private static function rowGroupFor($base)
    {
        foreach (self::ROW_GROUPS as $group) {
            if (in_array($base, $group, true)) {
                return $group;
            }
        }

        return [];
    }

    /**
     * @param string $key
     * @param string $prefix
     *
     * @return string
     */
    private static function baseName($key, $prefix)
    {
        if ($prefix !== '' && strpos($key, $prefix) === 0) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }
}
