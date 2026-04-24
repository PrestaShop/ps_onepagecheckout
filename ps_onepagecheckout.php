<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutAvailability;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutProcessProvider;
use PrestaShop\Module\PsOnePageCheckout\Form\BackOfficeConfigurationForm;
use PrestaShop\PrestaShop\Adapter\Order\Checkout\CheckoutProcessProviderInterface;

class Ps_Onepagecheckout extends Module
{
    public const CONFIG_ONE_PAGE_CHECKOUT_ENABLED = 'PS_ONE_PAGE_CHECKOUT_ENABLED';
    private ?BackOfficeConfigurationForm $backOfficeConfigurationForm = null;

    public function __construct()
    {
        $this->name = 'ps_onepagecheckout';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $tabNames = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tabNames[$lang['locale']] = $this->trans('Checkout', [], 'Modules.Psonepagecheckout.Admin', $lang['locale']);
        }
        $this->tabs = [
            [
                'class_name' => 'AdminPsOnePageCheckout',
                'visible' => true,
                'name' => $tabNames,
                'parent_class_name' => 'AdminParentThemes',
                'wording' => 'Checkout',
                'wording_domain' => 'Modules.Psonepagecheckout.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('One-page checkout', [], 'Modules.Psonepagecheckout.Admin');
        $this->description = $this->trans(
            'Native one-page checkout.',
            [],
            'Modules.Psonepagecheckout.Admin'
        );
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->controllers = ['GuestInit', 'AddressForm'];
    }

    public function install()
    {
        return $this->installInParent()
            && $this->installOnePageCheckoutConfiguration()
            && $this->registerHook('actionCheckoutBuildProcess')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionFrontControllerSetVariables');
    }

    public function enable($force_all = false)
    {
        return $this->enableInParent((bool) $force_all);
    }

    /**
     * Disable module-owned checkout configuration only in current multistore scope.
     *
     * @param bool $force_all
     *
     * @return bool
     */
    public function disable($force_all = false)
    {
        return $this->disableOnePageCheckoutConfigurationForCurrentContext()
            && $this->disableInParent((bool) $force_all);
    }

    public function uninstall()
    {
        return $this->uninstallOnePageCheckoutConfiguration()
            && $this->uninstallInParent();
    }

    public function getContent()
    {
        return $this->getBackOfficeConfigurationContent();
    }

    public function getBackOfficeConfigurationContent(): string
    {
        return $this->getBackOfficeConfigurationForm()->renderBackOfficeConfiguration();
    }

    public function hookActionCheckoutBuildProcess(array $params = []): CheckoutProcessProviderInterface
    {
        return new OnePageCheckoutProcessProvider($this->context, $this);
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        if (!isset($this->context->controller) || $this->context->controller->php_self !== 'order') {
            return;
        }

        $isOnePageCheckoutEnabled = $this->isOnePageCheckoutEnabled();
        $this->context->smarty->assign('is_one_page_checkout_enabled', $isOnePageCheckoutEnabled);

        if (!$isOnePageCheckoutEnabled) {
            return;
        }

        $opcRuntimeConfiguration = [
            'enabled' => true,
            'urls' => [
                'guestInit' => $this->context->link->getModuleLink(
                    $this->name,
                    'GuestInit',
                    ['ajax' => 1, 'action' => 'opcGuestInit'],
                    null,
                    null,
                    null,
                    true
                ),
                'addressForm' => $this->context->link->getModuleLink(
                    $this->name,
                    'AddressForm',
                    ['ajax' => 1, 'action' => 'opcAddressForm'],
                    null,
                    null,
                    null,
                    true
                ),
            ],
        ];

        $this->addOpcJavascriptDefinition([
            'ps_onepagecheckout' => $opcRuntimeConfiguration,
        ]);

        $this->registerOpcJavascriptAssets();
    }

    public function hookActionFrontControllerSetVariables(array $params): void
    {
        if (!isset($this->context->controller) || $this->context->controller->php_self !== 'order') {
            return;
        }

        if (!isset($params['templateVars']) || !is_array($params['templateVars'])) {
            return;
        }

        $params['templateVars']['is_one_page_checkout_enabled'] = $this->isOnePageCheckoutEnabled();
    }

    public function isOnePageCheckoutEnabled(): bool
    {
        return (new OnePageCheckoutAvailability(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED))->isEnabled();
    }

    protected function registerOpcJavascriptAssets(): void
    {
        if (!isset($this->context->controller)) {
            return;
        }

        $this->context->controller->registerJavascript(
            'module-ps-onepagecheckout-guest-init',
            'modules/' . $this->name . '/views/public/opc-guest-init.bundle.js',
            [
                'position' => 'bottom',
                'priority' => 150,
            ]
        );

        $this->context->controller->registerJavascript(
            'module-ps-onepagecheckout-address',
            'modules/' . $this->name . '/views/public/opc-address.bundle.js',
            [
                'position' => 'bottom',
                'priority' => 151,
            ]
        );
    }

    protected function addOpcJavascriptDefinition(array $javascriptDefinition): void
    {
        Media::addJsDef($javascriptDefinition);
    }

    protected function installInParent(): bool
    {
        return parent::install();
    }

    protected function disableInParent(bool $forceAll): bool
    {
        return parent::disable($forceAll);
    }

    protected function enableInParent(bool $forceAll): bool
    {
        return parent::enable($forceAll);
    }

    protected function uninstallInParent(): bool
    {
        return parent::uninstall();
    }

    protected function getBackOfficeConfigurationForm(): BackOfficeConfigurationForm
    {
        if (null === $this->backOfficeConfigurationForm) {
            $this->backOfficeConfigurationForm = new BackOfficeConfigurationForm(
                $this,
                self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED
            );
        }

        return $this->backOfficeConfigurationForm;
    }

    protected function installOnePageCheckoutConfiguration(): bool
    {
        return Configuration::updateValue(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED, 0, false);
    }

    protected function disableOnePageCheckoutConfigurationForCurrentContext(): bool
    {
        return Configuration::updateValue(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED, 0, false);
    }

    protected function uninstallOnePageCheckoutConfiguration(): bool
    {
        return Configuration::deleteByName(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED);
    }
}
