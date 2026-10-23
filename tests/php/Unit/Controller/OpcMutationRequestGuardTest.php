<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\TestCase;

class OpcMutationRequestGuardTest extends TestCase
{
    private $originalRequestMethod;
    private $originalResponseCode;

    protected function setUp(): void
    {
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->originalResponseCode = http_response_code();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        if ($this->originalRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }

        header_remove('Allow');
        http_response_code(is_int($this->originalResponseCode) ? $this->originalResponseCode : 200);
    }

    public function testRejectsNonPostRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $controller = new TestOpcMutationRequestGuardController();
        $response = $controller->validateRequest();

        self::assertSame(['success' => false], $response);
        self::assertSame(405, http_response_code());
    }

    public function testRejectsPostRequestsWithInvalidToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = new TestOpcMutationRequestGuardController();
        $controller->tokenValid = false;
        $response = $controller->validateRequest();

        self::assertSame(['success' => false], $response);
        self::assertSame(403, http_response_code());
    }

    public function testAcceptsPostRequestsWithValidToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = new TestOpcMutationRequestGuardController();
        $controller->tokenValid = true;

        self::assertNull($controller->validateRequest());
        self::assertSame(200, http_response_code());
    }

    public function testSaveAddressControllerRejectsGetBeforeCreatingItsHandler(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $controller = new TestSaveAddressRequestController();

        self::assertSame(['success' => false], $controller->callHandleAvailableOpcRequest());
        self::assertSame(405, http_response_code());
    }

    public function testDeleteAddressControllerRejectsGetBeforeCreatingItsHandler(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $controller = new TestDeleteAddressRequestController();

        self::assertSame(['success' => false], $controller->callHandleAvailableOpcRequest());
        self::assertSame(405, http_response_code());
    }

    public function testSaveDraftControllerRejectsGetBeforeCreatingItsHandler(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $controller = new TestSaveDraftRequestController();

        self::assertSame(['success' => false], $controller->callHandleAvailableOpcRequest());
        self::assertSame(405, http_response_code());
    }

    public function testAddressControllersRejectInvalidPostTokensBeforeCreatingHandlers(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $saveController = new TestSaveAddressRequestController();
        $deleteController = new TestDeleteAddressRequestController();

        self::assertSame(['success' => false], $saveController->callHandleAvailableOpcRequest());
        self::assertSame(403, http_response_code());

        http_response_code(200);
        self::assertSame(['success' => false], $deleteController->callHandleAvailableOpcRequest());
        self::assertSame(403, http_response_code());
    }
}

class TestOpcMutationRequestGuardController extends \Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    public bool $tokenValid = false;

    public function __construct()
    {
    }

    public function validateRequest(): ?array
    {
        return $this->validatePostAndToken();
    }

    public function isTokenValid(): bool
    {
        return $this->tokenValid;
    }

    protected function handleAvailableOpcRequest(): array
    {
        return ['success' => true];
    }
}

class TestSaveAddressRequestController extends \Ps_OnepagecheckoutSaveAddressModuleFrontController
{
    public function __construct()
    {
    }

    public function callHandleAvailableOpcRequest(): array
    {
        return $this->handleAvailableOpcRequest();
    }

    public function isTokenValid(): bool
    {
        return false;
    }
}

class TestDeleteAddressRequestController extends \Ps_OnepagecheckoutDeleteAddressModuleFrontController
{
    public function __construct()
    {
    }

    public function callHandleAvailableOpcRequest(): array
    {
        return $this->handleAvailableOpcRequest();
    }

    public function isTokenValid(): bool
    {
        return false;
    }
}

class TestSaveDraftRequestController extends \Ps_OnepagecheckoutSaveDraftModuleFrontController
{
    public function __construct()
    {
    }

    public function callHandleAvailableOpcRequest(): array
    {
        return $this->handleAvailableOpcRequest();
    }

    public function isTokenValid(): bool
    {
        return false;
    }
}
