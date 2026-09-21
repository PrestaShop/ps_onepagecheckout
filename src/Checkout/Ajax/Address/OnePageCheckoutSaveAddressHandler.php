<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutAddressForm;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutAddressFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutSaveAddressHandler
{
    private \Context $context;
    private TranslatorInterface $translator;
    private CheckoutCustomerContextResolver $customerResolver;
    private AddressDraftStorage $addressDraftStorage;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        CheckoutCustomerContextResolver $customerResolver,
        AddressDraftStorage $addressDraftStorage,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->customerResolver = $customerResolver;
        $this->addressDraftStorage = $addressDraftStorage;
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        $customerId = $this->customerResolver->resolveId();
        if ($customerId <= 0) {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Unable to resolve checkout customer.', [], 'Modules.Onepagecheckout.Shop')
            );
        }

        $addressType = (string) ($requestParameters['address_type'] ?? 'delivery');
        $prefix = $addressType === 'invoice' ? 'invoice_' : '';
        $addressId = (int) ($requestParameters['id_address'] ?? 0);
        $address = $addressId > 0 ? new \Address($addressId, (int) $this->context->language->id) : new \Address();

        if ($addressId > 0 && (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== $customerId)) {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Unable to load the requested address.', [], 'Modules.Onepagecheckout.Shop')
            );
        }

        $addressForm = $this->createAddressForm();
        $addressForm->fillFromRequest($requestParameters, $prefix);
        if (!$addressForm->validate()) {
            return CheckoutAjaxResponse::validation($addressForm->getErrors());
        }

        $storedFields = $addressId > 0 ? $this->persistedFieldValues($address) : null;

        $this->hydrateAddressFromForm($address, $addressForm, $addressType, $customerId);

        // WHY: this endpoint is also the autosave target, so it is reached on every change to the
        // checkout form - including ones that touch no address field at all, such as the "use this
        // address for invoice too" toggle. Persisting anyway is not harmless: once an address has
        // been used in a placed order, CustomerAddressPersister::save() cannot update it in place,
        // so it inserts a copy and soft-deletes the original. The customer's saved address then has
        // a new id while the page still holds the old one, and every later ajax call sends an id
        // that no longer resolves. A save that would change nothing is therefore skipped outright.
        if ($storedFields === null || $this->persistedFieldValues($address) !== $storedFields) {
            if (!$this->buildAddressPersister($customerId)->save($address, \Tools::getToken(true, $this->context))) {
                return CheckoutAjaxResponse::error(
                    $this->translator->trans('Unable to save address.', [], 'Modules.Onepagecheckout.Shop')
                );
            }
        }

        if (\Validate::isLoadedObject($this->context->cart)) {
            if ($addressType === 'invoice') {
                $this->context->cart->id_address_invoice = (int) $address->id;
            } else {
                $this->context->cart->id_address_delivery = (int) $address->id;

                if ((string) ($requestParameters['use_same_address'] ?? '1') !== '0') {
                    $this->context->cart->id_address_invoice = (int) $address->id;
                }
            }

            $this->context->cart->update();
        }

        // The typed address is now a real saved address, so the cookie draft is obsolete.
        $this->addressDraftStorage->clear();

        return [
            'success' => true,
            'id_address' => (int) $address->id,
            'address_type' => $addressType,
        ];
    }

    /**
     * The address values as they are stored, so an unchanged submission can be told apart from an
     * edit. Timestamps are excluded: they move on every write and never carry a customer's change.
     *
     * @return array<string,string>
     */
    private function persistedFieldValues(\Address $address): array
    {
        $values = [];
        foreach (array_keys(\Address::$definition['fields']) as $field) {
            if ($field === 'date_add' || $field === 'date_upd') {
                continue;
            }

            $values[$field] = (string) ($address->{$field} ?? '');
        }

        return $values;
    }

    private function createAddressForm(): OnePageCheckoutAddressForm
    {
        return new OnePageCheckoutAddressForm(
            $this->context->smarty,
            $this->context->language,
            $this->translator,
            $this->createAddressFormatter()
        );
    }

    private function createAddressFormatter(): OnePageCheckoutAddressFormatter
    {
        $country = $this->context->country;
        if (!$country instanceof \Country) {
            $country = new \Country(
                (int) \Configuration::get('PS_COUNTRY_DEFAULT'),
                (int) ($this->context->language->id ?? 0)
            );
        }

        $availableCountries = \Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES')
            ? \Carrier::getDeliveredCountries((int) $this->context->language->id, true, true)
            : \Country::getCountries((int) $this->context->language->id, true);

        return new OnePageCheckoutAddressFormatter(
            $country,
            $this->translator,
            $availableCountries
        );
    }

    private function hydrateAddressFromForm(
        \Address $address,
        OnePageCheckoutAddressForm $addressForm,
        string $addressType,
        int $customerId,
    ): void {
        $address = $addressForm->buildAddress($address);

        $address->id_customer = $customerId;
        $address->alias = trim((string) ($address->alias ?: ($addressType === 'invoice'
            ? $this->translator->trans('Invoice address', [], 'Modules.Onepagecheckout.Shop')
            : $this->translator->trans('My Address', [], 'Modules.Onepagecheckout.Shop'))));
        $address->id_country = (int) $address->id_country;
        $address->id_state = (int) ($address->id_state ?: 0);
        \Hook::exec('actionSubmitCustomerAddressForm', ['address' => &$address]);
    }

    private function buildAddressPersister(int $customerId): \CustomerAddressPersister
    {
        $customer = new \Customer($customerId);
        $cart = \Validate::isLoadedObject($this->context->cart) ? $this->context->cart : new \Cart();

        return new \CustomerAddressPersister(
            $customer,
            $cart,
            \Tools::getToken(true, $this->context)
        );
    }
}
