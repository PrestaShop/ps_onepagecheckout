<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class CheckoutTestFixtures
{
    public static function context(array $overrides = []): \Context
    {
        $context = new ContextStub();

        foreach ($overrides as $property => $value) {
            $context->{$property} = $value;
        }

        return $context;
    }

    public static function language(int $id = 1): \Language
    {
        $language = new LanguageStub();
        $language->id = $id;

        return $language;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function country(array $overrides = []): \Country
    {
        $country = new CountryStub();

        foreach ($overrides as $property => $value) {
            $country->{$property} = $value;
        }

        return $country;
    }

    /**
     * @param array<int, array<string, mixed>> $simpleAddresses
     * @param array<string, mixed> $overrides
     */
    public static function customer(
        array $simpleAddresses = [],
        bool $logged = false,
        bool $guest = false,
        array $overrides = [],
    ): \Customer {
        $customer = new CustomerStub($simpleAddresses, $logged, $guest);

        foreach ($overrides as $property => $value) {
            $customer->{$property} = $value;
        }

        return $customer;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function cart(array $overrides = []): \Cart
    {
        $cart = new CartStub();

        foreach ($overrides as $property => $value) {
            $cart->{$property} = $value;
        }

        return $cart;
    }

    public static function pricedCart(float $total, array $overrides = []): \Cart
    {
        $cart = new PricedCartStub($total);

        foreach ($overrides as $property => $value) {
            $cart->{$property} = $value;
        }

        return $cart;
    }

    public static function virtualCart(bool $isVirtual, array $overrides = []): \Cart
    {
        $cart = new VirtualCartStub($isVirtual);

        foreach ($overrides as $property => $value) {
            $cart->{$property} = $value;
        }

        return $cart;
    }

    public static function smarty(): \Smarty
    {
        return new SmartyStub();
    }

    public static function shop(int $id = 1): object
    {
        return new ShopStub($id);
    }

    public static function translator(?callable $translator = null): object
    {
        return new TranslatorStub($translator);
    }
}

final class ContextStub extends \Context
{
    public function __construct()
    {
    }
}

final class LanguageStub extends \Language
{
    public function __construct()
    {
    }
}

final class CountryStub extends \Country
{
    public function __construct()
    {
    }
}

class CartStub extends \Cart
{
    public function __construct()
    {
    }
}

final class PricedCartStub extends CartStub
{
    public function __construct(private readonly float $total)
    {
    }

    public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH, $products = null, $id_carrier = null, $use_cache = false, bool $keepOrderPrices = false)
    {
        return $this->total;
    }
}

final class VirtualCartStub extends CartStub
{
    public function __construct(private readonly bool $virtual)
    {
    }

    public function isVirtualCart()
    {
        return $this->virtual;
    }
}

final class CustomerStub extends \Customer
{
    /**
     * @param array<int, array<string, mixed>> $simpleAddresses
     */
    public function __construct(
        private readonly array $simpleAddresses = [],
        private readonly bool $logged = false,
        private readonly bool $guest = false,
    ) {
    }

    public function getSimpleAddresses($idLang = null): array
    {
        return $this->simpleAddresses;
    }

    public function getAddresses($idLang = null): array
    {
        return $this->simpleAddresses;
    }

    public function isGuest(): bool
    {
        return $this->guest;
    }

    public function isLogged($withGuest = false): bool
    {
        return $this->logged || ($withGuest && $this->guest);
    }
}

final class SmartyStub extends \Smarty
{
    public function __construct()
    {
    }
}

final class ShopStub
{
    public function __construct(public int $id)
    {
    }
}

final class TranslatorStub
{
    /**
     * @var callable|null
     */
    private $translatorCallback;

    public function __construct(?callable $translator = null)
    {
        $this->translatorCallback = $translator;
    }

    public function trans(string $message, array $parameters = [], string $domain = ''): string
    {
        if ($this->translatorCallback !== null) {
            return (string) call_user_func($this->translatorCallback, $message, $parameters, $domain);
        }

        return strtr($message, $parameters);
    }
}
