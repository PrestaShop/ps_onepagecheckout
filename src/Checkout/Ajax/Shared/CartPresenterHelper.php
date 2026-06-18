<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray;
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartPresenter;

class CartPresenterHelper
{
    private \Context $context;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    public function presentCart(): CartLazyArray
    {
        $this->context->cart->resetProductRelatedStaticCache();
        \Cache::clean('presentedCart_*');

        $cartLazyArray = (new CartPresenter())->present($this->context->cart, true);

        // CartLazyArray computes values on first access and caches them.
        // Force tax-sensitive properties now so callers that restore a temporary
        // delivery address in a finally block get correct tax-inclusive values.
        $cartLazyArray->offsetGet('products');
        $cartLazyArray->offsetGet('subtotals');
        $cartLazyArray->offsetGet('totals');

        return $cartLazyArray;
    }
}
