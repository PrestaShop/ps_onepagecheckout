<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

class CheckoutSessionFactory
{
    private \Context $context;
    private TranslatorInterface $translator;
    private ?\DeliveryOptionsFinder $deliveryOptionsFinder;

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        ?\DeliveryOptionsFinder $deliveryOptionsFinder = null,
    ) {
        $this->context = $context;
        $this->translator = $translator;
        $this->deliveryOptionsFinder = $deliveryOptionsFinder;
    }

    public function create(): \CheckoutSession
    {
        return new \CheckoutSession(
            $this->context,
            $this->createDeliveryOptionsFinder()
        );
    }

    public function createDeliveryOptionsFinder(): \DeliveryOptionsFinder
    {
        if ($this->deliveryOptionsFinder) {
            return $this->deliveryOptionsFinder;
        }

        return new \DeliveryOptionsFinder(
            $this->context,
            $this->translator,
            new ObjectPresenter(),
            new PriceFormatter()
        );
    }
}
