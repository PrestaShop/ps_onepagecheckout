<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutStatesHandler;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcStatesHandlerIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables([
            'configuration',
            'state',
        ]);
        \Configuration::loadConfiguration();
    }

    public function testItReturnsStatesForCountry(): void
    {
        $handler = new OnePageCheckoutStatesHandler();
        $response = $handler->handle([
            'id_country' => (int) \Configuration::get('PS_COUNTRY_DEFAULT'),
        ]);

        self::assertTrue($response['success']);
        self::assertArrayHasKey('states', $response);
    }
}
