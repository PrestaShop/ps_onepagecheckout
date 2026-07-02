<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Context;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Context\OpcContextRefreshBuilder;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

/**
 * Backend non-regression for the front "context sync": the builder must expose the SAME
 * country/currency/cart that a full page render would put into window.prestashop / the body classes,
 * so the OPC AJAX responses can re-sync them client-side without a reload.
 */
class OpcContextRefreshBuilderIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables([
            'configuration',
            'customer',
            'customer_group',
            'address',
            'cart',
            'country',
            'currency',
        ]);
        \Configuration::loadConfiguration();
        \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_delivery');
    }

    protected function tearDown(): void
    {
        // The legacy front container primed in createContext must not leak into later test
        // classes: Module::install resolves services through Context::getContext()->container when
        // it is set, and the legacy container lacks them.
        self::getContext()->container = null;

        parent::tearDown();
    }

    public function testItDerivesTheCountryFromTheCartTaxAddress(): void
    {
        $countryId = (int) \Country::getByIso('FR');
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer, $countryId);
        $cart = $this->createCartForCustomer($customer, [
            'id_address_delivery' => (int) $address->id,
            'id_address_invoice' => (int) $address->id,
        ]);
        $context = $this->createContext($customer, $cart);

        $result = (new OpcContextRefreshBuilder())->build($context);

        self::assertSame('FR', $result['country']['iso_code']);
        self::assertSame($countryId, (int) $result['country']['id']);
    }

    public function testItExposesCurrencyAndCartTotalsInThePresenterShape(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer, (int) \Country::getByIso('FR'));
        $cart = $this->createCartForCustomer($customer, [
            'id_address_delivery' => (int) $address->id,
            'id_address_invoice' => (int) $address->id,
        ]);
        $context = $this->createContext($customer, $cart);

        $result = (new OpcContextRefreshBuilder())->build($context);

        self::assertArrayHasKey('currency', $result);
        self::assertSame((int) $context->currency->id, (int) $result['currency']['id']);
        self::assertNotSame('', (string) $result['currency']['iso_code']);

        // Mirrors the cart presenter totals shape so window.prestashop.cart.totals readers stay consistent.
        self::assertArrayHasKey('cart', $result);
        foreach (['total', 'total_including_tax', 'total_excluding_tax'] as $key) {
            self::assertArrayHasKey($key, $result['cart']['totals'], $key . ' is missing');
            self::assertIsNumeric($result['cart']['totals'][$key]['amount']);
            self::assertArrayHasKey('value', $result['cart']['totals'][$key]);
        }
    }

    public function testAnExplicitCountryOverridesTheCartTaxAddress(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer, (int) \Country::getByIso('FR'));
        $cart = $this->createCartForCustomer($customer, [
            'id_address_delivery' => (int) $address->id,
            'id_address_invoice' => (int) $address->id,
        ]);
        $context = $this->createContext($customer, $cart);

        // The payment handler passes its inline-resolved country explicitly; it must win over the cart.
        $usCountry = new \Country((int) \Country::getByIso('US'));
        $result = (new OpcContextRefreshBuilder())->build($context, $usCountry);

        self::assertSame('US', $result['country']['iso_code']);
    }

    public function testItFallsBackToTheContextCountryWhenTheCartHasNoTaxAddress(): void
    {
        $customer = $this->createCustomer();
        $cart = $this->createCartForCustomer($customer);
        $context = $this->createContext($customer, $cart);
        $context->country = new \Country((int) \Country::getByIso('FR'));

        $result = (new OpcContextRefreshBuilder())->build($context);

        self::assertSame('FR', $result['country']['iso_code']);
    }

    private function createContext(\Customer $customer, \Cart $cart): \Context
    {
        $context = self::getContext();
        $context->customer = $customer;
        $context->cart = $cart;
        $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        // Cart::getOrderTotal resolves its Calculator through ContainerFinder(Context::getContext()):
        // prime the legacy front container so this class does not depend on another test having
        // initialised it first (it errors when run standalone otherwise).
        if (!isset($context->container) || $context->container === null) {
            $context->container = \PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', _PS_MODE_DEV_);
        }

        return $context;
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-ctxsync-%s@example.com', uniqid('', true));
        $customer->is_guest = true;
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddress(\Customer $customer, int $countryId): \Address
    {
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->alias = 'ctxsync';
        $address->firstname = 'Opc';
        $address->lastname = 'CtxSync';
        $address->address1 = '5 rue de la Synchronisation';
        $address->postcode = '75001';
        $address->city = 'Paris';
        $address->id_country = $countryId;
        self::assertTrue($address->save());

        return $address;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCartForCustomer(\Customer $customer, array $overrides = []): \Cart
    {
        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop_group = 1;
        $cart->id_shop = 1;
        $cart->id_customer = (int) $customer->id;

        foreach ($overrides as $property => $value) {
            $cart->{$property} = $value;
        }

        self::assertTrue($cart->add());

        return $cart;
    }
}
