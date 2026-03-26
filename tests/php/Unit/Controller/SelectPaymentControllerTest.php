<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\TestCase;

class SelectPaymentControllerTest extends TestCase
{
    public function testHandleSelectPaymentReturnsTechnicalErrorWhenModuleIsDisabled(): void
    {
        $controller = new class extends \Ps_OnepagecheckoutSelectPaymentModuleFrontController {
            public function __construct()
            {
            }

            public function callHandleSelectPayment(): array
            {
                return $this->handleSelectPayment();
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

        self::assertSame('technical-error', $controller->callHandleSelectPayment()['error']);
    }
}
