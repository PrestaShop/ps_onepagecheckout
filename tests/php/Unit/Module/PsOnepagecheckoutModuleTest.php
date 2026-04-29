<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutProcessProvider;
use PrestaShop\PrestaShop\Adapter\Order\Checkout\CheckoutProcessProviderInterface;

class PsOnepagecheckoutModuleTest extends TestCase
{
    public function testInstallInitializesDisabledFlagRegistersHooksAndCallsParentInstall(): void
    {
        $module = $this->createModule();

        $result = $module->install();

        self::assertTrue($result);
        self::assertSame(1, $module->installInParentCalls);
        self::assertSame(1, $module->installConfigurationCalls);
        self::assertSame([
            'actionCheckoutBuildProcess',
            'actionFrontControllerSetMedia',
            'actionFrontControllerSetVariables',
            'actionModuleUpgradeAfter',
        ], $module->registerHookCalls);
    }

    public function testUninstallRemovesFlagAndCallsParentUninstall(): void
    {
        $module = $this->createModule();

        $result = $module->uninstall();

        self::assertTrue($result);
        self::assertSame(1, $module->uninstallInParentCalls);
    }

    public function testDisableDisablesFlagForCurrentContextAndCallsParentDisable(): void
    {
        $module = $this->createModule();

        $result = $module->disable();

        self::assertTrue($result);
        self::assertSame(1, $module->disableCurrentContextCalls);
        self::assertSame([false], $module->disableInParentCalls);
    }

    public function testDisablePassesForceAllToParentDisable(): void
    {
        $module = $this->createModule();

        $result = $module->disable(true);

        self::assertTrue($result);
        self::assertSame(1, $module->disableCurrentContextCalls);
        self::assertSame([true], $module->disableInParentCalls);
    }

    public function testEnableCallsParentEnable(): void
    {
        $module = $this->createModule();

        $result = $module->enable();

        self::assertTrue($result);
        self::assertSame([false], $module->enableInParentCalls);
    }

    public function testEnablePassesForceAllToParentEnable(): void
    {
        $module = $this->createModule();

        $result = $module->enable(true);

        self::assertTrue($result);
        self::assertSame([true], $module->enableInParentCalls);
    }

    public function testEnableStopsWhenParentEnableFails(): void
    {
        $module = $this->createModule();
        $module->enableInParentResult = false;

        $result = $module->enable();

        self::assertFalse($result);
        self::assertSame([false], $module->enableInParentCalls);
    }

    public function testDisableStopsWhenCurrentContextDisableFails(): void
    {
        $module = $this->createModule();
        $module->disableCurrentContextResult = false;

        $result = $module->disable();

        self::assertFalse($result);
        self::assertSame(1, $module->disableCurrentContextCalls);
        self::assertSame([], $module->disableInParentCalls);
    }

    public function testHookActionCheckoutBuildProcessReturnsProvider(): void
    {
        $module = $this->createModule();

        $result = $module->hookActionCheckoutBuildProcess([]);

        self::assertInstanceOf(OnePageCheckoutProcessProvider::class, $result);
        self::assertInstanceOf(CheckoutProcessProviderInterface::class, $result);
    }

    public function testHookActionFrontControllerSetVariablesInjectsRuntimeFlagOnOrderPage(): void
    {
        $module = $this->createModule();
        $module->isEnabled = true;
        $module->setModuleContext((object) [
            'controller' => (object) ['php_self' => 'order'],
        ]);

        $templateVars = [];
        $module->hookActionFrontControllerSetVariables([
            'templateVars' => &$templateVars,
        ]);

        self::assertTrue($templateVars['is_one_page_checkout_enabled']);
    }

    public function testHookActionFrontControllerSetMediaAssignsFlagAndRegistersAssetsWhenEnabled(): void
    {
        $module = $this->createModule();
        $module->name = 'ps_onepagecheckout';
        $module->isEnabled = true;
        $module->setModuleContext((object) [
            'controller' => (object) ['php_self' => 'order'],
            'smarty' => new DummySmarty(),
            'link' => new DummyLink(),
        ]);

        $module->hookActionFrontControllerSetMedia();

        /** @var DummySmarty $smarty */
        $smarty = $module->getModuleContext()->smarty;
        self::assertTrue($smarty->assigned['is_one_page_checkout_enabled']);
        self::assertCount(1, $module->javascriptDefinitions);
        self::assertSame(1, $module->registeredJavascriptAssetsCalls);
        self::assertArrayHasKey('ps_onepagecheckout', $module->javascriptDefinitions[0]);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['guestInit']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['addressForm']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['addressesList']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['states']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['saveAddress']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['deleteAddress']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['carriers']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['selectCarrier']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['paymentMethods']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['selectPayment']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['urls']['opcSubmit']);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['messages']['selectCarrierFailed'] ?? null);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['messages']['selectPaymentFailed'] ?? null);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['messages']['statesLoadFailed'] ?? null);
        self::assertNotEmpty($module->javascriptDefinitions[0]['ps_onepagecheckout']['messages']['refreshAddressesFailed'] ?? null);
    }

    public function testHookActionFrontControllerSetMediaAssignsFlagAndSkipsAssetsWhenDisabled(): void
    {
        $module = $this->createModule();
        $module->name = 'ps_onepagecheckout';
        $module->isEnabled = false;
        $module->setModuleContext((object) [
            'controller' => (object) ['php_self' => 'order'],
            'smarty' => new DummySmarty(),
            'link' => new DummyLink(),
        ]);

        $module->hookActionFrontControllerSetMedia();

        /** @var DummySmarty $smarty */
        $smarty = $module->getModuleContext()->smarty;
        self::assertFalse($smarty->assigned['is_one_page_checkout_enabled']);
        self::assertCount(0, $module->javascriptDefinitions);
        self::assertSame(0, $module->registeredJavascriptAssetsCalls);
    }

    public function testMainModuleFileDoesNotContainCustomAutoloaderRegistration(): void
    {
        $mainFile = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/ps_onepagecheckout.php');

        self::assertStringNotContainsString('spl_autoload_functions', $mainFile);
        self::assertStringNotContainsString('addPsr4(', $mainFile);
    }

    private function createModule(): TestablePsOnepagecheckoutModule
    {
        return new TestablePsOnepagecheckoutModule();
    }
}

class TestablePsOnepagecheckoutModule extends \Ps_Onepagecheckout
{
    public int $installInParentCalls = 0;
    public bool $installInParentResult = true;
    public int $installConfigurationCalls = 0;
    public bool $installConfigurationResult = true;

    /**
     * @var list<string>
     */
    public array $registerHookCalls = [];
    public bool $registerHookResult = true;

    /**
     * @var list<bool>
     */
    public array $enableInParentCalls = [];

    /**
     * @var list<bool>
     */
    public array $disableInParentCalls = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $javascriptDefinitions = [];

    public int $registeredJavascriptAssetsCalls = 0;
    public bool $isEnabled = true;
    public int $disableCurrentContextCalls = 0;
    public bool $disableCurrentContextResult = true;
    public bool $enableInParentResult = true;
    public bool $disableInParentResult = true;
    public int $uninstallInParentCalls = 0;
    public bool $uninstallInParentResult = true;

    public function __construct()
    {
        $this->context = new \Context();
    }

    public function setModuleContext(object $context): void
    {
        $this->context = $context;
    }

    public function getModuleContext(): object
    {
        return $this->context;
    }

    public function isOnePageCheckoutEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function registerHook($hookName, $shopList = null): bool
    {
        $this->registerHookCalls[] = (string) $hookName;

        return $this->registerHookResult;
    }

    protected function installInParent(): bool
    {
        ++$this->installInParentCalls;

        return $this->installInParentResult;
    }

    protected function installOnePageCheckoutConfiguration(): bool
    {
        ++$this->installConfigurationCalls;

        return $this->installConfigurationResult;
    }

    protected function disableOnePageCheckoutConfigurationForCurrentContext(): bool
    {
        ++$this->disableCurrentContextCalls;

        return $this->disableCurrentContextResult;
    }

    protected function addOpcJavascriptDefinition(array $javascriptDefinition): void
    {
        $this->javascriptDefinitions[] = $javascriptDefinition;
    }

    protected function registerOpcJavascriptAssets(): void
    {
        ++$this->registeredJavascriptAssetsCalls;
    }

    protected function disableInParent(bool $forceAll): bool
    {
        $this->disableInParentCalls[] = $forceAll;

        return $this->disableInParentResult;
    }

    protected function enableInParent(bool $forceAll): bool
    {
        $this->enableInParentCalls[] = $forceAll;

        return $this->enableInParentResult;
    }

    protected function uninstallInParent(): bool
    {
        ++$this->uninstallInParentCalls;

        return $this->uninstallInParentResult;
    }
}

class DummySmarty
{
    /**
     * @var array<string, mixed>
     */
    public array $assigned = [];

    /**
     * @param string|array<string, mixed> $key
     * @param mixed|null $value
     */
    public function assign($key, $value = null): void
    {
        if (is_array($key)) {
            $this->assigned = array_merge($this->assigned, $key);

            return;
        }

        $this->assigned[(string) $key] = $value;
    }
}

class DummyLink
{
    /**
     * @param array<string, mixed> $params
     */
    public function getModuleLink(
        string $module,
        string $controller,
        array $params = [],
        ?bool $ssl = null,
        ?int $idLang = null,
        ?int $idShop = null,
        ?bool $relativeProtocol = null,
    ): string {
        return sprintf('/index.php?fc=module&module=%s&controller=%s', $module, $controller);
    }
}
