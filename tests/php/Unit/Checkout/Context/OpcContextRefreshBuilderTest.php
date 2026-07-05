<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout\Context;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Context\OpcContextRefreshBuilder;
use PrestaShop\PrestaShop\Core\Localization\LocaleInterface;

class OpcContextRefreshBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::$values = [];
    }

    protected function tearDown(): void
    {
        \Configuration::$values = [];
    }

    public function testItBuildsCountryCurrencyAndCartTotals(): void
    {
        $context = $this->buildContext();

        $data = (new OpcContextRefreshBuilder())->build($context, $this->buildCountry(8, 'FR'));

        self::assertSame(['id' => 8, 'iso_code' => 'FR'], $data['country']);
        self::assertSame(['id' => 1, 'iso_code' => 'EUR'], $data['currency']);
        self::assertSame(20.0, $data['cart']['totals']['total']['amount']);
        self::assertSame('EUR 20.00', $data['cart']['totals']['total']['value']);
        self::assertSame(20.0, $data['cart']['totals']['total_including_tax']['amount']);
        self::assertSame(16.5, $data['cart']['totals']['total_excluding_tax']['amount']);
        self::assertSame('EUR 16.50', $data['cart']['totals']['total_excluding_tax']['value']);
    }

    public function testItSkipsTheCartTotalsWhenTheEndpointCannotHaveChangedTheCart(): void
    {
        $context = $this->buildContext();
        $context->cart->getOrderTotalCalls = 0;

        $data = (new OpcContextRefreshBuilder())->build($context, $this->buildCountry(8, 'FR'), false);

        self::assertSame(['id' => 8, 'iso_code' => 'FR'], $data['country']);
        self::assertSame(['id' => 1, 'iso_code' => 'EUR'], $data['currency']);
        self::assertArrayNotHasKey('cart', $data);
        self::assertSame(0, $context->cart->getOrderTotalCalls);
    }

    public function testItSkipsTheCartTotalsWhenTheCartIsNotLoaded(): void
    {
        $context = $this->buildContext();
        $context->cart->id = 0;

        $data = (new OpcContextRefreshBuilder())->build($context, $this->buildCountry(8, 'FR'));

        self::assertArrayNotHasKey('cart', $data);
    }

    private function buildContext(): \Context
    {
        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('formatPrice')->willReturnCallback(
            static fn (float $amount, string $isoCode): string => sprintf('%s %.2f', $isoCode, $amount)
        );

        $context = new ContextRefreshTestContext($locale);
        $context->currency = $this->buildCurrency();
        $context->cart = new ContextRefreshTestCart();
        $context->cart->id = 6;

        return $context;
    }

    private function buildCountry(int $id, string $isoCode): \Country
    {
        $country = new class extends \Country {
            public function __construct()
            {
            }
        };
        $country->id = $id;
        $country->iso_code = $isoCode;

        return $country;
    }

    private function buildCurrency(): \Currency
    {
        $currency = new class extends \Currency {
            public function __construct()
            {
            }
        };
        $currency->id = 1;
        $currency->iso_code = 'EUR';

        return $currency;
    }
}

class ContextRefreshTestContext extends \Context
{
    private LocaleInterface $stubLocale;

    public function __construct(LocaleInterface $stubLocale)
    {
        $this->stubLocale = $stubLocale;
    }

    public function getCurrentLocale(): ?LocaleInterface
    {
        return $this->stubLocale;
    }
}

class ContextRefreshTestCart extends \Cart
{
    /** @var int */
    public $getOrderTotalCalls = 0;

    public function __construct()
    {
    }

    public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH, $products = null, $id_carrier = null, $use_cache = true, bool $keepOrderPrices = false)
    {
        ++$this->getOrderTotalCalls;

        return $withTaxes ? 20.0 : 16.5;
    }
}
