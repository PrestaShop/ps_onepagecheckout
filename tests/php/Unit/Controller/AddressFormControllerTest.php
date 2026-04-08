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
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressFormHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;
use Tests\Fixtures\CheckoutTestFixtures;

class AddressFormControllerTest extends TestCase
{
    public function testHandleAddressFormRefreshReturnsTechnicalErrorWhenModuleIsDisabled(): void
    {
        $controller = new TestAddressFormController();
        $controller->module = $this->createDisabledModule();

        $response = $controller->callHandleOpcRequest();

        self::assertFalse($response['success']);
        self::assertSame('technical-error', $response['error']);
    }

    public function testHandleAddressFormRefreshReturnsRenderedPartialWhenEnabled(): void
    {
        $controller = new TestAddressFormController();
        $controller->module = $this->createEnabledModule();
        $controller->setTestContext($this->createControllerContext());

        $handler = $this->getMockBuilder(OnePageCheckoutAddressFormHandler::class)
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

        $response = $controller->callHandleOpcRequest();

        self::assertSame('rendered:checkout/_partials/one-page-checkout/addresses-section', $response['addresses_section']);
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
            $response = $controller->callHandleOpcRequest();
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

    private function createControllerContext(): \Context
    {
        return CheckoutTestFixtures::context([
            'smarty' => CheckoutTestFixtures::smarty(),
            'customer' => CheckoutTestFixtures::customer(
                [
                    [
                        'id' => 0,
                        'alias' => 'Home',
                    ],
                ],
                false,
                true,
                [
                    'id' => 42,
                    'firstname' => 'Alice',
                    'lastname' => 'Doe',
                    'id_gender' => 0,
                    'id_risk' => 0,
                    'is_guest' => true,
                ]
            ),
            'language' => CheckoutTestFixtures::language(1),
            'cart' => CheckoutTestFixtures::cart(),
        ]);
    }
}

class TestAddressFormController extends \Ps_OnepagecheckoutAddressFormModuleFrontController
{
    public ?OnePageCheckoutAddressFormHandler $addressFormHandler = null;
    public ?OnePageCheckoutFormFactory $opcFormFactory = null;
    public bool $throwOnCreateHandler = false;

    public function __construct()
    {
    }

    public function callHandleOpcRequest(): array
    {
        return $this->handleOpcRequest();
    }

    public function setTestContext(\Context $context): void
    {
        $this->context = $context;
    }

    protected function createAddressFormHandler(OnePageCheckoutFormFactory $opcFormFactory): OnePageCheckoutAddressFormHandler
    {
        if ($this->throwOnCreateHandler) {
            throw new \RuntimeException('address form handler creation failed');
        }

        if (!$this->addressFormHandler instanceof OnePageCheckoutAddressFormHandler) {
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
