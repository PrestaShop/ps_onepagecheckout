<?php

/**
 * Register ps_onepagecheckout PSR-4 namespace in the existing Composer autoloader for test suites.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$loader = null;

foreach ((array) spl_autoload_functions() as $autoloadFunction) {
    if (!is_array($autoloadFunction)) {
        continue;
    }

    if (($autoloadFunction[0] ?? null) instanceof ClassLoader) {
        $loader = $autoloadFunction[0];
        break;
    }
}

if (!$loader) {
    $autoloadFile = _PS_ROOT_DIR_ . '/vendor/autoload.php';
    if (is_file($autoloadFile)) {
        $candidate = require $autoloadFile;
        if ($candidate instanceof ClassLoader) {
            $loader = $candidate;
        }
    }
}

if (!$loader) {
    throw new RuntimeException('Unable to locate a Composer autoloader for ps_onepagecheckout tests.');
}

$loader->addPsr4('PrestaShop\\Module\\PsOnePageCheckout\\', _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src');
$loader->addClassMap([
    'Ps_Onepagecheckout' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/ps_onepagecheckout.php',
    'AdminPsOnePageCheckoutController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/admin/AdminPsOnePageCheckoutController.php',
    'Ps_OnepagecheckoutGuestInitModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/guestinit.php',
    'Ps_OnepagecheckoutAddressFormModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/addressform.php',
    'Ps_OnepagecheckoutAddressModalModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/addressmodal.php',
    'Ps_OnepagecheckoutAddressesListModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/addresseslist.php',
    'Ps_OnepagecheckoutStatesModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/states.php',
    'Ps_OnepagecheckoutSaveAddressModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/saveaddress.php',
    'Ps_OnepagecheckoutDeleteAddressModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/deleteaddress.php',
    'Ps_OnepagecheckoutCarriersModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/carriers.php',
    'Ps_OnepagecheckoutSelectCarrierModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/selectcarrier.php',
    'Ps_OnepagecheckoutPaymentMethodsModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/paymentmethods.php',
    'Ps_OnepagecheckoutSelectPaymentModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/selectpayment.php',
    'Ps_OnepagecheckoutOpcSubmitModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/opcsubmit.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\CheckoutAjaxResponse' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Shared/CheckoutAjaxResponse.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\CartPresenterHelper' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Shared/CartPresenterHelper.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\CheckoutSessionFactory' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Shared/CheckoutSessionFactory.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\CheckoutCustomerContextResolver' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Customer/CheckoutCustomerContextResolver.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\CheckoutCustomerTemplateBuilder' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Customer/CheckoutCustomerTemplateBuilder.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutGuestInitHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Customer/OnePageCheckoutGuestInitHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutAddressFormHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Address/OnePageCheckoutAddressFormHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutAddressModalFieldsHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Address/OnePageCheckoutAddressModalFieldsHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutAddressesListHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Address/OnePageCheckoutAddressesListHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutSaveAddressHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Address/OnePageCheckoutSaveAddressHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutDeleteAddressHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Address/OnePageCheckoutDeleteAddressHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutStatesHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Address/OnePageCheckoutStatesHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OpcTempAddress' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Address/OpcTempAddress.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutCarriersHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Carrier/OnePageCheckoutCarriersHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutSelectCarrierHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Carrier/OnePageCheckoutSelectCarrierHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\TempAddressCarrierSelectionStorage' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Carrier/TempAddressCarrierSelectionStorage.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutPaymentMethodsHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Payment/OnePageCheckoutPaymentMethodsHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\OnePageCheckoutSelectPaymentHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Payment/OnePageCheckoutSelectPaymentHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\Submit\\OnePageCheckoutSubmitHandler' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Submit/OnePageCheckoutSubmitHandler.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\Submit\\OnePageCheckoutSubmitProcessor' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Submit/OnePageCheckoutSubmitProcessor.php',
    'PrestaShop\\Module\\PsOnePageCheckout\\Checkout\\Ajax\\Submit\\OnePageCheckoutSubmitValidationStateStorage' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src/Checkout/Ajax/Submit/OnePageCheckoutSubmitValidationStateStorage.php',
]);
