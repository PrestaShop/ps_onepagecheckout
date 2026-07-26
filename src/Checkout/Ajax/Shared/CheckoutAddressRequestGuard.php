<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

/**
 * Shared request-address logic for the carrier endpoints: ownership check for a front-sent address
 * id, and the inline separate-billing temp fallback. Extracted from OnePageCheckoutCarriersHandler
 * so OnePageCheckoutSelectCarrierHandler mirrors the exact same battle-tested id-branch.
 */
class CheckoutAddressRequestGuard
{
    private CheckoutCustomerContextResolver $customerResolver;

    public function __construct(
        \Context $context,
        ?CheckoutCustomerContextResolver $customerResolver = null,
    ) {
        $this->customerResolver = $customerResolver ?? new CheckoutCustomerContextResolver($context);
    }

    public function isOwnedCheckoutAddress(int $addressId): bool
    {
        if ($addressId <= 0) {
            return false;
        }

        $customerId = $this->customerResolver->resolveId();
        if ($customerId <= 0) {
            return false;
        }

        return \Customer::customerHasAddress($customerId, $addressId);
    }

    /**
     * Edge case: with exactly one saved address, delivery is selected from the saved list while
     * separate billing is rendered as inline fields. There is no saved invoice address id yet, but
     * invoice-based carrier totals must still be computed from the inline billing country. A
     * front-sent id_address_invoice (persisted separate billing) takes precedence: no temp mount.
     *
     * @param array<string,mixed> $requestParameters
     */
    public function applyTemporaryInlineInvoiceAddress(OpcTempAddress $tempAddress, array $requestParameters): void
    {
        if ((string) ($requestParameters['use_same_address'] ?? '1') !== '0') {
            return;
        }

        if (!empty($requestParameters['id_address_invoice'])) {
            return;
        }

        if ((int) ($requestParameters['invoice_id_country'] ?? 0) <= 0) {
            return;
        }

        $tempAddress->createFromRequest(
            [
                'use_same_address' => '0',
                'invoice_id_country' => $requestParameters['invoice_id_country'],
                'invoice_id_state' => $requestParameters['invoice_id_state'] ?? 0,
                'invoice_postcode' => $requestParameters['invoice_postcode'] ?? '',
                'invoice_city' => $requestParameters['invoice_city'] ?? '',
            ],
            true
        );
    }
}
