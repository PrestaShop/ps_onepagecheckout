<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Checkout\Context\OpcContextRefreshBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutSelectCarrierHandler
{
    private \Context $context;
    private TranslatorInterface $translator;
    private CheckoutSessionFactory $checkoutSessionFactory;
    private CartPresenterHelper $cartPresenterHelper;
    private TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage;
    private TempAddressStorage $tempAddressStorage;
    private CheckoutAddressRequestGuard $addressRequestGuard;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        ?\DeliveryOptionsFinder $deliveryOptionsFinder = null,
        ?CheckoutSessionFactory $checkoutSessionFactory = null,
        ?CartPresenterHelper $cartPresenterHelper = null,
        ?TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage = null,
        ?TempAddressStorage $tempAddressStorage = null,
        ?CheckoutAddressRequestGuard $addressRequestGuard = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->checkoutSessionFactory = $checkoutSessionFactory ?? new CheckoutSessionFactory($context, $translator, $deliveryOptionsFinder);
        $this->cartPresenterHelper = $cartPresenterHelper ?? new CartPresenterHelper($context);
        $this->tempCarrierSelectionStorage = $tempCarrierSelectionStorage ?? new TempAddressCarrierSelectionStorage($context);
        $this->tempAddressStorage = $tempAddressStorage ?? new TempAddressStorage($context);
        $this->addressRequestGuard = $addressRequestGuard ?? new CheckoutAddressRequestGuard($context);
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        $deliveryOption = (string) ($requestParameters['delivery_option'] ?? '');
        if ($deliveryOption === '') {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Missing delivery option.', [], 'Modules.Onepagecheckout.Shop'),
                'delivery_option'
            );
        }

        if (!\Validate::isLoadedObject($this->context->cart)) {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Unable to resolve the current cart.', [], 'Modules.Onepagecheckout.Shop')
            );
        }

        $originalAddressId = (int) $this->context->cart->id_address_delivery;
        $tempAddress = new OpcTempAddress($this->context);
        $tempAddressId = 0;

        try {
            $requestedAddressId = (int) ($requestParameters['id_address_delivery'] ?? 0);
            if ($requestedAddressId > 0) {
                if (!$this->isOwnedCheckoutAddress($requestedAddressId)) {
                    return CheckoutAjaxResponse::error(
                        $this->translator->trans('Invalid delivery address.', [], 'Modules.Onepagecheckout.Shop'),
                        'id_address_delivery'
                    );
                }

                // Mirror of the carriers-handler id-branch: the front resolved a real address
                // (saved selection or autosave-persisted inline draft) — point the cart at it and
                // skip the delivery temp mount entirely, so the selection is persisted and the
                // hook below fired against the real row. A separate-billing temp stays possible.
                $deliveryAddressId = $requestedAddressId;
                $this->context->cart->id_address_delivery = $requestedAddressId;
                if ((string) ($requestParameters['use_same_address'] ?? '1') === '1') {
                    $this->context->cart->id_address_invoice = $requestedAddressId;
                }
                $this->context->cart->save();
                $this->addressRequestGuard->applyTemporaryInlineInvoiceAddress($tempAddress, $requestParameters);
            } else {
                $tempAddressId = $tempAddress->createFromRequest(
                    $requestParameters,
                    true
                );
                $deliveryAddressId = $tempAddressId ?: $originalAddressId;
            }

            if ($deliveryAddressId <= 0) {
                return CheckoutAjaxResponse::error(
                    $this->translator->trans('Unable to resolve the current delivery address.', [], 'Modules.Onepagecheckout.Shop')
                );
            }

            // Persist the choice on the address the order-summary preview is computed against:
            // $deliveryAddressId (the temp preview address when one is created, else the cart address).
            // It must be the ONLY key — core Cart::getDeliveryOption invalidates the WHOLE delivery_option
            // map if it holds any address id not in the current package list, and at presentCart() the
            // package is keyed by the temp address; co-keying the real address would invalidate the map
            // and core would fall back to the best-price (Free) carrier, so the summary shipping would not
            // reflect the chosen paid carrier.
            $this->persistCarrierSelection($deliveryAddressId, $deliveryOption);
            // Core parity (docs/MODULE_COMPATIBILITY.md §1): CheckoutDeliveryStep fires this hook
            // unconditionally right after the delivery option is persisted — carrier modules
            // receive it on every selection, including sessions that end in a binary payment
            // (whose order creation bypasses the OPC submit pipeline and its submit-time call).
            // Guard: never fire against a temp placeholder (delivery OR invoice) — Core always has
            // real customer addresses on the cart at fire time; the submit-time call still covers
            // the degraded pre-persist window.
            if ($tempAddressId === 0 && !$tempAddress->hasTemporaryInvoiceAddress()) {
                \Hook::exec('actionCarrierProcess', ['cart' => $this->context->cart]);
            }
            // Reload-persistence is handled out-of-band by the cookie: save it whenever the inline/temp
            // flow is used — INCLUDING when a real address is already persisted — so the carriers handler
            // restores it onto the real address on the next request (it used to save only when no real
            // address existed, leaving the guest-with-persisted-address case with no reload persistence).
            $this->persistTemporaryCarrierSelection($deliveryOption, $tempAddressId > 0, $requestParameters);

            $cartPreview = $this->cartPresenterHelper->presentCart();

            return [
                'success' => true,
                'delivery_option' => $deliveryOption,
                'id_address_delivery' => $tempAddressId > 0 ? 0 : $deliveryAddressId,
                'cart_preview' => $cartPreview,
                'totals' => $cartPreview['totals'],
                // Built INSIDE the try: the finally-cleanup below restores the cart pointer and
                // deletes the temp address, so the central fallback (AbstractOpcJsonFrontController)
                // would compute the re-sync against the restored cart — default country, invalidated
                // delivery-option map — and patch the front with wrong values.
                'context_refresh' => (new OpcContextRefreshBuilder())->build($this->context),
            ];
        } finally {
            $tempAddress->cleanup($tempAddressId, $originalAddressId);
        }
    }

    private function persistCarrierSelection(int $deliveryAddressId, string $deliveryOption): void
    {
        $this->checkoutSessionFactory->create()->setDeliveryOption([
            $deliveryAddressId => $deliveryOption,
        ]);
    }

    protected function isOwnedCheckoutAddress(int $addressId): bool
    {
        return $this->addressRequestGuard->isOwnedCheckoutAddress($addressId);
    }

    private function persistTemporaryCarrierSelection(string $deliveryOption, bool $shouldPersist, array $requestParameters = []): void
    {
        if ($shouldPersist) {
            $this->tempCarrierSelectionStorage->save($deliveryOption);
            $this->tempAddressStorage->saveFromRequest($requestParameters);

            return;
        }

        $this->tempCarrierSelectionStorage->clear();
        $this->tempAddressStorage->clear();
    }
}
