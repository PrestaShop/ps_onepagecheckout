<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressesListHandler;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcAddressesListHandlerIntegrationTest extends TestCase
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
        ]);
        \Configuration::loadConfiguration();
    }

    public function testItReturnsCurrentCheckoutCustomerAddresses(): void
    {
        $customer = $this->createCustomer();
        $firstAddress = $this->createAddressForCustomer((int) $customer->id, 'Home');
        $this->createAddressForCustomer((int) $customer->id, 'Office');

        $context = self::getContext();
        $context->customer = $customer;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        $handler = new OnePageCheckoutAddressesListHandler($context, new CheckoutCustomerContextResolver($context));
        $response = $handler->handle(['id_address' => (string) $firstAddress->id]);

        self::assertTrue($response['success']);
        self::assertCount(2, $response['addresses']);
        self::assertSame((int) $firstAddress->id, (int) $response['address']['id_address']);
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-addresses-%s@example.com', uniqid('', true));
        $customer->is_guest = true;
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddressForCustomer(int $customerId, string $alias): \Address
    {
        $address = new \Address();
        $address->id_customer = $customerId;
        $address->id_country = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $address->firstname = 'Integration';
        $address->lastname = 'Customer';
        $address->address1 = '1 rue Integration';
        $address->alias = $alias . '_' . uniqid('', true);
        $address->city = 'Paris';
        self::assertTrue($address->save());

        return $address;
    }
}
