<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OrderOptions;

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutAjaxResponse;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
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
                ModuleTranslation::translate($this->translator, 'Unable to resolve the current cart.')
            );
        }

        if (!(bool) \Configuration::get('PS_GIFT_WRAPPING')) {
            return CheckoutAjaxResponse::error(
                ModuleTranslation::translate($this->translator, 'Gift wrapping is currently unavailable.')
            );
        }

        $checkoutSession = $this->checkoutSessionFactory->create();
        $useGift = !empty($requestParameters['gift']);
        $giftMessage = $useGift ? (string) ($requestParameters['gift_message'] ?? '') : '';

        $checkoutSession->setGift($useGift, $giftMessage);

        $cart = $this->cartPresenterHelper->presentCart();

        return [
            'success' => true,
            'cart' => $cart,
            'totals' => $cart['totals'],
        ];
    }
}
