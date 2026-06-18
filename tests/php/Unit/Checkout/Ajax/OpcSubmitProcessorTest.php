<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitProcessor;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcSubmitProcessorTest extends TestCase
{
    private \Cart|MockObject $cart;
    private \Context|MockObject $context;
    private \CheckoutSession|MockObject $checkoutSession;
    private OnePageCheckoutForm|MockObject $opcForm;
    private \PaymentOptionsFinder|MockObject $paymentOptionsFinder;
    private \ConditionsToApproveFinder|MockObject $conditionsToApproveFinder;
    private OnePageCheckoutSubmitProcessor $processor;

    protected function setUp(): void
    {
        $this->cart = $this->createMock(\Cart::class);
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
        };

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->checkoutSession = $this->createMock(\CheckoutSession::class);
        $this->checkoutSession->method('getCart')->willReturn($this->cart);
        $this->checkoutSession->method('getCustomer')->willReturn($customer);
        $this->checkoutSession->method('getSelectedDeliveryOption')->willReturn('');

        $this->opcForm = $this->createMock(OnePageCheckoutForm::class);
        $this->opcForm->method('fillWith')->willReturnSelf();
        $this->paymentOptionsFinder = $this->createMock(\PaymentOptionsFinder::class);
        $this->conditionsToApproveFinder = $this->createMock(\ConditionsToApproveFinder::class);

        $this->processor = new OnePageCheckoutSubmitProcessor(
            $this->context,
            $translator,
            $this->opcForm,
            $this->paymentOptionsFinder,
            $this->conditionsToApproveFinder
        );
    }

    public function testProcessNormalizesMissingUseSameAddressFlag(): void
    {
        $this->checkoutSession->method('getDeliveryOptions')->willReturn([]);
        $this->paymentOptionsFinder->method('present')->willReturn([]);
        $this->conditionsToApproveFinder->method('getConditionsToApproveForTemplate')->willReturn([]);
        $this->opcForm->expects($this->once())
            ->method('fillWith')
            ->with($this->callback(static function (array $parameters): bool {
                return isset($parameters['use_same_address']) && $parameters['use_same_address'] === '0';
            }))
            ->willReturnSelf();
        $this->opcForm->method('validate')->willReturn(false);
        $this->opcForm->method('getErrors')->willReturn(['firstname' => ['required']]);

        $response = $this->processor->process($this->checkoutSession, [
            'email' => 'connected@example.com',
        ]);

        self::assertFalse($response['success']);
        self::assertSame('0', $response['submitted_values']['use_same_address']);
    }

    public function testProcessNormalizesMissingUseSameAddressFlagForVirtualCart(): void
    {
        $virtualCart = $this->createMock(\Cart::class);
        $virtualCart->method('isVirtualCart')->willReturn(true);
        $virtualCart->method('getOrderTotal')->willReturn(10.0);

        $virtualContext = clone $this->context;
        $virtualContext->cart = $virtualCart;

        $virtualCheckoutSession = $this->createMock(\CheckoutSession::class);
        $virtualCheckoutSession->method('getCart')->willReturn($virtualCart);
        $virtualCheckoutSession->method('getCustomer')->willReturn($this->context->customer);
        $virtualCheckoutSession->method('getSelectedDeliveryOption')->willReturn('');
        $virtualCheckoutSession->method('getDeliveryOptions')->willReturn([]);

        $virtualOpcForm = $this->createMock(OnePageCheckoutForm::class);
        $virtualOpcForm->expects($this->once())
            ->method('fillWith')
            ->with($this->callback(static function (array $parameters): bool {
                return isset($parameters['use_same_address']) && $parameters['use_same_address'] === '1';
            }))
            ->willReturnSelf();
        $virtualOpcForm->method('validate')->willReturn(false);
        $virtualOpcForm->method('getErrors')->willReturn(['firstname' => ['required']]);

        $processor = new OnePageCheckoutSubmitProcessor(
            $virtualContext,
            $this->createMock(TranslatorInterface::class),
            $virtualOpcForm,
            $this->paymentOptionsFinder,
            $this->conditionsToApproveFinder
        );

        $response = $processor->process($virtualCheckoutSession, [
            'email' => 'connected@example.com',
        ]);

        self::assertFalse($response['success']);
        self::assertSame('1', $response['submitted_values']['use_same_address']);
    }

    public function testProcessRequiresDeliveryOptionForPhysicalCart(): void
    {
        $this->paymentOptionsFinder->method('present')->willReturn([]);
        $this->conditionsToApproveFinder->method('getConditionsToApproveForTemplate')->willReturn([]);
        $this->checkoutSession->method('getDeliveryOptions')->willReturn([]);
        $this->opcForm->method('validate')->willReturn(true);
        $this->opcForm->method('getErrors')->willReturn([]);

        $response = $this->processor->process($this->checkoutSession, [
            'email' => 'connected@example.com',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('shipping', $response['validation_errors']);
    }

    public function testProcessRequiresPaymentSelectionWhenPaymentOptionsExist(): void
    {
        $this->checkoutSession->method('getDeliveryOptions')->willReturn([
            '1,' => [
                'is_module' => false,
            ],
        ]);
        $this->paymentOptionsFinder->method('present')->willReturn([
            'ps_checkpayment' => [[
                'module_name' => 'ps_checkpayment',
                'id' => 'payment-option-1',
            ]],
        ]);
        $this->conditionsToApproveFinder->method('getConditionsToApproveForTemplate')->willReturn([]);
        $this->opcForm->method('validate')->willReturn(true);
        $this->opcForm->method('getErrors')->willReturn([]);

        $response = $this->processor->process($this->checkoutSession, [
            'email' => 'connected@example.com',
            'delivery_option' => '1,',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('payment', $response['validation_errors']);
    }

    public function testProcessRequiresConditionsToApprove(): void
    {
        $this->paymentOptionsFinder->method('present')->willReturn([]);
        $this->checkoutSession->method('getDeliveryOptions')->willReturn([
            '1,' => [
                'is_module' => false,
            ],
        ]);
        $this->conditionsToApproveFinder->method('getConditionsToApproveForTemplate')->willReturn([
            'terms-and-conditions' => '<a>Terms</a>',
        ]);
        $this->opcForm->method('validate')->willReturn(true);
        $this->opcForm->method('getErrors')->willReturn([]);

        $response = $this->processor->process($this->checkoutSession, [
            'email' => 'connected@example.com',
            'delivery_option' => '1,',
            'paymentMethod' => 'ps_checkpayment',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('conditions', $response['validation_errors']);
    }

    public function testProcessPersistsResolvedDeliveryOptionAndAddressesOnSuccess(): void
    {
        $this->paymentOptionsFinder->method('present')->willReturn([]);
        $this->conditionsToApproveFinder->method('getConditionsToApproveForTemplate')->willReturn([]);
        $this->checkoutSession->method('getDeliveryOptions')->willReturn([
            '1,' => [
                'is_module' => false,
            ],
        ]);
        $this->opcForm->method('validate')->willReturn(true);
        $this->opcForm->expects($this->exactly(2))->method('fillWith')->willReturnSelf();
        $this->opcForm->method('submit')->willReturn([
            'id_address_delivery' => 10,
            'id_address_invoice' => 10,
        ]);
        $this->checkoutSession->expects($this->once())
            ->method('setDeliveryOption')
            ->with([10 => '1,']);
        $this->checkoutSession->expects($this->once())->method('setIdAddressDelivery')->with(10);
        $this->checkoutSession->expects($this->once())->method('setIdAddressInvoice')->with(10);

        $response = $this->processor->process($this->checkoutSession, [
            'email' => 'connected@example.com',
            'delivery_option' => '1,',
            'paymentMethod' => 'ps_checkpayment',
            'conditions_to_approve' => [],
        ]);

        self::assertTrue($response['success']);
    }

    public function testProcessReturnsAddressErrorsWhenFormSubmitFails(): void
    {
        $this->paymentOptionsFinder->method('present')->willReturn([]);
        $this->conditionsToApproveFinder->method('getConditionsToApproveForTemplate')->willReturn([]);
        $this->checkoutSession->method('getDeliveryOptions')->willReturn([
            '1,' => [
                'is_module' => false,
            ],
        ]);
        $this->opcForm->method('validate')->willReturn(true);
        $this->opcForm->expects($this->exactly(2))->method('fillWith')->willReturnSelf();
        $this->opcForm->method('submit')->willReturn(false);
        $this->opcForm->method('getErrors')->willReturn([
            'address1' => ['Address is invalid.'],
        ]);

        $response = $this->processor->process($this->checkoutSession, [
            'email' => 'connected@example.com',
            'delivery_option' => '1,',
            'paymentMethod' => 'ps_checkpayment',
            'conditions_to_approve' => [],
        ]);

        self::assertFalse($response['success']);
        self::assertSame([
            'address' => [
                'address1' => ['Address is invalid.'],
            ],
        ], $response['validation_errors']);
    }
}
