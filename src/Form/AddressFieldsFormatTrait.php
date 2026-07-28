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
 * Module-owned shared logic for OPC address format generation.
 *
 * Expects the using class to define: $country, $translator, $availableCountries, $definition.
 */
trait AddressFieldsFormatTrait
{
    /**
     * Build address fields format with optional prefix (for delivery vs invoice).
     *
     * @param string $prefix Prefix for field names (e.g. 'invoice_' for invoice address)
     * @param bool $aliasRequired Whether alias field is required
     *
     * @return array<string, \FormField>
     */
    protected function getAddressFieldsFormat($prefix = '', $aliasRequired = false)
    {
        $fields = \AddressFormat::getOrderedAddressFields(
            $this->country->id,
            true,
            true
        );
        $required = array_flip(\AddressFormat::getFieldsRequired());
        $format = [];

        $format[$prefix . 'alias'] = (new \FormField())
            ->setName($prefix . 'alias')
            ->setLabel($this->getFieldLabel('alias'));
        if ($aliasRequired) {
            $format[$prefix . 'alias']->setRequired(true);
        }

        foreach ($fields as $field) {
            $formField = new \FormField();
            $fieldName = $field;
            $fieldParts = explode(':', $field, 2);

            if (count($fieldParts) === 2) {
                list($entity, $entityField) = $fieldParts;
                $fieldName = 'id_' . strtolower($entity);
            }

            $formField->setName($prefix . $fieldName);

            if (count($fieldParts) === 1) {
                if ($field === 'postcode') {
                    if ($this->country->need_zip_code) {
                        $formField->setRequired(true);
                    }
                    $formField->setMinLength(
                        strlen($this->country->zip_code_format)
                    );
                } elseif ($field === 'phone') {
                    $formField->setType('tel');
                    // WHY: buyers hesitate to hand out a phone number; stating what it is
                    // used for reduces that hesitation (the theme renders this under the
                    // field as a form-text hint, next to the "Optional" marker).
                    $formField->addAvailableValue(
                        'comment',
                        $this->translator->trans('Only used if we need to contact you about the order or its delivery.', [], 'Modules.Onepagecheckout.Shop')
                    );
                } elseif ($field === 'dni') {
                    if ($this->country->need_identification_number) {
                        $formField->setRequired(true);
                    }
                }
            } elseif (count($fieldParts) === 2) {
                list($entity, $entityField) = $fieldParts;
                $formField->setType('select');

                if ($entity === 'Country') {
                    $formField->setType('countrySelect');
                    $formField->setValue($this->country->id);
                    foreach ($this->availableCountries as $country) {
                        $formField->addAvailableValue(
                            $country['id_country'],
                            $country[$entityField]
                        );
                    }
                } elseif ($entity === 'State') {
                    if ($this->country->contains_states) {
                        $states = \State::getStatesByIdCountry($this->country->id, true, 'name', 'asc');
                        foreach ($states as $state) {
                            $formField->addAvailableValue(
                                $state['id_state'],
                                $state[$entityField]
                            );
                        }
                        $formField->setRequired(true);
                    }
                }
            }

            $formField->setLabel($this->getFieldLabel($field));
            if (!$formField->isRequired()) {
                $formField->setRequired(
                    array_key_exists($field, $required)
                );
            }

            $format[$formField->getName()] = $formField;
        }

        return $format;
    }

    /**
     * Get base field name for definition lookup (e.g. strip invoice_ prefix).
     * Override in OnePageCheckoutFormatter to strip invoice_ prefix.
     *
     * @param string $name
     *
     * @return string
     */
    protected function getDefinitionKey($name)
    {
        return $name;
    }

    /**
     * @param array<string, \FormField> $format
     *
     * @return array<string, \FormField>
     */
    protected function addConstraints(array $format)
    {
        foreach ($format as $field) {
            $key = $this->getDefinitionKey($field->getName());
            if (!empty($this->definition[$key]['validate'])) {
                $field->addConstraint($this->definition[$key]['validate']);
            }
        }

        return $format;
    }

    /**
     * @param array<string, \FormField> $format
     *
     * @return array<string, \FormField>
     */
    protected function addMaxLength(array $format)
    {
        foreach ($format as $field) {
            $key = $this->getDefinitionKey($field->getName());
            if (!empty($this->definition[$key]['size'])) {
                $field->setMaxLength($this->definition[$key]['size']);
            }
        }

        return $format;
    }

    /**
     * @param string $field
     *
     * @return string
     */
    protected function getFieldLabel($field)
    {
        $field = explode(':', $field)[0];

        switch ($field) {
            case 'alias':
                return $this->translator->trans('Alias', [], 'Shop.Forms.Labels');
            case 'firstname':
                return $this->translator->trans('First name', [], 'Shop.Forms.Labels');
            case 'lastname':
                return $this->translator->trans('Last name', [], 'Shop.Forms.Labels');
            case 'address1':
                return $this->translator->trans('Address', [], 'Shop.Forms.Labels');
            case 'address2':
                return $this->translator->trans('Address Complement', [], 'Shop.Forms.Labels');
            case 'postcode':
                return $this->translator->trans('Zip/Postal Code', [], 'Shop.Forms.Labels');
            case 'city':
                return $this->translator->trans('City', [], 'Shop.Forms.Labels');
            case 'Country':
                return $this->translator->trans('Country', [], 'Shop.Forms.Labels');
            case 'State':
                return $this->translator->trans('State', [], 'Shop.Forms.Labels');
            case 'phone':
                return $this->translator->trans('Phone', [], 'Shop.Forms.Labels');
            case 'phone_mobile':
                return $this->translator->trans('Mobile phone', [], 'Shop.Forms.Labels');
            case 'company':
                return $this->translator->trans('Company', [], 'Shop.Forms.Labels');
            case 'vat_number':
                return $this->translator->trans('VAT number', [], 'Shop.Forms.Labels');
            case 'dni':
                return $this->translator->trans('Identification number', [], 'Shop.Forms.Labels');
            case 'other':
                return $this->translator->trans('Other', [], 'Shop.Forms.Labels');
            default:
                return $field;
        }
    }
}
