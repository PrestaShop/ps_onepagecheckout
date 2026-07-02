<?php

/**
 * Module AJAX handler for OPC address form refresh.
 */

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;

class OnePageCheckoutAddressFormHandler
{
    /**
     * The only request parameters this handler accepts. The raw request is reduced to
     * these (scalar-valued) parameters at the entry point; address ids are additionally
     * ownership-checked before being forwarded to the form.
     */
    private const ALLOWED_REQUEST_PARAMETERS = [
        'id_country',
        'invoice_id_country',
        'use_same_address',
        'id_address_delivery',
        'id_address_invoice',
    ];

    /**
     * @var OnePageCheckoutForm
     */
    private $opcForm;
    private \Context $context;
    private CheckoutCustomerContextResolver $customerResolver;

    public function __construct(
        OnePageCheckoutForm $opcForm,
        ?\Context $context = null,
        ?CheckoutCustomerContextResolver $customerResolver = null,
    ) {
        $this->opcForm = $opcForm;
        $this->context = $context ?? \Context::getContext();
        $this->customerResolver = $customerResolver ?? new CheckoutCustomerContextResolver($this->context);
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function getTemplateVariables(array $requestParameters): array
    {
        $requestParameters = $this->extractAllowedScalarParameters($requestParameters);

        $deliveryAddressId = (int) ($requestParameters['id_address_delivery'] ?? 0);
        $ownedDeliveryAddress = null;
        $customer = $this->customerResolver->resolve();

        if ($customer instanceof \Customer) {
            $this->opcForm->fillFromCustomer($customer);
        }

        if ($deliveryAddressId > 0) {
            $ownedDeliveryAddress = $this->loadOwnedAddress($deliveryAddressId);
            if ($ownedDeliveryAddress instanceof \Address) {
                $this->opcForm->fillFromAddress($ownedDeliveryAddress);
            }
        }

        // Reload parity for renders that carry no address context (e.g. the post-delete section
        // refresh): anchor the form on the cart's surviving addresses, exactly like the initial page
        // render does from the session. Without this the form falls back to the SHOP DEFAULT country,
        // silently switching the inline forms to another country's format (e.g. a US form requiring a
        // state on an FR journey) — a re-typed address then never passes the completeness checks.
        if (!$ownedDeliveryAddress instanceof \Address && !isset($requestParameters['id_country'])) {
            $cartDeliveryAddress = $this->loadOwnedAddress((int) ($this->context->cart->id_address_delivery ?? 0));
            if ($cartDeliveryAddress instanceof \Address) {
                $this->opcForm->fillFromAddress($cartDeliveryAddress);
            }
        }

        // Invoice anchor: only meaningful when a SEPARATE billing form is rendered (use_same off)
        // and the request carries no invoice context. With use_same on the invoice mirrors the
        // delivery, and anchoring it would both waste an address load on hot re-renders (inline
        // country changes) and pin the formatter's invoice country to a stale cart pointer.
        if (
            (string) ($requestParameters['use_same_address'] ?? '1') === '0'
            && !isset($requestParameters['invoice_id_country'])
            && !isset($requestParameters['id_address_invoice'])
        ) {
            $cartInvoiceAddress = $this->loadOwnedAddress((int) ($this->context->cart->id_address_invoice ?? 0));
            if ($cartInvoiceAddress instanceof \Address && (int) $cartInvoiceAddress->id_country > 0) {
                $this->opcForm->fillWith(['invoice_id_country' => (int) $cartInvoiceAddress->id_country]);
            }
        }

        $formParams = [];

        foreach (self::ALLOWED_REQUEST_PARAMETERS as $name) {
            if (!isset($requestParameters[$name])) {
                continue;
            }

            if (in_array($name, ['id_address_delivery', 'id_address_invoice'], true) && (int) $requestParameters[$name] <= 0) {
                continue;
            }

            if ($name === 'id_address_delivery' && !$ownedDeliveryAddress instanceof \Address) {
                continue;
            }

            if ($name === 'id_address_invoice' && !$this->isOwnedAddressId((int) $requestParameters[$name])) {
                continue;
            }

            $formParams[$name] = $requestParameters[$name];
        }

        if (
            $this->shouldFallbackToDefaultDeliveryCountry($customer, $requestParameters, $deliveryAddressId)
            && !isset($formParams['id_country'])
        ) {
            $formParams['id_country'] = (string) \Configuration::get('PS_COUNTRY_DEFAULT');
        }

        if (!empty($formParams)) {
            $this->opcForm->fillWith($formParams);
        }

        return $this->buildAddressesSectionTemplateVariables();
    }

    /**
     * Reduce the raw request to the allowed parameters, dropping non-scalar values
     * (e.g. `?id_country[]=1`) so downstream casts never operate on arrays.
     *
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,scalar>
     */
    private function extractAllowedScalarParameters(array $requestParameters): array
    {
        $parameters = [];

        foreach (self::ALLOWED_REQUEST_PARAMETERS as $name) {
            if (isset($requestParameters[$name]) && is_scalar($requestParameters[$name])) {
                $parameters[$name] = $requestParameters[$name];
            }
        }

        return $parameters;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function shouldFallbackToDefaultDeliveryCountry(
        ?\Customer $customer,
        array $requestParameters,
        int $deliveryAddressId,
    ): bool {
        if (!$customer instanceof \Customer) {
            return false;
        }

        if ($deliveryAddressId > 0 || isset($requestParameters['id_country'])) {
            return false;
        }

        return count($customer->getSimpleAddresses((int) $this->context->language->id)) === 0;
    }

    private function loadOwnedAddress(int $addressId): ?\Address
    {
        $customerId = $this->customerResolver->resolveId();
        if ($customerId <= 0) {
            return null;
        }

        $address = new \Address($addressId, (int) $this->context->language->id);
        // Exclude SOFT-DELETED addresses (PrestaShop sets `deleted=1` rather than removing the row):
        // after the customer deletes an address the cart keeps its id, and without this check the
        // deleted address would still load as "owned" — re-populating the inline form's hidden
        // id_address_* and keeping the carrier/payment sections wrongly accessible for an address that
        // no longer exists.
        if (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== $customerId || $address->deleted) {
            return null;
        }

        return $address;
    }

    private function isOwnedAddressId(int $addressId): bool
    {
        return $this->loadOwnedAddress($addressId) instanceof \Address;
    }

    /**
     * The refreshed addresses partial needs the same top-level checkout variables
     * as the initial step render, not just the form payload.
     *
     * @return array<string,mixed>
     */
    private function buildAddressesSectionTemplateVariables(): array
    {
        $cartDeliveryId = (int) ($this->context->cart->id_address_delivery ?? 0);
        $cartInvoiceId = (int) ($this->context->cart->id_address_invoice ?? 0);

        return $this->opcForm->getTemplateVariables() + [
            'is_virtual_cart' => $this->context->cart->isVirtualCart(),
            'cart' => [
                // Only expose a cart address id the inline form treats as a persisted address (its
                // hidden id_address_* field, read by hasPersistedInlineAddress) when that address STILL
                // exists and belongs to the customer. The cart keeps a dangling id after the customer
                // deletes the address; rendering it would keep the carrier/payment sections wrongly
                // accessible for an address that no longer exists. A full page reload already renders 0
                // in that case — match it so deleting the last address withholds the sections.
                'id_address_delivery' => $this->isOwnedAddressId($cartDeliveryId) ? $cartDeliveryId : 0,
                'id_address_invoice' => $this->isOwnedAddressId($cartInvoiceId) ? $cartInvoiceId : 0,
            ],
        ];
    }
}
