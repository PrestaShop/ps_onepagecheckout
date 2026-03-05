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

namespace PrestaShop\Module\PsOnepagecheckout\Form;

use Address;
use Configuration;
use Country;
use Customer;
use FormField;
use FormFormatterInterface;
use Hook;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutFormatter implements FormFormatterInterface
{
    use AddressFieldsFormatTrait;

    protected $country;
    protected $translator;
    protected $availableCountries;
    protected $definition;

    /**
     * Separate country for the billing (invoice) address section.
     * Null means billing uses the same country as delivery.
     *
     * @var Country|null
     */
    protected $invoiceCountry = null;

    public function __construct(
        Country $country,
        TranslatorInterface $translator,
        array $availableCountries
    ) {
        $this->country = $country;
        $this->translator = $translator;
        $this->availableCountries = $availableCountries;
        $this->definition = Address::$definition['fields'];
    }

    public function setCountry(Country $country)
    {
        $this->country = $country;

        return $this;
    }

    public function getCountry()
    {
        return $this->country;
    }

    /**
     * Set the billing country for invoice field structure generation.
     *
     * @param Country $country
     *
     * @return self
     */
    public function setInvoiceCountry(Country $country)
    {
        $this->invoiceCountry = $country;

        return $this;
    }

    public function getFormat()
    {
        $format = [];

        // Identity section: email only
        $format['email'] = (new FormField())
            ->setName('email')
            ->setType('email')
            ->setLabel(
                $this->translator->trans(
                    'Email',
                    [],
                    'Shop.Forms.Labels'
                )
            )
            ->setRequired(true);

        // Opt-in for partner offers (if enabled)
        if (Configuration::get('PS_CUSTOMER_OPTIN')) {
            $customer = new Customer();
            $format['optin'] = (new FormField())
                ->setName('optin')
                ->setType('checkbox')
                ->setLabel(
                    $this->translator->trans(
                        'Receive offers from our partners',
                        [],
                        'Shop.Theme.Customeraccount'
                    )
                )
                ->setRequired($customer->isFieldRequired('optin'));
        }

        $additionalCustomerFormFields = Hook::exec('additionalCustomerFormFields', ['fields' => &$format], null, true);
        if (is_array($additionalCustomerFormFields)) {
            foreach ($additionalCustomerFormFields as $moduleName => $additionalFormFields) {
                if (!is_array($additionalFormFields)) {
                    continue;
                }

                foreach ($additionalFormFields as $formField) {
                    if (!$formField instanceof FormField) {
                        continue;
                    }

                    $formField->moduleName = $moduleName;
                    $format[$moduleName . '_' . $formField->getName()] = $formField;
                }
            }
        }

        // Use same address for delivery and invoice
        $format['use_same_address'] = (new FormField())
            ->setName('use_same_address')
            ->setType('checkbox')
            ->setLabel(
                $this->translator->trans(
                    'Use the same address for invoice',
                    [],
                    'Shop.Theme.Checkout'
                )
            )
            ->setValue(true);

        // Hidden field to preserve invoice address ID when editing
        $format['id_address_invoice'] = (new FormField())
            ->setName('id_address_invoice')
            ->setType('hidden');

        // Delivery address fields
        $format = array_merge($format, $this->getAddressFieldsFormat('', true));

        // Invoice address fields (prefixed with invoice_)
        if ($this->invoiceCountry !== null) {
            $deliveryCountry = $this->getCountry();
            $this->setCountry($this->invoiceCountry);
            $format = array_merge($format, $this->getAddressFieldsFormat('invoice_', true));
            $this->setCountry($deliveryCountry);
        } else {
            $format = array_merge($format, $this->getAddressFieldsFormat('invoice_', true));
        }

        // Add constraints and max length
        $format = $this->addConstraints(
            $this->addMaxLength($format)
        );

        $additionalAddressFormFields = Hook::exec('additionalCustomerAddressFields', ['fields' => &$format], null, true);
        if (is_array($additionalAddressFormFields)) {
            foreach ($additionalAddressFormFields as $moduleName => $additionalFormFields) {
                if (!is_array($additionalFormFields)) {
                    continue;
                }

                foreach ($additionalFormFields as $formField) {
                    if (!$formField instanceof FormField) {
                        continue;
                    }

                    $formField->moduleName = $moduleName;
                    $format[$moduleName . '_' . $formField->getName()] = $formField;
                }
            }
        }

        return $format;
    }

    /**
     * Get base field name for definition lookup (strip invoice_ prefix)
     *
     * @param string $name
     *
     * @return string
     */
    protected function getDefinitionKey($name)
    {
        return strpos($name, 'invoice_') === 0 ? substr($name, 8) : $name;
    }
}
