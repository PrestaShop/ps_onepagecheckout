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

$loader->addPsr4('PrestaShop\\Module\\PsOnepagecheckout\\', _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/src');
$loader->addClassMap([
    'Ps_Onepagecheckout' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/ps_onepagecheckout.php',
    'AdminPsOnePageCheckoutController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/admin/AdminPsOnePageCheckoutController.php',
    'AdminPsOnepagecheckoutController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/admin/AdminPsOnepagecheckoutController.php',
    'Ps_OnepagecheckoutGuestInitModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/GuestInit.php',
    'Ps_OnepagecheckoutAddressFormModuleFrontController' => _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/controllers/front/AddressForm.php',
]);
