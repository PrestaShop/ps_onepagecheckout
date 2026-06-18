<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\Module\PsOnePageCheckout\Form;

use Country;
use Hook;

/**
 * Trait for shared address form validation (postcode + hook).
 * Used by CustomerAddressForm and OnePageCheckoutForm (legacy classes).
 */
trait AddressFormValidationTrait
{
    /**
     * Validate delivery and optionally invoice postcode.
     *
     * @param \FormField|null $postcode Delivery postcode field
     * @param \Country|null $country Delivery country (used for delivery and as fallback for invoice)
     * @param \FormField|null $invoicePostcode Invoice postcode field (null to skip)
     * @param \FormField|null $invoiceCountryField Invoice country field (null to use $country for invoice)
     *
     * @return bool
     */
    protected function validateAddressPostcode(
        $postcode = null,
        $country = null,
        $invoicePostcode = null,
        $invoiceCountryField = null,
    ) {
        $is_valid = true;

        if ($postcode && $country && $postcode->isRequired()) {
            if (!$country->checkZipCode($postcode->getValue())) {
                $postcode->addError($this->translator->trans(
                    'Invalid postcode - should look like "%zipcode%"',
                    ['%zipcode%' => $country->zip_code_format],
                    'Shop.Forms.Errors'
                ));
                $is_valid = false;
            }
        }

        if ($invoicePostcode && $invoicePostcode->isRequired()) {
            $invoiceValidationCountry = $country;
            if ($invoiceCountryField && $invoiceCountryField->getValue()) {
                $invoiceValidationCountry = new \Country(
                    (int) $invoiceCountryField->getValue(),
                    $this->language->id
                );
            }

            if (
                $invoiceValidationCountry
                && $invoiceValidationCountry->need_zip_code
                && !$invoiceValidationCountry->checkZipCode($invoicePostcode->getValue())
            ) {
                $invoicePostcode->addError($this->translator->trans(
                    'Invalid postcode - should look like "%zipcode%"',
                    ['%zipcode%' => $invoiceValidationCountry->zip_code_format],
                    'Shop.Forms.Errors'
                ));
                $is_valid = false;
            }
        }

        return $is_valid;
    }

    /**
     * Run the address form validation hook.
     *
     * @return bool false if hook returned false, true otherwise
     */
    protected function validateAddressFormHook()
    {
        return \Hook::exec('actionValidateCustomerAddressForm', ['form' => $this]) !== false;
    }
}
