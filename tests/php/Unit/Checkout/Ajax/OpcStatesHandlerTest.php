<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutStatesHandler;

class OpcStatesHandlerTest extends TestCase
{
    public function testItReturnsEmptyStatesWithoutCountry(): void
    {
        $handler = new OnePageCheckoutStatesHandler();
        $response = $handler->handle([]);

        self::assertTrue($response['success']);
        self::assertSame([], $response['states']);
        self::assertFalse($response['contains_states']);
    }
}
