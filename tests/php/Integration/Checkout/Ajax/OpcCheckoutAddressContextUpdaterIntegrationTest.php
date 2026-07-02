<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutAddressContextUpdater;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcCheckoutAddressContextUpdaterIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables(['configuration', 'customer', 'customer_group', 'address', 'cart']);
        \Configuration::loadConfiguration();
    }

    public function testPersistsSeparateInvoiceWhenNotUseSameAddress(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $addressC = $this->createAddress($customer, 'C');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressA->id); // delivery=A, invoice=A
        $context = $this->createCheckoutContext($customer, $cart);

        $changed = $this->updater($context)->updateFromRequest([
            'use_same_address' => '0',
            'id_address_invoice' => (string) $addressC->id,
        ]);

        self::assertTrue($changed);
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressA->id, (int) $freshCart->id_address_delivery, 'delivery untouched');
        self::assertSame((int) $addressC->id, (int) $freshCart->id_address_invoice, 'separate invoice persisted');
    }

    public function testInvoiceFollowsDeliveryWhenUseSameAddress(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $addressB = $this->createAddress($customer, 'B');
        $cart = $this->createCart($customer, (int) $addressB->id, (int) $addressA->id); // delivery=B, invoice=A
        $context = $this->createCheckoutContext($customer, $cart);

        $changed = $this->updater($context)->updateFromRequest([
            'use_same_address' => '1',
            'id_address_delivery' => (string) $addressB->id,
        ]);

        self::assertTrue($changed);
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressB->id, (int) $freshCart->id_address_invoice, 'invoice realigned to delivery');
    }

    public function testRejectsUnownedInvoiceAndKeepsCurrent(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressA->id);
        $context = $this->createCheckoutContext($customer, $cart);

        try {
            $this->updater($context)->updateFromRequest([
                'use_same_address' => '0',
                'id_address_invoice' => '99999999', // not owned
            ]);
            self::fail('Unowned invoice address should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Invalid invoice address.', $exception->getMessage());
        }

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressA->id, (int) $freshCart->id_address_invoice, 'unowned invoice rejected');
    }

    public function testRejectsUnownedDeliveryAndKeepsCurrent(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressA->id);
        $context = $this->createCheckoutContext($customer, $cart);

        try {
            $this->updater($context)->updateFromRequest([
                'use_same_address' => '1',
                'id_address_delivery' => '99999999', // not owned
            ]);
            self::fail('Unowned delivery address should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Invalid delivery address.', $exception->getMessage());
        }

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressA->id, (int) $freshCart->id_address_delivery, 'unowned delivery rejected');
        self::assertSame((int) $addressA->id, (int) $freshCart->id_address_invoice, 'invoice untouched');
    }

    /**
     * Regression guard (raised in review): a bare call with no explicit address intent MUST NOT
     * mutate the cart, so a read-only refresh can never reset a separate billing address.
     */
    public function testBareCallWithoutIntentDoesNotMutate(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $addressC = $this->createAddress($customer, 'C');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressC->id); // delivery=A, invoice=C (separate)
        $context = $this->createCheckoutContext($customer, $cart);

        $changed = $this->updater($context)->updateFromRequest([]); // no use_same_address => no-op

        self::assertFalse($changed);
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressA->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $addressC->id, (int) $freshCart->id_address_invoice, 'separate invoice NOT reset by bare call');
    }

    /**
     * Re-checking "use same address" IS an explicit invoice = delivery intent, even when the client
     * omits the address ids: in guest inline mode the persisted delivery id lives in a HIDDEN field the
     * client does not resend. The updater must realign a previously-separate invoice back to the
     * delivery address, otherwise the cart invoice stays stuck on a just-persisted separate billing
     * (wrong payment/tax country on re-check — the SPE E2E regression). A truly bare call (NO
     * use_same_address key) still stays a no-op, see testBareCallWithoutIntentDoesNotMutate.
     */
    public function testUseSameRealignsInvoiceToDeliveryWithoutAddressId(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $addressC = $this->createAddress($customer, 'C');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressC->id); // delivery=A, invoice=C (separate)
        $context = $this->createCheckoutContext($customer, $cart);

        $changed = $this->updater($context)->updateFromRequest(['use_same_address' => '1']);

        self::assertTrue($changed);
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressA->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $addressA->id, (int) $freshCart->id_address_invoice, 'use_same=1 realigns invoice to delivery even without ids');
    }

    private function updater(\Context $context): CheckoutAddressContextUpdater
    {
        return new CheckoutAddressContextUpdater($context, new CheckoutCustomerContextResolver($context));
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-ctx-%s@example.com', uniqid('', true));
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddress(\Customer $customer, string $alias): \Address
    {
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->alias = $alias;
        $address->firstname = 'Integration';
        $address->lastname = 'Customer';
        $address->address1 = '1 rue ' . $alias;
        $address->city = 'Paris';
        $address->postcode = '75001';
        $address->id_country = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        self::assertTrue($address->save());

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

    private function createCheckoutContext(\Customer $customer, \Cart $cart): \Context
    {
        $context = self::getContext();
        $context->customer = $customer;
        $context->cart = $cart;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        return $context;
    }
}
