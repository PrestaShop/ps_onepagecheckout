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

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\AddressDraftStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
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
        $this->version = '0.6.3';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $tabNames = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tabNames[$lang['locale']] = $this->trans('Checkout', [], 'Modules.Onepagecheckout.Admin', $lang['locale']);
        }
        $this->tabs = [
            [
                'class_name' => 'AdminPsOnePageCheckout',
                'visible' => true,
                'name' => $tabNames,
                'parent_class_name' => 'AdminParentThemes',
                'wording' => 'Checkout',
                'wording_domain' => 'Modules.Onepagecheckout.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('One-page checkout', [], 'Modules.Onepagecheckout.Admin');
        $this->description = $this->trans(
            'Native one-page checkout.',
            [],
            'Modules.Onepagecheckout.Admin'
        );
        $this->ps_versions_compliancy = ['min' => '9.2.0', 'max' => _PS_VERSION_];
        $this->controllers = [
            'guestinit',
            'addressform',
            'addresseslist',
            'states',
            'saveaddress',
            'selectaddress',
            'deleteaddress',
            'carriers',
            'selectcarrier',
            'paymentmethods',
            'selectpayment',
            'opcsubmit',
        ];
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install()
    {
        return $this->installInParent()
            && $this->installOnePageCheckoutConfiguration()
            && $this->registerHook('actionCheckoutBuildProcess')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionFrontControllerSetVariables')
            && $this->registerHook('actionCustomerLogoutAfter')
            && $this->registerHook('displayOverrideTemplate');
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
        if (!isset($this->context->controller) || !$this->context->controller instanceof OrderController) {
            return;
        }

        $isOnePageCheckoutEnabled = $this->isOnePageCheckoutEnabled();
        $this->context->smarty->assign('is_one_page_checkout_enabled', $isOnePageCheckoutEnabled);

        if (!$isOnePageCheckoutEnabled) {
            return;
        }

        $guestCheckoutEnabled = (bool) Configuration::get('PS_GUEST_CHECKOUT_ENABLED');

        if (
            !$guestCheckoutEnabled
            && $this->context->customer
            && !$this->context->customer->isLogged()
        ) {
            $backParams = [];
            $orderPageUrl = $this->context->link->getPageLink('order');

            if (Tools::urlBelongsToShop($orderPageUrl)) {
                $backParams = ['back' => $orderPageUrl];
            }

            Tools::redirect($this->context->link->getPageLink('authentication', null, null, $backParams));
        }

        $checkoutCustomerContextResolver = new CheckoutCustomerContextResolver($this->context);

        $opcRuntimeConfiguration = [
            'enabled' => true,
            'taxAddressType' => (string) Configuration::get('PS_TAX_ADDRESS_TYPE'),
            // Customers with no saved address yet have no saved address to start from, so their
            // typed address is autosaved to a cookie draft. The same autosave saves it as a real
            // address attached to the cart once it is complete and valid and a customer exists.
            // Once an address is saved, the saved-address flow takes over on the next page load.
            'persistAddressDraft' => !$checkoutCustomerContextResolver->hasSavedAddress(),
            // Server-rendered identity when a checkout customer is already attached to the cart
            // (a logged-in customer, or a guest whose guest-init created one). The option sections gate
            // their reveal on an identified buyer; this lets a returning/reloading guest stay identified
            // even though the re-rendered contact step shows an empty email field and a reset consent
            // box — their address/carrier are already persisted to the cart, so withholding the options
            // behind the contact step again would be wrong.
            'buyerIdentified' => $checkoutCustomerContextResolver->resolveId() > 0,
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
                'addressModal' => $this->context->link->getModuleLink(
                    $this->name,
                    'addressmodal',
                    ['ajax' => 1, 'action' => 'opcAddressModal'],
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
                'saveDraft' => $this->context->link->getModuleLink(
                    $this->name,
                    'savedraft',
                    ['ajax' => 1, 'action' => 'opcSaveDraft'],
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
                'selectAddress' => $this->context->link->getModuleLink(
                    $this->name,
                    'selectaddress',
                    ['ajax' => 1, 'action' => 'opcSelectAddress'],
                    null,
                    null,
                    null,
                    true
                ),
            ],
            'messages' => [
                'missingGuestInitUrl' => $this->trans('Unable to initialize checkout customer.', [], 'Modules.Onepagecheckout.Shop'),
                'missingAddressFormUrl' => $this->trans('Unable to refresh addresses.', [], 'Modules.Onepagecheckout.Shop'),
                'loadCarriersFailed' => $this->trans('Unable to load delivery methods.', [], 'Modules.Onepagecheckout.Shop'),
                'missingCarrierSelectionPayload' => $this->trans('Missing delivery option.', [], 'Modules.Onepagecheckout.Shop'),
                'missingCarrierSelection' => $this->trans('Please select a delivery method.', [], 'Modules.Onepagecheckout.Shop'),
                'selectCarrierFailed' => $this->trans('Unable to select the delivery method.', [], 'Modules.Onepagecheckout.Shop'),
                'loadPaymentMethodsFailed' => $this->trans('Unable to load payment methods.', [], 'Modules.Onepagecheckout.Shop'),
                'missingPaymentSelectionPayload' => $this->trans('Missing payment selection payload.', [], 'Modules.Onepagecheckout.Shop'),
                'missingPaymentSelection' => $this->trans('Please select a payment method.', [], 'Modules.Onepagecheckout.Shop'),
                'selectPaymentFailed' => $this->trans('Unable to select the payment method.', [], 'Modules.Onepagecheckout.Shop'),
                'statesLoadFailed' => $this->trans('Unable to load states.', [], 'Modules.Onepagecheckout.Shop'),
                'addressFieldsLoadFailed' => $this->trans('Unable to load address fields.', [], 'Modules.Onepagecheckout.Shop'),
                'addressFieldsInvalid' => $this->trans('Please correct the highlighted address fields.', [], 'Modules.Onepagecheckout.Shop'),
                'awaitingAddressMissingFields' => $this->trans('Still needed:', [], 'Modules.Onepagecheckout.Shop'),
                'awaitingAddressNeedsContact' => $this->trans('Enter your email address above to see the delivery and payment options.', [], 'Modules.Onepagecheckout.Shop'),
                'awaitingAddressNeedsConsent' => $this->trans('Please accept the required terms above to see the delivery and payment options.', [], 'Modules.Onepagecheckout.Shop'),
                'invalidEmail' => $this->trans('Please enter a valid email address.', [], 'Modules.Onepagecheckout.Shop'),
                'emailMissingAt' => $this->trans('The email address is missing an "@" (e.g. name@example.com).', [], 'Modules.Onepagecheckout.Shop'),
                'emailMissingDomain' => $this->trans('The email address is missing the part after the "@" (e.g. name@example.com).', [], 'Modules.Onepagecheckout.Shop'),
                'emailMissingLocalPart' => $this->trans('The email address is missing the part before the "@" (e.g. name@example.com).', [], 'Modules.Onepagecheckout.Shop'),
                'awaitingAddressPersistFailed' => $this->trans("We couldn't save your delivery address. Please check the fields above and try again.", [], 'Modules.Onepagecheckout.Shop'),
                'missingSaveAddressUrl' => $this->trans('Unable to save address.', [], 'Modules.Onepagecheckout.Shop'),
                'saveAddressFailed' => $this->trans('Unable to save address.', [], 'Modules.Onepagecheckout.Shop'),
                'missingDeleteAddressUrl' => $this->trans('Unable to delete address.', [], 'Modules.Onepagecheckout.Shop'),
                'deleteAddressFailed' => $this->trans('Unable to delete address.', [], 'Modules.Onepagecheckout.Shop'),
                'refreshAddressesFailed' => $this->trans('Unable to refresh addresses.', [], 'Modules.Onepagecheckout.Shop'),
                'missingPaymentForm' => $this->trans('Unable to initialize the selected payment method.', [], 'Modules.Onepagecheckout.Shop'),
                'missingSubmitUrl' => $this->trans('Unable to submit checkout.', [], 'Modules.Onepagecheckout.Shop'),
                'submitFailed' => $this->trans('Unable to submit checkout.', [], 'Modules.Onepagecheckout.Shop'),
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

    public function hookActionCustomerLogoutAfter(array $params): void
    {
        // Drop any address draft so personal data does not survive a logout on a shared device.
        (new AddressDraftStorage($this->context))->clear();
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
