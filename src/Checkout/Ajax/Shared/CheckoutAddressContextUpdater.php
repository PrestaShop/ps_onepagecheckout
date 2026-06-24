<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

/**
 * Explicit writer of saved-address checkout context (delivery/invoice) on the cart.
 *
 * It only mutates the cart when the caller explicitly signals the address intent via
 * use_same_address. A bare call (no address intent) is a no-op, so read-only refreshes
 * can never silently reset a separate billing address.
 */
class CheckoutAddressContextUpdater
{
    private \Context $context;
    private CheckoutCustomerContextResolver $customerResolver;

    public function __construct(
        \Context $context,
        ?CheckoutCustomerContextResolver $customerResolver = null,
    ) {
        $this->context = $context;
        $this->customerResolver = $customerResolver ?? new CheckoutCustomerContextResolver($context);
    }

    /**
     * Persist the selected delivery/invoice addresses onto the cart, honouring "use same address".
     *
     * @param array<string,mixed> $requestParameters
     *
     * @return bool whether the cart was changed and saved
     */
    public function updateFromRequest(array $requestParameters): bool
    {
        $cart = $this->context->cart;
        if (!\Validate::isLoadedObject($cart)) {
            return false;
        }

        if (
            !array_key_exists('use_same_address', $requestParameters)
            || (empty($requestParameters['id_address_delivery']) && empty($requestParameters['id_address_invoice']))
        ) {
            return false;
        }

        $initialDeliveryAddressId = (int) $cart->id_address_delivery;
        $initialInvoiceAddressId = (int) $cart->id_address_invoice;
        $useSameAddress = (string) $requestParameters['use_same_address'] !== '0';

        $deliveryAddressId = (int) ($requestParameters['id_address_delivery'] ?? 0);
        if ($deliveryAddressId > 0) {
            $this->assertOwnedAddress($deliveryAddressId, 'Invalid delivery address.');
            $cart->id_address_delivery = $deliveryAddressId;
        }

        if ($useSameAddress) {
            $targetInvoiceId = (int) $cart->id_address_delivery;
        } else {
            $requestedInvoiceId = (int) ($requestParameters['id_address_invoice'] ?? 0);
            if ($requestedInvoiceId > 0) {
                $this->assertOwnedAddress($requestedInvoiceId, 'Invalid invoice address.');
            }

            $targetInvoiceId = $requestedInvoiceId > 0 ? $requestedInvoiceId : (int) $cart->id_address_invoice;
        }

        if ($targetInvoiceId > 0) {
            $cart->id_address_invoice = $targetInvoiceId;
        }

        if (
            (int) $cart->id_address_delivery === $initialDeliveryAddressId
            && (int) $cart->id_address_invoice === $initialInvoiceAddressId
        ) {
            return false;
        }

        if (!$cart->save()) {
            throw new \RuntimeException('Unable to save checkout address context.');
        }

        return true;
    }

    private function assertOwnedAddress(int $addressId, string $message): void
    {
        $customerId = $this->customerResolver->resolveId();
        if ($customerId <= 0 || !\Customer::customerHasAddress($customerId, $addressId)) {
            throw new \InvalidArgumentException($message);
        }
    }
}
