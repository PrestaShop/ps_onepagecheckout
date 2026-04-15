<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutAddressForm;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutAddressFormatter;
use PrestaShop\Module\PsOnePageCheckout\Translation\ModuleTranslation;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutSaveAddressHandler
{
    private \Context $context;
    private TranslatorInterface $translator;
    private CheckoutCustomerContextResolver $customerResolver;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        CheckoutCustomerContextResolver $customerResolver,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->customerResolver = $customerResolver;
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
                ModuleTranslation::translate($this->translator, 'Unable to resolve checkout customer.')
            );
        }

        $addressType = (string) ($requestParameters['address_type'] ?? 'delivery');
        $prefix = $addressType === 'invoice' ? 'invoice_' : '';
        $addressId = (int) ($requestParameters['id_address'] ?? 0);
        $address = $addressId > 0 ? new \Address($addressId, (int) $this->context->language->id) : new \Address();

        if ($addressId > 0 && (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== $customerId)) {
            return CheckoutAjaxResponse::error(
                ModuleTranslation::translate($this->translator, 'Unable to load the requested address.')
            );
        }

        $addressForm = $this->createAddressForm();
        $addressForm->fillFromRequest($requestParameters, $prefix);
        if (!$addressForm->validate()) {
            return CheckoutAjaxResponse::validation($addressForm->getErrors());
        }

        $this->hydrateAddressFromForm($address, $addressForm, $addressType, $customerId);

        if (!$this->buildAddressPersister($customerId)->save($address, \Tools::getToken(true, $this->context))) {
            return CheckoutAjaxResponse::error(
                ModuleTranslation::translate($this->translator, 'Unable to save address.')
            );
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

        return [
            'success' => true,
            'id_address' => (int) $address->id,
            'address_type' => $addressType,
        ];
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
            ? ModuleTranslation::translate($this->translator, 'Invoice address')
            : ModuleTranslation::translate($this->translator, 'My Address'))));
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
