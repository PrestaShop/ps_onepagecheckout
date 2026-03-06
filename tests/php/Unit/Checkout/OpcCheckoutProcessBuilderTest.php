<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\CheckoutOnePageStep;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutAvailability;
use PrestaShop\Module\PsOnePageCheckout\Checkout\OnePageCheckoutProcessBuilder;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcCheckoutProcessBuilderTest extends TestCase
{
    public function testItBuildsSingleStepProcessForVirtualCart(): void
    {
        $context = $this->createContextWithCart(true);
        $module = $this->getMockBuilder(\Ps_Onepagecheckout::class)->disableOriginalConstructor()->getMock();
        $formFactory = $this->getMockBuilder(OnePageCheckoutFormFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $formFactory
            ->expects($this->once())
            ->method('create')
            ->willReturn($this->getMockBuilder(OnePageCheckoutForm::class)->disableOriginalConstructor()->getMock());

        $builder = new SpyOpcCheckoutProcessBuilder(
            $context,
            $module,
            $formFactory,
            new EnabledAvailability()
        );

        $process = $builder->build(
            $this->getMockBuilder(\CheckoutSession::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(TranslatorInterface::class)
        );

        self::assertNotNull($process);
        self::assertCount(1, $process->getSteps());
        self::assertInstanceOf(CheckoutOnePageStep::class, $process->getSteps()[0]);
        self::assertSame(0, $builder->deliveryConfigurationCalls);
    }

    public function testItConfiguresDeliveryOptionsForNonVirtualCart(): void
    {
        $context = $this->createContextWithCart(false);
        $module = $this->getMockBuilder(\Ps_Onepagecheckout::class)->disableOriginalConstructor()->getMock();
        $formFactory = $this->getMockBuilder(OnePageCheckoutFormFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $formFactory
            ->expects($this->once())
            ->method('create')
            ->willReturn($this->getMockBuilder(OnePageCheckoutForm::class)->disableOriginalConstructor()->getMock());

        $builder = new SpyOpcCheckoutProcessBuilder(
            $context,
            $module,
            $formFactory,
            new EnabledAvailability()
        );

        $builder->build(
            $this->getMockBuilder(\CheckoutSession::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(TranslatorInterface::class)
        );

        self::assertSame(1, $builder->deliveryConfigurationCalls);
    }

    private function createContextWithCart(bool $isVirtual): \Context
    {
        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->cart = new class($isVirtual) extends \Cart {
            private bool $isVirtual;

            public function __construct(bool $isVirtual)
            {
                $this->isVirtual = $isVirtual;
            }

            public function isVirtualCart()
            {
                return $this->isVirtual;
            }
        };

        return $context;
    }
}

class SpyOpcCheckoutProcessBuilder extends OnePageCheckoutProcessBuilder
{
    public int $deliveryConfigurationCalls = 0;

    protected function configureDeliveryOptionsForStep($step): void
    {
        ++$this->deliveryConfigurationCalls;
    }
}

class EnabledAvailability extends OnePageCheckoutAvailability
{
    public function __construct()
    {
        parent::__construct('PS_ONE_PAGE_CHECKOUT_ENABLED');
    }

    protected function getConfigurationValue(): bool
    {
        return true;
    }
}
