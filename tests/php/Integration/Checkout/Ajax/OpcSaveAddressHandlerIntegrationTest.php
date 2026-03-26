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
        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop_group = 1;
        $cart->id_shop = 1;
        $cart->id_customer = (int) $customer->id;
        self::assertTrue($cart->add());

        $context = self::getContext();
        $context->customer = $customer;
        $context->cart = $cart;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $countryId = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $states = \State::getStatesByIdCountry($countryId);
        $defaultStateId = !empty($states) ? (int) $states[0]['id_state'] : 0;

        $handler = new OnePageCheckoutSaveAddressHandler($context, $translator, new CheckoutCustomerContextResolver($context));
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

        self::assertTrue($response['success'], var_export($response, true));
        self::assertGreaterThan(0, $response['id_address']);

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $response['id_address'], (int) $freshCart->id_address_delivery);
        self::assertSame((int) $response['id_address'], (int) $freshCart->id_address_invoice);

        $checkoutSession = $this->createCheckoutSession($context, $translator);
        self::assertSame((int) $response['id_address'], (int) $checkoutSession->getIdAddressDelivery());
        self::assertSame((int) $response['id_address'], (int) $checkoutSession->getIdAddressInvoice());
    }

    public function testItCreatesDeliveryAddressForCountryWithStatesWhenRequestChangesCountry(): void
    {
        $customer = $this->createCustomer();
        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop_group = 1;
        $cart->id_shop = 1;
        $cart->id_customer = (int) $customer->id;
        self::assertTrue($cart->add());

        $context = self::getContext();
        $context->customer = $customer;
        $context->cart = $cart;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $unitedStatesId = (int) \Country::getByIso('US');
        self::assertGreaterThan(0, $unitedStatesId);

        $stateRows = \State::getStatesByIdCountry($unitedStatesId);
        $illinoisState = current(array_filter($stateRows, static function (array $stateRow): bool {
            return isset($stateRow['name']) && $stateRow['name'] === 'Illinois';
        }));
        self::assertNotFalse($illinoisState);

        $handler = new OnePageCheckoutSaveAddressHandler($context, $translator, new CheckoutCustomerContextResolver($context));
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

        self::assertTrue($response['success'], var_export($response, true));

        $savedAddress = new \Address((int) $response['id_address']);
        self::assertSame($unitedStatesId, (int) $savedAddress->id_country);
        self::assertSame((int) $illinoisState['id_state'], (int) $savedAddress->id_state);
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
