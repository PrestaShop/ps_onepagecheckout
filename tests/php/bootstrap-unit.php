<?php

use PrestaShop\PrestaShop\Adapter\AddressFactory;
use PrestaShop\PrestaShop\Adapter\ServiceLocator;
use PrestaShop\PrestaShop\Core\Foundation\IoC\Container;
use Tests\PHP\Mocks\LegacyConfiguration;
use Tests\PHP\Mocks\LegacyEntityMapper;

require __DIR__ . '/../../../../tests/Unit/bootstrap.php';
require_once __DIR__ . '/Mocks/bootstrap.php';
require_once __DIR__ . '/Fixtures/CheckoutProcessProviderInterfaceStub.php';
require_once __DIR__ . '/Fixtures/LegacyFormStubs.php';
require_once __DIR__ . '/Fixtures/CheckoutTestFixtures.php';
require __DIR__ . '/bootstrap-autoload.php';

$legacyTestContainer = new Container();
$legacyTestContainer->bind('\\PrestaShop\\PrestaShop\\Adapter\\EntityMapper', new LegacyEntityMapper(), true);
$legacyTestContainer->bind('\\PrestaShop\\PrestaShop\\Core\\ConfigurationInterface', new LegacyConfiguration(), true);
$legacyTestContainer->bind('\\PrestaShop\\PrestaShop\\Adapter\\AddressFactory', new AddressFactory(), true);

ServiceLocator::setServiceContainerInstance($legacyTestContainer);
