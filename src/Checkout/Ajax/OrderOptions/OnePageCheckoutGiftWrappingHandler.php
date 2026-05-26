<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OrderOptions;

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutAjaxResponse;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OpcTempAddress;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressCarrierSelectionStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressStorage;
use PrestaShop\Module\PsOnePageCheckout\Translation\ModuleTranslation;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutGiftWrappingHandler
{
    private \Context $context;
    private TranslatorInterface $translator;
    private CheckoutSessionFactory $checkoutSessionFactory;
    private CartPresenterHelper $cartPresenterHelper;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        ?CheckoutSessionFactory $checkoutSessionFactory = null,
        ?CartPresenterHelper $cartPresenterHelper = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->checkoutSessionFactory = $checkoutSessionFactory ?? new CheckoutSessionFactory($context, $translator);
        $this->cartPresenterHelper = $cartPresenterHelper ?? new CartPresenterHelper($context);
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Unable to resolve the current cart.', [], ModuleTranslation::SHOP_DOMAIN)
            );
        }

        if (!(bool) \Configuration::get('PS_GIFT_WRAPPING')) {
            return CheckoutAjaxResponse::error(
                $this->translator->trans('Gift wrapping is currently unavailable.', [], ModuleTranslation::SHOP_DOMAIN)
            );
        }

        $checkoutSession = $this->checkoutSessionFactory->create();
        $useGift = !empty($requestParameters['gift']);
        $giftMessage = $useGift ? (string) ($requestParameters['gift_message'] ?? '') : '';

        $checkoutSession->setGift($useGift, $giftMessage);

        if ((int) $this->context->cart->id_address_delivery <= 0) {
            return $this->presentWithTempAddress($requestParameters);
        }

        $cart = $this->cartPresenterHelper->presentCart();

        return [
            'success' => true,
            'cart' => $cart,
            'totals' => $cart['totals'],
        ];
    }

    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    private function presentWithTempAddress(array $requestParameters): array
    {
        $originalAddressId = (int) $this->context->cart->id_address_delivery;
        $tempAddress = new OpcTempAddress($this->context);
        $tempAddressId = 0;

        try {
            // Prefer address params from the persisted cookie (set during carrier selection)
            // so this works even when the JS doesn't forward address fields.
            $storedParams = (new TempAddressStorage($this->context))->get();
            $addressParams = $storedParams !== [] ? $storedParams : $requestParameters;

            $tempAddressId = $tempAddress->createFromRequest($addressParams);

            if ($tempAddressId > 0) {
                $persistedOption = (new TempAddressCarrierSelectionStorage($this->context))->get();
                if ($persistedOption !== '') {
                    $this->checkoutSessionFactory->create()->setDeliveryOption([
                        $tempAddressId => $persistedOption,
                    ]);
                }
            }

            $cart = $this->cartPresenterHelper->presentCart();

            return [
                'success' => true,
                'cart' => $cart,
                'totals' => $cart['totals'],
            ];
        } finally {
            if ($tempAddressId > 0) {
                $tempAddress->cleanup($tempAddressId, $originalAddressId);
            }
        }
    }
}
