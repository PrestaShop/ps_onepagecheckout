<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\TestCase;

class AddressesListControllerTest extends TestCase
{
    public function testHandleAddressesListReturnsTechnicalErrorWhenModuleIsDisabled(): void
    {
        $controller = new class extends \Ps_OnepagecheckoutAddressesListModuleFrontController {
            public function __construct()
            {
            }

            public function callHandleAddressesList(): array
            {
                return $this->handleAddressesList();
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

        self::assertSame('technical-error', $controller->callHandleAddressesList()['error']);
    }
}
