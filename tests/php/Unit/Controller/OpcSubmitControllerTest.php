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

    public function testHandleOpcSubmitPassesReloadErrorResponseToHandler(): void
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
            ->with(
                $this->isType('array'),
                $this->callback(static function (array $response): bool {
                    return ($response['reload'] ?? false) === true
                        && ($response['errors'][''][0] ?? null) === 'One-page checkout is currently unavailable.';
                })
            )
            ->willThrowException(new \RuntimeException('submit exploded'));

        $response = $controller->callHandleOpcRequest();

        self::assertFalse($response['success']);
        self::assertTrue($response['reload']);
        self::assertArrayHasKey('errors', $response);
        self::assertSame(
            ['One-page checkout is currently unavailable.'],
            $response['errors']['']
        );
    }
}

class TestOpcSubmitController extends \Ps_OnepagecheckoutOpcSubmitModuleFrontController
{
    public ?OnePageCheckoutSubmitHandler $submitHandler = null;
    private InMemoryCheckoutSessionDataDb $storageDb;

    public function __construct()
    {
        $this->context = new \Context();
        $this->context->cart = new \stdClass();
        $this->context->cart->id = 42;
        $this->storageDb = new InMemoryCheckoutSessionDataDb();
        $this->context->cookie = new class {
            public array $values = [];

            public function __set(string $key, string $value): void
            {
                $this->values[$key] = $value;
            }

            public function __get(string $key): string
            {
                return $this->values[$key] ?? '';
            }

            public function __unset(string $key): void
            {
                unset($this->values[$key]);
            }

            public function write(): void
            {
            }
        };
    }

    public function callHandleOpcRequest(): array
    {
        return $this->handleOpcRequest();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getStoredValidationState(): ?array
    {
        $checkoutSessionData = json_decode((string) ($this->context->cart->checkout_session_data ?? ''), true);
        if (!is_array($checkoutSessionData)) {
            return null;
        }

        $storedState = $checkoutSessionData['_opc_submit_validation_state'] ?? null;

        return is_array($storedState) ? $storedState : null;
    }

    protected function createSubmitHandler(): OnePageCheckoutSubmitHandler
    {
        if (!$this->submitHandler instanceof OnePageCheckoutSubmitHandler) {
            throw new \RuntimeException('submit handler not configured');
        }

        return $this->submitHandler;
    }

    protected function createSubmitValidationStateStorage(): \PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitValidationStateStorage
    {
        return new TestableSubmitValidationStateStorage($this->context, $this->storageDb);
    }

    protected function buildTechnicalErrorResponse(): array
    {
        return [
            'success' => false,
            'error' => 'technical-error',
            'errors' => [
                '' => ['One-page checkout is currently unavailable.'],
            ],
            'reload' => true,
            'checkout_url' => '/commande',
        ];
    }
}

class InMemoryCheckoutSessionDataDb
{
    /**
     * @var array<int, string>
     */
    public array $rows = [];

    public function getValue(string $sql): string
    {
        preg_match('/id_cart = (\d+)/', $sql, $matches);
        $cartId = isset($matches[1]) ? (int) $matches[1] : 0;

        return $this->rows[$cartId] ?? '';
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function execute(string $sql): bool
    {
        preg_match('/id_cart = (\d+)/', $sql, $matches);
        $cartId = isset($matches[1]) ? (int) $matches[1] : 0;

        if (str_contains($sql, 'checkout_session_data = NULL')) {
            unset($this->rows[$cartId]);

            return true;
        }

        preg_match('/checkout_session_data = "(.*)" WHERE id_cart/s', $sql, $valueMatches);
        $checkoutSessionData = isset($valueMatches[1]) ? stripslashes($valueMatches[1]) : '';
        $this->rows[$cartId] = $checkoutSessionData;

        return true;
    }
}

class TestableSubmitValidationStateStorage extends \PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitValidationStateStorage
{
    public function __construct(\Context $context, private InMemoryCheckoutSessionDataDb $db)
    {
        parent::__construct($context);
    }

    protected function getDb(): InMemoryCheckoutSessionDataDb
    {
        return $this->db;
    }
}
