<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax\OrderOptions;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CartPresenterHelper;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutSessionFactory;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OrderOptions\OnePageCheckoutGiftWrappingHandler;
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcGiftWrappingHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::$values = [];
    }

    protected function tearDown(): void
    {
        \Configuration::$values = [];
    }

    public function testItReturnsErrorWhenCartIsNotLoaded(): void
    {
        \Configuration::$values['PS_GIFT_WRAPPING'] = true;
        $cart = new GiftWrappingCart();
        $cart->id = 0;

        $handler = new OnePageCheckoutGiftWrappingHandler($this->context($cart), $this->translator());

        $response = $handler->handle(['gift' => '1']);

        self::assertFalse($response['success']);
    }

    public function testItReturnsErrorWhenGiftWrappingIsDisabled(): void
    {
        \Configuration::$values['PS_GIFT_WRAPPING'] = false;
        $cart = new GiftWrappingCart();
        $cart->id = 1;

        $handler = new OnePageCheckoutGiftWrappingHandler($this->context($cart), $this->translator());

        $response = $handler->handle(['gift' => '1']);

        self::assertFalse($response['success']);
    }

    public function testItStoresTheGiftMessageWhenGiftIsEnabled(): void
    {
        \Configuration::$values['PS_GIFT_WRAPPING'] = true;
        $cart = new GiftWrappingCart();
        $cart->id = 1;
        $cart->id_address_delivery = 5;

        $session = $this->createMock(\CheckoutSession::class);
        $session->expects(self::once())->method('setGift')->with(true, 'Happy birthday');

        $response = $this->buildHandler($cart, $session)->handle([
            'gift' => '1',
            'gift_message' => 'Happy birthday',
        ]);

        self::assertTrue($response['success']);
        self::assertSame(['total' => '10'], $response['totals']);
    }

    public function testItClearsTheGiftMessageWhenGiftIsDisabled(): void
    {
        \Configuration::$values['PS_GIFT_WRAPPING'] = true;
        $cart = new GiftWrappingCart();
        $cart->id = 1;
        $cart->id_address_delivery = 5;

        // Turning gift wrapping off must not carry over a message from the request.
        $session = $this->createMock(\CheckoutSession::class);
        $session->expects(self::once())->method('setGift')->with(false, '');

        $response = $this->buildHandler($cart, $session)->handle([
            'gift' => '0',
            'gift_message' => 'should be ignored',
        ]);

        self::assertTrue($response['success']);
    }

    private function buildHandler(GiftWrappingCart $cart, \CheckoutSession $session): OnePageCheckoutGiftWrappingHandler
    {
        $factory = $this->createMock(CheckoutSessionFactory::class);
        $factory->method('create')->willReturn($session);

        $presentedCart = $this->createMock(CartLazyArray::class);
        $presentedCart->method('offsetGet')->willReturnCallback(
            static fn ($offset) => 'totals' === $offset ? ['total' => '10'] : null
        );

        $presenter = $this->createMock(CartPresenterHelper::class);
        $presenter->method('presentCart')->willReturn($presentedCart);

        return new OnePageCheckoutGiftWrappingHandler($this->context($cart), $this->translator(), $factory, $presenter);
    }

    private function context(GiftWrappingCart $cart): \Context
    {
        $context = \Context::getContext();
        $context->cart = $cart;

        return $context;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}

class GiftWrappingCart extends \Cart
{
    public function __construct()
    {
    }
}
