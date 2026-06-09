<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutDeleteAddressHandler
{
    private \Context $context;
    private TranslatorInterface $translator;
    private CheckoutCustomerContextResolver $customerResolver;
    private CheckoutSessionFactory $checkoutSessionFactory;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        CheckoutCustomerContextResolver $customerResolver,
        ?CheckoutSessionFactory $checkoutSessionFactory = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->customerResolver = $customerResolver;
        $this->checkoutSessionFactory = $checkoutSessionFactory ?? new CheckoutSessionFactory($context, $translator);
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        $customer = $this->customerResolver->resolve();
        if (!$customer instanceof \Customer) {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Unable to resolve checkout customer.', [], 'Modules.Onepagecheckout.Shop')
            );
        }

        $address = $this->loadOwnedAddress($customer, (int) ($requestParameters['id_address'] ?? 0));
        if (!$address instanceof \Address) {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Unable to load the requested address.', [], 'Modules.Onepagecheckout.Shop')
            );
        }

        $addressId = (int) $address->id;

        $deletedActiveDeliveryAddress = \Validate::isLoadedObject($this->context->cart)
            && (int) $this->context->cart->id_address_delivery === $addressId;
        $deletedActiveInvoiceAddress = \Validate::isLoadedObject($this->context->cart)
            && (int) $this->context->cart->id_address_invoice === $addressId;

        if (!$this->buildAddressPersister($customer)->delete($address, \Tools::getToken(true, $this->context))) {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Unable to delete address.', [], 'Modules.Onepagecheckout.Shop')
            );
        }

        $remainingAddresses = $customer->getAddresses((int) $this->context->language->id);
        $remainingAddressIds = array_map(static function (array $customerAddress): int {
            return (int) ($customerAddress['id_address'] ?? 0);
        }, $remainingAddresses);
        $fallbackAddressId = !empty($remainingAddressIds) ? (int) reset($remainingAddressIds) : 0;

        $this->synchronizeCartAddressesAfterDeletion(
            $addressId,
            $remainingAddressIds,
            $fallbackAddressId,
            $deletedActiveDeliveryAddress,
            $deletedActiveInvoiceAddress
        );

        return [
            'success' => true,
            'id_address' => $addressId,
            'message' => $this->translator->trans('Address successfully deleted.', [], 'Modules.Onepagecheckout.Shop'),
        ];
    }

    private function buildAddressPersister(\Customer $customer): \CustomerAddressPersister
    {
        $cart = \Validate::isLoadedObject($this->context->cart) ? $this->context->cart : new \Cart();

        return new \CustomerAddressPersister(
            $customer,
            $cart,
            \Tools::getToken(true, $this->context)
        );
    }

    private function loadOwnedAddress(\Customer $customer, int $addressId): ?\Address
    {
        if ($addressId <= 0) {
            return null;
        }

        $address = new \Address($addressId, (int) $this->context->language->id);
        if (!\Validate::isLoadedObject($address)) {
            return null;
        }

        if ((int) $address->id_customer !== (int) $customer->id) {
            return null;
        }

        return $address;
    }

    /**
     * @param list<int> $remainingAddressIds
     */
    private function synchronizeCartAddressesAfterDeletion(
        int $deletedAddressId,
        array $remainingAddressIds,
        int $fallbackAddressId,
        bool $deletedActiveDeliveryAddress,
        bool $deletedActiveInvoiceAddress,
    ): void {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return;
        }

        $deliveryAddressId = $this->resolveRemainingCartAddressId(
            (int) $this->context->cart->id_address_delivery,
            $deletedAddressId,
            $remainingAddressIds,
            $fallbackAddressId
        );
        $invoiceAddressId = $this->resolveRemainingCartAddressId(
            (int) $this->context->cart->id_address_invoice,
            $deletedAddressId,
            $remainingAddressIds,
            $fallbackAddressId
        );

        $checkoutSession = $this->checkoutSessionFactory->create();
        $checkoutSession->setIdAddressDelivery($deliveryAddressId);
        $checkoutSession->setIdAddressInvoice($invoiceAddressId);

        if ($deletedActiveDeliveryAddress || $deletedActiveInvoiceAddress) {
            $this->context->cart->setDeliveryOption(null);
        }
    }

    /**
     * @param list<int> $remainingAddressIds
     */
    private function resolveRemainingCartAddressId(
        int $currentAddressId,
        int $deletedAddressId,
        array $remainingAddressIds,
        int $fallbackAddressId,
    ): int {
        if ($currentAddressId === $deletedAddressId || !in_array($currentAddressId, $remainingAddressIds, true)) {
            return $fallbackAddressId;
        }

        return $currentAddressId;
    }
}
