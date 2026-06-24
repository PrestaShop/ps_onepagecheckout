<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Checkout\PaymentSelectionKeyBuilder;

class OnePageCheckoutPaymentMethodsHandler
{
    private \Context $context;
    private \PaymentOptionsFinder $paymentOptionsFinder;
    private PaymentSelectionKeyBuilder $selectionKeyBuilder;

    public function __construct(
        \Context $context,
        ?\PaymentOptionsFinder $paymentOptionsFinder = null,
        ?PaymentSelectionKeyBuilder $selectionKeyBuilder = null,
    ) {
        $this->context = $context;
        $this->paymentOptionsFinder = $paymentOptionsFinder ?? new \PaymentOptionsFinder();
        $this->selectionKeyBuilder = $selectionKeyBuilder ?? new PaymentSelectionKeyBuilder();
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return CheckoutAjaxResponse::error('Unable to load cart.');
        }

        $originalCountry = $this->context->country;
        $resolvedCountry = null;
        $taxAddressType = (string) \Configuration::get('PS_TAX_ADDRESS_TYPE');
        $addressIds = $this->resolveCountryAddressCandidates($taxAddressType, $requestParameters);

        foreach ($addressIds as $addressId) {
            $resolvedCountry = $this->loadCountryFromAddressId($addressId);
            if ($resolvedCountry instanceof \Country) {
                break;
            }
        }

        if (!$resolvedCountry instanceof \Country) {
            if ($taxAddressType === 'id_address_invoice') {
                $resolvedCountryId = (int) ($requestParameters['invoice_id_country'] ?? 0) ?: (int) ($requestParameters['id_country'] ?? 0);
            } else {
                $resolvedCountryId = (int) ($requestParameters['id_country'] ?? 0) ?: (int) ($requestParameters['delivery_id_country'] ?? 0);
            }

            if ($resolvedCountryId > 0) {
                $candidateCountry = new \Country($resolvedCountryId);
                if (\Validate::isLoadedObject($candidateCountry)) {
                    $resolvedCountry = $candidateCountry;
                }
            }
        }

        $countryId = $resolvedCountry instanceof \Country
            ? (int) $resolvedCountry->id
            : (\Validate::isLoadedObject($this->context->country) ? (int) $this->context->country->id : 0);

        try {
            if ($resolvedCountry instanceof \Country) {
                $this->context->country = $resolvedCountry;
            }

            $isFree = 0.0 == (float) $this->context->cart->getOrderTotal(true, \Cart::BOTH);
            $paymentOptions = $this->paymentOptionsFinder->present($isFree);
            $paymentOptions = $this->filterByCountry($paymentOptions, $countryId);
            $paymentOptions = $this->selectionKeyBuilder->enrichPaymentOptions($paymentOptions);
            [$selectedPaymentModule, $selectedPaymentSelectionKey] = $this->resolvePersistedSelection($paymentOptions);

            return [
                'success' => true,
                'payment_options' => $paymentOptions,
                'is_free' => $isFree,
                'selected_payment_module' => $selectedPaymentModule,
                'selected_payment_selection_key' => $selectedPaymentSelectionKey,
            ];
        } finally {
            $this->context->country = $originalCountry;
        }
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return int[]
     */
    private function resolveCountryAddressCandidates(string $taxAddressType, array $requestParameters): array
    {
        $requestAddressKey = $taxAddressType === 'id_address_invoice'
            ? 'id_address_invoice'
            : 'id_address_delivery';
        $requestAddressId = (int) ($requestParameters[$requestAddressKey] ?? 0);
        $cartAddressId = (int) ($this->context->cart->{$taxAddressType} ?? 0);
        // Inline billing has no address id yet, its visible country must win over stale cart data.
        $hasInlineInvoiceCountry = $taxAddressType === 'id_address_invoice'
            && $requestAddressId <= 0
            && (int) ($requestParameters['invoice_id_country'] ?? 0) > 0;

        $addressIds = [];
        if ($requestAddressId > 0) {
            $addressIds[] = $requestAddressId;
        }

        if (!$hasInlineInvoiceCountry && $cartAddressId > 0 && $cartAddressId !== $requestAddressId) {
            $addressIds[] = $cartAddressId;
        }

        return $addressIds;
    }

    private function loadCountryFromAddressId(int $addressId): ?\Country
    {
        if ($addressId <= 0) {
            return null;
        }

        $address = new \Address($addressId);
        if (!\Validate::isLoadedObject($address) || (int) $address->id_country <= 0) {
            return null;
        }

        $candidateCountry = new \Country((int) $address->id_country);
        if (!\Validate::isLoadedObject($candidateCountry)) {
            return null;
        }

        return $candidateCountry;
    }

    /**
     * @param array<int|string, mixed> $paymentOptions
     *
     * @return array<int|string, mixed>
     */
    private function filterByCountry(array $paymentOptions, int $countryId): array
    {
        if ($paymentOptions === [] || $countryId <= 0) {
            return $paymentOptions;
        }

        $moduleNames = array_keys($paymentOptions);
        $rows = \Db::getInstance()->executeS(
            'SELECT m.`name`, mc.`id_country`
             FROM `' . _DB_PREFIX_ . 'module` m
             INNER JOIN `' . _DB_PREFIX_ . 'module_country` mc ON mc.`id_module` = m.`id_module`
             WHERE mc.`id_shop` = ' . (int) $this->context->shop->id . '
             AND m.`name` IN (\'' . implode("','", array_map('pSQL', $moduleNames)) . '\')'
        );

        if ($rows === false) {
            return $paymentOptions;
        }

        $hasRestriction = [];
        $allowedForCountry = [];
        foreach ($rows as $row) {
            $moduleName = (string) ($row['name'] ?? '');
            if ($moduleName === '') {
                continue;
            }

            $hasRestriction[$moduleName] = true;
            if ((int) $row['id_country'] === $countryId) {
                $allowedForCountry[$moduleName] = true;
            }
        }

        return array_filter(
            $paymentOptions,
            static function ($moduleName) use ($hasRestriction, $allowedForCountry): bool {
                return !isset($hasRestriction[$moduleName]) || isset($allowedForCountry[$moduleName]);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    private function getSelectedPaymentModule(): string
    {
        if (!isset($this->context->cookie)) {
            return '';
        }

        return (string) ($this->context->cookie->__get('opc_selected_payment_module') ?: '');
    }

    private function getSelectedPaymentSelectionKey(): string
    {
        if (!isset($this->context->cookie)) {
            return '';
        }

        return (string) ($this->context->cookie->__get('opc_selected_payment_selection_key') ?: '');
    }

    /**
     * @param array<int|string, mixed> $paymentOptions
     *
     * @return array{0:string,1:string}
     */
    private function resolvePersistedSelection(array $paymentOptions): array
    {
        $selectedPaymentModule = $this->getSelectedPaymentModule();
        $selectedPaymentSelectionKey = $this->getSelectedPaymentSelectionKey();

        if ($selectedPaymentModule === '' && $selectedPaymentSelectionKey === '') {
            return ['', ''];
        }

        if ($this->hasValidPersistedSelection($paymentOptions, $selectedPaymentSelectionKey, $selectedPaymentModule)) {
            return [$selectedPaymentModule, $selectedPaymentSelectionKey];
        }

        $this->clearPersistedSelection();

        return ['', ''];
    }

    /**
     * @param array<int|string, mixed> $paymentOptions
     */
    private function hasValidPersistedSelection(
        array $paymentOptions,
        string $selectedPaymentSelectionKey,
        string $selectedPaymentModule,
    ): bool {
        if ($selectedPaymentSelectionKey !== '') {
            foreach ($paymentOptions as $moduleOptions) {
                if (!is_array($moduleOptions)) {
                    continue;
                }

                foreach ($moduleOptions as $paymentOption) {
                    if (
                        is_array($paymentOption)
                        && (string) ($paymentOption['selection_key'] ?? '') === $selectedPaymentSelectionKey
                    ) {
                        return true;
                    }
                }
            }

            return false;
        }

        return $selectedPaymentModule !== '' && array_key_exists($selectedPaymentModule, $paymentOptions);
    }

    private function clearPersistedSelection(): void
    {
        if (!isset($this->context->cookie)) {
            return;
        }

        $this->context->cookie->__unset('opc_selected_payment_option');
        $this->context->cookie->__unset('opc_selected_payment_module');
        $this->context->cookie->__unset('opc_selected_payment_selection_key');
        $this->context->cookie->write();
    }
}
