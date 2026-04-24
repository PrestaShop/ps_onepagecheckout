<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\Module\PsOnePageCheckout\Translation\ModuleTranslation;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutSelectCarrierHandler
{
    private \Context $context;
    private TranslatorInterface $translator;
    private CheckoutSessionFactory $checkoutSessionFactory;
    private CartPresenterHelper $cartPresenterHelper;
    private TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage;
    private TempAddressStorage $tempAddressStorage;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        ?\DeliveryOptionsFinder $deliveryOptionsFinder = null,
        ?CheckoutSessionFactory $checkoutSessionFactory = null,
        ?CartPresenterHelper $cartPresenterHelper = null,
        ?TempAddressCarrierSelectionStorage $tempCarrierSelectionStorage = null,
        ?TempAddressStorage $tempAddressStorage = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
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
        $deliveryOption = (string) ($requestParameters['delivery_option'] ?? '');
        if ($deliveryOption === '') {
            return CheckoutAjaxResponse::error(
                ModuleTranslation::translate($this->translator, 'Missing delivery option.'),
                'delivery_option'
            );
        }

        if (!\Validate::isLoadedObject($this->context->cart)) {
            return CheckoutAjaxResponse::error(
                ModuleTranslation::translate($this->translator, 'Unable to resolve the current cart.')
            );
        }

        $originalAddressId = (int) $this->context->cart->id_address_delivery;
        $tempAddress = new OpcTempAddress($this->context);
        $tempAddressId = 0;

        try {
            $tempAddressId = $tempAddress->createFromRequest($requestParameters);
            $deliveryAddressId = $tempAddressId ?: $originalAddressId;

            if ($deliveryAddressId <= 0) {
                return CheckoutAjaxResponse::error(
                    ModuleTranslation::translate($this->translator, 'Unable to resolve the current delivery address.')
                );
            }

            $this->persistCarrierSelection($deliveryAddressId, $deliveryOption);
            $this->persistTemporaryCarrierSelection($deliveryOption, $tempAddressId > 0 && $originalAddressId <= 0, $requestParameters);

            $cartPreview = $this->cartPresenterHelper->presentCart();

            return [
                'success' => true,
                'delivery_option' => $deliveryOption,
                'id_address_delivery' => $tempAddressId > 0 ? 0 : $deliveryAddressId,
                'cart_preview' => $cartPreview,
                'totals' => $cartPreview['totals'],
            ];
        } finally {
            if ($tempAddressId > 0) {
                $tempAddress->cleanup($tempAddressId, $originalAddressId);
            }
        }
    }

    private function persistCarrierSelection(int $deliveryAddressId, string $deliveryOption): void
    {
        $this->checkoutSessionFactory->create()->setDeliveryOption([
            $deliveryAddressId => $deliveryOption,
        ]);
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
