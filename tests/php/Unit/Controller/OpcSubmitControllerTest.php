<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitHandler;

class OpcSubmitControllerTest extends TestCase
{
    public function testHandleOpcSubmitReturnsTechnicalErrorWhenModuleIsDisabled(): void
    {
        $controller = new TestOpcSubmitController();
        $controller->module = new class extends \Ps_Onepagecheckout {
            public function __construct()
            {
            }

            public function isOnePageCheckoutEnabled(): bool
            {
                return false;
            }
        };

        $response = $controller->callHandleOpcRequest();

        self::assertFalse($response['success']);
        self::assertSame('technical-error', $response['error']);
    }

    public function testHandleOpcSubmitReturnsHandlerPayloadWhenModuleIsEnabled(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = new TestOpcSubmitController();
        $controller->module = new class extends \Ps_Onepagecheckout {
            public function __construct()
            {
            }

            public function isOnePageCheckoutEnabled(): bool
            {
                return true;
            }
        };
        $controller->submitHandler = $this->getMockBuilder(OnePageCheckoutSubmitHandler::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['handle'])
            ->getMock();
        $controller->submitHandler
            ->expects($this->once())
            ->method('handle')
            ->willReturn([
                'success' => true,
                'reload' => false,
                'checkout_url' => '/commande',
            ]);

        $response = $controller->callHandleOpcRequest();

        self::assertTrue($response['success']);
        self::assertFalse($response['reload']);
    }

    public function testHandleOpcSubmitRejectsNonPostRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $controller = new TestOpcSubmitController();
        $controller->module = new class extends \Ps_Onepagecheckout {
            public function __construct()
            {
            }

            public function isOnePageCheckoutEnabled(): bool
            {
                return true;
            }
        };

        $response = $controller->callHandleOpcRequest();

        self::assertFalse($response['success']);
        self::assertTrue($response['reload']);
    }
}

class TestOpcSubmitController extends \Ps_OnepagecheckoutOpcSubmitModuleFrontController
{
    public ?OnePageCheckoutSubmitHandler $submitHandler = null;

    public function __construct()
    {
    }

    public function callHandleOpcRequest(): array
    {
        return $this->handleOpcRequest();
    }

    protected function createSubmitHandler(): OnePageCheckoutSubmitHandler
    {
        if (!$this->submitHandler instanceof OnePageCheckoutSubmitHandler) {
            throw new \RuntimeException('submit handler not configured');
        }

        return $this->submitHandler;
    }

    protected function buildTechnicalErrorResponse(): array
    {
        return [
            'success' => false,
            'error' => 'technical-error',
            'reload' => true,
            'checkout_url' => '/commande',
        ];
    }
}
