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

    public function testItReturnsCurrentCheckoutCustomerAddressListContext(): void
    {
        $customer = $this->createCustomer();
        $firstAddress = $this->createAddressForCustomer((int) $customer->id, 'Home');
        $secondAddress = $this->createAddressForCustomer((int) $customer->id, 'Office');

        $context = self::getContext();
        $context->customer = $customer;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
        $context->cart = new \Cart();
        $context->cart->id_address_delivery = (int) $secondAddress->id;
        $context->cart->id_address_invoice = (int) $firstAddress->id;

        $handler = new OnePageCheckoutAddressesListHandler(
            $context,
            $this->createConfiguredMock(\Symfony\Contracts\Translation\TranslatorInterface::class, ['trans' => 'translated']),
            new CheckoutCustomerContextResolver($context)
        );
        $response = $handler->handle();

        self::assertTrue($response['success']);
        self::assertSame(2, $response['address_count']);
        self::assertSame((int) $secondAddress->id, (int) $response['selected_delivery_address']);
        self::assertSame((int) $firstAddress->id, (int) $response['selected_invoice_address']);
        self::assertCount(2, $response['customer']['addresses']);
    }

    public function testItReturnsNoAddressesForAGuestEvenWithSavedAddresses(): void
    {
        // Parity guard: a guest is never shown the saved-address list, so the handler reports
        // zero addresses even though the guest customer record owns saved addresses (those stay
        // attached to the cart and are still used at submit, they are just not listed in the UI).
        $customer = $this->createCustomer(true);
        $this->createAddressForCustomer((int) $customer->id, 'Home');
        $this->createAddressForCustomer((int) $customer->id, 'Office');

        $context = self::getContext();
        $context->customer = $customer;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
        $context->cart = new \Cart();

        $handler = new OnePageCheckoutAddressesListHandler(
            $context,
            $this->createConfiguredMock(\Symfony\Contracts\Translation\TranslatorInterface::class, ['trans' => 'translated']),
            new CheckoutCustomerContextResolver($context)
        );
        $response = $handler->handle();

        self::assertTrue($response['success']);
        self::assertSame(0, $response['address_count']);
        self::assertSame([], $response['customer']['addresses']);
    }

    private function createCustomer(bool $isGuest = false): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-addresses-%s@example.com', uniqid('', true));
        $customer->is_guest = $isGuest;
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
