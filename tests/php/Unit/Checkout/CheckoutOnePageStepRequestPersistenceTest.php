<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitValidationStateStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\CheckoutOnePageStep;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use Symfony\Contracts\Translation\TranslatorInterface;

class TestableCheckoutOnePageStep extends CheckoutOnePageStep
{
    private \CheckoutProcess $mockProcess;

    public function setMockProcess(\CheckoutProcess $process): void
    {
        $this->mockProcess = $process;
    }

    public function getCheckoutProcess(): \CheckoutProcess
    {
        return $this->mockProcess;
    }
}

class CheckoutOnePageStepRequestPersistenceTest extends TestCase
{
    private \Cart|MockObject $cart;
    private \Context|MockObject $context;
    private \CheckoutSession|MockObject $session;
    private \CheckoutProcess|MockObject $checkoutProcess;
    private OnePageCheckoutForm|MockObject $opcForm;
    private \PaymentOptionsFinder|MockObject $paymentOptionsFinder;
    private \ConditionsToApproveFinder|MockObject $conditionsToApproveFinder;
    private InMemoryCheckoutSessionDataDb $storageDb;
    private TestableCheckoutOnePageStep $step;

    protected function setUp(): void
    {
        $this->cart = $this->createMock(\Cart::class);
        $this->cart->id = 42;
        $this->cart->method('isVirtualCart')->willReturn(false);
        $this->cart->method('getOrderTotal')->willReturn(10.0);

        $customer = $this->createMock(\Customer::class);
        $customer->method('isLogged')->willReturn(true);
        $customer->method('isGuest')->willReturn(false);
        $customer->id = 42;
        $customer->email = 'existing@example.com';

        $language = $this->createMock(\Language::class);
        $language->id = 1;

        $this->context = $this->createMock(\Context::class);
        $this->context->cart = $this->cart;
        $this->context->customer = $customer;
        $this->context->language = $language;
        $this->context->smarty = $this->createMock(\Smarty::class);
        $this->context->cookie = new class {
            public array $values = [];

            public function __set(string $key, string $value): void
            {
                $this->values[$key] = $value;
            }

            public function __get(string $key): string
            {
                return $this->values[$key] ?? '';
            }

            public function __unset(string $key): void
            {
                unset($this->values[$key]);
            }

            public function write(): void
            {
            }
        };

        $this->session = $this->createMock(\CheckoutSession::class);
        $this->session->method('getCart')->willReturn($this->cart);
        $this->session->method('getCustomer')->willReturn($customer);
        $this->session->method('getIdAddressDelivery')->willReturn(0);
        $this->session->method('getIdAddressInvoice')->willReturn(0);

        $this->checkoutProcess = $this->createMock(\CheckoutProcess::class);
        $this->checkoutProcess->method('getCheckoutSession')->willReturn($this->session);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->opcForm = $this->createMock(OnePageCheckoutForm::class);
        $this->paymentOptionsFinder = $this->createMock(\PaymentOptionsFinder::class);
        $this->conditionsToApproveFinder = $this->createMock(\ConditionsToApproveFinder::class);
        $this->storageDb = new InMemoryCheckoutSessionDataDb();

        $this->step = new TestableCheckoutOnePageStep(
            $this->context,
            $translator,
            $this->opcForm,
            $this->paymentOptionsFinder,
            $this->conditionsToApproveFinder,
            null,
            new TestableSubmitValidationStateStorage($this->context, $this->storageDb)
        );
        $this->step->setMockProcess($this->checkoutProcess);
    }

    public function testDeliveryOptionArrayIsPersisted(): void
    {
        $this->session->expects($this->once())
            ->method('setDeliveryOption')
            ->with([5 => '1,']);

        $this->step->handleRequest(['delivery_option' => [5 => '1,']]);
    }

    public function testDeliveryOptionStringIsNotPersisted(): void
    {
        $this->session->expects($this->never())
            ->method('setDeliveryOption');

        $this->step->handleRequest(['delivery_option' => '1,']);
    }

    public function testDeliveryOptionArrayIsPersistedWithoutSubmitSpecificBranch(): void
    {
        $this->session->expects($this->once())
            ->method('setDeliveryOption')
            ->with([5 => '1,']);

        $this->step->handleRequest([
            'delivery_option' => [5 => '1,'],
        ]);
    }

    public function testPersistedValidationErrorsAreFlushedAfterFirstRestore(): void
    {
        $this->step->restorePersistedData([
            'validation_errors' => [
                'payment' => [
                    'paymentMethod' => 'Please select a payment method.',
                ],
            ],
        ]);

        self::assertSame([
            'payment' => [
                'paymentMethod' => 'Please select a payment method.',
            ],
        ], $this->step->getValidationErrors());
        self::assertSame([
            'validation_errors' => [],
        ], $this->step->getDataToPersist());
    }

    public function testStepRestoresLastFailedSubmitStateFromModuleStorage(): void
    {
        $this->context->cart->checkout_session_data = json_encode([
            '_opc_submit_validation_state' => [
                'validation_errors' => [
                    'payment' => [
                        'paymentMethod' => 'Please select a payment method.',
                    ],
                ],
                'form_errors' => [
                    'firstname' => ['The firstname field is required.'],
                ],
                'submitted_values' => [
                    'firstname' => 'Ada',
                    'use_same_address' => '0',
                ],
            ],
        ]);

        $this->opcForm->expects($this->once())
            ->method('restoreSubmissionState')
            ->with(
                [
                    'firstname' => 'Ada',
                    'use_same_address' => '0',
                ],
                [
                    'firstname' => ['The firstname field is required.'],
                ]
            )
            ->willReturnSelf();

        $this->step->handleRequest([]);

        self::assertSame([
            'payment' => [
                'paymentMethod' => 'Please select a payment method.',
            ],
        ], $this->step->getValidationErrors());
        self::assertSame([
            'validation_errors' => [],
        ], $this->step->getDataToPersist());
        self::assertSame('', $this->context->cart->checkout_session_data);
    }

    public function testStepConsumesTopLevelSubmitErrorOnlyOnceAcrossReloads(): void
    {
        $this->context->cart->checkout_session_data = json_encode([
            '_opc_submit_validation_state' => [
                'cart_id' => 42,
                'validation_errors' => [],
                'form_errors' => [
                    '' => ['One-page checkout is currently unavailable.'],
                ],
                'submitted_values' => [],
            ],
        ]);

        $this->opcForm->expects($this->once())
            ->method('restoreSubmissionState')
            ->with([], ['' => ['One-page checkout is currently unavailable.']])
            ->willReturnSelf();

        $this->step->handleRequest([]);

        self::assertSame('', $this->context->cart->checkout_session_data);

        $freshOpcForm = $this->createMock(OnePageCheckoutForm::class);
        $freshOpcForm->expects($this->never())->method('restoreSubmissionState');

        $freshStep = new TestableCheckoutOnePageStep(
            $this->context,
            $this->createConfiguredMock(TranslatorInterface::class, ['trans' => '']),
            $freshOpcForm,
            $this->paymentOptionsFinder,
            $this->conditionsToApproveFinder,
            null,
            new TestableSubmitValidationStateStorage($this->context, $this->storageDb)
        );
        $freshStep->setMockProcess($this->checkoutProcess);

        $freshStep->handleRequest([]);

        self::assertSame([], $freshStep->getValidationErrors());
    }

    public function testStepIgnoresSubmitStateFromAnotherCart(): void
    {
        $this->context->cart->checkout_session_data = json_encode([
            '_opc_submit_validation_state' => [
                'cart_id' => 999,
                'validation_errors' => [],
                'form_errors' => [
                    '' => ['One-page checkout is currently unavailable.'],
                ],
                'submitted_values' => [],
            ],
        ]);

        $this->opcForm->expects($this->never())->method('restoreSubmissionState');

        $this->step->handleRequest([]);

        self::assertSame([], $this->step->getValidationErrors());
        self::assertSame('', $this->context->cart->checkout_session_data);
    }

    public function testItRestoresCountrySpecificDraftFieldsAfterApplyingSavedCountry(): void
    {
        $this->context->cookie->values['opc_address_draft'] = json_encode([
            'ts' => time(),
            'cart_id' => 42,
            'values' => [
                'id_country' => '21',
                'firstname' => 'Ada',
                'city' => 'New York',
                'id_state' => '33',
            ],
        ]);

        // id_state only appears in the field list once the saved (state-bearing) country
        // has been applied, mirroring how the live form rebuilds its format per country.
        $this->opcForm->method('getAddressDraftFieldNames')->willReturnOnConsecutiveCalls(
            ['id_country', 'firstname', 'city'],
            ['id_country', 'firstname', 'city', 'id_state']
        );

        $fillWithCalls = [];
        $this->opcForm->method('fillWith')->willReturnCallback(function (array $params) use (&$fillWithCalls) {
            $fillWithCalls[] = $params;

            return $this->opcForm;
        });

        $this->step->handleRequest([]);

        self::assertNotEmpty($fillWithCalls);
        $finalDraft = end($fillWithCalls);
        // Without the country-aware re-read, id_state would have been filtered out before
        // the country was applied and lost on restore.
        self::assertSame('33', $finalDraft['id_state'] ?? null);
        self::assertSame('New York', $finalDraft['city'] ?? null);
        // Registered customer keeps its account identity rather than the draft's name.
        self::assertArrayNotHasKey('firstname', $finalDraft);
    }

    public function testDeliveryOptionNotPersistedOnVirtualCart(): void
    {
        $cart = $this->createMock(\Cart::class);
        $cart->method('isVirtualCart')->willReturn(true);

        $session = $this->createMock(\CheckoutSession::class);
        $session->method('getCart')->willReturn($cart);
        $session->expects($this->never())->method('setDeliveryOption');
        $session->method('getCustomer')->willReturn($this->context->customer);
        $session->method('getIdAddressDelivery')->willReturn(0);
        $session->method('getIdAddressInvoice')->willReturn(0);

        $checkoutProcess = $this->createMock(\CheckoutProcess::class);
        $checkoutProcess->method('getCheckoutSession')->willReturn($session);

        $context = clone $this->context;
        $context->cart = $cart;

        $step = new TestableCheckoutOnePageStep(
            $context,
            $this->createMock(TranslatorInterface::class),
            $this->opcForm,
            $this->createMock(\PaymentOptionsFinder::class),
            $this->createMock(\ConditionsToApproveFinder::class)
        );
        $step->setMockProcess($checkoutProcess);
        $step->handleRequest(['delivery_option' => [5 => '1,']]);

        self::assertTrue(true);
    }
}

class InMemoryCheckoutSessionDataDb
{
    /**
     * @var array<int, string>
     */
    public array $rows = [];

    public function getValue(string $sql): string
    {
        preg_match('/id_cart = (\d+)/', $sql, $matches);
        $cartId = isset($matches[1]) ? (int) $matches[1] : 0;

        return $this->rows[$cartId] ?? '';
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function execute(string $sql): bool
    {
        preg_match('/id_cart = (\d+)/', $sql, $matches);
        $cartId = isset($matches[1]) ? (int) $matches[1] : 0;

        if (str_contains($sql, 'checkout_session_data = NULL')) {
            unset($this->rows[$cartId]);
            $this->rows[$cartId] = '';

            return true;
        }

        preg_match('/checkout_session_data = "(.*)" WHERE id_cart/s', $sql, $valueMatches);
        $this->rows[$cartId] = isset($valueMatches[1]) ? stripslashes($valueMatches[1]) : '';

        return true;
    }
}

class TestableSubmitValidationStateStorage extends OnePageCheckoutSubmitValidationStateStorage
{
    public function __construct(\Context $context, private InMemoryCheckoutSessionDataDb $db)
    {
        parent::__construct($context);
    }

    protected function getDb(): InMemoryCheckoutSessionDataDb
    {
        return $this->db;
    }
}
