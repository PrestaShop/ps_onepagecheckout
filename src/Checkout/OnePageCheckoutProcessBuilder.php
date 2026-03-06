<?php

/**
 * Builds the module-owned OPC checkout process returned by actionCheckoutBuildProcess.
 */

namespace PrestaShop\Module\PsOnePageCheckout\Checkout;

use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutProcessBuilder
{
    /**
     * @var \Context
     */
    private $context;

    /**
     * @var \Ps_Onepagecheckout
     */
    private $module;

    /**
     * @var OnePageCheckoutFormFactory
     */
    private $opcFormFactory;

    /**
     * @var OnePageCheckoutAvailability
     */
    private $opcAvailability;

    public function __construct(
        \Context $context,
        \Ps_Onepagecheckout $module,
        ?OnePageCheckoutFormFactory $opcFormFactory = null,
        ?OnePageCheckoutAvailability $opcAvailability = null,
    ) {
        $this->context = $context;
        $this->module = $module;
        $this->opcFormFactory = $opcFormFactory ?? new OnePageCheckoutFormFactory($context, $module);
        $this->opcAvailability = $opcAvailability ?? new OnePageCheckoutAvailability(\Ps_Onepagecheckout::CONFIG_ONE_PAGE_CHECKOUT_ENABLED);
    }

    /**
     * @param \CheckoutSession $checkoutSession
     * @param TranslatorInterface $translator
     *
     * @return \CheckoutProcess|null
     */
    public function build(\CheckoutSession $checkoutSession, TranslatorInterface $translator): ?\CheckoutProcess
    {
        $checkoutProcess = new OnePageCheckoutProcess(
            $this->context,
            $checkoutSession,
            $this->opcAvailability
        );

        $onePageStep = new CheckoutOnePageStep(
            $this->context,
            $translator,
            $this->opcFormFactory->create(),
            new \PaymentOptionsFinder(),
            new \ConditionsToApproveFinder(
                $this->context,
                $translator
            )
        );

        if (!$this->context->cart->isVirtualCart()) {
            $this->configureDeliveryOptionsForStep($onePageStep);
        }

        $checkoutProcess->addStep($onePageStep);

        return $checkoutProcess;
    }

    /**
     * Configure delivery options with the native checkout one-page setup.
     *
     * @param \CheckoutDeliveryStep|CheckoutOnePageStep $step
     */
    protected function configureDeliveryOptionsForStep($step): void
    {
        $step
            ->setRecyclablePackAllowed((bool) \Configuration::get('PS_RECYCLABLE_PACK'))
            ->setGiftAllowed((bool) \Configuration::get('PS_GIFT_WRAPPING'))
            ->setIncludeTaxes(
                !\Product::getTaxCalculationMethod((int) $this->context->cart->id_customer)
                && (int) \Configuration::get('PS_TAX')
            )
            ->setDisplayTaxesLabel(\Configuration::get('PS_TAX'))
            ->setGiftCost(
                $this->context->cart->getGiftWrappingPrice(
                    $step->getIncludeTaxes()
                )
            );
    }
}
