<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Translation\ModuleTranslation;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutCarriersHandler
{
    private \Context $context;
    private TranslatorInterface $translator;
    private CheckoutCustomerContextResolver $customerResolver;
    private CheckoutSessionFactory $checkoutSessionFactory;
    private CartPresenterHelper $cartPresenterHelper;
    private TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        ?\DeliveryOptionsFinder $deliveryOptionsFinder = null,
        ?CheckoutCustomerContextResolver $customerResolver = null,
        ?CheckoutSessionFactory $checkoutSessionFactory = null,
        ?CartPresenterHelper $cartPresenterHelper = null,
        ?TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->customerResolver = $customerResolver ?? new CheckoutCustomerContextResolver($context);
        $this->checkoutSessionFactory = $checkoutSessionFactory ?? new CheckoutSessionFactory($context, $translator, $deliveryOptionsFinder);
        $this->cartPresenterHelper = $cartPresenterHelper ?? new CartPresenterHelper($context);
        $this->tempCarrierSelectionStorage = $tempCarrierSelectionStorage ?? new TempAddressCarrierSelectionStorage($context);
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
                        ModuleTranslation::translate($this->translator, 'Invalid delivery address.'),
                        'id_address_delivery'
                    );
                }

                $this->context->cart->id_address_delivery = $requestedAddressId;
                $this->context->cart->save();
                $this->tempCarrierSelectionStorage->clear();
            } else {
                $tempAddressId = $tempAddress->createFromRequest($requestParameters);
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
            if ($tempAddressId > 0) {
                $tempAddress->cleanup($tempAddressId, $originalAddressId);
            }
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
}
