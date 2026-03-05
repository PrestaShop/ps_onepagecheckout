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

use PrestaShop\Module\PsOnepagecheckout\Checkout\OpcCheckoutProcessBuilder;
use PrestaShop\Module\PsOnepagecheckout\Checkout\OnePageCheckoutAvailability;
use PrestaShop\Module\PsOnepagecheckout\Form\BackOfficeConfigurationForm;
use Symfony\Contracts\Translation\TranslatorInterface;

class Ps_Onepagecheckout extends Module
{
    public const CONFIG_ONE_PAGE_CHECKOUT_ENABLED = 'PS_ONE_PAGE_CHECKOUT_ENABLED';
    private ?BackOfficeConfigurationForm $backOfficeConfigurationForm = null;

    public function __construct()
    {
        $this->name = 'ps_onepagecheckout';
        $this->tab = 'front_office_features';
        $this->version = '1.0.2';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $tabNames = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tabNames[$lang['locale']] = $this->trans('One-page checkout', [], 'Modules.Psonepagecheckout.Admin', $lang['locale']);
        }
        $this->tabs = [
            [
                'class_name' => 'AdminPsOnePageCheckout',
                'visible' => true,
                'name' => $tabNames,
                'parent_class_name' => 'AdminParentThemes',
                'wording' => 'One-page checkout',
                'wording_domain' => 'Modules.Psonepagecheckout.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('One-page checkout (native)', [], 'Modules.Psonepagecheckout.Admin');
        $this->description = $this->trans(
            'Injects native one-page checkout from module scope.',
            [],
            'Modules.Psonepagecheckout.Admin'
        );
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->controllers = ['GuestInit', 'AddressForm'];
    }

    public function install()
    {
        return $this->removeLegacyAdminPsOpcTab()
            && $this->installInParent()
            && $this->initializeOnePageCheckoutConfiguration()
            && $this->registerHook('actionCheckoutBuildProcessBefore')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionFrontControllerSetVariables');
    }

    /**
     * Keep activation idempotent and only disable OPC configuration in current multishop scope.
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
        return $this->disableOnePageCheckoutConfigurationForAllShops()
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

    public function hookActionCheckoutBuildProcessBefore(array $params): void
    {
        if (!$this->isOnePageCheckoutEnabled()) {
            return;
        }

        if (!isset($params['checkoutSession']) || !$params['checkoutSession'] instanceof CheckoutSession) {
            return;
        }

        if (!isset($params['translator']) || !$params['translator'] instanceof TranslatorInterface) {
            return;
        }

        try {
            $checkoutProcessBuilder = $this->createCheckoutProcessBuilder();
            $moduleCheckoutProcess = $checkoutProcessBuilder->build($params['checkoutSession'], $params['translator']);

            if ($moduleCheckoutProcess instanceof CheckoutProcess) {
                $params['checkoutProcess'] = $moduleCheckoutProcess;
            }
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                sprintf('ps_onepagecheckout: unable to build module checkout process (%s)', $exception->getMessage()),
                3,
                null,
                'Module',
                (int) $this->id,
                true
            );
        }
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
            // TODO: remove this key after non regression tests
            // Compatibility alias for legacy checkout scripts that still read this key.
            'prestashopOpc' => $opcRuntimeConfiguration,
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
        $availability = new OnePageCheckoutAvailability(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED);

        return $availability->isEnabled();
    }

    protected function registerOpcJavascriptAssets(): void
    {
        if (!isset($this->context->controller)) {
            return;
        }

        $this->context->controller->registerJavascript(
            'module-ps-onepagecheckout-guest-init',
            'modules/' . $this->name . '/views/js/opc-guest-init.js',
            [
                'position' => 'bottom',
                'priority' => 150,
            ]
        );

        $this->context->controller->registerJavascript(
            'module-ps-onepagecheckout-address',
            'modules/' . $this->name . '/views/js/opc-address.js',
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

    protected function createCheckoutProcessBuilder(): OpcCheckoutProcessBuilder
    {
        return new OpcCheckoutProcessBuilder($this->context, $this);
    }

    protected function installInParent(): bool
    {
        return parent::install();
    }

    protected function disableInParent(bool $forceAll): bool
    {
        return parent::disable($forceAll);
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

    protected function initializeOnePageCheckoutConfiguration(): bool
    {
        return Configuration::updateGlobalValue(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED, 0);
    }

    protected function disableOnePageCheckoutConfigurationForCurrentContext(): bool
    {
        return Configuration::updateValue(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED, 0, false);
    }

    protected function updateOnePageCheckoutConfigurationValue(int $value, ?int $idShopGroup, ?int $idShop): bool
    {
        if (null === $idShopGroup && null === $idShop) {
            return Configuration::updateGlobalValue(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED, $value);
        }

        return Configuration::updateValue(
            self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED,
            $value,
            false,
            $idShopGroup,
            $idShop
        );
    }

    private function disableOnePageCheckoutConfigurationForAllShops(): bool
    {
        $result = $this->updateOnePageCheckoutConfigurationValue(0, null, null);

        foreach (Shop::getShops(false, null, false) as $shop) {
            $result = $result && $this->updateOnePageCheckoutConfigurationValue(
                0,
                (int) $shop['id_shop_group'],
                (int) $shop['id_shop']
            );
        }

        return $result;
    }

    protected function removeLegacyAdminPsOpcTab(): bool
    {
        $legacyTabId = (int) Tab::getIdFromClassName('AdminPsOpc');
        if ($legacyTabId <= 0) {
            return true;
        }

        $legacyTab = new Tab($legacyTabId);
        if (!Validate::isLoadedObject($legacyTab)) {
            return true;
        }

        if ($legacyTab->module !== 'ps_opc') {
            return true;
        }

        return (bool) $legacyTab->delete();
    }
}
