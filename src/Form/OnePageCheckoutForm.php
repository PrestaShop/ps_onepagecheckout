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
use Country;
use Customer;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerTemplateBuilder;
use PrestaShop\PrestaShop\Core\Util\InternationalizedDomainNameConverter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Validate;

class OnePageCheckoutForm extends \AbstractForm
{
    use AddressFormValidationTrait;

    private const GUEST_PLACEHOLDER_FIRSTNAME = 'Guest';
    private const GUEST_PLACEHOLDER_LASTNAME = 'Guest';

    /**
     * @var OnePageCheckoutFormatter
     */
    protected $formatter;

    /**
     * @var \Context
     */
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

        $selectedCountryField = $this->getField('id_country');
        $selectedCountryId = $selectedCountryField ? (int) $selectedCountryField->getValue() : 0;

        // Rebuild delivery field rules from selected country (state/postcode requirements).
        if (isset($params['id_country'])) {
            $country = (int) $params['id_country'] !== (int) $this->formatter->getCountry()->id
                ? new \Country((int) $params['id_country'], $this->language->id)
                : $this->formatter->getCountry()
            ;
        } elseif ($selectedCountryId > 0) {
            // Saved-address submits send address IDs only; keep the hydrated address country for validation.
            $country = $selectedCountryId !== (int) $this->formatter->getCountry()->id
                ? new \Country($selectedCountryId, $this->language->id)
                : $this->formatter->getCountry()
            ;
        } elseif ($this->address) { // @phpstan-ignore elseif.alwaysTrue (defensive fallback when formatter country differs)
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

        parent::fillWith($params);

        $this->stripAdditionalCustomerFieldsForConnectedCustomer();
        $this->hydrateConnectedCustomerEmail();

        return $this;
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
        $params['id_address_delivery'] = $address->id;

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
            $params['id_address_delivery'] = $deliveryAddress->id;
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

    /**
     * Restore the last failed submit state after the AJAX submit redirects back to /order.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $errors
     */
    public function restoreSubmissionState(array $params, array $errors): self
    {
        $this->fillWith($params);
        $this->errors = ['' => is_array($errors[''] ?? null) ? $errors[''] : []];

        foreach ($errors as $fieldKey => $fieldErrors) {
            if ($fieldKey === '' || !is_array($fieldErrors)) {
                continue;
            }

            $field = $this->getField((string) $fieldKey);
            if ($field instanceof \FormField) {
                $field->setErrors($fieldErrors);
            }
        }

        return $this;
    }

    /**
     * @param array<string,mixed> $submittedValues
     * @param array<string,mixed> $validationErrors
     *
     * @return array<string,mixed>
     */
    public function buildPersistedSubmissionState(array $submittedValues, array $validationErrors): array
    {
        return [
            'validation_errors' => $validationErrors,
            'form_errors' => $this->getPersistableErrors(),
            'submitted_values' => $submittedValues,
        ];
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

        if ($is_valid) {
            $this->validateCustomerFieldsByModules();
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

        $fieldsByGroup = $this->mapFieldsByGroup();
        // Captured BEFORE the customer resolution: Context::updateCustomer (called below) resets
        // the cart address pointers to the customer's first address and persists that.
        $persistedCartAddressIds = $this->capturePersistedCartAddressIds();
        $customer = $this->getCheckoutCustomerForSubmit($fieldsByGroup);

        if ((int) $customer->id <= 0 || $customer->isGuest()) {
            $customer = $this->buildCustomerFromFields($this->getCustomerFields($fieldsByGroup));
            $customer->is_guest = true;

            if (!$this->customerPersister->save($customer, '', '', false)) {
                $this->applyFieldErrors($this->customerPersister->getErrors());

                return false;
            }

            $this->context->updateCustomer($customer);
        }

        $this->refreshAddressPersister();

        $token = \Tools::getToken(true, $this->context);
        $useSameAddress = $this->getField('use_same_address') && $this->getField('use_same_address')->getValue();

        $deliveryAddress = $this->resolveSelectedCustomerAddress(
            $this->resolveSelectedDeliveryAddressId(),
            (int) $customer->id
        );

        if ($deliveryAddress instanceof \Address) {
            $idAddressDelivery = (int) $deliveryAddress->id;
        } else {
            // No explicit saved-address selection: the TYPED fields are authoritative (the OPC
            // client strips the hidden technical ids from the final submit payload). Update the
            // cart-attached address in place when it is safely reusable so autosave-persisted
            // addresses are not duplicated — but never silently reuse its stale CONTENT: the buyer
            // may have edited fields after the last autosave landed.
            $deliveryAddress = $this->buildAddressFromGroup(
                $fieldsByGroup['deliveryFields'],
                $this->reusableCartAddressIdForSubmit('delivery', (int) $customer->id, $persistedCartAddressIds) ?: null
            );
            $deliveryAddress->id_customer = $customer->id;
            if (empty($deliveryAddress->alias)) {
                $deliveryAddress->alias = $this->translator->trans('My Address', [], 'Modules.Onepagecheckout.Shop');
            }
            \Hook::exec('actionSubmitCustomerAddressForm', ['address' => &$deliveryAddress]);
            if (!$this->addressPersister->save($deliveryAddress, $token)) {
                return false;
            }

            $idAddressDelivery = (int) $deliveryAddress->id;
        }

        $idAddressInvoice = $idAddressDelivery;

        // Create/update invoice address if different
        if (!$useSameAddress) {
            $idAddressInvoice = $this->resolveSelectedInvoiceAddressId();
            $invoiceAddress = $this->resolveSelectedCustomerAddress($idAddressInvoice, (int) $customer->id);

            if ($invoiceAddress instanceof \Address) {
                $idAddressInvoice = (int) $invoiceAddress->id;
            } else {
                // Same contract as the delivery address above: typed invoice fields win, the
                // separate persisted billing address is updated in place instead of duplicated.
                $invoiceAddress = $this->buildAddressFromGroup(
                    $fieldsByGroup['invoiceFields'],
                    $this->reusableCartAddressIdForSubmit('invoice', (int) $customer->id, $persistedCartAddressIds) ?: null,
                    'invoice_'
                );
                $invoiceAddress->id_customer = $customer->id;
                $invoiceAddress->alias = $invoiceAddress->alias ?: $this->translator->trans('Invoice address', [], 'Modules.Onepagecheckout.Shop');
                \Hook::exec('actionSubmitCustomerAddressForm', ['address' => &$invoiceAddress]);
                if (!$this->addressPersister->save($invoiceAddress, $token)) {
                    return false;
                }

                $idAddressInvoice = (int) $invoiceAddress->id;
            }
        }

        return [
            'id_address_delivery' => $idAddressDelivery,
            'id_address_invoice' => $idAddressInvoice,
        ];
    }

    /**
     * Final submit must follow the cart owner stored in persistence, not the current session flags.
     * When a registered customer already owns the cart, we keep that customer as checkout owner.
     *
     * @param array<string, array<string, \FormField>> $fieldsByGroup
     */
    private function getCheckoutCustomerForSubmit(array $fieldsByGroup): \Customer
    {
        $persistedCartOwner = $this->getPersistedCartOwner();
        if ($persistedCartOwner && !$persistedCartOwner->isGuest()) {
            $this->context->updateCustomer($persistedCartOwner);

            return $persistedCartOwner;
        }

        $this->syncContextCustomerFromCart();

        return $this->buildCustomerFromFields($this->getCustomerFields($fieldsByGroup));
    }

    private function refreshAddressPersister(): void
    {
        if (get_class($this->addressPersister) !== \CustomerAddressPersister::class) {
            return;
        }

        $this->addressPersister = new \CustomerAddressPersister(
            $this->context->customer,
            $this->context->cart,
            \Tools::getToken(true, $this->context)
        );
    }

    private function syncContextCustomerFromCart(): void
    {
        if (!isset($this->context->cart) || !\Validate::isUnsignedId((int) $this->context->cart->id)) {
            return;
        }

        $freshCart = new \Cart((int) $this->context->cart->id);
        if (!\Validate::isLoadedObject($freshCart)) {
            return;
        }

        $cartCustomer = $this->getPersistedCartOwner();
        if (!$cartCustomer) {
            return;
        }

        if (
            !\Validate::isLoadedObject($this->context->cart)
            || (int) $this->context->cart->id_customer !== (int) $freshCart->id_customer
            || (int) $this->context->cart->id_currency <= 0
            || (int) $this->context->cart->id_lang <= 0
            || (int) $this->context->cart->id_shop <= 0
        ) {
            $this->context->cart = $freshCart;
        }

        if ((int) $this->context->customer->id === (int) $cartCustomer->id) {
            return;
        }

        $this->context->updateCustomer($cartCustomer);
    }

    private function getPersistedCartOwner(): ?\Customer
    {
        if (!isset($this->context->cart) || !\Validate::isUnsignedId((int) $this->context->cart->id)) {
            return null;
        }

        $freshCart = new \Cart((int) $this->context->cart->id);
        if (!\Validate::isLoadedObject($freshCart) || (int) $freshCart->id_customer <= 0) {
            return null;
        }

        $cartCustomer = new \Customer((int) $freshCart->id_customer);

        return \Validate::isLoadedObject($cartCustomer) ? $cartCustomer : null;
    }

    /**
     * Identify the guest as soon as email and required checkboxes are valid.
     *
     * @param array $params
     */
    public function submitGuestInit(array $params = []): bool
    {
        $this->fillWith($params);
        $fieldsByGroup = $this->mapFieldsByGroup();

        if (!$this->validateGuestInit($fieldsByGroup)) {
            return false;
        }

        $customer = $this->buildCustomerFromFields($this->getCustomerFields($fieldsByGroup));
        $customer->is_guest = true;
        $customer->firstname = $customer->firstname ?: self::GUEST_PLACEHOLDER_FIRSTNAME;
        $customer->lastname = $customer->lastname ?: self::GUEST_PLACEHOLDER_LASTNAME;

        if (!$this->persistGuestCustomer($customer)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, array<string, \FormField>> $fieldsByGroup
     */
    private function validateGuestInit(array $fieldsByGroup): bool
    {
        if (!$this->isGuestInitEmailValid()) {
            return false;
        }

        if (!$this->formFields) {
            $this->formFields = $this->formatter->getFormat();
            $this->stripAdditionalCustomerFieldsForConnectedCustomer();
        }

        $this->validateRequiredGuestConsentFields($fieldsByGroup);

        return !$this->hasErrors();
    }

    protected function validateCustomerFieldsByModules(): void
    {
        $formFieldsAssociated = [];
        $formFieldKeysByModule = [];
        $additionalCustomerFields = $this->mapFieldsByGroup()['additionalCustomerFields'];

        foreach ($additionalCustomerFields as $key => $formField) {
            if (empty($formField->moduleName)) {
                continue;
            }

            $formFieldsAssociated[$formField->moduleName][] = $formField;
            $formFieldKeysByModule[$formField->moduleName][$formField->getName()] = $key;
        }

        foreach ($formFieldsAssociated as $moduleName => $formFields) {
            $moduleId = \Module::getModuleIdByName($moduleName);
            if (!$moduleId) {
                continue;
            }

            $validatedCustomerFormFields = \Hook::exec(
                'validateCustomerFormFields',
                ['fields' => $formFields],
                $moduleId,
                true
            );

            if (!is_array($validatedCustomerFormFields)) {
                continue;
            }

            foreach ($validatedCustomerFormFields as $name => $field) {
                if (!$field instanceof \FormFieldCore) {
                    continue;
                }

                $targetKey = $formFieldKeysByModule[$moduleName][$name] ?? $name;
                $this->formFields[$targetKey] = $field;
            }
        }
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

        $this->applyFieldErrors($this->customerPersister->getErrors());

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
            $this->translator->trans('Invalid email format.', [], 'Modules.Onepagecheckout.Shop')
        );

        return false;
    }

    /**
     * Validate required checkbox fields used by guest.
     *
     * @return void
     */
    /**
     * @param array<string, array<string, \FormField>> $fieldsByGroup
     */
    private function validateRequiredGuestConsentFields(array $fieldsByGroup): void
    {
        $guestFields = $fieldsByGroup['contactFields'] + $fieldsByGroup['additionalCustomerFields'];
        foreach ($guestFields as $field) {
            if (!in_array($field->getType(), ['checkbox', 'radio-buttons'], true) || !$field->isRequired()) {
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
        return $this->buildCustomerFromFields(
            $this->getCustomerFields($this->mapFieldsByGroup())
        );
    }

    /**
     * Get delivery address from form data
     *
     * @return \Address
     */
    public function getAddress()
    {
        $fieldsByGroup = $this->mapFieldsByGroup();

        return $this->buildAddressFromGroup($fieldsByGroup['deliveryFields'], $this->resolveSelectedDeliveryAddressId());
    }

    /**
     * Get invoice address from form data (when use_same_address is false)
     *
     * @return \Address
     */
    public function getInvoiceAddress()
    {
        $fieldsByGroup = $this->mapFieldsByGroup();

        return $this->buildAddressFromGroup($fieldsByGroup['invoiceFields'], null, 'invoice_');
    }

    /**
     * Names of the editable address fields (delivery + invoice sections) plus the
     * same-address flag, read from the live form definition. Driving the draft whitelist
     * from here keeps it in sync with configurable per-country address formats and
     * module-added custom address fields, instead of relying on a hardcoded list.
     *
     * @return array<int, string>
     */
    public function getAddressDraftFieldNames(): array
    {
        if (!$this->formFields) {
            $this->formFields = $this->formatter->getFormat();
            $this->stripAdditionalCustomerFieldsForConnectedCustomer();
        }

        $fieldsByGroup = $this->mapFieldsByGroup();
        $names = ['use_same_address'];

        foreach (['deliveryFields', 'invoiceFields'] as $group) {
            foreach ($fieldsByGroup[$group] as $field) {
                // Skip hidden technical fields such as id_address_delivery; they are not draft data.
                if ($field->getType() === 'hidden') {
                    continue;
                }

                $names[] = (string) $field->getName();
            }
        }

        return array_values(array_unique($names));
    }

    public function getTemplateVariables()
    {
        if (!$this->formFields) {
            $this->formFields = $this->formatter->getFormat();
            $this->stripAdditionalCustomerFieldsForConnectedCustomer();
        }

        $fieldsByGroup = $this->mapFieldsByGroup();
        $formFields = $this->convertFieldsToTemplateArray($this->formFields);
        $deliveryFields = $this->convertFieldsToTemplateArray($fieldsByGroup['deliveryFields']);
        $invoiceFields = $this->convertFieldsToTemplateArray($fieldsByGroup['invoiceFields']);

        return [
            'action' => $this->action,
            'errors' => $this->getErrors(),
            'customer' => $this->buildCheckoutCustomerTemplateVariables(),
            'formFields' => $formFields,
            'contactFields' => $this->convertFieldsToTemplateArray($fieldsByGroup['contactFields']),
            'additionalCustomerFields' => $this->convertFieldsToTemplateArray($fieldsByGroup['additionalCustomerFields']),
            'useSameAddressField' => $this->convertFieldToTemplateArray($fieldsByGroup['useSameAddressField']['use_same_address'] ?? null),
            'deliveryFields' => $deliveryFields,
            'invoiceFields' => $invoiceFields,
            // The address sections split into rows here rather than in the template, so the layout
            // is unit tested and the modals go through the same code (see AddressFieldRows).
            'deliveryFieldRows' => AddressFieldRows::build($deliveryFields),
            'invoiceFieldRows' => AddressFieldRows::build($invoiceFields, 'invoice_'),
            'invoiceMetaFields' => $this->convertFieldsToTemplateArray($fieldsByGroup['invoiceMetaFields']),
            'token' => \Tools::getToken(true, $this->context),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCheckoutCustomerTemplateVariables(): array
    {
        return (new CheckoutCustomerTemplateBuilder(
            $this->context,
            new CheckoutCustomerContextResolver($this->context)
        ))->build();
    }

    private function resolveSelectedDeliveryAddressId(): ?int
    {
        $selectedDeliveryAddressId = (int) \Tools::getValue('id_address_delivery');

        return $selectedDeliveryAddressId > 0 ? $selectedDeliveryAddressId : null;
    }

    private function resolveSelectedInvoiceAddressId(): int
    {
        return (int) \Tools::getValue('id_address_invoice');
    }

    /**
     * The persisted cart address pointers, read BEFORE Context::updateCustomer runs: it resets
     * both cart pointers to the customer's first address, destroying the separate-billing pointer
     * the autosave maintained.
     *
     * @return array{delivery:int,invoice:int}
     */
    private function capturePersistedCartAddressIds(): array
    {
        if (!isset($this->context->cart) || (int) $this->context->cart->id <= 0) {
            return ['delivery' => 0, 'invoice' => 0];
        }

        $cart = new \Cart((int) $this->context->cart->id);
        if (!\Validate::isLoadedObject($cart)) {
            return ['delivery' => 0, 'invoice' => 0];
        }

        return [
            'delivery' => (int) $cart->id_address_delivery,
            'invoice' => (int) $cart->id_address_invoice,
        ];
    }

    /**
     * The address already attached to the cart for this type, when an inline submit can safely
     * update it in place: it must still exist, belong to the customer and not be soft-deleted.
     * A billing id that still mirrors the delivery address is not reused (no separate billing was
     * persisted, so submit builds it from the typed invoice fields).
     *
     * @param array{delivery:int,invoice:int} $persistedCartAddressIds
     */
    private function reusableCartAddressIdForSubmit(string $addressType, int $customerId, array $persistedCartAddressIds): int
    {
        $deliveryAddressId = $persistedCartAddressIds['delivery'];
        $candidateId = $addressType === 'invoice' ? $persistedCartAddressIds['invoice'] : $deliveryAddressId;

        if ($candidateId <= 0 || ($addressType === 'invoice' && $candidateId === $deliveryAddressId)) {
            return 0;
        }

        return $this->resolveSelectedCustomerAddress($candidateId, $customerId) ? $candidateId : 0;
    }

    private function resolveSelectedCustomerAddress(?int $addressId, int $customerId): ?\Address
    {
        if ($addressId === null || $addressId <= 0 || $customerId <= 0) {
            return null;
        }

        $address = new \Address($addressId, (int) $this->language->id);

        // Exclude SOFT-DELETED addresses (PrestaShop sets `deleted=1` rather than removing the
        // row): the cart can keep a dangling id after the customer deletes the address, and an
        // order must never be carried by an address the customer no longer owns.
        if (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== $customerId || $address->deleted) {
            return null;
        }

        return $address;
    }

    /**
     * @return array<string, array<string, \FormField>>
     */
    private function mapFieldsByGroup(): array
    {
        $fieldsByGroup = [
            'contactFields' => [],
            'additionalCustomerFields' => [],
            'useSameAddressField' => [],
            'deliveryFields' => [],
            'invoiceFields' => [],
            'invoiceMetaFields' => [],
        ];

        foreach ($this->formFields as $key => $field) {
            if ($this->isCustomerField($key, $field)) {
                $fieldsByGroup['additionalCustomerFields'][$key] = $field;
                continue;
            }

            if ($this->isContactField($key)) {
                $fieldsByGroup['contactFields'][$key] = $field;
                continue;
            }

            if ($this->isUseSameAddressField($key)) {
                $fieldsByGroup['useSameAddressField'][$key] = $field;
                continue;
            }

            if ($this->isInvoiceMetaField($key)) {
                $fieldsByGroup['invoiceMetaFields'][$key] = $field;
                continue;
            }

            if ($this->isInvoiceField($key)) {
                $fieldsByGroup['invoiceFields'][$key] = $field;
                continue;
            }

            $fieldsByGroup['deliveryFields'][$key] = $field;
        }

        return $fieldsByGroup;
    }

    private function stripAdditionalCustomerFieldsForConnectedCustomer(): void
    {
        if (!$this->isRegisteredCustomer()) {
            return;
        }

        foreach ($this->formFields as $key => $field) {
            if ($this->isCustomerField($key, $field)) {
                unset($this->formFields[$key]);
            }
        }
    }

    private function isRegisteredCustomer(): bool
    {
        $customer = $this->context->customer;

        return $customer instanceof \Customer
            && !empty($customer->id)
            && !$customer->isGuest();
    }

    private function hydrateConnectedCustomerEmail(): void
    {
        if (!$this->isRegisteredCustomer()) {
            return;
        }

        $emailField = $this->getField('email');
        if (!$emailField || $emailField->getValue()) {
            return;
        }

        $email = (string) $this->context->customer->email;
        if (!\Validate::isEmail($email)) {
            return;
        }

        $emailField->setValue($email);
    }

    private function isCustomerField(string $key, ?\FormField $field = null): bool
    {
        if ($this->formatter->getFieldGroup($key) === 'customer') {
            return true;
        }

        if (strpos($key, 'customer_') === 0) {
            return true;
        }

        if ($field && strpos((string) $field->getName(), '_customer_') !== false) {
            return true;
        }

        return false;
    }

    private function isContactField(string $key): bool
    {
        return in_array($key, ['email', 'optin'], true);
    }

    private function isUseSameAddressField(string $key): bool
    {
        return $key === 'use_same_address';
    }

    private function isInvoiceMetaField(string $key): bool
    {
        return $key === 'id_address_invoice';
    }

    private function isInvoiceField(string $key): bool
    {
        return strpos($key, 'invoice_') === 0;
    }

    /**
     * @param array<string, array<string, \FormField>> $fieldsByGroup
     *
     * @return array<string, \FormField>
     */
    private function getCustomerFields(array $fieldsByGroup): array
    {
        return $fieldsByGroup['contactFields']
            + $fieldsByGroup['additionalCustomerFields']
            + $fieldsByGroup['deliveryFields'];
    }

    /**
     * @param array<string, \FormField> $fields
     */
    private function buildCustomerFromFields(array $fields): \Customer
    {
        $customerId = (int) $this->context->customer->id;
        $customer = $customerId > 0 ? new \Customer($customerId) : new \Customer();

        foreach ($fields as $field) {
            $customerField = $field->getName();
            if (property_exists($customer, $customerField)) {
                $customer->$customerField = $field->getValue();
            }
        }

        return $customer;
    }

    /**
     * @param array<string, \FormField> $fields
     * @param int|null $idAddress
     */
    private function buildAddressFromGroup(array $fields, $idAddress, string $prefix = ''): \Address
    {
        $address = new \Address($idAddress ? (int) $idAddress : null, $this->language->id);

        foreach ($fields as $formField) {
            $fieldName = $formField->getName();
            $baseName = $prefix && strpos($fieldName, $prefix) === 0
                ? substr($fieldName, strlen($prefix))
                : $fieldName;

            if (property_exists($address, $baseName)) {
                $address->{$baseName} = $formField->getValue();
            }
        }

        if (!isset($fields[$prefix . 'id_state'])) {
            $address->id_state = 0;
        }

        return $address;
    }

    /**
     * @param array<string, \FormField> $fields
     *
     * @return array<string, array<string, mixed>>
     */
    private function convertFieldsToTemplateArray(array $fields): array
    {
        return array_map(
            function (\FormField $field) {
                return $field->toArray();
            },
            $fields
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertFieldToTemplateArray(?\FormField $field): ?array
    {
        return $field ? $field->toArray() : null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function getPersistableErrors(): array
    {
        $persistableErrors = [
            '' => is_array($this->errors[''] ?? null) ? $this->errors[''] : [],
        ];

        foreach ($this->formFields as $fieldKey => $field) {
            $fieldErrors = $field->getErrors();

            if ($fieldErrors !== []) {
                $persistableErrors[$fieldKey] = $fieldErrors;
            }
        }

        return $persistableErrors;
    }

    /**
     * @param array<string, array<int, string>> $errorsByField
     */
    private function applyFieldErrors(array $errorsByField): void
    {
        foreach ($errorsByField as $fieldKey => $fieldErrors) {
            if ($fieldErrors === []) {
                continue;
            }

            $field = $this->getField((string) $fieldKey);
            if ($field instanceof \FormField) {
                $field->setErrors($fieldErrors);
            }
        }
    }
}
