<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSaveAddressHandler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcSaveAddressHandlerIntegrationTest extends TestCase
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

    public function testItCreatesDeliveryAddressAndUpdatesCart(): void
    {
        $customer = $this->createCustomer();
        $cart = $this->createCartForCustomer($customer);
        $context = $this->createCheckoutContext($customer, $cart);
        $translator = $this->createTranslator();
        $countryId = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $states = \State::getStatesByIdCountry($countryId);
        $defaultStateId = !empty($states) ? (int) $states[0]['id_state'] : 0;

        $handler = $this->createHandler($context, $translator);
        $response = $handler->handle([
            'address_type' => 'delivery',
            'firstname' => 'Integration',
            'lastname' => 'Customer',
            'address1' => '1 rue Integration',
            'city' => 'Paris',
            'postcode' => '75001',
            'id_country' => (string) $countryId,
            'id_state' => $defaultStateId > 0 ? (string) $defaultStateId : '',
            'alias' => 'Home',
            'use_same_address' => '1',
        ]);

        self::assertSame(true, $response['success'] ?? null, var_export($response, true));
        self::assertSame('delivery', $response['address_type'] ?? null, var_export($response, true));
        self::assertIsInt($response['id_address'] ?? null, var_export($response, true));

        $freshCart = new \Cart((int) $cart->id);
        self::assertGreaterThan(0, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $freshCart->id_address_delivery, (int) $freshCart->id_address_invoice);

        $checkoutSession = $this->createCheckoutSession($context, $translator);
        self::assertSame((int) $freshCart->id_address_delivery, (int) $checkoutSession->getIdAddressDelivery());
        self::assertSame((int) $freshCart->id_address_invoice, (int) $checkoutSession->getIdAddressInvoice());
    }

    public function testItCreatesDeliveryAddressWithoutAliasAndUsesTheFallbackAlias(): void
    {
        $customer = $this->createCustomer();
        $cart = $this->createCartForCustomer($customer);
        $context = $this->createCheckoutContext($customer, $cart);
        $translator = $this->createTranslator();
        $countryId = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $defaultStateId = $this->getFirstStateIdForCountry($countryId);

        $handler = $this->createHandler($context, $translator);
        $response = $handler->handle([
            'address_type' => 'delivery',
            'firstname' => 'Integration',
            'lastname' => 'Customer',
            'address1' => '3 rue Sans Alias',
            'city' => 'Paris',
            'postcode' => '75001',
            'id_country' => (string) $countryId,
            'id_state' => $defaultStateId > 0 ? (string) $defaultStateId : '',
            'alias' => '',
            'use_same_address' => '1',
        ]);

        self::assertSame(true, $response['success'] ?? null, var_export($response, true));
        self::assertSame('delivery', $response['address_type'] ?? null, var_export($response, true));
        self::assertIsInt($response['id_address'] ?? null, var_export($response, true));

        $savedAddress = new \Address((int) $context->cart->id_address_delivery);
        self::assertSame('My Address', $savedAddress->alias);
    }

    public function testItCreatesDeliveryAddressForCountryWithStatesWhenRequestChangesCountry(): void
    {
        $customer = $this->createCustomer();
        $cart = $this->createCartForCustomer($customer);
        $context = $this->createCheckoutContext($customer, $cart);
        $translator = $this->createTranslator();

        $unitedStatesId = (int) \Country::getByIso('US');
        self::assertGreaterThan(0, $unitedStatesId);

        $stateRows = \State::getStatesByIdCountry($unitedStatesId);
        $illinoisState = current(array_filter($stateRows, static function (array $stateRow): bool {
            return isset($stateRow['name']) && $stateRow['name'] === 'Illinois';
        }));
        self::assertNotFalse($illinoisState);

        $handler = $this->createHandler($context, $translator);
        $response = $handler->handle([
            'address_type' => 'delivery',
            'firstname' => 'Integration',
            'lastname' => 'Customer',
            'address1' => '16 Main street',
            'city' => 'Chicago',
            'postcode' => '60601',
            'id_country' => (string) $unitedStatesId,
            'id_state' => (string) $illinoisState['id_state'],
            'alias' => 'Illinois address',
            'use_same_address' => '1',
        ]);

        self::assertSame(true, $response['success'] ?? null, var_export($response, true));
        self::assertSame('delivery', $response['address_type'] ?? null, var_export($response, true));
        self::assertIsInt($response['id_address'] ?? null, var_export($response, true));

        $savedAddress = new \Address((int) $context->cart->id_address_delivery);
        self::assertSame($unitedStatesId, (int) $savedAddress->id_country);
        self::assertSame((int) $illinoisState['id_state'], (int) $savedAddress->id_state);
    }

    public function testItCreatesInvoiceAddressWithoutChangingTheDeliveryAddress(): void
    {
        $customer = $this->createCustomer();
        $deliveryAddress = $this->createAddress($customer, [
            'alias' => 'Existing delivery',
            'firstname' => 'Integration',
            'lastname' => 'Customer',
            'address1' => '1 rue Livraison',
            'city' => 'Paris',
            'postcode' => '75001',
            'id_country' => (int) \Configuration::get('PS_COUNTRY_DEFAULT'),
        ]);
        $cart = $this->createCartForCustomer($customer, [
            'id_address_delivery' => (int) $deliveryAddress->id,
            'id_address_invoice' => (int) $deliveryAddress->id,
        ]);
        $context = $this->createCheckoutContext($customer, $cart);
        $translator = $this->createTranslator();
        $countryId = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $defaultStateId = $this->getFirstStateIdForCountry($countryId);

        $handler = $this->createHandler($context, $translator);
        $response = $handler->handle([
            'address_type' => 'invoice',
            'id_address' => '0',
            'invoice_firstname' => 'Invoice',
            'invoice_lastname' => 'Customer',
            'invoice_address1' => '2 rue Facture',
            'invoice_city' => 'Lyon',
            'invoice_postcode' => '69001',
            'invoice_id_country' => (string) $countryId,
            'invoice_id_state' => $defaultStateId > 0 ? (string) $defaultStateId : '',
            'invoice_alias' => 'Invoice',
        ]);

        self::assertSame(true, $response['success'] ?? null, var_export($response, true));
        self::assertSame('invoice', $response['address_type'] ?? null, var_export($response, true));
        self::assertIsInt($response['id_address'] ?? null, var_export($response, true));

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $deliveryAddress->id, (int) $freshCart->id_address_delivery);
        self::assertNotSame((int) $deliveryAddress->id, (int) $freshCart->id_address_invoice);
    }

    public function testItUpdatesAnOwnedAddress(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer, [
            'alias' => 'Home',
            'firstname' => 'Integration',
            'lastname' => 'Customer',
            'address1' => '1 rue Initiale',
            'city' => 'Paris',
            'postcode' => '75001',
            'id_country' => (int) \Configuration::get('PS_COUNTRY_DEFAULT'),
        ]);
        $cart = $this->createCartForCustomer($customer, [
            'id_address_delivery' => (int) $address->id,
            'id_address_invoice' => (int) $address->id,
        ]);
        $context = $this->createCheckoutContext($customer, $cart);
        $translator = $this->createTranslator();
        $countryId = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $defaultStateId = $this->getFirstStateIdForCountry($countryId);

        $handler = $this->createHandler($context, $translator);
        $response = $handler->handle([
            'address_type' => 'delivery',
            'id_address' => (string) $address->id,
            'firstname' => 'Integration',
            'lastname' => 'Customer',
            'address1' => '99 rue Modifiee',
            'city' => 'Marseille',
            'postcode' => '13001',
            'id_country' => (string) $countryId,
            'id_state' => $defaultStateId > 0 ? (string) $defaultStateId : '',
            'alias' => 'Updated',
            'use_same_address' => '1',
        ]);

        self::assertSame(true, $response['success'] ?? null, var_export($response, true));
        self::assertSame('delivery', $response['address_type'] ?? null, var_export($response, true));
        self::assertSame((int) $address->id, $response['id_address'] ?? null, var_export($response, true));

        $freshAddress = new \Address((int) $address->id);
        self::assertSame('99 rue Modifiee', $freshAddress->address1);
        self::assertSame('Marseille', $freshAddress->city);
        self::assertSame('Updated', $freshAddress->alias);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createAddress(\Customer $customer, array $overrides): \Address
    {
        $address = new \Address();
        $address->id_customer = (int) $customer->id;

        foreach ($overrides as $property => $value) {
            $address->{$property} = $value;
        }

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

    private function createCheckoutContext(\Customer $customer, \Cart $cart): \Context
    {
        $context = self::getContext();
        $context->customer = $customer;
        $context->cart = $cart;
        $context->language = $this->createLanguage();

        return $context;
    }

    private function createLanguage(): \Language
    {
        return new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
    }

    private function getFirstStateIdForCountry(int $countryId): int
    {
        $states = \State::getStatesByIdCountry($countryId);

        return !empty($states) ? (int) $states[0]['id_state'] : 0;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    private function createHandler(\Context $context, TranslatorInterface $translator): OnePageCheckoutSaveAddressHandler
    {
        return new OnePageCheckoutSaveAddressHandler($context, $translator, new CheckoutCustomerContextResolver($context));
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-save-%s@example.com', uniqid('', true));
        $customer->is_guest = true;
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
    }

    private function createCheckoutSession(\Context $context, TranslatorInterface $translator): \CheckoutSession
    {
        return new \CheckoutSession(
            $context,
            new \DeliveryOptionsFinder(
                $context,
                $translator,
                new \PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter(),
                new \PrestaShop\PrestaShop\Adapter\Product\PriceFormatter()
            )
        );
    }
}
