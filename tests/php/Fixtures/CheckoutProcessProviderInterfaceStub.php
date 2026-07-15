<?php

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\Order\Checkout;

use Symfony\Contracts\Translation\TranslatorInterface;

if (!interface_exists(CheckoutProcessProviderInterface::class)) {
    interface CheckoutProcessProviderInterface
    {
        public function isEnabled(): bool;

        public function buildCheckoutProcess(
            \CheckoutSession $session,
            TranslatorInterface $translator,
        ): \CheckoutProcess;
    }
}
