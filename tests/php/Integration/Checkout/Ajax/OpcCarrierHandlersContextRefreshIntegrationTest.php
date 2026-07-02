<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutCarriersHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSelectCarrierHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OpcTempAddress;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressCarrierSelectionStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressStorage;
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

/**
 * The front re-sync (context_refresh) must reflect the cart state the carrier response was computed
 * against — the inline-typed country and the chosen delivery option live on a TEMPORARY address that
 * the handlers delete (and the cart pointer they restore) in their finally-cleanup. A context_refresh
 * computed AFTER the handler returns (the AbstractOpcJsonFrontController fallback) reads the restored
 * cart: default country, invalidated delivery-option map — and re-syncs window.prestashop.* / the
 * body classes to WRONG values on every inline carrier interaction. The handlers must therefore
 * attach their own context_refresh while the temp state is still in place.
 */
class OpcCarrierHandlersContextRefreshIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables(['configuration', 'customer', 'customer_group', 'address', 'cart']);
        \Configuration::loadConfiguration();
    }

    public function testCarriersResponseCarriesContextRefreshForTheInlineTypedCountry(): void
    {
        $customer = $this->createCustomer();
        $cart = $this->createCart($customer, 0, 0); // guest inline flow: no persisted address yet
        $context = $this->createCheckoutContext($customer, $cart);
        $inlineCountryId = (int) \Country::getByIso('US');
        self::assertGreaterThan(0, $inlineCountryId);

        $response = $this->createCarriersHandler($context)->handle([
            'use_same_address' => '1',
            'id_country' => (string) $inlineCountryId,
            'postcode' => '10001',
            'city' => 'New York',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        self::assertArrayHasKey('context_refresh', $response, 'the carriers response must resync the front from the state it was computed against');
        self::assertSame('US', $response['context_refresh']['country']['iso_code'] ?? null, 'context_refresh must carry the inline-typed country, not the restored default');

        // The temp-address cleanup contract is unchanged.
        self::assertSame(0, $this->countTemporaryAddresses());
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame(0, (int) $freshCart->id_address_delivery, 'the original cart delivery pointer must be restored');
    }

    public function testSelectCarrierResponseCarriesContextRefreshForTheInlineTypedCountry(): void
    {
        $customer = $this->createCustomer();
        $cart = $this->createCart($customer, 0, 0); // guest inline flow: no persisted address yet
        $context = $this->createCheckoutContext($customer, $cart);
        $inlineCountryId = (int) \Country::getByIso('US');
        self::assertGreaterThan(0, $inlineCountryId);

        $response = $this->createSelectCarrierHandler($context)->handle([
            'delivery_option' => '1,',
            'use_same_address' => '1',
            'id_country' => (string) $inlineCountryId,
            'postcode' => '10001',
            'city' => 'New York',
        ]);

        self::assertTrue($response['success'] ?? false, var_export($response, true));
        self::assertArrayHasKey('context_refresh', $response, 'the selectcarrier response must resync the front from the state it was computed against');
        self::assertSame('US', $response['context_refresh']['country']['iso_code'] ?? null, 'context_refresh must carry the inline-typed country, not the restored default');

        // The temp-address cleanup contract is unchanged.
        self::assertSame(0, $this->countTemporaryAddresses());
        $freshCart = new \Cart((int) $cart->id);
        self::assertSame(0, (int) $freshCart->id_address_delivery, 'the original cart delivery pointer must be restored');
    }

    private function createCarriersHandler(\Context $context): OnePageCheckoutCarriersHandler
    {
        return new OnePageCheckoutCarriersHandler(
            $context,
            $this->createTranslator(),
            $this->createDeliveryOptionsFinderStub(),
            new CheckoutCustomerContextResolver($context),
            null, // default CheckoutSessionFactory, built with the delivery finder stub above
            $this->createCartPresenterStub($context),
            $this->createMock(TempAddressCarrierSelectionStorage::class),
            $this->createMock(TempAddressStorage::class)
        );
    }

    private function createSelectCarrierHandler(\Context $context): OnePageCheckoutSelectCarrierHandler
    {
        return new OnePageCheckoutSelectCarrierHandler(
            $context,
            $this->createTranslator(),
            $this->createDeliveryOptionsFinderStub(),
            $this->createCheckoutSessionFactoryStub($context),
            $this->createCartPresenterStub($context),
            $this->createMock(TempAddressCarrierSelectionStorage::class),
            $this->createMock(TempAddressStorage::class)
        );
    }

    private function createCheckoutSessionFactoryStub(\Context $context): CheckoutSessionFactory
    {
        // CheckoutSession::setDeliveryOption recomputes cart totals through the Symfony kernel
        // container, which this harness does not boot; these tests only cover the response
        // context contract, so persisting the selection is a no-op.
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
        // Deterministic stub: these tests cover the response context contract,
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

    private function createCartPresenterStub(\Context $context): CartPresenterHelper
    {
        // This harness boots no Symfony kernel, so the real CartPresenter cannot run.
        return new class($context) extends CartPresenterHelper {
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

    private function createCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-ctxrefresh-%s@example.com', uniqid('', true));
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
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
