<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
    private TestableCheckoutOnePageStep $step;

    protected function setUp(): void
    {
        $this->cart = $this->createMock(\Cart::class);

        $customer = $this->createMock(\Customer::class);
        $customer->method('isLogged')->willReturn(true);
        $customer->method('isGuest')->willReturn(false);

        $language = $this->createMock(\Language::class);
        $language->id = 1;

        $this->context = $this->createMock(\Context::class);
        $this->context->cart = $this->cart;
        $this->context->customer = $customer;
        $this->context->language = $language;
        $this->context->smarty = $this->createMock(\Smarty::class);

        $this->session = $this->createMock(\CheckoutSession::class);

        $this->checkoutProcess = $this->createMock(\CheckoutProcess::class);
        $this->checkoutProcess->method('getCheckoutSession')->willReturn($this->session);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->opcForm = $this->createMock(OnePageCheckoutForm::class);
        $this->opcForm->method('fillWith')->willReturnSelf();

        $this->step = new TestableCheckoutOnePageStep(
            $this->context,
            $translator,
            $this->opcForm,
            $this->createMock(\PaymentOptionsFinder::class),
            $this->createMock(\ConditionsToApproveFinder::class)
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

    public function testDeliveryOptionNotPersistedOnSubmit(): void
    {
        $this->session->expects($this->never())
            ->method('setDeliveryOption');

        $this->step->handleRequest([
            'delivery_option' => [5 => '1,'],
            'submitOnePageCheckout' => '1',
        ]);
    }

    public function testSubmitNormalizesMissingUseSameAddressFlag(): void
    {
        $this->opcForm->expects($this->once())
            ->method('fillWith')
            ->with($this->callback(static function (array $parameters): bool {
                return isset($parameters['use_same_address'])
                    && $parameters['use_same_address'] === '0';
            }))
            ->willReturnSelf();
        $this->opcForm->method('validate')->willReturn(false);

        $this->step->handleRequest([
            'submitOnePageCheckout' => '1',
            'email' => 'connected@example.com',
        ]);
    }

    public function testDeliveryOptionNotPersistedOnVirtualCart(): void
    {
        $cart = $this->createMock(\Cart::class);
        $cart->method('isVirtualCart')->willReturn(true);

        $session = $this->createMock(\CheckoutSession::class);
        $session->expects($this->never())->method('setDeliveryOption');

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
