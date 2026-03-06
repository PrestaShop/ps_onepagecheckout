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

use Address;
use Cart;
use Context;
use Country;
use Customer;
use PrestaShop\PrestaShop\Core\Util\InternationalizedDomainNameConverter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Validate;

class OnePageCheckoutForm extends \AbstractForm
{
    use AddressFormValidationTrait;

    protected $template = 'checkout/_partials/one-page-checkout-form.tpl';
    private const GUEST_PLACEHOLDER_FIRSTNAME = 'Guest';
    private const GUEST_PLACEHOLDER_LASTNAME = 'Guest';

    /**
     * @var OnePageCheckoutFormatter
     */
    protected $formatter;

    private $context;
    private $customerPersister;
    private $addressPersister;
    private $IDNConverter;
    private $language;

    /**
     * @var \Address
     */
    private $address;

    public function __construct(
        \Smarty $smarty,
        \Context $context,
        \Language $language,
        TranslatorInterface $translator,
        OnePageCheckoutFormatter $formatter,
        \CustomerPersister $customerPersister,
        \CustomerAddressPersister $addressPersister,
    ) {
        parent::__construct(
            $smarty,
            $translator,
            $formatter
        );

        $this->context = $context;
        $this->language = $language;
        $this->formatter = $formatter;
        $this->customerPersister = $customerPersister;
        $this->addressPersister = $addressPersister;
        $this->IDNConverter = new InternationalizedDomainNameConverter();
    }

    public function fillWith(array $params = [])
    {
        // Keep email readable in checkout even when it was stored in punycode.
        if (!empty($params['email'])) {
            $params['email'] = $this->IDNConverter->emailToUtf8($params['email']);
        }

        // Rebuild delivery field rules from selected country (state/postcode requirements).
        if (isset($params['id_country'])) {
            $country = (int) $params['id_country'] !== (int) $this->formatter->getCountry()->id
                ? new \Country($params['id_country'], $this->language->id)
                : $this->formatter->getCountry()
            ;
        } elseif ($this->address) {
            $country = $this->formatter->getCountry();
        } elseif (
            \Tools::isCountryFromBrowserAvailable()
            && \Country::getByIso($countryIsoCode = \Tools::getCountryIsoCodeFromHeader(), true)
        ) {
            $country = new \Country((int) \Country::getByIso($countryIsoCode, true), \Language::getIdByIso($countryIsoCode));
        } else {
            $country = new \Country((int) \Configuration::get('PS_COUNTRY_DEFAULT'), $this->language->id);
        }

        $this->formatter->setCountry($country);

        // Apply billing country too so invoice field requirements stay coherent.
        if (isset($params['invoice_id_country']) && (int) $params['invoice_id_country'] > 0) {
            $invoiceCountry = new \Country((int) $params['invoice_id_country'], $this->language->id);
            $this->formatter->setInvoiceCountry($invoiceCountry);
        }

        return parent::fillWith($params);
    }

    public function fillFromCustomer(\Customer $customer)
    {
        $params = get_object_vars($customer);

        // Do not prefill checkout names with internal guest placeholders ("Guest").
        if (
            $customer->isGuest()
            && ($params['firstname'] ?? '') === self::GUEST_PLACEHOLDER_FIRSTNAME
            && ($params['lastname'] ?? '') === self::GUEST_PLACEHOLDER_LASTNAME
        ) {
            $params['firstname'] = '';
            $params['lastname'] = '';
        }

        return $this->fillWith($params);
    }

    public function fillFromAddress(\Address $address)
    {
        $this->address = $address;
        $params = get_object_vars($address);
        $params['id_address'] = $address->id;

        return $this->fillWith($params);
    }

    /**
     * Fill form from delivery and invoice addresses
     *
     * @param \Address|null $deliveryAddress
     * @param \Address|null $invoiceAddress
     *
     * @return self
     */
    public function fillFromAddresses(?\Address $deliveryAddress = null, ?\Address $invoiceAddress = null)
    {
        $params = [];
        if ($deliveryAddress) {
            foreach (get_object_vars($deliveryAddress) as $key => $value) {
                if ($key !== 'id_address' && !is_object($value)) {
                    $params[$key] = $value;
                }
            }
            $params['id_address'] = $deliveryAddress->id;
        }
        $deliveryAddressId = $deliveryAddress ? $deliveryAddress->id : 0;

        if ($invoiceAddress && $invoiceAddress->id != $deliveryAddressId) {
            $params['use_same_address'] = false;
            $params['id_address_invoice'] = $invoiceAddress->id;
            foreach (get_object_vars($invoiceAddress) as $key => $value) {
                if ($key !== 'id_address' && !is_object($value)) {
                    $params['invoice_' . $key] = $value;
                }
            }
        } else {
            $params['use_same_address'] = true;
        }

        return $this->fillWith($params);
    }

    public function validate()
    {
        // When use_same_address, invoice fields are optional (skipped)
        $useSameAddress = $this->getField('use_same_address');
        if ($useSameAddress && $useSameAddress->getValue()) {
            foreach ($this->formFields as $field) {
                if (strpos($field->getName(), 'invoice_') === 0) {
                    $field->setRequired(false);
                }
            }
        }

        $country = $this->formatter->getCountry();
        $invoicePostcode = (!$useSameAddress || !$useSameAddress->getValue())
            ? $this->getField('invoice_postcode')
            : null;
        $invoiceCountryField = (!$useSameAddress || !$useSameAddress->getValue())
            ? $this->getField('invoice_id_country')
            : null;

        $is_valid = $this->validateAddressPostcode(
            $this->getField('postcode'),
            $country,
            $invoicePostcode,
            $invoiceCountryField
        );
        if ($is_valid) {
            $is_valid = $this->validateAddressFormHook();
        }

        return $is_valid && parent::validate();
    }

    /**
     * Submit the form: create/update customer guest and addresses (delivery + invoice)
     *
     * @return array|false ['id_address_delivery' => int, 'id_address_invoice' => int] on success, false on failure
     */
    public function submit()
    {
        if (!$this->validate()) {
            return false;
        }

        $this->syncContextCustomerFromCart();

        // Get customer from form data
        $customer = $this->getCustomer();

        // If customer is not logged in, create/update guest customer
        if (!$this->context->customer->isLogged() || $this->context->customer->isGuest()) {
            $customer->is_guest = true;

            if (!$this->customerPersister->save($customer, '', '', false)) {
                $errors = $this->customerPersister->getErrors();
                foreach ($errors as $field => $fieldErrors) {
                    foreach ($fieldErrors as $error) {
                        $this->errors[$field][] = $error;
                    }
                }

                return false;
            }

            $this->context->updateCustomer($customer);
        }

        $token = \Tools::getToken(true, $this->context);
        $useSameAddress = $this->getField('use_same_address') && $this->getField('use_same_address')->getValue();

        // Create/update delivery address
        $deliveryAddress = $this->buildAddressFromFields('', \Tools::getValue('id_address'));
        $deliveryAddress->id_customer = $customer->id;
        if (empty($deliveryAddress->alias)) {
            $deliveryAddress->alias = $this->translator->trans('My Address', [], 'Shop.Theme.Checkout');
        }
        \Hook::exec('actionSubmitCustomerAddressForm', ['address' => &$deliveryAddress]);
        if (!$this->addressPersister->save($deliveryAddress, $token)) {
            return false;
        }

        $idAddressDelivery = $deliveryAddress->id;
        $idAddressInvoice = $idAddressDelivery;

        // Create/update invoice address if different
        if (!$useSameAddress) {
            $idAddressInvoice = (int) \Tools::getValue('id_address_invoice');
            $invoiceAddress = $this->buildAddressFromFields('invoice_', $idAddressInvoice ?: null);
            $invoiceAddress->id_customer = $customer->id;
            $invoiceAddress->alias = $invoiceAddress->alias ?: $this->translator->trans(
                'Invoice address',
                [],
                'Shop.Theme.Checkout'
            );
            \Hook::exec('actionSubmitCustomerAddressForm', ['address' => &$invoiceAddress]);
            if (!$this->addressPersister->save($invoiceAddress, $token)) {
                return false;
            }
            $idAddressInvoice = $invoiceAddress->id;
        }

        return [
            'id_address_delivery' => $idAddressDelivery,
            'id_address_invoice' => $idAddressInvoice,
        ];
    }

    /**
     * In OPC, guest auto-save can attach a guest to the cart just before final submit.
     * If `context->customer` is still empty, final submit may create a duplicate guest.
     * Reload persisted cart owner and sync context only when that owner is a guest.
     */
    private function syncContextCustomerFromCart(): void
    {
        if ((int) $this->context->customer->id > 0 || !\Validate::isLoadedObject($this->context->cart)) {
            return;
        }

        $cartId = (int) $this->context->cart->id;
        if ($cartId <= 0) {
            return;
        }

        // Read cart owner from DB to align final submit with the real cart owner.
        $freshCart = new \Cart($cartId);
        if (!\Validate::isLoadedObject($freshCart)) {
            return;
        }

        $cartCustomerId = (int) $freshCart->id_customer;
        if ($cartCustomerId <= 0) {
            return;
        }

        $cartCustomer = new \Customer($cartCustomerId);
        if (!\Validate::isLoadedObject($cartCustomer) || !$cartCustomer->isGuest()) {
            return;
        }

        $this->context->customer = $cartCustomer;
    }

    /**
     * Identify the guest as soon as email and required checkboxes are valid.
     *
     * @param array $params
     */
    public function submitGuestInit(array $params = []): bool
    {
        $this->fillWith($params);

        if (!$this->validateGuestInit()) {
            return false;
        }

        $customer = $this->getCustomer();
        $customer->is_guest = true;
        $customer->firstname = $customer->firstname ?: self::GUEST_PLACEHOLDER_FIRSTNAME;
        $customer->lastname = $customer->lastname ?: self::GUEST_PLACEHOLDER_LASTNAME;

        if (!$this->persistGuestCustomer($customer)) {
            return false;
        }

        return true;
    }

    /**
     * Build Address object from form fields with given prefix
     *
     * @param string $prefix Field prefix ('' for delivery, 'invoice_' for invoice)
     * @param int|null $idAddress Existing address ID or null for new
     *
     * @return \Address
     */
    private function buildAddressFromFields($prefix, $idAddress)
    {
        $address = new \Address($idAddress ? (int) $idAddress : null, $this->language->id);

        foreach ($this->formFields as $formField) {
            $fieldName = $formField->getName();
            if (strpos($fieldName, $prefix) !== 0) {
                continue;
            }
            $baseName = $prefix ? substr($fieldName, strlen($prefix)) : $fieldName;
            if (property_exists($address, $baseName)) {
                $address->{$baseName} = $formField->getValue();
            }
        }

        if (!isset($this->formFields[$prefix . 'id_state'])) {
            $address->id_state = 0;
        }

        return $address;
    }

    private function validateGuestInit(): bool
    {
        if (!$this->isGuestInitEmailValid()) {
            return false;
        }

        if (!$this->formFields) {
            $this->formFields = $this->formatter->getFormat();
        }

        $this->validateRequiredGuestConsentFields();

        return !$this->hasErrors();
    }

    /**
     * Persist guest customer and propagate persister errors to the form.
     *
     * @param \Customer $customer
     *
     * @return bool
     */
    private function persistGuestCustomer(\Customer $customer): bool
    {
        if ($this->customerPersister->save($customer, '', '', false)) {
            return true;
        }

        $errors = $this->customerPersister->getErrors();
        foreach ($errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $this->errors[$field][] = $error;
            }
        }

        return false;
    }

    /**
     * Validate guest email field format.
     *
     * @return bool
     */
    private function isGuestInitEmailValid(): bool
    {
        $emailField = $this->getField('email');
        if (!$emailField) {
            return false;
        }

        if (\Validate::isEmail(trim((string) $emailField->getValue()))) {
            return true;
        }

        $emailField->addError(
            $this->translator->trans(
                'Invalid email format.',
                [],
                'Shop.Notifications.Error'
            )
        );

        return false;
    }

    /**
     * Validate required checkbox fields used by guest.
     *
     * @return void
     */
    private function validateRequiredGuestConsentFields(): void
    {
        foreach ($this->formFields as $field) {
            if ($field->getType() !== 'checkbox' || !$field->isRequired()) {
                continue;
            }

            if (!$field->getValue()) {
                $field->addError($this->constraintTranslator->translate('required'));
            }
        }
    }

    /**
     * Get customer from form data
     *
     * @return \Customer
     */
    public function getCustomer()
    {
        $customer = new \Customer($this->context->customer->id);

        foreach ($this->formFields as $field) {
            $customerField = $field->getName();
            if (property_exists($customer, $customerField)) {
                $customer->$customerField = $field->getValue();
            }
        }

        return $customer;
    }

    /**
     * Get delivery address from form data
     *
     * @return \Address
     */
    public function getAddress()
    {
        return $this->buildAddressFromFields('', \Tools::getValue('id_address'));
    }

    /**
     * Get invoice address from form data (when use_same_address is false)
     *
     * @return \Address
     */
    public function getInvoiceAddress()
    {
        return $this->buildAddressFromFields('invoice_', null);
    }

    public function getTemplateVariables()
    {
        if (!$this->formFields) {
            // This is usually done by fillWith but the form may be
            // rendered before fillWith is called.
            $this->formFields = $this->formatter->getFormat();
        }

        $formFields = array_map(
            function (\FormField $item) {
                return $item->toArray();
            },
            $this->formFields
        );

        return [
            'action' => $this->action,
            'errors' => $this->getErrors(),
            'formFields' => $formFields,
        ];
    }
}
