<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\AddressDraftStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitHandler;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitProcessor;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitValidationStateStorage;

class OpcSubmitHandlerTest extends TestCase
{
    private \Context $context;
    private CheckoutSessionFactory|MockObject $checkoutSessionFactory;
    private OnePageCheckoutSubmitProcessor|MockObject $submitProcessor;
    private OnePageCheckoutSubmitValidationStateStorage|MockObject $submitValidationStateStorage;
    private \CheckoutSession|MockObject $checkoutSession;
    private OnePageCheckoutSubmitHandler $handler;

    protected function setUp(): void
    {
        $this->context = $this->createMock(\Context::class);
        $this->context->cart = new \stdClass();
        $this->context->cart->id = 42;
        $this->context->cookie = new class {
            public array $values = [];
            public bool $written = false;

            public function __set(string $key, string $value): void
            {
                $this->values[$key] = $value;
            }

            public function __get(string $key): string
            {
                return $this->values[$key] ?? '';
            }

            public function write(): void
            {
                $this->written = true;
            }
        };
        $this->checkoutSessionFactory = $this->createMock(CheckoutSessionFactory::class);
        $this->submitProcessor = $this->createMock(OnePageCheckoutSubmitProcessor::class);
        $this->submitValidationStateStorage = $this->createMock(OnePageCheckoutSubmitValidationStateStorage::class);
        $this->checkoutSession = $this->createMock(\CheckoutSession::class);
        $this->checkoutSession->method('getCheckoutURL')->willReturn('/commande');

        $this->checkoutSessionFactory->method('create')->willReturn($this->checkoutSession);

        $this->handler = new OnePageCheckoutSubmitHandler(
            $this->context,
            $this->checkoutSessionFactory,
            $this->submitProcessor,
            $this->submitValidationStateStorage,
            $this->createMock(AddressDraftStorage::class)
        );
    }

    public function testHandlePassesRequestParametersAsIs(): void
    {
        $this->submitProcessor
            ->expects($this->once())
            ->method('process')
            ->with($this->checkoutSession, $this->callback(static function (array $requestParameters): bool {
                return !isset($requestParameters['submitOnePageCheckout'])
                    && $requestParameters['email'] === 'customer@example.com';
            }))
            ->willReturn([
                'success' => true,
                'validation_errors' => [],
                'form_errors' => [],
                'submitted_values' => [],
            ]);
        $this->submitValidationStateStorage->expects($this->once())->method('clear');

        $response = $this->handler->handle([
            'email' => 'customer@example.com',
            'paymentMethod' => 'ps_checkpayment',
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['reload']);
        self::assertSame('/commande', $response['checkout_url']);
        self::assertSame('ps_checkpayment', $this->context->cookie->__get('opc_selected_payment_module'));
        self::assertTrue($this->context->cookie->written);
    }

    public function testHandlePersistsFailedSubmitStateAndReturnsReload(): void
    {
        $this->submitProcessor->expects($this->once())
            ->method('process')
            ->willReturn([
                'success' => false,
                'validation_errors' => [
                    'payment' => ['paymentMethod' => 'Please select a payment method.'],
                ],
                'form_errors' => [
                    'firstname' => ['Required'],
                ],
                'submitted_values' => [
                    'firstname' => 'Ada',
                ],
            ]);
        $this->submitValidationStateStorage->expects($this->once())
            ->method('save')
            ->with([
                'cart_id' => 42,
                'validation_errors' => [
                    'payment' => ['paymentMethod' => 'Please select a payment method.'],
                ],
                'form_errors' => [
                    'firstname' => ['Required'],
                ],
                'submitted_values' => [
                    'firstname' => 'Ada',
                ],
            ]);

        $response = $this->handler->handle([
        ]);

        self::assertFalse($response['success']);
        self::assertTrue($response['reload']);
        self::assertSame('/commande', $response['checkout_url']);
    }

    public function testHandlePersistsReloadErrorsAndRethrowsWhenSubmitFails(): void
    {
        $this->submitProcessor->expects($this->once())
            ->method('process')
            ->willThrowException(new \RuntimeException('submit exploded'));
        $this->submitValidationStateStorage->expects($this->once())
            ->method('save')
            ->with([
                'cart_id' => 42,
                'validation_errors' => [],
                'form_errors' => [
                    '' => ['One-page checkout is currently unavailable.'],
                ],
                'submitted_values' => [
                    'email' => 'customer@example.com',
                ],
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('submit exploded');

        $this->handler->handle(
            [
                'email' => 'customer@example.com',
            ],
            [
                'reload' => true,
                'errors' => [
                    '' => ['One-page checkout is currently unavailable.'],
                ],
            ]
        );
    }

    public function testHandleFailsWhenFailedSubmitStateCannotBeLinkedToACart(): void
    {
        $this->context->cart = new \stdClass();
        $this->context->cart->id = 0;
        $this->submitProcessor->expects($this->once())
            ->method('process')
            ->willReturn([
                'success' => false,
                'validation_errors' => [],
                'form_errors' => [
                    'firstname' => ['Required'],
                ],
                'submitted_values' => [],
            ]);
        $this->submitValidationStateStorage->expects($this->never())->method('save');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot persist one-page checkout submit state without an active cart.');

        $this->handler->handle([]);
    }
}
