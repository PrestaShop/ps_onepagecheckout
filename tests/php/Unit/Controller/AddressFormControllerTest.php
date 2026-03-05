<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Controller;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnepagecheckout\Checkout\Ajax\OpcAddressFormHandler;
use PrestaShop\Module\PsOnepagecheckout\Form\OnePageCheckoutFormFactory;

class AddressFormControllerTest extends TestCase
{
    public function testHandleAddressFormRefreshReturnsTechnicalErrorWhenModuleIsDisabled(): void
    {
        $controller = new TestAddressFormController();
        $controller->module = $this->createDisabledModule();

        $response = $controller->callHandleAddressFormRefresh();

        self::assertFalse($response['success']);
        self::assertSame('technical-error', $response['error']);
    }

    public function testHandleAddressFormRefreshReturnsRenderedPartialWhenEnabled(): void
    {
        $controller = new TestAddressFormController();
        $controller->module = $this->createEnabledModule();

        $handler = $this->getMockBuilder(OpcAddressFormHandler::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTemplateVariables'])
            ->getMock();
        $handler
            ->expects($this->once())
            ->method('getTemplateVariables')
            ->willReturn([
                'firstname' => 'Alice',
                'lastname' => 'Doe',
            ]);

        $controller->addressFormHandler = $handler;
        $controller->opcFormFactory = $this->getMockBuilder(OnePageCheckoutFormFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $response = $controller->callHandleAddressFormRefresh();

        self::assertSame('rendered:checkout/_partials/one-page-checkout-form', $response['address_form']);
    }

    public function testHandleAddressFormRefreshReturnsTechnicalErrorOnRuntimeException(): void
    {
        $controller = new TestAddressFormController();
        $controller->module = $this->createEnabledModule();
        $controller->throwOnCreateHandler = true;
        $controller->opcFormFactory = $this->getMockBuilder(OnePageCheckoutFormFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        set_error_handler(static function (): bool {
            return true;
        }, E_WARNING);
        try {
            $response = $controller->callHandleAddressFormRefresh();
        } finally {
            restore_error_handler();
        }

        self::assertFalse($response['success']);
        self::assertSame('technical-error', $response['error']);
    }

    private function createEnabledModule(): \Ps_Onepagecheckout
    {
        return new EnabledPsOnepagecheckoutModuleForAddressForm();
    }

    private function createDisabledModule(): \Ps_Onepagecheckout
    {
        return new DisabledPsOnepagecheckoutModuleForAddressForm();
    }
}

class TestAddressFormController extends \Ps_OnepagecheckoutAddressFormModuleFrontController
{
    public ?OpcAddressFormHandler $addressFormHandler = null;
    public ?OnePageCheckoutFormFactory $opcFormFactory = null;
    public bool $throwOnCreateHandler = false;

    public function __construct()
    {
    }

    public function callHandleAddressFormRefresh(): array
    {
        return $this->handleAddressFormRefresh();
    }

    protected function createAddressFormHandler(OnePageCheckoutFormFactory $opcFormFactory): OpcAddressFormHandler
    {
        if ($this->throwOnCreateHandler) {
            throw new \RuntimeException('address form handler creation failed');
        }

        if (!$this->addressFormHandler instanceof OpcAddressFormHandler) {
            throw new \RuntimeException('address form handler not configured');
        }

        return $this->addressFormHandler;
    }

    protected function getOpcFormFactory(): OnePageCheckoutFormFactory
    {
        if (!$this->opcFormFactory instanceof OnePageCheckoutFormFactory) {
            throw new \RuntimeException('OPC form factory not configured');
        }

        return $this->opcFormFactory;
    }

    protected function buildTechnicalErrorResponse(): array
    {
        return [
            'success' => false,
            'error' => 'technical-error',
        ];
    }

    protected function render($template, array $parameters = [])
    {
        return sprintf('rendered:%s', (string) $template);
    }
}

class EnabledPsOnepagecheckoutModuleForAddressForm extends \Ps_Onepagecheckout
{
    public function __construct()
    {
    }

    public function isOnePageCheckoutEnabled(): bool
    {
        return true;
    }
}

class DisabledPsOnepagecheckoutModuleForAddressForm extends \Ps_Onepagecheckout
{
    public function __construct()
    {
    }

    public function isOnePageCheckoutEnabled(): bool
    {
        return false;
    }
}
