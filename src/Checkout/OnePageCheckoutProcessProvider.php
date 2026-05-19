<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout;

use PrestaShop\PrestaShop\Adapter\Order\Checkout\CheckoutProcessProviderInterface;
use PrestaShopBundle\Translation\TranslatorComponent;

class OnePageCheckoutProcessProvider implements CheckoutProcessProviderInterface
{
    private readonly OnePageCheckoutAvailability $availability;
    private readonly OnePageCheckoutProcessBuilder $checkoutProcessBuilder;

    public function __construct(
        \Context $context,
        \Ps_Onepagecheckout $module,
    ) {
        $this->availability = new OnePageCheckoutAvailability(\Ps_Onepagecheckout::CONFIG_ONE_PAGE_CHECKOUT_ENABLED);
        $this->checkoutProcessBuilder = new OnePageCheckoutProcessBuilder($context, $module);
    }

    public function isEnabled(): bool
    {
        return $this->availability->isEnabled();
    }

    public function buildCheckoutProcess(
        \CheckoutSession $session,
        TranslatorComponent $translator,
    ): \CheckoutProcess {
        return $this->checkoutProcessBuilder->build($session, $translator);
    }

    public function getCheckoutPageTemplate(): ?string
    {
        return 'module:ps_onepagecheckout/views/templates/front/checkout/checkout.tpl';
    }
}
