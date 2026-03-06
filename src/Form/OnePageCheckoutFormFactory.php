<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShop\Module\PsOnePageCheckout\Form;

class OnePageCheckoutFormFactory
{
    /**
     * @var \Context
     */
    private $context;

    /**
     * @var \Ps_Onepagecheckout
     */
    private $module;

    public function __construct(\Context $context, \Ps_Onepagecheckout $module)
    {
        $this->context = $context;
        $this->module = $module;
    }

    public function create(): OnePageCheckoutForm
    {
        $availableCountries = $this->getAvailableCountries();
        $formatter = $this->createFormatter($availableCountries);
        $form = $this->createFormInstance(
            $formatter,
            $this->createCustomerPersister(),
            $this->createAddressPersister()
        );

        $form->setAction($this->context->link->getPageLink('order', true));

        return $form;
    }

    /**
     * @return array<int, mixed>
     */
    protected function getAvailableCountries(): array
    {
        if (\Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES')) {
            return \Carrier::getDeliveredCountries($this->context->language->id, true, true);
        }

        return \Country::getCountries($this->context->language->id, true);
    }

    /**
     * @param array<int, mixed> $availableCountries
     */
    protected function createFormatter(array $availableCountries): OnePageCheckoutFormatter
    {
        return new OnePageCheckoutFormatter(
            $this->context->country,
            $this->module->getTranslator(),
            $availableCountries
        );
    }

    protected function createFormInstance(
        OnePageCheckoutFormatter $formatter,
        \CustomerPersister $customerPersister,
        \CustomerAddressPersister $addressPersister,
    ): OnePageCheckoutForm {
        return new OnePageCheckoutForm(
            $this->context->smarty,
            $this->context,
            $this->context->language,
            $this->module->getTranslator(),
            $formatter,
            $customerPersister,
            $addressPersister
        );
    }

    public function createCustomerPersister(): \CustomerPersister
    {
        return new \CustomerPersister(
            $this->context,
            $this->module->get('hashing'),
            $this->module->getTranslator(),
            (bool) \Configuration::get('PS_GUEST_CHECKOUT_ENABLED')
        );
    }

    public function createAddressPersister(): \CustomerAddressPersister
    {
        return new \CustomerAddressPersister(
            $this->context->customer,
            $this->context->cart,
            \Tools::getToken(true, $this->context)
        );
    }
}
