<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

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

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        ?\DeliveryOptionsFinder $deliveryOptionsFinder = null,
        ?CheckoutCustomerContextResolver $customerResolver = null,
        ?CheckoutSessionFactory $checkoutSessionFactory = null,
        ?CartPresenterHelper $cartPresenterHelper = null,
        ?TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage = null,
        ?TempAddressStorage $tempAddressStorage = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->customerResolver = $customerResolver ?? new CheckoutCustomerContextResolver($context);
        $this->checkoutSessionFactory = $checkoutSessionFactory ?? new CheckoutSessionFactory($context, $translator, $deliveryOptionsFinder);
        $this->cartPresenterHelper = $cartPresenterHelper ?? new CartPresenterHelper($context);
        $this->tempCarrierSelectionStorage = $tempCarrierSelectionStorage ?? new TempAddressCarrierSelectionStorage($context);
        $this->tempAddressStorage = $tempAddressStorage ?? new TempAddressStorage($context);
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
            if ($tempAddressId > 0 && $originalAddressId <= 0) {
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
            ];
        } finally {
            $tempAddress->cleanup($tempAddressId, $originalAddressId);
        }
    }

    protected function isOwnedCheckoutAddress(int $addressId): bool
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
     * invoice-based carrier totals must still be computed from the inline billing country.
     *
     * @param array<string,mixed> $requestParameters
     */
    private function applyTemporaryInlineInvoiceAddress(OpcTempAddress $tempAddress, array $requestParameters): void
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
