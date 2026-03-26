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

        return (new CartPresenter())->present($this->context->cart, true);
    }
}
