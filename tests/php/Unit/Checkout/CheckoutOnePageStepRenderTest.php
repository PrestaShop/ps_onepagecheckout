<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\CheckoutOnePageStep;
use PrestaShop\Module\PsOnePageCheckout\Checkout\PaymentSelectionKeyBuilder;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use Symfony\Contracts\Translation\TranslatorInterface;

class CheckoutOnePageStepRenderTest extends TestCase
{
    public function testRenderInjectsOpcFormTemplateVariablesIntoStepTemplate(): void
    {
        $smarty = $this->getMockBuilder(\Smarty::class)
            ->disableOriginalConstructor()
            ->getMock();

        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->smarty = $smarty;
        $context->cart = new class extends \Cart {
            public function __construct()
            {
            }

            public function isVirtualCart()
            {
                return false;
            }

            public function getOrderTotal(
                $withTaxes = true,
                $type = \Cart::BOTH,
                $products = null,
                $id_carrier = null,
                $use_cache = false,
                bool $keepOrderPrices = false,
            ) {
                return 10.0;
            }
        };
        $context->cookie = new class {
            public function __get(string $name)
            {
                return '';
            }
        };
        $context->controller = new class {
            public function getTemplateVarConfiguration(): array
            {
                return [
                    'display_taxes_label' => true,
                ];
            }
        };

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('');

        $opcForm = $this->getMockBuilder(OnePageCheckoutForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTemplateVariables'])
            ->getMock();
        $opcForm->expects($this->once())
            ->method('getTemplateVariables')
            ->willReturn([
                'contactFields' => ['email' => ['value' => 'john@example.com']],
                'additionalCustomerFields' => [],
                'useSameAddressField' => ['value' => true],
                'deliveryFields' => ['firstname' => ['name' => 'firstname']],
                'invoiceFields' => ['invoice_firstname' => ['name' => 'invoice_firstname']],
                'invoiceMetaFields' => [],
                'errors' => ['' => []],
                'token' => 'token',
            ]);

        $paymentOptionsFinder = $this->getMockBuilder(\PaymentOptionsFinder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['present'])
            ->getMock();
        $paymentOptionsFinder->method('present')->willReturn([]);

        $conditionsToApproveFinder = $this->getMockBuilder(\ConditionsToApproveFinder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConditionsToApproveForTemplate'])
            ->getMock();
        $conditionsToApproveFinder->method('getConditionsToApproveForTemplate')->willReturn([]);

        $checkoutSession = new class($context->cart) {
            private \Cart $cart;

            public function __construct(\Cart $cart)
            {
                $this->cart = $cart;
            }

            public function getCart()
            {
                return $this->cart;
            }

            public function getDeliveryOptions()
            {
                return [];
            }

            public function getSelectedDeliveryOption()
            {
                return '';
            }

            public function isRecyclable()
            {
                return false;
            }

            public function getMessage()
            {
                return '';
            }

            public function getGift()
            {
                return [
                    'isGift' => false,
                    'message' => '',
                ];
            }
        };

        $step = new class($context, $translator, $opcForm, $paymentOptionsFinder, $conditionsToApproveFinder, new PaymentSelectionKeyBuilder(), $checkoutSession) extends CheckoutOnePageStep {
            public array $capturedParams = [];
            private object $checkoutSessionStub;

            public function __construct(
                \Context $context,
                TranslatorInterface $translator,
                OnePageCheckoutForm $opcForm,
                \PaymentOptionsFinder $paymentOptionsFinder,
                \ConditionsToApproveFinder $conditionsToApproveFinder,
                PaymentSelectionKeyBuilder $paymentSelectionKeyBuilder,
                object $checkoutSessionStub,
            ) {
                parent::__construct(
                    $context,
                    $translator,
                    $opcForm,
                    $paymentOptionsFinder,
                    $conditionsToApproveFinder,
                    $paymentSelectionKeyBuilder
                );
                $this->checkoutSessionStub = $checkoutSessionStub;
            }

            public function getCheckoutSession()
            {
                return $this->checkoutSessionStub;
            }

            protected function renderTemplate($template, array $extraParams = [], array $params = [])
            {
                $this->capturedParams = $params;

                return 'rendered';
            }
        };

        self::assertSame('rendered', $step->render());
        self::assertArrayHasKey('contactFields', $step->capturedParams);
        self::assertArrayHasKey('deliveryFields', $step->capturedParams);
        self::assertArrayHasKey('invoiceFields', $step->capturedParams);
        self::assertArrayHasKey('errors', $step->capturedParams);
        self::assertArrayHasKey('configuration', $step->capturedParams);
        self::assertArrayHasKey('is_guest_checkout_enabled', $step->capturedParams['configuration']);
        self::assertIsBool($step->capturedParams['configuration']['is_guest_checkout_enabled']);
        self::assertArrayNotHasKey('opc_form', $step->capturedParams);
    }
}
