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

use PrestaShop\Module\PsOnePageCheckout\Analytics\Analytics;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutAvailability;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutProcessProvider;
use PrestaShop\Module\PsOnePageCheckout\Form\BackOfficeConfigurationForm;
use PrestaShop\Module\PsOnePageCheckout\Translation\ModuleTranslation;
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
            $tabNames[$lang['locale']] = $this->trans('Checkout', [], ModuleTranslation::ADMIN_DOMAIN, $lang['locale']);
        }
        $this->tabs = [
            [
                'class_name' => 'AdminPsOnePageCheckout',
                'visible' => true,
                'name' => $tabNames,
                'parent_class_name' => 'AdminParentThemes',
                'wording' => 'Checkout',
                'wording_domain' => ModuleTranslation::ADMIN_DOMAIN,
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('One-page checkout', [], ModuleTranslation::ADMIN_DOMAIN);
        $this->description = $this->trans(
            'Native one-page checkout.',
            [],
            ModuleTranslation::ADMIN_DOMAIN
        );
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->controllers = [
            'guestinit',
            'addressform',
            'addresseslist',
            'states',
            'saveaddress',
            'deleteaddress',
            'carriers',
            'selectcarrier',
            'paymentmethods',
            'selectpayment',
            'opcsubmit',
        ];
    }

    public function install()
    {
        return $this->installInParent()
            && $this->installOnePageCheckoutConfiguration()
            && $this->registerHook('actionCheckoutBuildProcess')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionFrontControllerSetVariables')
            && $this->registerHook('actionModuleUpgradeAfter')
            && $this->registerHook('displayOverrideTemplate');
    }

    public function enable($force_all = false)
    {
        $result = $this->enableInParent((bool) $force_all);
        if ($result) {
            Analytics::trackEvent(Analytics::EVENT_MODULE_ENABLED, [], (string) $this->version);
        }

        return $result;
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
        $result = $this->disableOnePageCheckoutConfigurationForCurrentContext()
            && $this->disableInParent((bool) $force_all);

        if ($result) {
            Analytics::trackEvent(Analytics::EVENT_MODULE_DISABLED, [], (string) $this->version);
        }

        return $result;
    }

    public function uninstall()
    {
        $result = $this->uninstallOnePageCheckoutConfiguration()
            && $this->uninstallInParent();

        if ($result) {
            Analytics::trackEvent(Analytics::EVENT_MODULE_UNINSTALLED, [], (string) $this->version);
        }

        return $result;
    }

    public function hookActionModuleUpgradeAfter(array $params): void
    {
        if (!isset($params['object']) || !($params['object'] instanceof Module)) {
            return;
        }

        if ($params['object']->name !== $this->name) {
            return;
        }

        Analytics::trackEvent(Analytics::EVENT_MODULE_UPDATED, [], (string) $this->version);
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
        if (!isset($this->context->controller) || !$this->context->controller instanceof OrderController) {
            return;
        }

        $isOnePageCheckoutEnabled = $this->isOnePageCheckoutEnabled();
        $this->context->smarty->assign('is_one_page_checkout_enabled', $isOnePageCheckoutEnabled);

        if (!$isOnePageCheckoutEnabled) {
            return;
        }

        Analytics::trackCheckoutStarted(
            (bool) Configuration::get('PS_GUEST_CHECKOUT_ENABLED') ? 'yes' : 'no',
            (string) $this->version
        );

        $opcRuntimeConfiguration = [
            'enabled' => true,
            'urls' => [
                'guestInit' => $this->context->link->getModuleLink(
                    $this->name,
                    'guestinit',
                    ['ajax' => 1, 'action' => 'opcGuestInit'],
                    null,
                    null,
                    null,
                    true
                ),
                'addressForm' => $this->context->link->getModuleLink(
                    $this->name,
                    'addressform',
                    ['ajax' => 1, 'action' => 'opcAddressForm'],
                    null,
                    null,
                    null,
                    true
                ),
                'addressesList' => $this->context->link->getModuleLink(
                    $this->name,
                    'addresseslist',
                    ['ajax' => 1, 'action' => 'opcAddressesList'],
                    null,
                    null,
                    null,
                    true
                ),
                'states' => $this->context->link->getModuleLink(
                    $this->name,
                    'states',
                    ['ajax' => 1, 'action' => 'getStatesByCountry'],
                    null,
                    null,
                    null,
                    true
                ),
                'saveAddress' => $this->context->link->getModuleLink(
                    $this->name,
                    'saveaddress',
                    ['ajax' => 1, 'action' => 'saveOpcAddress'],
                    null,
                    null,
                    null,
                    true
                ),
                'deleteAddress' => $this->context->link->getModuleLink(
                    $this->name,
                    'deleteaddress',
                    ['ajax' => 1, 'action' => 'deleteOpcAddress'],
                    null,
                    null,
                    null,
                    true
                ),
                'carriers' => $this->context->link->getModuleLink(
                    $this->name,
                    'carriers',
                    ['ajax' => 1, 'action' => 'opcCarriers'],
                    null,
                    null,
                    null,
                    true
                ),
                'selectCarrier' => $this->context->link->getModuleLink(
                    $this->name,
                    'selectcarrier',
                    ['ajax' => 1, 'action' => 'opcSelectCarrier'],
                    null,
                    null,
                    null,
                    true
                ),
                'paymentMethods' => $this->context->link->getModuleLink(
                    $this->name,
                    'paymentmethods',
                    ['ajax' => 1, 'action' => 'opcPaymentMethods'],
                    null,
                    null,
                    null,
                    true
                ),
                'selectPayment' => $this->context->link->getModuleLink(
                    $this->name,
                    'selectpayment',
                    ['ajax' => 1, 'action' => 'opcSelectPayment'],
                    null,
                    null,
                    null,
                    true
                ),
                'opcSubmit' => $this->context->link->getModuleLink(
                    $this->name,
                    'opcsubmit',
                    ['ajax' => 1, 'action' => 'opcSubmit'],
                    null,
                    null,
                    null,
                    true
                ),
                'giftWrapping' => $this->context->link->getModuleLink(
                    $this->name,
                    'giftwrapping',
                    ['ajax' => 1, 'action' => 'opcGiftWrapping'],
                    null,
                    null,
                    null,
                    true
                ),
                'cartTotals' => $this->context->link->getModuleLink(
                    $this->name,
                    'carttotals',
                    ['ajax' => 1, 'action' => 'opcCartTotals'],
                    null,
                    null,
                    null,
                    true
                ),
            ],
            'messages' => [
                'missingGuestInitUrl' => $this->trans('Unable to initialize checkout customer.', [], ModuleTranslation::SHOP_DOMAIN),
                'missingAddressFormUrl' => $this->trans('Unable to refresh addresses.', [], ModuleTranslation::SHOP_DOMAIN),
                'loadCarriersFailed' => $this->trans('Unable to load delivery methods.', [], ModuleTranslation::SHOP_DOMAIN),
                'missingCarrierSelectionPayload' => $this->trans('Missing delivery option.', [], ModuleTranslation::SHOP_DOMAIN),
                'selectCarrierFailed' => $this->trans('Unable to select the delivery method.', [], ModuleTranslation::SHOP_DOMAIN),
                'loadPaymentMethodsFailed' => $this->trans('Unable to load payment methods.', [], ModuleTranslation::SHOP_DOMAIN),
                'missingPaymentSelectionPayload' => $this->trans('Missing payment selection payload.', [], ModuleTranslation::SHOP_DOMAIN),
                'selectPaymentFailed' => $this->trans('Unable to select the payment method.', [], ModuleTranslation::SHOP_DOMAIN),
                'statesLoadFailed' => $this->trans('Unable to load states.', [], ModuleTranslation::SHOP_DOMAIN),
                'missingSaveAddressUrl' => $this->trans('Unable to save address.', [], ModuleTranslation::SHOP_DOMAIN),
                'saveAddressFailed' => $this->trans('Unable to save address.', [], ModuleTranslation::SHOP_DOMAIN),
                'missingDeleteAddressUrl' => $this->trans('Unable to delete address.', [], ModuleTranslation::SHOP_DOMAIN),
                'deleteAddressFailed' => $this->trans('Unable to delete address.', [], ModuleTranslation::SHOP_DOMAIN),
                'refreshAddressesFailed' => $this->trans('Unable to refresh addresses.', [], ModuleTranslation::SHOP_DOMAIN),
                'missingPaymentForm' => $this->trans('Unable to initialize the selected payment method.', [], ModuleTranslation::SHOP_DOMAIN),
                'missingSubmitUrl' => $this->trans('Unable to submit checkout.', [], ModuleTranslation::SHOP_DOMAIN),
                'submitFailed' => $this->trans('Unable to submit checkout.', [], ModuleTranslation::SHOP_DOMAIN),
            ],
        ];

        $this->addOpcJavascriptDefinition([
            'ps_onepagecheckout' => $opcRuntimeConfiguration,
        ]);

        $this->registerOpcJavascriptAssets();
        $this->registerOpcStylesheets();
    }

    public function hookActionFrontControllerSetVariables(array $params): void
    {
        if (!isset($this->context->controller) || !$this->context->controller instanceof OrderController) {
            return;
        }

        if (!isset($params['templateVars']) || !is_array($params['templateVars'])) {
            return;
        }

        $params['templateVars']['is_one_page_checkout_enabled'] = $this->isOnePageCheckoutEnabled();
    }

    public function hookDisplayOverrideTemplate(array $params)
    {
        if (
            $this->isOnePageCheckoutEnabled()
            && $params['template_file'] === 'checkout/checkout'
            && $params['controller'] instanceof OrderController
        ) {
            return 'module:ps_onepagecheckout/views/templates/front/checkout/checkout.tpl';
        }

        return null;
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

        $this->context->controller->registerJavascript(
            'module-ps-onepagecheckout-submit',
            'modules/' . $this->name . '/views/public/opc-submit.bundle.js',
            [
                'position' => 'bottom',
                'priority' => 149,
            ]
        );

        foreach ([
            ['module-ps-onepagecheckout-address-modal', 'views/public/opc-address-modal.bundle.js', 152],
            ['module-ps-onepagecheckout-carriers', 'views/public/opc-carrier-list.bundle.js', 153],
            ['module-ps-onepagecheckout-select-carrier', 'views/public/opc-carrier-select.bundle.js', 154],
            ['module-ps-onepagecheckout-payment-methods', 'views/public/opc-payment-list.bundle.js', 155],
            ['module-ps-onepagecheckout-select-payment', 'views/public/opc-payment-select.bundle.js', 156],
            ['module-ps-onepagecheckout-gift-wrapping', 'views/public/opc-gift-wrapping.bundle.js', 157],
            ['module-ps-onepagecheckout-cart-summary-state', 'views/public/opc-cart-summary-state.bundle.js', 158],
        ] as [$id, $path, $priority]) {
            $this->context->controller->registerJavascript(
                $id,
                'modules/' . $this->name . '/' . $path,
                [
                    'position' => 'bottom',
                    'priority' => $priority,
                ]
            );
        }
    }

    protected function addOpcJavascriptDefinition(array $javascriptDefinition): void
    {
        Media::addJsDef($javascriptDefinition);
    }

    protected function registerOpcStylesheets(): void
    {
        if (!isset($this->context->controller)) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'module-ps-onepagecheckout',
            'modules/' . $this->name . '/views/public/one-page-checkout.css',
            [
                'media' => 'all',
                'priority' => 200,
            ]
        );
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
