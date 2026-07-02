<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Context;

/**
 * Builds the "fresh context" (delivery country, currency, cart totals) that the
 * single-page checkout must re-inject into the front after each AJAX update.
 *
 * Core only emits window.prestashop and the <body> classes (lang-/country-/
 * currency-) on a full page render (FrontController::assignGeneralPurposeVariables +
 * getTemplateVarPage). The 4-step checkout stays consistent because every step
 * transition reloads the page; OPC never reloads, so those values go stale after an
 * AJAX address/carrier/cart change. This builder mirrors how Core derives them
 * so the front helper can patch window.prestashop.* and the body classes without a reload.
 */
class OpcContextRefreshBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(\Context $context, ?\Country $country = null): array
    {
        $country = $country instanceof \Country ? $country : $this->resolveTaxCountry($context);
        $currency = $context->currency;
        $cart = $context->cart;

        $data = [];

        if ($country instanceof \Country && \Validate::isLoadedObject($country)) {
            $data['country'] = [
                'id' => (int) $country->id,
                'iso_code' => (string) $country->iso_code,
            ];
        }

        if ($currency instanceof \Currency && \Validate::isLoadedObject($currency)) {
            $data['currency'] = [
                'id' => (int) $currency->id,
                'iso_code' => (string) $currency->iso_code,
            ];
        }

        if ($cart instanceof \Cart && \Validate::isLoadedObject($cart)) {
            try {
                $locale = $context->getCurrentLocale();
                $currencyIsoCode = ($currency instanceof \Currency) ? (string) $currency->iso_code : '';
                $format = static function (float $amount) use ($locale, $currencyIsoCode): array {
                    return [
                        // numeric amount — for amount-threshold BNPL widgets (Klarna, PayPal Pay Later)
                        'amount' => $amount,
                        'value' => $locale
                            ? $locale->formatPrice($amount, $currencyIsoCode)
                            : (string) $amount,
                    ];
                };

                $totalIncludingTax = (float) $cart->getOrderTotal(true, \Cart::BOTH);
                $totalExcludingTax = (float) $cart->getOrderTotal(false, \Cart::BOTH);

                // Mirror the cart presenter's totals shape (total / incl_tax / excl_tax) so an
                // amount-threshold BNPL widget — or any window.prestashop.cart.totals reader — stays
                // internally consistent after the front re-sync, instead of seeing a partial totals object.
                $data['cart'] = [
                    'totals' => [
                        'total' => $format($totalIncludingTax),
                        'total_including_tax' => $format($totalIncludingTax),
                        'total_excluding_tax' => $format($totalExcludingTax),
                    ],
                ];
            } catch (\Throwable $exception) {
                // The totals are a best-effort enrichment for window.prestashop.cart readers: a
                // failure to compute them (e.g. no DI container in a CLI context) must never take
                // down the whole OPC response — the client-side sync skips absent keys.
            }
        }

        return $data;
    }

    private function resolveTaxCountry(\Context $context): ?\Country
    {
        $fallback = $context->country instanceof \Country ? $context->country : null;
        $cart = $context->cart;

        if (!$cart instanceof \Cart || !\Validate::isLoadedObject($cart)) {
            return $fallback;
        }

        $taxAddressType = (string) \Configuration::get('PS_TAX_ADDRESS_TYPE');
        if ($taxAddressType === '' || !property_exists($cart, $taxAddressType)) {
            $taxAddressType = 'id_address_delivery';
        }

        $addressId = (int) $cart->{$taxAddressType};
        if ($addressId <= 0) {
            $addressId = (int) $cart->id_address_delivery;
        }

        if ($addressId <= 0) {
            return $fallback;
        }

        $address = new \Address($addressId);
        if (!\Validate::isLoadedObject($address) || (int) $address->id_country <= 0) {
            return $fallback;
        }

        $country = new \Country((int) $address->id_country);

        return \Validate::isLoadedObject($country) ? $country : $fallback;
    }
}
