<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutGuestInitHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcGuestInitHandlerTest extends TestCase
{
    private TranslatorInterface|MockObject $translator;

    protected function setUp(): void
    {
        \MockDb::resetBehaviors();

        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator
            ->method('trans')
            ->willReturnArgument(0)
        ;
    }

    public function testItReturnsErrorWhenOpcIsDisabled(): void
    {
        [$handler] = $this->buildHandler(['opc_enabled' => false]);

        $response = $handler->handle([]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertNotEmpty($response['errors']['']);
    }

    public function testItReturnsSuccessWhenGuestCheckoutIsDisabled(): void
    {
        [$handler] = $this->buildHandler(['guest_checkout_enabled' => false]);

        $response = $handler->handle([]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(0, $response['id_customer']);
    }

    public function testItReturnsNoopWhenCartIsNotLoaded(): void
    {
        $cart = new LightweightCart();
        $cart->id = 0;
        $cart->productsCount = 1;

        [$handler, $opcForm] = $this->buildHandler(['cart' => $cart]);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'guest@example.com',
            'token' => 'expected-token',
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(0, $response['id_customer']);
    }

    public function testItReturnsNoopWhenCartIsEmpty(): void
    {
        $cart = new LightweightCart();
        $cart->id = 1;
        $cart->productsCount = 0;

        [$handler, $opcForm] = $this->buildHandler(['cart' => $cart]);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'guest@example.com',
            'token' => 'expected-token',
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(0, $response['id_customer']);
    }

    public function testItRejectsCreationWhenTokenIsMissing(): void
    {
        [$handler, $opcForm] = $this->buildHandler();

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle(['email' => 'guest@example.com']);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('token', $response['errors']);
        self::assertNotEmpty($response['errors']['token']);
        self::assertArrayNotHasKey('token', $response);
        self::assertArrayNotHasKey('static_token', $response);
    }

    public function testItIgnoresGuestInitForExistingRegisteredCustomer(): void
    {
        $cart = new LightweightCart();
        $cart->id = 1;
        $cart->id_customer = 42;
        $cart->productsCount = 1;

        [$handler, $opcForm] = $this->buildHandler(['cart' => $cart]);

        $registeredCustomer = new LightweightCustomer();
        $registeredCustomer->id = 42;
        $registeredCustomer->is_guest = 0;
        $registeredCustomer->email = 'registered@example.com';
        $handler->setCustomerById(42, $registeredCustomer);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'registered@example.com',
            'token' => 'expected-token',
        ]);

        self::assertTrue($response['success']);
        self::assertSame(42, $response['id_customer']);
        self::assertFalse($response['customer_created']);
    }

    public function testItReturnsNeutralSuccessWhenEmailBelongsToAnotherCustomer(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest]);
        $handler->setCustomerById(10, $guest);
        $handler->setCustomerIdByEmail('used@example.com', 99);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'used@example.com',
            'token' => 'expected-token',
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(10, $response['id_customer']);
    }

    public function testItReturnsFormErrorsWhenGuestInitSaveFails(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest]);
        $handler->setCustomerById(10, $guest);

        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturn(false)
        ;
        $opcForm
            ->expects($this->once())
            ->method('getErrors')
            ->willReturn([
                'email' => ['Unable to save guest customer'],
            ])
        ;

        $response = $handler->handle([
            'email' => 'invalid-email',
            'token' => 'expected-token',
        ]);

        self::assertFalse($response['success']);
        self::assertSame(['Unable to save guest customer'], $response['errors']['email']);
    }

    public function testItReturnsCreatedResponseWhenGuestInitSucceeds(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest]);
        $handler->setCustomerById(10, $guest);

        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturn(true)
        ;

        $response = $handler->handle([
            'email' => 'invalid-email',
            'token' => 'expected-token',
        ]);

        self::assertTrue($response['success']);
        self::assertTrue($response['customer_created']);
        self::assertSame(10, $response['id_customer']);
    }

    public function testItReturnsErrorWhenCartCustomerSyncFailsAfterGuestInitSuccess(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';
        $cart = new LightweightCart();
        $cart->id = 1;
        $cart->productsCount = 1;
        $cart->updateResult = false;

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest, 'cart' => $cart]);
        $handler->setCustomerById(10, $guest);

        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturn(true)
        ;

        $response = $handler->handle([
            'email' => 'invalid-email',
            'token' => 'expected-token',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertNotEmpty($response['errors']['']);
    }

    public function testItStaysNoopWhenGuestEmailIsAlreadySynced(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest]);
        $handler->setCustomerById(10, $guest);
        $handler->setCustomerIdByEmail('guest@example.com', 10);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'guest@example.com',
            'token' => 'expected-token',
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(10, $response['id_customer']);
    }

    public function testItUpdatesExistingGuestEmailWithoutUsingFormSubmission(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest]);
        $handler->setCustomerById(10, $guest);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'updated@example.com',
            'token' => 'expected-token',
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(10, $response['id_customer']);
        self::assertSame('updated@example.com', $guest->email);
    }

    public function testItReturnsErrorWhenExistingGuestEmailUpdateFails(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';
        $guest->updateResult = false;

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest]);
        $handler->setCustomerById(10, $guest);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'updated@example.com',
            'token' => 'expected-token',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('email', $response['errors']);
        self::assertNotEmpty($response['errors']['email']);
    }

    public function testItReturnsErrorWhenExistingGuestCartCustomerSyncFails(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';
        $cart = new LightweightCart();
        $cart->id = 1;
        $cart->productsCount = 1;
        $cart->updateResult = false;

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest, 'cart' => $cart]);
        $handler->setCustomerById(10, $guest);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'updated@example.com',
            'token' => 'expected-token',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertNotEmpty($response['errors']['']);
    }

    public function testItIgnoresCreationWhenEmailAlreadyExistsAndNoGuestIsLinked(): void
    {
        [$handler, $opcForm] = $this->buildHandler(['expected_token' => 'expected-token']);
        $handler->setCustomerIdByEmail('john@doe.example', 55);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'john@doe.example',
            'token' => 'expected-token',
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(0, $response['id_customer']);
    }

    public function testItDoesNotNoopWhenCartCustomerReferenceIsStale(): void
    {
        $cart = new LightweightCart();
        $cart->id = 1;
        $cart->id_customer = 999;
        $cart->productsCount = 1;

        [$handler, $opcForm] = $this->buildHandler(['cart' => $cart]);

        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturn(false)
        ;
        $opcForm
            ->expects($this->once())
            ->method('getErrors')
            ->willReturn([
                'email' => ['Unable to save guest customer'],
            ])
        ;

        $response = $handler->handle([
            'email' => 'new@example.com',
            'token' => 'expected-token',
        ]);

        self::assertFalse($response['success']);
        self::assertSame(['Unable to save guest customer'], $response['errors']['email']);
    }

    public function testItReturnsErrorWhenCartCustomerReferenceIsStaleDuringExistingGuestEmailUpdate(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';
        $cart = new LightweightCart();
        $cart->id = 1;
        $cart->id_customer = 999;
        $cart->productsCount = 1;

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest, 'cart' => $cart]);
        $handler->setCustomerById(10, $guest);
        \MockDb::setGetValueCallback(static function ($sql) {
            if (is_string($sql) && str_contains($sql, 'FROM `ps_cart` WHERE `id_cart` = 1')) {
                return 999;
            }

            return false;
        });

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'updated@example.com',
            'token' => 'expected-token',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertNotEmpty($response['errors']['']);
        self::assertSame(999, (int) $cart->id_customer);
    }

    public function testItReturnsErrorWhenCartClaimLosesRaceAndFreshOwnerCannotBeReadInUnitDbMock(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';

        $concurrentWinner = new LightweightCustomer();
        $concurrentWinner->id = 88;
        $concurrentWinner->is_guest = 1;
        $concurrentWinner->email = 'winner@example.com';

        $cart = new LightweightCart();
        $cart->id = 1;
        $cart->id_customer = 0;
        $cart->productsCount = 1;

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest, 'cart' => $cart]);
        $handler->setCustomerById(10, $guest);
        $handler->setCustomerById(88, $concurrentWinner);
        $handler->forceReplaceRaceWinner(1, 88);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'updated@example.com',
            'token' => 'expected-token',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertNotEmpty($response['errors']['']);
        self::assertSame(88, (int) $cart->id_customer);
    }

    public function testItRequiresTokenEvenForExistingGuestUpdate(): void
    {
        $guest = new LightweightCustomer();
        $guest->id = 10;
        $guest->is_guest = 1;
        $guest->email = 'guest@example.com';

        [$handler, $opcForm] = $this->buildHandler(['customer' => $guest]);
        $handler->setCustomerById(10, $guest);

        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $response = $handler->handle([
            'email' => 'updated@example.com',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('token', $response['errors']);
        self::assertNotEmpty($response['errors']['token']);
        self::assertArrayNotHasKey('token', $response);
        self::assertArrayNotHasKey('static_token', $response);
    }

    /**
     * @param array{
     *  opc_enabled?: bool,
     *  guest_checkout_enabled?: bool,
     *  expected_token?: string,
     *  \customer?: \Customer,
     *  \cart?: \Cart
     * } $options
     *
     * @return array{TestableCheckoutGuestInitHandler, OnePageCheckoutForm&MockObject, \CustomerPersister&MockObject}
     */
    private function buildHandler(array $options = []): array
    {
        $context = new LightweightContext();
        $context->customer = $options['customer'] ?? new LightweightCustomer();
        $context->cart = $options['cart'] ?? new LightweightCart();
        if (empty($options['cart'])) {
            $context->cart->id = 1;
            $context->cart->productsCount = 1;
        }

        $opcForm = $this->getMockBuilder(OnePageCheckoutForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['submitGuestInit', 'getErrors'])
            ->getMock()
        ;
        $customerPersister = $this->getMockBuilder(\CustomerPersister::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock()
        ;
        $customerPersister
            ->method('save')
            ->willReturnCallback(static function (\Customer $customer): bool {
                if (property_exists($customer, 'updateResult') && $customer->updateResult === false) {
                    return false;
                }

                return true;
            })
        ;

        $handler = new TestableCheckoutGuestInitHandler(
            $context,
            $opcForm,
            $this->translator,
            $customerPersister,
            $options['opc_enabled'] ?? true
        );
        $handler->setCartById((int) $context->cart->id, $context->cart);
        $handler->setGuestCheckoutEnabled($options['guest_checkout_enabled'] ?? true);
        $handler->setExpectedToken($options['expected_token'] ?? 'expected-token');

        return [$handler, $opcForm, $customerPersister];
    }
}

class TestableCheckoutGuestInitHandler extends OnePageCheckoutGuestInitHandler
{
    /**
     * @var bool
     */
    private $guestCheckoutEnabled = true;

    /**
     * @var string
     */
    private $expectedToken = '';

    /**
     * @var array<int, \Customer>
     */
    private $customersById = [];

    /**
     * @var array<string, int>
     */
    private $customerIdsByEmail = [];

    /**
     * @var array<int, \Cart>
     */
    private $cartsById = [];

    /**
     * @var array<int, int>
     */
    private $forcedRaceWinnerByCartId = [];

    public function setGuestCheckoutEnabled(bool $guestCheckoutEnabled): void
    {
        $this->guestCheckoutEnabled = $guestCheckoutEnabled;
    }

    public function setExpectedToken(string $expectedToken): void
    {
        $this->expectedToken = $expectedToken;
    }

    public function setCustomerById(int $customerId, \Customer $customer): void
    {
        $this->customersById[$customerId] = $customer;
    }

    public function setCustomerIdByEmail(string $email, int $customerId): void
    {
        $this->customerIdsByEmail[strtolower($email)] = $customerId;
    }

    public function setCartById(int $cartId, \Cart $cart): void
    {
        if ($cartId <= 0) {
            return;
        }

        $this->cartsById[$cartId] = $cart;
    }

    public function forceReplaceRaceWinner(int $cartId, int $winnerCustomerId): void
    {
        if ($cartId <= 0 || $winnerCustomerId <= 0) {
            return;
        }

        $this->forcedRaceWinnerByCartId[$cartId] = $winnerCustomerId;
    }

    protected function isGuestCheckoutEnabled(): bool
    {
        return $this->guestCheckoutEnabled;
    }

    protected function loadCustomerById(int $customerId): \Customer
    {
        return $this->customersById[$customerId] ?? new LightweightCustomer();
    }

    protected function findCustomerByEmail(string $email): int
    {
        return $this->customerIdsByEmail[strtolower($email)] ?? 0;
    }

    protected function getExpectedToken(): string
    {
        return $this->expectedToken;
    }

    protected function loadCartById(int $cartId): \Cart
    {
        return $this->cartsById[$cartId] ?? new LightweightCart();
    }

    protected function compareAndSetCartCustomerId(int $cartId, int $customerId): int
    {
        return $this->replaceCartCustomerIdIfMatches($cartId, 0, $customerId);
    }

    protected function replaceCartCustomerIdIfMatches(int $cartId, int $expectedCustomerId, int $newCustomerId): int
    {
        if (!isset($this->cartsById[$cartId])) {
            return -1;
        }

        $cart = $this->cartsById[$cartId];
        if (property_exists($cart, 'updateResult') && $cart->updateResult === false) {
            return -1;
        }

        if (isset($this->forcedRaceWinnerByCartId[$cartId])) {
            $cart->id_customer = $this->forcedRaceWinnerByCartId[$cartId];

            return 0;
        }

        if ((int) $cart->id_customer !== $expectedCustomerId) {
            return 0;
        }

        $cart->id_customer = $newCustomerId;

        return 1;
    }
}

class LightweightContext extends \Context
{
    public function __construct()
    {
    }
}

class LightweightCustomer extends \Customer
{
    /**
     * @var bool
     */
    public $updateResult = true;

    public function __construct()
    {
    }

    /**
     * @param bool $nullValues
     *
     * @return bool
     */
    public function update($nullValues = false)
    {
        return (bool) $this->updateResult;
    }
}

class LightweightCart extends \Cart
{
    /**
     * @var int
     */
    public $productsCount = 0;

    /**
     * @var bool
     */
    public $updateResult = true;

    public function __construct()
    {
    }

    /**
     * @return int
     */
    public function nbProducts($id_product = false)
    {
        return (int) $this->productsCount;
    }

    /**
     * @param bool $nullValues
     *
     * @return bool
     */
    public function update($nullValues = false)
    {
        return (bool) $this->updateResult;
    }
}
