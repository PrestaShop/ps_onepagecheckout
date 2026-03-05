<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Cart;
use Configuration;
use Context;
use Customer;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnepagecheckout\Form\OnePageCheckoutForm;
use ReflectionClass;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;
use Tools;

class OnePageCheckoutFormSyncContextIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        self::resetTables();
        Configuration::loadConfiguration();
        Configuration::updateValue('PS_GUEST_CHECKOUT_ENABLED', true);
    }

    private static function resetTables(): void
    {
        DatabaseDump::restoreTables([
            'configuration',
            'cart',
            'customer',
            'customer_group',
        ]);
    }

    public function testItHydratesEmptyContextFromFreshGuestCartOwner(): void
    {
        $guestOwner = $this->createCustomer($this->uniqueEmail('guest-owner'), true);
        $cart = $this->createPersistedCart((int) $guestOwner->id);

        $context = self::getContext();
        $context->customer = new Customer();
        $context->cart = new Cart((int) $cart->id);

        $form = $this->createFormWithoutConstructor($context);
        $this->invokeSyncContextCustomerFromCart($form);

        self::assertSame((int) $guestOwner->id, (int) $context->customer->id);
        self::assertTrue((bool) $context->customer->is_guest);
    }

    public function testItDoesNotHydrateWhenFreshCartOwnerIsRegistered(): void
    {
        $registeredOwner = $this->createCustomer($this->uniqueEmail('registered-owner'), false);
        $cart = $this->createPersistedCart((int) $registeredOwner->id);

        $context = self::getContext();
        $context->customer = new Customer();
        $context->cart = new Cart((int) $cart->id);

        $form = $this->createFormWithoutConstructor($context);
        $this->invokeSyncContextCustomerFromCart($form);

        self::assertSame(0, (int) $context->customer->id);
    }

    public function testItKeepsAlreadyHydratedContextCustomer(): void
    {
        $guestOwner = $this->createCustomer($this->uniqueEmail('owner-guest'), true);
        $existingContextCustomer = $this->createCustomer($this->uniqueEmail('existing-customer'), true);
        $cart = $this->createPersistedCart((int) $guestOwner->id);

        $context = self::getContext();
        $context->customer = $existingContextCustomer;
        $context->cart = new Cart((int) $cart->id);

        $form = $this->createFormWithoutConstructor($context);
        $this->invokeSyncContextCustomerFromCart($form);

        self::assertSame((int) $existingContextCustomer->id, (int) $context->customer->id);
    }

    public function testItHydratesFromFreshOwnerWhenContextCartSnapshotOwnerIsZero(): void
    {
        $guestOwner = $this->createCustomer($this->uniqueEmail('snapshot-owner-guest'), true);
        $cart = $this->createPersistedCart((int) $guestOwner->id);

        $context = self::getContext();
        $context->customer = new Customer();
        $context->cart = new Cart();
        $context->cart->id = (int) $cart->id;
        $context->cart->id_customer = 0;

        $form = $this->createFormWithoutConstructor($context);
        $this->invokeSyncContextCustomerFromCart($form);

        self::assertSame((int) $guestOwner->id, (int) $context->customer->id);
        self::assertTrue((bool) $context->customer->is_guest);
    }

    public function testItDoesNothingWhenContextCustomerIdIsPositiveButStale(): void
    {
        $guestOwner = $this->createCustomer($this->uniqueEmail('stale-context-guest-owner'), true);
        $cart = $this->createPersistedCart((int) $guestOwner->id);

        $context = self::getContext();
        $context->customer = new Customer();
        $context->customer->id = 999999;
        $context->cart = new Cart((int) $cart->id);

        $form = $this->createFormWithoutConstructor($context);
        $this->invokeSyncContextCustomerFromCart($form);

        self::assertSame(999999, (int) $context->customer->id);
    }

    public function testItDoesNothingWhenCartHasNoOwner(): void
    {
        $cart = $this->createPersistedCart(0);

        $context = self::getContext();
        $context->customer = new Customer();
        $context->cart = new Cart((int) $cart->id);

        $form = $this->createFormWithoutConstructor($context);
        $this->invokeSyncContextCustomerFromCart($form);

        self::assertSame(0, (int) $context->customer->id);
    }

    public function testItDoesNothingWhenCartOwnerCannotBeLoaded(): void
    {
        $cart = $this->createPersistedCart(999999);

        $context = self::getContext();
        $context->customer = new Customer();
        $context->cart = new Cart((int) $cart->id);

        $form = $this->createFormWithoutConstructor($context);
        $this->invokeSyncContextCustomerFromCart($form);

        self::assertSame(0, (int) $context->customer->id);
    }

    public function testItDoesNothingWhenCartIsNotLoaded(): void
    {
        $context = self::getContext();
        $context->customer = new Customer();
        $context->cart = new Cart();
        $context->cart->id = 0;

        $form = $this->createFormWithoutConstructor($context);
        $this->invokeSyncContextCustomerFromCart($form);

        self::assertSame(0, (int) $context->customer->id);
    }

    private function createCustomer(string $email, bool $isGuest): Customer
    {
        $customer = new Customer();
        $customer->firstname = 'Sync';
        $customer->lastname = 'Context';
        $customer->email = $email;
        $customer->is_guest = $isGuest;
        $customer->passwd = Tools::hash('integration-password');

        self::assertTrue($customer->save());

        return $customer;
    }

    private function createPersistedCart(int $ownerCustomerId): Cart
    {
        $context = self::getContext();

        $cart = new Cart();
        $cart->id_customer = $ownerCustomerId;
        $cart->id_currency = (int) $context->currency->id;
        $cart->id_lang = (int) $context->language->id;
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_shop_group = (int) $context->shop->id_shop_group;

        self::assertTrue($cart->save());

        return $cart;
    }

    private function createFormWithoutConstructor(Context $context): OnePageCheckoutForm
    {
        $reflection = new ReflectionClass(OnePageCheckoutForm::class);
        /** @var OnePageCheckoutForm $form */
        $form = $reflection->newInstanceWithoutConstructor();

        $contextProperty = $reflection->getProperty('context');
        $contextProperty->setAccessible(true);
        $contextProperty->setValue($form, $context);

        return $form;
    }

    private function invokeSyncContextCustomerFromCart(OnePageCheckoutForm $form): void
    {
        $reflection = new ReflectionClass(OnePageCheckoutForm::class);
        $method = $reflection->getMethod('syncContextCustomerFromCart');
        $method->setAccessible(true);
        $method->invoke($form);
    }

    private function uniqueEmail(string $prefix): string
    {
        return sprintf('%s_%s@example.com', $prefix, uniqid('', true));
    }
}
