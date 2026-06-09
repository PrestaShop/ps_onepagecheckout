<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartPresenter;
use PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Shipment\DeliveryOptionsProvider;
use PrestaShop\PrestaShop\Core\FeatureFlag\FeatureFlagSettings;
use PrestaShop\PrestaShop\Core\FeatureFlag\FeatureFlagStateCheckerInterface;
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

    public function createDeliveryOptionsFinder(): \DeliveryOptionsFinderCore
    {
        if ($this->deliveryOptionsFinder) {
            return $this->deliveryOptionsFinder;
        }

        if ($this->isImprovedShipmentEnabled()) {
            return new DeliveryOptionsProvider(
                $this->context,
                $this->translator,
                new ObjectPresenter(),
                new PriceFormatter(),
                new CartPresenter()
            );
        }

        return new \DeliveryOptionsFinder(
            $this->context,
            $this->translator,
            new ObjectPresenter(),
            new PriceFormatter()
        );
    }

    private function isImprovedShipmentEnabled(): bool
    {
        try {
            $controller = $this->context->controller ?? null;
            if ($controller instanceof \Controller) {
                /** @var FeatureFlagStateCheckerInterface $checker */
                $checker = $controller->get(FeatureFlagStateCheckerInterface::class);

                return $checker->isEnabled(FeatureFlagSettings::FEATURE_FLAG_IMPROVED_SHIPMENT);
            }
        } catch (\Throwable $e) {
        }

        return false;
    }
}
