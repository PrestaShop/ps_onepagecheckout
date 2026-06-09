<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OpcTempAddress;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcTempAddressIntegrationTest extends TestCase
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
        ]);
        \Configuration::loadConfiguration();
    }

    public function testItCreatesAndCleansUpTempInvoiceAddressWhenTaxesUseInvoiceAddress(): void
    {
        \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_invoice');

        $context = self::getContext();
        $customer = $this->createCustomer();
        $context->customer = $customer;

        $originalDelivery = $this->createAddress($customer, 'Original delivery', 'FR', '75001', 'Paris');
        $originalInvoice = $this->createAddress($customer, 'Original invoice', 'FR', '69001', 'Lyon');

        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop_group = 1;
        $cart->id_shop = 1;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $originalDelivery->id;
        $cart->id_address_invoice = (int) $originalInvoice->id;
        self::assertTrue($cart->add());
        $context->cart = $cart;

        $tempAddress = new OpcTempAddress($context);
        $tempDeliveryId = $tempAddress->createFromRequest([
            'id_country' => (string) \Country::getByIso('FR'),
            'postcode' => '75009',
            'city' => 'Paris',
            'invoice_id_country' => (string) \Country::getByIso('US'),
            'use_same_address' => '0',
        ]);

        self::assertGreaterThan(0, $tempDeliveryId);
        self::assertSame($tempDeliveryId, (int) $context->cart->id_address_delivery);
        self::assertNotSame((int) $originalInvoice->id, (int) $context->cart->id_address_invoice);
        self::assertNotSame($tempDeliveryId, (int) $context->cart->id_address_invoice);

        $tempInvoiceId = (int) $context->cart->id_address_invoice;
        $tempDeliveryAddress = new \Address($tempDeliveryId);
        $tempInvoiceAddress = new \Address($tempInvoiceId);

        self::assertSame((int) \Country::getByIso('FR'), (int) $tempDeliveryAddress->id_country);
        self::assertSame((int) \Country::getByIso('US'), (int) $tempInvoiceAddress->id_country);

        $tempAddress->cleanup($tempDeliveryId, (int) $originalDelivery->id);

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $originalDelivery->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $originalInvoice->id, (int) $freshCart->id_address_invoice);
        self::assertSame(
            '0',
            (string) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address` WHERE id_address = ' . (int) $tempDeliveryId)
        );
        self::assertSame(
            '0',
            (string) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address` WHERE id_address = ' . (int) $tempInvoiceId)
        );
    }

    public function testItFallsBackToDeliveryCountryForTempInvoiceAddressWhenUseSameAddressIsEnabled(): void
    {
        \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_invoice');

        $context = self::getContext();
        $customer = $this->createCustomer();
        $context->customer = $customer;

        $originalDelivery = $this->createAddress($customer, 'Original delivery', 'FR', '75001', 'Paris');
        $originalInvoice = $this->createAddress($customer, 'Original invoice', 'FR', '69001', 'Lyon');

        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop_group = 1;
        $cart->id_shop = 1;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $originalDelivery->id;
        $cart->id_address_invoice = (int) $originalInvoice->id;
        self::assertTrue($cart->add());
        $context->cart = $cart;

        $tempAddress = new OpcTempAddress($context);
        $tempDeliveryId = $tempAddress->createFromRequest([
            'id_country' => (string) \Country::getByIso('US'),
            'postcode' => '33133',
            'city' => 'Miami',
            'use_same_address' => '1',
        ]);

        self::assertGreaterThan(0, $tempDeliveryId);
        $tempInvoiceId = (int) $context->cart->id_address_invoice;
        self::assertNotSame((int) $originalInvoice->id, $tempInvoiceId);
        self::assertSame((int) \Country::getByIso('US'), (int) (new \Address($tempInvoiceId))->id_country);

        $tempAddress->cleanup($tempDeliveryId, (int) $originalDelivery->id);
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-temp-%s@example.com', uniqid('', true));
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
}
