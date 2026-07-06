<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSelectCarrierHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OpcTempAddress;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressCarrierSelectionStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressStorage;
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

/**
 * docs/MODULE_COMPATIBILITY.md §2 — selectcarrier id-branch (mirror of the carriers handler).
 *
 * When the front resolves a real address id (saved selection or autosave-persisted inline draft),
 * the handler must NOT mount a temp placeholder: the whole request — carrier persistence, cart
 * preview, actionCarrierProcess — runs against the real row the cart is on. The raw inline fields
 * keep the temp branch alive as fallback for the pre-persist window.
 *
 * The CartPresenterHelper stub records the cart pointers AT presentCart() TIME — the mid-request
 * observation point where any mounted temp address is still in place. This is exactly what any
 * module hooked during the cart presentation observes.
 */
class OpcSelectCarrierHandlerAddressBranchIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables(['configuration', 'customer', 'customer_group', 'address', 'cart']);
        \Configuration::loadConfiguration();
    }

    public function testOwnedRequestedAddressSkipsTheTempMountAndPersistsTheCartPointer(): void
    {
        $customer = $this->createCustomer();
        $persistedAddress = $this->createAddress($customer, 'FR', 'My persisted draft');
        $cart = $this->createCart($customer, (int) $persistedAddress->id, (int) $persistedAddress->id);
        $context = $this->createCheckoutContext($customer, $cart);
        $observer = $this->createRecordingCartPresenter($context);

        $response = $this->createSelectCarrierHandler($context, $observer)->handle([
            'delivery_option' => '1,',
            'use_same_address' => '1',
            'id_address_delivery' => (string) $persistedAddress->id,
            // Raw inline fields are still sent by the front (temp-branch fallback contract):
            // the id must win over them.
            'id_country' => (string) \Country::getByIso('FR'),
            'postcode' => '75001',
            'city' => 'Paris',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        self::assertSame((int) $persistedAddress->id, (int) $response['id_address_delivery']);

        // Mid-request: the computation ran against the real persisted row, not a temp placeholder.
        self::assertSame((int) $persistedAddress->id, $observer->observedDeliveryAddressId);
        self::assertSame((int) $persistedAddress->id, $observer->observedInvoiceAddressId);
        self::assertStringStartsNotWith(OpcTempAddress::TEMPORARY_ADDRESS_ALIAS_PREFIX, $observer->observedDeliveryAlias);

        // The pointer is PERSISTED (id-branch), not restored to a pre-request value.
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame((int) $persistedAddress->id, (int) $freshCart->id_address_delivery);
        self::assertSame((int) $persistedAddress->id, (int) $freshCart->id_address_invoice);
        self::assertSame(0, $this->countTemporaryAddresses());
    }

    public function testUnownedRequestedAddressIsRejectedWithoutTouchingTheCart(): void
    {
        $customer = $this->createCustomer();
        $stranger = $this->createCustomer();
        $strangerAddress = $this->createAddress($stranger, 'FR', 'Not yours');
        $cart = $this->createCart($customer, 0, 0);
        $context = $this->createCheckoutContext($customer, $cart);

        $response = $this->createSelectCarrierHandler($context, $this->createRecordingCartPresenter($context))->handle([
            'delivery_option' => '1,',
            'use_same_address' => '1',
            'id_address_delivery' => (string) $strangerAddress->id,
        ]);

        self::assertFalse($response['success'] ?? true, var_export($response, true));

        $freshCart = new \Cart((int) $cart->id);
        self::assertSame(0, (int) $freshCart->id_address_delivery);
        self::assertSame(0, (int) $freshCart->id_address_invoice);
        self::assertSame(0, $this->countTemporaryAddresses());
    }

    public function testFrontSentInvoiceIdSkipsTheTempInvoiceMount(): void
    {
        \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_invoice');

        try {
            $customer = $this->createCustomer();
            $deliveryAddress = $this->createAddress($customer, 'FR', 'Delivery draft');
            $invoiceAddress = $this->createAddress($customer, 'FR', 'Billing draft');
            $cart = $this->createCart($customer, (int) $deliveryAddress->id, (int) $invoiceAddress->id);
            $context = $this->createCheckoutContext($customer, $cart);
            $observer = $this->createRecordingCartPresenter($context);

            $response = $this->createSelectCarrierHandler($context, $observer)->handle([
                'delivery_option' => '1,',
                'use_same_address' => '0',
                'id_address_delivery' => (string) $deliveryAddress->id,
                'id_address_invoice' => (string) $invoiceAddress->id,
                // Raw invoice fields still sent (fallback contract): the id must win over them.
                'invoice_id_country' => (string) \Country::getByIso('FR'),
                'invoice_postcode' => '69001',
                'invoice_city' => 'Lyon',
            ]);

            self::assertTrue($response['success'] ?? false, var_export($response, true));

            // Mid-request: the invoice side stayed on the persisted billing row — no temp mount.
            self::assertSame((int) $invoiceAddress->id, $observer->observedInvoiceAddressId);
            self::assertStringStartsNotWith(OpcTempAddress::TEMPORARY_ADDRESS_ALIAS_PREFIX, $observer->observedInvoiceAlias);

            $freshCart = new \Cart((int) $cart->id);
            self::assertSame((int) $invoiceAddress->id, (int) $freshCart->id_address_invoice);
            self::assertSame(0, $this->countTemporaryAddresses());
        } finally {
            \Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_delivery');
        }
    }

    public function testWithoutARequestedIdTheTempFallbackStillRuns(): void
    {
        $customer = $this->createCustomer();
        $cart = $this->createCart($customer, 0, 0); // pre-persist window: nothing on the cart yet
        $context = $this->createCheckoutContext($customer, $cart);
        $observer = $this->createRecordingCartPresenter($context);

        $response = $this->createSelectCarrierHandler($context, $observer)->handle([
            'delivery_option' => '1,',
            'use_same_address' => '1',
            'id_country' => (string) \Country::getByIso('US'),
            'postcode' => '10001',
            'city' => 'New York',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));

        // Mid-request the computation ran against a mounted temp placeholder (fallback contract) …
        self::assertGreaterThan(0, $observer->observedDeliveryAddressId);
        self::assertStringStartsWith(OpcTempAddress::TEMPORARY_ADDRESS_ALIAS_PREFIX, $observer->observedDeliveryAlias);

        // … and the finally-cleanup restored the cart and deleted the throwaway row.
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame(0, (int) $freshCart->id_address_delivery);
        self::assertSame(0, $this->countTemporaryAddresses());
    }

    /**
     * @return CartPresenterHelper&object{observedDeliveryAddressId:int,observedInvoiceAddressId:int,observedDeliveryAlias:string,observedInvoiceAlias:string}
     */
    private function createRecordingCartPresenter(\Context $context): CartPresenterHelper
    {
        return new class($context) extends CartPresenterHelper {
            public int $observedDeliveryAddressId = 0;
            public int $observedInvoiceAddressId = 0;
            public string $observedDeliveryAlias = '';
            public string $observedInvoiceAlias = '';
            private \Context $testContext;

            public function __construct(\Context $context)
            {
                parent::__construct($context);
                $this->testContext = $context;
            }

            public function presentCart(): CartLazyArray
            {
                // Mid-request observation point: any mounted temp address is still in place here —
                // this is the cart state every module hooked during the presentation observes.
                $this->observedDeliveryAddressId = (int) $this->testContext->cart->id_address_delivery;
                $this->observedInvoiceAddressId = (int) $this->testContext->cart->id_address_invoice;
                $this->observedDeliveryAlias = $this->readAlias($this->observedDeliveryAddressId);
                $this->observedInvoiceAlias = $this->readAlias($this->observedInvoiceAddressId);

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

            private function readAlias(int $addressId): string
            {
                if ($addressId <= 0) {
                    return '';
                }

                return (string) \Db::getInstance()->getValue(
                    'SELECT alias FROM `' . _DB_PREFIX_ . 'address` WHERE id_address = ' . $addressId
                );
            }
        };
    }

    private function createSelectCarrierHandler(\Context $context, CartPresenterHelper $cartPresenter): OnePageCheckoutSelectCarrierHandler
    {
        return new OnePageCheckoutSelectCarrierHandler(
            $context,
            $this->createTranslator(),
            $this->createDeliveryOptionsFinderStub(),
            $this->createCheckoutSessionFactoryStub($context),
            $cartPresenter,
            $this->createMock(TempAddressCarrierSelectionStorage::class),
            $this->createMock(TempAddressStorage::class)
        );
    }

    private function createCheckoutSessionFactoryStub(\Context $context): CheckoutSessionFactory
    {
        // CheckoutSession::setDeliveryOption recomputes cart totals through the Symfony kernel
        // container, which this harness does not boot; these tests cover the address-branch
        // contract, so persisting the selection is a no-op.
        return new class($context, $this->createTranslator(), $this->createDeliveryOptionsFinderStub()) extends CheckoutSessionFactory {
            private \Context $testContext;
            private \DeliveryOptionsFinder $testFinder;

            public function __construct(\Context $context, TranslatorInterface $translator, \DeliveryOptionsFinder $finder)
            {
                parent::__construct($context, $translator, $finder);
                $this->testContext = $context;
                $this->testFinder = $finder;
            }

            public function create(): \CheckoutSession
            {
                return new class($this->testContext, $this->testFinder) extends \CheckoutSession {
                    public function setDeliveryOption($deliveryOption)
                    {
                        // No-op: carrier persistence is outside this contract.
                    }
                };
            }
        };
    }

    /**
     * @return TranslatorInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    private function createDeliveryOptionsFinderStub(): \DeliveryOptionsFinder
    {
        // Deterministic stub: these tests cover the address-branch contract,
        // not the carrier computation itself.
        return new class extends \DeliveryOptionsFinder {
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
    }

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-addrbranch-%s@example.com', uniqid('', true));
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddress(\Customer $customer, string $countryIso, string $alias): \Address
    {
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = (int) \Country::getByIso($countryIso);
        $address->alias = $alias;
        $address->firstname = 'Integration';
        $address->lastname = 'Customer';
        $address->address1 = '12 rue des Tests';
        $address->postcode = '75001';
        $address->city = 'Paris';
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
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address` WHERE alias LIKE "' . OpcTempAddress::TEMPORARY_ADDRESS_ALIAS_PREFIX . '%"'
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
