<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Checkout\Context\OpcContextRefreshBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutCarriersHandler
{
    private \Context $context;
    private TranslatorInterface $translator;
    private CheckoutCustomerContextResolver $customerResolver;
    private CheckoutSessionFactory $checkoutSessionFactory;
    private CartPresenterHelper $cartPresenterHelper;
    private TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage;
    private TempAddressStorage $tempAddressStorage;
    private CheckoutAddressRequestGuard $addressRequestGuard;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        ?\DeliveryOptionsFinder $deliveryOptionsFinder = null,
        ?CheckoutCustomerContextResolver $customerResolver = null,
        ?CheckoutSessionFactory $checkoutSessionFactory = null,
        ?CartPresenterHelper $cartPresenterHelper = null,
        ?TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage = null,
        ?TempAddressStorage $tempAddressStorage = null,
        ?CheckoutAddressRequestGuard $addressRequestGuard = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->customerResolver = $customerResolver ?? new CheckoutCustomerContextResolver($context);
        $this->checkoutSessionFactory = $checkoutSessionFactory ?? new CheckoutSessionFactory($context, $translator, $deliveryOptionsFinder);
        $this->cartPresenterHelper = $cartPresenterHelper ?? new CartPresenterHelper($context);
        $this->tempCarrierSelectionStorage = $tempCarrierSelectionStorage ?? new TempAddressCarrierSelectionStorage($context);
        $this->tempAddressStorage = $tempAddressStorage ?? new TempAddressStorage($context);
        $this->addressRequestGuard = $addressRequestGuard ?? new CheckoutAddressRequestGuard($context, $this->customerResolver);
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return CheckoutAjaxResponse::error('Unable to resolve checkout cart.');
        }

        $originalAddressId = (int) $this->context->cart->id_address_delivery;
        $tempAddress = new OpcTempAddress($this->context);
        $tempAddressId = 0;

        try {
            if (!empty($requestParameters['id_address_delivery'])) {
                $requestedAddressId = (int) $requestParameters['id_address_delivery'];
                if (!$this->isOwnedCheckoutAddress($requestedAddressId)) {
                    return CheckoutAjaxResponse::error(
                        $this->translator->trans('Invalid delivery address.', [], 'Modules.Onepagecheckout.Shop'),
                        'id_address_delivery'
                    );
                }

                $this->context->cart->id_address_delivery = $requestedAddressId;
                if (
                    array_key_exists('use_same_address', $requestParameters)
                    && (string) $requestParameters['use_same_address'] === '1'
                ) {
                    $this->context->cart->id_address_invoice = $requestedAddressId;
                }
                $this->context->cart->save();
                $this->restorePendingCarrierSelectionOntoAddress($requestedAddressId);
                $this->tempCarrierSelectionStorage->clear();
                $this->tempAddressStorage->clear();
                $this->applyTemporaryInlineInvoiceAddress($tempAddress, $requestParameters);
            } else {
                $tempAddressId = $tempAddress->createFromRequest($requestParameters);
                if ($tempAddressId > 0 && $originalAddressId <= 0) {
                    $this->tempAddressStorage->saveFromRequest($requestParameters);
                }
            }

            if ((int) $this->context->cart->id_address_delivery <= 0) {
                return [
                    'success' => true,
                    'delivery_options' => [],
                    'delivery_option' => '',
                    'selected_delivery_option' => '',
                    'id_address_delivery' => 0,
                ];
            }

            $persistedTempOption = '';
            // A guest can pick a carrier inline, then leave and reopen checkout after
            // the inline address has been persisted to the cart.
            // On that return the request still carries inline fields (no id_address_delivery), so we build a temp
            // address, but the cart already holds the real address. The cookie only
            // survives the inline->persisted transition, so reading it here cannot clobber
            // a deliberately chosen carrier.
            if ($tempAddressId > 0) {
                $persistedTempOption = $this->tempCarrierSelectionStorage->get();
                if ($persistedTempOption !== '') {
                    $this->checkoutSessionFactory->create()->setDeliveryOption([
                        (int) $this->context->cart->id_address_delivery => $persistedTempOption,
                    ]);
                }
            }

            $finder = $this->checkoutSessionFactory->createDeliveryOptionsFinder();
            $deliveryOptions = $finder->getDeliveryOptions();
            $selectedDeliveryOption = $finder->getSelectedDeliveryOption();
            $hadSelectedDeliveryOption = (bool) $selectedDeliveryOption;

            if ($selectedDeliveryOption && !isset($deliveryOptions[$selectedDeliveryOption])) {
                $selectedDeliveryOption = null;
            }

            if ($persistedTempOption !== '') {
                if (isset($deliveryOptions[$persistedTempOption])) {
                    $selectedDeliveryOption = $persistedTempOption;
                } else {
                    $this->tempCarrierSelectionStorage->clear();
                    $this->tempAddressStorage->clear();
                }
            }

            $deliveryAddressId = $tempAddressId ?: (int) $this->context->cart->id_address_delivery;

            if ($selectedDeliveryOption && $deliveryAddressId > 0) {
                // Key ONLY the address the cart's package is on ($deliveryAddressId — the temp address
                // when one is built). Core Cart::getDeliveryOption invalidates the WHOLE map if it holds
                // any address id absent from the current package list, so co-keying the real address
                // would invalidate it and force a best-price/Free fallback in the cart preview. Durable
                // reload-persistence comes from the temp-carrier cookie (restored here on the next
                // request), not from pinning the real address into this preview-time map.
                $this->checkoutSessionFactory->create()->setDeliveryOption([$deliveryAddressId => $selectedDeliveryOption]);
            } elseif ($hadSelectedDeliveryOption && $deliveryAddressId > 0) {
                $this->context->cart->setDeliveryOption(null);
            }

            $cartPreview = $this->cartPresenterHelper->presentCart();

            return [
                'success' => true,
                'delivery_options' => $deliveryOptions,
                'delivery_option' => $selectedDeliveryOption ?: '',
                'selected_delivery_option' => $selectedDeliveryOption ?: '',
                'id_address_delivery' => $tempAddressId > 0 ? 0 : (int) $this->context->cart->id_address_delivery,
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

    /**
     * A carrier chosen while the address was still being typed inline is stored in a cookie keyed to
     * the throwaway temp address. Once that address is persisted and selected as a saved address, move
     * the pending choice onto it so the selection is not lost on reload. The cookie only ever exists
     * during the inline -> persisted transition, so it cannot clobber a deliberately chosen carrier.
     */
    private function restorePendingCarrierSelectionOntoAddress(int $addressId): void
    {
        if ($addressId <= 0) {
            return;
        }

        $pendingOption = $this->tempCarrierSelectionStorage->get();
        if ($pendingOption === '') {
            return;
        }

        $this->checkoutSessionFactory->create()->setDeliveryOption([$addressId => $pendingOption]);
    }

    protected function isOwnedCheckoutAddress(int $addressId): bool
    {
        return $this->addressRequestGuard->isOwnedCheckoutAddress($addressId);
    }

    private function applyTemporaryInlineInvoiceAddress(OpcTempAddress $tempAddress, array $requestParameters): void
    {
        $this->addressRequestGuard->applyTemporaryInlineInvoiceAddress($tempAddress, $requestParameters);
    }
}
