<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Module;

use Module;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

/**
 * These tests mutate module installation state and related caches.
 *
 * @group isolatedProcess
 */
class ModuleLifecycleIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    private const MODULE_NAME = 'ps_onepagecheckout';
    private const CONFIG_ONE_PAGE_CHECKOUT_ENABLED = 'PS_ONE_PAGE_CHECKOUT_ENABLED';
    private const CONFIG_CHECKOUT_PROCESS_PROVIDER_MODULE = 'PS_CHECKOUT_PROCESS_PROVIDER_MODULE';

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        self::getContext()->employee = new \Employee(1);

        DatabaseDump::restoreTables([
            'access',
            'authorization_role',
            'configuration',
            'hook_module',
            'hook_module_exceptions',
            'module',
            'module_access',
            'module_shop',
        ]);

        \Configuration::loadConfiguration();
        \Module::updateTranslationsAfterInstall(false);
    }

    public function testInstallDisableEnableAndUninstallKeepCheckoutProviderStateConsistent(): void
    {
        $module = $this->buildModule();

        self::assertTrue($module->install());
        self::assertTrue(\Module::isInstalled(self::MODULE_NAME));
        self::assertTrue(\Module::isEnabled(self::MODULE_NAME));
        self::assertSame('1', (string) \Configuration::get(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED));
        self::assertSame(self::MODULE_NAME, trim((string) \Configuration::get(self::CONFIG_CHECKOUT_PROCESS_PROVIDER_MODULE)));

        $module = $this->buildModule();
        self::assertTrue($module->disable());
        \Configuration::loadConfiguration();

        self::assertFalse(\Module::isEnabled(self::MODULE_NAME));
        self::assertSame('0', (string) \Configuration::get(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED));
        self::assertSame('', trim((string) \Configuration::get(self::CONFIG_CHECKOUT_PROCESS_PROVIDER_MODULE)));

        $module = $this->buildModule();
        self::assertTrue($module->enable());
        \Configuration::loadConfiguration();

        self::assertTrue(\Module::isEnabled(self::MODULE_NAME));
        self::assertSame(self::MODULE_NAME, trim((string) \Configuration::get(self::CONFIG_CHECKOUT_PROCESS_PROVIDER_MODULE)));
        self::assertSame('0', (string) \Configuration::get(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED));

        $module = $this->buildModule();
        self::assertTrue($module->uninstall());
        \Configuration::loadConfiguration();

        self::assertFalse(\Module::isInstalled(self::MODULE_NAME));
        self::assertSame('0', (string) \Configuration::get(self::CONFIG_ONE_PAGE_CHECKOUT_ENABLED));
        self::assertSame('', trim((string) \Configuration::get(self::CONFIG_CHECKOUT_PROCESS_PROVIDER_MODULE)));
    }

    private function buildModule(): \Ps_Onepagecheckout
    {
        \Module::resetStaticCache();

        return new \Ps_Onepagecheckout();
    }

    protected function tearDown(): void
    {
        \Module::updateTranslationsAfterInstall(true);

        parent::tearDown();
    }
}
