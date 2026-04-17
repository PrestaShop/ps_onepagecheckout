<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

class AdminPsOnePageCheckoutControllerTest extends TestCase
{
    public function testItReturnsConfigurationContentWhenModuleIsPsOnepagecheckout(): void
    {
        $controller = new TestAdminPsOnePageCheckoutController();
        $module = $this->getMockBuilder(\Ps_Onepagecheckout::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBackOfficeConfigurationContent'])
            ->getMock();
        $module
            ->expects($this->once())
            ->method('getBackOfficeConfigurationContent')
            ->willReturn('<form>opc</form>');

        $controller->module = $module;

        self::assertSame('<form>opc</form>', $controller->callGetBackOfficeConfigurationContent());
    }

    public function testItReturnsEmptyStringWhenModuleIsNotPsOnepagecheckout(): void
    {
        $controller = new TestAdminPsOnePageCheckoutController();
        $controller->module = new \stdClass();

        self::assertSame('', $controller->callGetBackOfficeConfigurationContent());
    }

    public function testViewAccessRequiresLegacyViewAndModuleConfigurePermission(): void
    {
        $controller = new TestAdminPsOnePageCheckoutController();
        $controller->legacyViewAccess = true;
        $controller->moduleConfigurePermission = true;

        self::assertTrue($controller->viewAccess());

        $controller->moduleConfigurePermission = false;
        self::assertFalse($controller->viewAccess());

        $controller->legacyViewAccess = false;
        $controller->moduleConfigurePermission = true;
        self::assertFalse($controller->viewAccess());
    }
}

class TestAdminPsOnePageCheckoutController extends \AdminPsOnePageCheckoutController
{
    public bool $legacyViewAccess = true;
    public bool $moduleConfigurePermission = true;

    public function __construct()
    {
    }

    public function callGetBackOfficeConfigurationContent(): string
    {
        return $this->getBackOfficeConfigurationContent();
    }

    protected function hasLegacyViewAccess(bool $disable = false): bool
    {
        return $this->legacyViewAccess;
    }

    protected function hasModuleConfigurePermission(): bool
    {
        return $this->moduleConfigurePermission;
    }
}
