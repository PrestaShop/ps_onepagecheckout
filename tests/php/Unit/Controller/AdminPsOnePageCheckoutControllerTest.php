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
}

class TestAdminPsOnePageCheckoutController extends \AdminPsOnePageCheckoutController
{
    public function __construct()
    {
    }

    public function callGetBackOfficeConfigurationContent(): string
    {
        return $this->getBackOfficeConfigurationContent();
    }
}

