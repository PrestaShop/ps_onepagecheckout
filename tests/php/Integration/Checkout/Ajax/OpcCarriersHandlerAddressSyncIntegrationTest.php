<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutCarriersHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressCarrierSelectionStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressStorage;
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcCarriersHandlerAddressSyncIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables(['configuration', 'customer', 'customer_group', 'address', 'cart']);
        \Configuration::loadConfiguration();
    }

    public function testSyncsInvoiceOntoDeliveryWhenUseSameAddress(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $addressB = $this->createAddress($customer, 'B');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressA->id); // delivery=A, invoice=A
        $context = $this->createCheckoutContext($customer, $cart);

        $response = $this->createHandler($context)->handle([
            'id_address_delivery' => (string) $addressB->id,
            'use_same_address' => '1',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressB->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $addressB->id, (int) $freshCart->id_address_invoice, 'invoice must follow delivery when use same address');
    }

    public function testPreservesSeparateInvoiceWhenNotUseSameAddress(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $addressB = $this->createAddress($customer, 'B');
        $addressC = $this->createAddress($customer, 'C');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressC->id); // delivery=A, invoice=C (separate)
        $context = $this->createCheckoutContext($customer, $cart);

        $response = $this->createHandler($context)->handle([
            'id_address_delivery' => (string) $addressB->id,
            'use_same_address' => '0',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressB->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $addressC->id, (int) $freshCart->id_address_invoice, 'separate invoice must be preserved');
    }

    /**
     * Regression guard: when the customer keeps a separate billing address (use_same off), changing
     * the delivery must NEVER overwrite the invoice address, even if it happened to equal the old
     * delivery address. The previous (heuristic) implementation wrongly overwrote it in this case.
     */
    public function testDoesNotOverwriteInvoiceWhenNotUseSameEvenIfInvoiceEqualsDelivery(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $addressB = $this->createAddress($customer, 'B');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressA->id); // delivery=A, invoice=A
        $context = $this->createCheckoutContext($customer, $cart);

        $response = $this->createHandler($context)->handle([
            'id_address_delivery' => (string) $addressB->id,
            'use_same_address' => '0',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressB->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $addressA->id, (int) $freshCart->id_address_invoice, 'invoice must stay on the customer choice when not use same address');
    }

    public function testSyncsInvoiceWhenUseSameAndInvoiceWasUnset(): void
    {
        $customer = $this->createCustomer();
        $addressB = $this->createAddress($customer, 'B');
        $cart = $this->createCart($customer, 0, 0); // delivery/invoice not set
        $context = $this->createCheckoutContext($customer, $cart);

        $response = $this->createHandler($context)->handle([
            'id_address_delivery' => (string) $addressB->id,
            'use_same_address' => '1',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressB->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $addressB->id, (int) $freshCart->id_address_invoice);
    }

    public function testPreservesInvoiceWhenUseSameAddressIsOmitted(): void
    {
        $customer = $this->createCustomer();
        $addressA = $this->createAddress($customer, 'A');
        $addressB = $this->createAddress($customer, 'B');
        $addressC = $this->createAddress($customer, 'C');
        $cart = $this->createCart($customer, (int) $addressA->id, (int) $addressC->id); // delivery=A, invoice=C (separate)
        $context = $this->createCheckoutContext($customer, $cart);

        $response = $this->createHandler($context)->handle([
            'id_address_delivery' => (string) $addressB->id,
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $addressB->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $addressC->id, (int) $freshCart->id_address_invoice, 'omitted use_same_address must preserve invoice');
    }

    public function testSavedDeliveryUsesTemporaryInlineInvoiceAddressForPreview(): void
    {
        \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_invoice');

        $customer = $this->createCustomer();
        $deliveryAddress = $this->createAddress($customer, 'Delivery');
        $cart = $this->createCart($customer, (int) $deliveryAddress->id, (int) $deliveryAddress->id);
        $context = $this->createCheckoutContext($customer, $cart);
        $previewProbe = (object) [
            'invoiceAddressId' => 0,
            'invoiceCountryId' => 0,
        ];

        $cartPresenter = new class($context, $previewProbe) extends CartPresenterHelper {
            private \Context $context;
            private \stdClass $previewProbe;

            public function __construct(\Context $context, \stdClass $previewProbe)
            {
                $this->context = $context;
                $this->previewProbe = $previewProbe;
            }

            public function presentCart(): CartLazyArray
            {
                $invoiceAddressId = (int) $this->context->cart->id_address_invoice;
                $this->previewProbe->invoiceAddressId = $invoiceAddressId;
                $this->previewProbe->invoiceCountryId = (int) (new \Address($invoiceAddressId))->id_country;

                return new class extends CartLazyArray {
                    public function __construct()
                    {
                        // Bypass the parent constructor: offsetGet is fully overridden below.
                    }

                    #[\ReturnTypeWillChange]
                    public function offsetGet($index)
                    {
                        return [];
                    }
                };
            }
        };

        $response = $this->createHandler($context, $cartPresenter)->handle([
            'id_address_delivery' => (string) $deliveryAddress->id,
            'use_same_address' => '0',
            'invoice_id_country' => (string) \Country::getByIso('US'),
            'invoice_postcode' => '10001',
            'invoice_city' => 'New York',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        self::assertNotSame((int) $deliveryAddress->id, $previewProbe->invoiceAddressId);
        self::assertSame((int) \Country::getByIso('US'), $previewProbe->invoiceCountryId);
        self::assertSame(0, $this->countTemporaryAddresses());

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $deliveryAddress->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $deliveryAddress->id, (int) $freshCart->id_address_invoice);
    }

    private function createHandler(\Context $context, ?CartPresenterHelper $cartPresenter = null): OnePageCheckoutCarriersHandler
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        // Deterministic stubs: this test only covers the cart address-consistency contract,
        // not the carrier computation itself.
        $deliveryFinder = new class extends \DeliveryOptionsFinder {
            public function __construct()
            {
                // Stub: bypass the heavy parent constructor; only the getters below are exercised.
            }

            public function getDeliveryOptions()
            {
                return [];
            }

            public function getSelectedDeliveryOption()
            {
                return '';
            }
        };
        if (!$cartPresenter) {
            // This harness boots no Symfony kernel, so the real CartPresenter cannot run. Return an
            // inert CartLazyArray; the test only asserts the persisted cart address state, which is
            // saved before the presenter is reached.
            $cartPresenter = new class($context) extends CartPresenterHelper {
                public function presentCart(): CartLazyArray
                {
                    return new class extends CartLazyArray {
                        public function __construct()
                        {
                            // Bypass the parent constructor: offsetGet is fully overridden below.
                        }

                        #[\ReturnTypeWillChange]
                        public function offsetGet($index)
                        {
                            return [];
                        }
                    };
                }
            };
        }

        return new OnePageCheckoutCarriersHandler(
            $context,
            $translator,
            $deliveryFinder,
            new CheckoutCustomerContextResolver($context),
            null, // default CheckoutSessionFactory, built with the delivery finder stub above
            $cartPresenter,
            $this->createMock(TempAddressCarrierSelectionStorage::class),
            $this->createMock(TempAddressStorage::class)
        );
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-carriers-%s@example.com', uniqid('', true));
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

    private function countTemporaryAddresses(): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address` WHERE alias LIKE "temp_opc_%"'
        );
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
