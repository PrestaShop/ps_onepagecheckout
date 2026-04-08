<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\TestCase;

class SaveAddressControllerTest extends TestCase
{
    public function testHandleSaveAddressReturnsTechnicalErrorWhenModuleIsDisabled(): void
    {
        $controller = new class extends \Ps_OnepagecheckoutSaveAddressModuleFrontController {
            public function __construct()
            {
            }

            public function callHandleOpcRequest(): array
            {
                return $this->handleOpcRequest();
            }

            protected function buildTechnicalErrorResponse(): array
            {
                return ['success' => false, 'error' => 'technical-error'];
            }
        };
        $controller->module = new class extends \Ps_Onepagecheckout {
            public function __construct()
            {
            }

            public function isOnePageCheckoutEnabled(): bool
            {
                return false;
            }
        };

        self::assertSame('technical-error', $controller->callHandleOpcRequest()['error']);
    }
}
