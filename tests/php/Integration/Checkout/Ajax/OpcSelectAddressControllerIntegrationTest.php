<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use Tests\Resources\DatabaseDump;
use Tests\TestCase\SymfonyIntegrationTestCase;

class OpcSelectAddressControllerIntegrationTest extends SymfonyIntegrationTestCase
{
    /** @var array<string,mixed> */
    private array $previousPost = [];
    /** @var array<string,mixed> */
    private array $previousGet = [];

    protected static function getKernelClass(): string
    {
        return \AdminKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousPost = $_POST;
        $this->previousGet = $_GET;

        self::mockContext();
        DatabaseDump::restoreTables([
            'configuration',
            'customer',
            'customer_group',
            'address',
            'cart',
        ]);
        \Configuration::loadConfiguration();
    }

    protected function tearDown(): void
    {
        $_POST = $this->previousPost;
        $_GET = $this->previousGet;

        parent::tearDown();
    }

    public function testItUsesTemporaryInvoiceAddressForInlineInvoicePreviewAndCleansItUp(): void
    {
        \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_invoice');

        $context = self::getContext();
        $customer = $this->createCustomer();
        $context->customer = $customer;

        $deliveryAddress = $this->createAddress($customer, 'Delivery', 'FR', '75001', 'Paris');
        $invoiceAddress = $this->createAddress($customer, 'Invoice', 'FR', '69001', 'Lyon');
        $cart = $this->createCart($customer, (int) $deliveryAddress->id, (int) $invoiceAddress->id);
        $context->cart = $cart;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
        $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));

        $_POST = [
            'use_same_address' => '0',
            'id_address_delivery' => (string) $deliveryAddress->id,
            'invoice_id_country' => (string) \Country::getByIso('US'),
            'invoice_postcode' => '10001',
            'invoice_city' => 'New York',
        ];
        $_GET = [];

        $controller = new TestSelectAddressController($context);
        $response = $controller->callHandleAvailableOpcRequest();

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        self::assertSame('rendered-cart-summary', $response['preview']);
        self::assertSame(0, $this->countTemporaryAddresses());

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $deliveryAddress->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $invoiceAddress->id, (int) $freshCart->id_address_invoice);
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-select-address-%s@example.com', uniqid('', true));
        $customer->is_guest = true;
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddress(\Customer $customer, string $alias, string $countryIso, string $postcode, string $city): \Address
    {
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->alias = $alias;
        $address->firstname = $customer->firstname;
        $address->lastname = $customer->lastname;
        $address->address1 = '1 rue Integration';
        $address->city = $city;
        $address->postcode = $postcode;
        $address->id_country = (int) \Country::getByIso($countryIso);
        self::assertTrue($address->add());

        return $address;
    }

    private function createCart(\Customer $customer, int $deliveryId, int $invoiceId): \Cart
    {
        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop_group = 1;
        $cart->id_shop = 1;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = $deliveryId;
        $cart->id_address_invoice = $invoiceId;
        self::assertTrue($cart->add());

        return $cart;
    }

    private function countTemporaryAddresses(): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address` WHERE alias LIKE "temp_opc_%"'
        );
    }
}

class TestSelectAddressController extends \Ps_OnepagecheckoutSelectAddressModuleFrontController
{
    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    public function callHandleAvailableOpcRequest(): array
    {
        return $this->handleAvailableOpcRequest();
    }

    protected function render($template, array $params = [])
    {
        TestCase::assertSame('checkout/_partials/cart-summary', $template);
        TestCase::assertArrayHasKey('cart', $params);

        return 'rendered-cart-summary';
    }
}
