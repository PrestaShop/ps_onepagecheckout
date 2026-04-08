<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutProcessBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

class PsOnepagecheckoutModuleTest extends TestCase
{
    public function testInstallInitializesDisabledFlagRegistersHooksAndCallsParentInstall(): void
    {
        $module = $this->createModule();

        $result = $module->install();

        self::assertTrue($result);
        self::assertSame(1, $module->installInParentCalls);
        self::assertSame(1, $module->installConfigurationCalls);
        self::assertSame(1, $module->initializeProviderCalls);
        self::assertSame([
            'actionCheckoutBuildProcess',
            'actionFrontControllerSetMedia',
            'actionAdminControllerSetMedia',
            'actionFrontControllerSetVariables',
        ], $module->registerHookCalls);
    }

    public function testUninstallRemovesFlagAndCallsParentUninstall(): void
    {
        $module = $this->createModule();

        $result = $module->uninstall();

        self::assertTrue($result);
        self::assertSame(1, $module->clearProviderForCurrentModuleCalls);
        self::assertSame(1, $module->uninstallInParentCalls);
    }

    public function testDisableDisablesFlagForCurrentContextAndCallsParentDisable(): void
    {
        $module = $this->createModule();

        $result = $module->disable();

        self::assertTrue($result);
        self::assertSame(1, $module->disableCurrentContextCalls);
        self::assertSame(1, $module->clearProviderCalls);
        self::assertSame([false], $module->disableInParentCalls);
    }

    public function testDisablePassesForceAllToParentDisable(): void
    {
        $module = $this->createModule();

        $result = $module->disable(true);

        self::assertTrue($result);
        self::assertSame(1, $module->disableCurrentContextCalls);
        self::assertSame(1, $module->clearProviderCalls);
        self::assertSame([true], $module->disableInParentCalls);
    }

    public function testEnableReinitializesProviderAndCallsParentEnable(): void
    {
        $module = $this->createModule();

        $result = $module->enable();

        self::assertTrue($result);
        self::assertSame([false], $module->enableInParentCalls);
        self::assertSame(1, $module->initializeProviderCalls);
    }

    public function testEnablePassesForceAllToParentEnable(): void
    {
        $module = $this->createModule();

        $result = $module->enable(true);

        self::assertTrue($result);
        self::assertSame([true], $module->enableInParentCalls);
        self::assertSame(1, $module->initializeProviderCalls);
    }

    public function testEnableStopsWhenParentEnableFails(): void
    {
        $module = $this->createModule();
        $module->enableInParentResult = false;

        $result = $module->enable();

        self::assertFalse($result);
        self::assertSame([false], $module->enableInParentCalls);
        self::assertSame(0, $module->initializeProviderCalls);
    }

    public function testDisableStopsWhenCurrentContextDisableFails(): void
    {
        $module = $this->createModule();
        $module->disableCurrentContextResult = false;

        $result = $module->disable();

        self::assertFalse($result);
        self::assertSame(1, $module->disableCurrentContextCalls);
        self::assertSame(0, $module->clearProviderCalls);
        self::assertSame([], $module->disableInParentCalls);
    }

    public function testHookActionCheckoutBuildProcessReturnsModuleProcess(): void
    {
        $module = $this->createModule();
        $module->isEnabled = true;

        $builder = $this->getMockBuilder(OnePageCheckoutProcessBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build'])
            ->getMock();
        $checkoutSession = $this->getMockBuilder(\CheckoutSession::class)
            ->disableOriginalConstructor()
            ->getMock();
        $translator = $this->createMock(TranslatorInterface::class);
        $checkoutProcess = $this->getMockBuilder(\CheckoutProcess::class)
            ->disableOriginalConstructor()
            ->getMock();

        $builder
            ->expects($this->once())
            ->method('build')
            ->with($checkoutSession, $translator)
            ->willReturn($checkoutProcess);
        $module->checkoutProcessBuilder = $builder;

        $result = $module->hookActionCheckoutBuildProcess([
            'checkoutSession' => $checkoutSession,
            'translator' => $translator,
        ]);

        self::assertSame($checkoutProcess, $result);
    }

    public function testHookActionCheckoutBuildProcessReturnsNullOnInvalidPayload(): void
    {
        $module = $this->createModule();
        $module->isEnabled = true;

        $module->checkoutProcessBuilder = $this->getMockBuilder(OnePageCheckoutProcessBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build'])
            ->getMock();
        $module->checkoutProcessBuilder
            ->expects($this->never())
            ->method('build');

        $result = $module->hookActionCheckoutBuildProcess([
            'checkoutSession' => new \stdClass(),
            'translator' => $this->createMock(TranslatorInterface::class),
        ]);

        self::assertNull($result);
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

    public function testHookActionAdminControllerSetMediaBootstrapsPhpSegmentOnConfigurationPage(): void
    {
        $_GET['configure'] = 'ps_onepagecheckout';
        $module = $this->createModule();
        $module->name = 'ps_onepagecheckout';
        $module->setModuleContext((object) [
            'controller' => (object) ['php_self' => 'adminmodules'],
        ]);

        $module->hookActionAdminControllerSetMedia();

        self::assertSame(1, $module->bootstrapPhpSegmentClientCalls);
        self::assertCount(0, $module->javascriptDefinitions);
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
    public int $initializeProviderCalls = 0;
    public bool $initializeProviderResult = true;

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
    public int $bootstrapPhpSegmentClientCalls = 0;
    public bool $isEnabled = true;
    public int $disableCurrentContextCalls = 0;
    public bool $disableCurrentContextResult = true;
    public int $clearProviderCalls = 0;
    public bool $clearProviderResult = true;
    public bool $enableInParentResult = true;
    public bool $disableInParentResult = true;
    public int $clearProviderForCurrentModuleCalls = 0;
    public bool $clearProviderForCurrentModuleResult = true;
    public int $uninstallInParentCalls = 0;
    public bool $uninstallInParentResult = true;

    public ?OnePageCheckoutProcessBuilder $checkoutProcessBuilder = null;

    public function __construct()
    {
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

    protected function createCheckoutProcessBuilder(): OnePageCheckoutProcessBuilder
    {
        if ($this->checkoutProcessBuilder instanceof OnePageCheckoutProcessBuilder) {
            return $this->checkoutProcessBuilder;
        }

        throw new \RuntimeException('Checkout process builder test double is not configured.');
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

    protected function initializeCheckoutProcessProviderConfiguration(): bool
    {
        ++$this->initializeProviderCalls;

        return $this->initializeProviderResult;
    }


    protected function disableOnePageCheckoutConfigurationForCurrentContext(): bool
    {
        ++$this->disableCurrentContextCalls;

        return $this->disableCurrentContextResult;
    }

    protected function clearCheckoutProcessProviderConfigurationForCurrentContext(): bool
    {
        ++$this->clearProviderCalls;

        return $this->clearProviderResult;
    }

    protected function clearCheckoutProcessProviderConfigurationForCurrentModule(): bool
    {
        ++$this->clearProviderForCurrentModuleCalls;

        return $this->clearProviderForCurrentModuleResult;
    }

    protected function addOpcJavascriptDefinition(array $javascriptDefinition): void
    {
        $this->javascriptDefinitions[] = $javascriptDefinition;
    }

    protected function registerOpcJavascriptAssets(): void
    {
        ++$this->registeredJavascriptAssetsCalls;
    }

    protected function bootstrapPhpSegmentClient(): void
    {
        ++$this->bootstrapPhpSegmentClientCalls;
        parent::bootstrapPhpSegmentClient();
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
