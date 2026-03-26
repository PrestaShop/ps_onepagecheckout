<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\TestCase;

class CarriersControllerTest extends TestCase
{
    public function testHandleCarriersReturnsTechnicalErrorWhenModuleIsDisabled(): void
    {
        $controller = new class extends \Ps_OnepagecheckoutCarriersModuleFrontController {
            public function __construct()
            {
            }

            public function callHandleCarriers(): array
            {
                return $this->handleCarriers();
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

        self::assertSame('technical-error', $controller->callHandleCarriers()['error']);
    }
}
