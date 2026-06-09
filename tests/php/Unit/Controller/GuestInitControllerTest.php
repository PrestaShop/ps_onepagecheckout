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
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutGuestInitHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;

class GuestInitControllerTest extends TestCase
{
    public function testHandleGuestInitReturnsTechnicalErrorWhenModuleIsDisabled(): void
    {
        $controller = new TestGuestInitController();
        $controller->module = $this->createDisabledModule();

        $response = $controller->callHandleOpcRequest();

        self::assertFalse($response['success']);
        self::assertSame('technical-error', $response['error']);
    }

    public function testHandleGuestInitReturnsHandlerPayloadWhenModuleIsEnabled(): void
    {
        $controller = new TestGuestInitController();
        $controller->module = $this->createEnabledModule();

        $handler = $this->getMockBuilder(OnePageCheckoutGuestInitHandler::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['handle'])
            ->getMock();
        $handler
            ->expects($this->once())
            ->method('handle')
            ->willReturn([
                'success' => true,
                'customer_created' => true,
                'id_customer' => 42,
            ]);

        $controller->guestInitHandler = $handler;
        $controller->opcFormFactory = $this->getMockBuilder(OnePageCheckoutFormFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $response = $controller->callHandleOpcRequest();

        self::assertTrue($response['success']);
        self::assertSame(42, $response['id_customer']);
    }

    public function testHandleGuestInitReturnsTechnicalErrorOnRuntimeException(): void
    {
        $controller = new TestGuestInitController();
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
        return new EnabledPsOnepagecheckoutModule();
    }

    private function createDisabledModule(): \Ps_Onepagecheckout
    {
        return new DisabledPsOnepagecheckoutModule();
    }
}

class TestGuestInitController extends \Ps_OnepagecheckoutGuestInitModuleFrontController
{
    public ?OnePageCheckoutGuestInitHandler $guestInitHandler = null;
    public ?OnePageCheckoutFormFactory $opcFormFactory = null;
    public bool $throwOnCreateHandler = false;

    public function __construct()
    {
    }

    public function callHandleOpcRequest(): array
    {
        return $this->handleOpcRequest();
    }

    protected function createGuestInitHandler(OnePageCheckoutFormFactory $opcFormFactory): OnePageCheckoutGuestInitHandler
    {
        if ($this->throwOnCreateHandler) {
            throw new \RuntimeException('guest init handler creation failed');
        }

        if (!$this->guestInitHandler instanceof OnePageCheckoutGuestInitHandler) {
            throw new \RuntimeException('guest init handler not configured');
        }

        return $this->guestInitHandler;
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
}

class EnabledPsOnepagecheckoutModule extends \Ps_Onepagecheckout
{
    public function __construct()
    {
    }

    public function isOnePageCheckoutEnabled(): bool
    {
        return true;
    }
}

class DisabledPsOnepagecheckoutModule extends \Ps_Onepagecheckout
{
    public function __construct()
    {
    }

    public function isOnePageCheckoutEnabled(): bool
    {
        return false;
    }
}
