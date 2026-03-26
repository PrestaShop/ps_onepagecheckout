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
use PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter;

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

        $response = $controller->callHandleAddressFormRefresh();

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

    private function createControllerContext(): \Context
    {
        $smarty = $this->getMockBuilder(\Smarty::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['assign', 'getTemplateVars'])
            ->getMock();
        $smarty
            ->expects($this->once())
            ->method('assign')
            ->with('customer', $this->callback(static function (array $customer): bool {
                return array_key_exists('is_logged', $customer)
                    && array_key_exists('is_guest', $customer)
                    && array_key_exists('firstname', $customer)
                    && array_key_exists('lastname', $customer)
                    && array_key_exists('gender', $customer)
                    && array_key_exists('risk', $customer)
                    && array_key_exists('addresses', $customer)
                    && $customer['is_logged'] === false
                    && $customer['is_guest'] === true
                    && $customer['firstname'] === 'Alice'
                    && $customer['lastname'] === 'Doe'
                    && is_array($customer['addresses']);
            }))
        ;
        $smarty
            ->method('getTemplateVars')
            ->with('customer')
            ->willReturn([])
        ;

        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->smarty = $smarty;
        $context->customer = new class extends \Customer {
            public function __construct()
            {
            }

            public function getSimpleAddresses($idLang = null)
            {
                return [
                    [
                        'id' => 0,
                        'alias' => 'Home',
                    ],
                ];
            }

            public function isGuest()
            {
                return true;
            }

            public function isLogged($withGuest = false)
            {
                return false;
            }
        };
        $context->customer->id = 42;
        $context->customer->firstname = 'Alice';
        $context->customer->lastname = 'Doe';
        $context->customer->id_gender = 0;
        $context->customer->id_risk = 0;
        $context->customer->is_guest = true;
        $context->language = new class extends \Language {
            public function __construct()
            {
            }
        };
        $context->language->id = 1;

        return $context;
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

    public function callHandleAddressFormRefresh(): array
    {
        return $this->handleAddressFormRefresh();
    }

    public function setTestContext(\Context $context): void
    {
        $this->context = $context;
        $this->objectPresenter = new ObjectPresenter();
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
