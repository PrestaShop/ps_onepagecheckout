<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutDeleteAddressHandler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcDeleteAddressHandlerIntegrationTest extends TestCase
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

    public function testItSynchronizesCheckoutSessionAfterDeletingActiveAddress(): void
    {
        $customer = $this->createCustomer();
        $context = self::getContext();
        $context->customer = $customer;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        $activeAddress = $this->createAddress($customer, 'Active delivery');
        $fallbackAddress = $this->createAddress($customer, 'Fallback delivery');

        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop_group = 1;
        $cart->id_shop = 1;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $activeAddress->id;
        $cart->id_address_invoice = (int) $activeAddress->id;
        self::assertTrue($cart->add());
        $context->cart = $cart;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $handler = new OnePageCheckoutDeleteAddressHandler($context, $translator, new CheckoutCustomerContextResolver($context));
        $response = $handler->handle([
            'id_address' => (string) $activeAddress->id,
        ]);

        self::assertTrue($response['success'], var_export($response, true));

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $fallbackAddress->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $fallbackAddress->id, (int) $freshCart->id_address_invoice);

        $checkoutSession = $this->createCheckoutSession($context, $translator);
        self::assertSame((int) $fallbackAddress->id, (int) $checkoutSession->getIdAddressDelivery());
        self::assertSame((int) $fallbackAddress->id, (int) $checkoutSession->getIdAddressInvoice());
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-delete-%s@example.com', uniqid('', true));
        $customer->is_guest = false;
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddress(\Customer $customer, string $alias): \Address
    {
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->alias = $alias;
        $address->firstname = $customer->firstname;
        $address->lastname = $customer->lastname;
        $address->address1 = '1 rue Integration';
        $address->city = 'Paris';
        $address->postcode = '75001';
        $address->id_country = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        self::assertTrue($address->add());

        return $address;
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
