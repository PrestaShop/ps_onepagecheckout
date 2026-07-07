<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OrderOptions\OnePageCheckoutGiftWrappingHandler;
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcGiftWrappingHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        \Configuration::$values = [];
    }

    public function testItReturnsAnErrorWhenTheCartIsNotLoaded(): void
    {
        \Configuration::$values['PS_GIFT_WRAPPING'] = true;

        $handler = $this->handler($this->context($this->cart(0, 0)));

        $response = $handler->handle(['gift' => '1']);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
    }

    public function testItReturnsAnErrorWhenGiftWrappingIsDisabled(): void
    {
        \Configuration::$values['PS_GIFT_WRAPPING'] = false;

        $handler = $this->handler($this->context($this->cart(5, 10)));

        $response = $handler->handle(['gift' => '1']);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
    }

    public function testItPersistsTheGiftChoiceAndReturnsThePresentedCart(): void
    {
        \Configuration::$values['PS_GIFT_WRAPPING'] = true;

        $presented = $this->presentedCart(['total' => '€10.00']);

        $session = $this->createMock(\CheckoutSession::class);
        $session->expects(self::once())->method('setGift')->with(true, 'Happy birthday');

        $sessionFactory = $this->createMock(CheckoutSessionFactory::class);
        $sessionFactory->method('create')->willReturn($session);

        $cartPresenter = $this->createMock(CartPresenterHelper::class);
        $cartPresenter->method('presentCart')->willReturn($presented);

        $handler = $this->handler($this->context($this->cart(5, 10)), $sessionFactory, $cartPresenter);

        $response = $handler->handle(['gift' => '1', 'gift_message' => 'Happy birthday']);

        self::assertTrue($response['success']);
        self::assertSame($presented, $response['cart']);
        self::assertSame(['total' => '€10.00'], $response['totals']);
    }

    private function handler(
        \Context $context,
        ?CheckoutSessionFactory $sessionFactory = null,
        ?CartPresenterHelper $cartPresenter = null,
    ): OnePageCheckoutGiftWrappingHandler {
        return new OnePageCheckoutGiftWrappingHandler(
            $context,
            $this->createConfiguredMock(TranslatorInterface::class, ['trans' => 'translated message']),
            $sessionFactory ?? $this->createMock(CheckoutSessionFactory::class),
            $cartPresenter ?? $this->createMock(CartPresenterHelper::class)
        );
    }

    private function context(\Cart $cart): \Context
    {
        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->cart = $cart;

        return $context;
    }

    private function cart(int $id, int $idAddressDelivery): \Cart
    {
        $cart = new class extends \Cart {
            public function __construct()
            {
            }
        };
        $cart->id = $id;
        $cart->id_address_delivery = $idAddressDelivery;

        return $cart;
    }

    /**
     * @param array<string,mixed> $totals
     */
    private function presentedCart(array $totals): CartLazyArray
    {
        return new class($totals) extends CartLazyArray {
            /** @var array<string,mixed> */
            private array $totals;

            /**
             * @param array<string,mixed> $totals
             */
            public function __construct(array $totals)
            {
                $this->totals = $totals;
            }

            public function offsetExists($index): bool
            {
                return $index === 'totals';
            }

            #[\ReturnTypeWillChange]
            public function offsetGet($index)
            {
                return $index === 'totals' ? $this->totals : null;
            }
        };
    }
}
