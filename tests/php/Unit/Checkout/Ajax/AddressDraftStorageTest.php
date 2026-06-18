<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\AddressDraftStorage;

class AddressDraftStorageTest extends TestCase
{
    private const COOKIE_KEY = 'opc_address_draft';

    /** @var string[] */
    private const ALLOWED_FIELDS = [
        'id_country',
        'firstname',
        'lastname',
        'address1',
        'postcode',
        'city',
        'use_same_address',
        'invoice_city',
        'custom_module_field',
    ];

    private function makeCookie(): object
    {
        return new class {
            /** @var array<string,string> */
            public array $values = [];
            public int $writes = 0;

            public function __get(string $name)
            {
                return $this->values[$name] ?? null;
            }

            public function __set(string $name, string $value): void
            {
                $this->values[$name] = $value;
            }

            public function __unset(string $name): void
            {
                unset($this->values[$name]);
            }

            public function write(): void
            {
                ++$this->writes;
            }
        };
    }

    private function makeContext(object $cookie, int $cartId = 42): \Context
    {
        $context = new \Context();
        $context->cookie = $cookie;
        $context->cart = new class extends \Cart {
            public function __construct()
            {
            }
        };
        $context->cart->id = $cartId;

        return $context;
    }

    public function testItSavesAndRestoresAllowedFields(): void
    {
        $cookie = $this->makeCookie();
        $storage = new AddressDraftStorage($this->makeContext($cookie));

        $storage->saveFromRequest([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'address1' => '1 Test Street',
            'postcode' => '75001',
            'city' => 'Paris',
            'id_country' => '8',
            'use_same_address' => '1',
        ], self::ALLOWED_FIELDS);

        self::assertSame([
            'id_country' => '8',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'address1' => '1 Test Street',
            'postcode' => '75001',
            'city' => 'Paris',
            'use_same_address' => '1',
        ], $storage->get(self::ALLOWED_FIELDS));
    }

    public function testItPersistsCustomAndInvoiceFieldsAndDropsEverythingElse(): void
    {
        $cookie = $this->makeCookie();
        $storage = new AddressDraftStorage($this->makeContext($cookie));

        $storage->saveFromRequest([
            'city' => 'Paris',
            'invoice_city' => 'Lyon',
            'custom_module_field' => 'kept',
            'id_address_delivery' => '999',
            'token' => 'abc',
            'ajax' => '1',
            'evil' => 'x',
        ], self::ALLOWED_FIELDS);

        $draft = $storage->get(self::ALLOWED_FIELDS);

        self::assertSame([
            'city' => 'Paris',
            'invoice_city' => 'Lyon',
            'custom_module_field' => 'kept',
        ], $draft);
        self::assertArrayNotHasKey('id_address_delivery', $draft);
        self::assertArrayNotHasKey('token', $draft);
        self::assertArrayNotHasKey('evil', $draft);
    }

    public function testItCapsFieldLength(): void
    {
        $cookie = $this->makeCookie();
        $storage = new AddressDraftStorage($this->makeContext($cookie));

        $storage->saveFromRequest(['city' => str_repeat('a', 500)], self::ALLOWED_FIELDS);

        self::assertSame(255, mb_strlen($storage->get(self::ALLOWED_FIELDS)['city']));
    }

    public function testItDoesNotRestoreADraftFromAnotherCart(): void
    {
        $cookie = $this->makeCookie();
        $storage = new AddressDraftStorage($this->makeContext($cookie, 42));
        $storage->saveFromRequest(['city' => 'Paris'], self::ALLOWED_FIELDS);

        $otherCartStorage = new AddressDraftStorage($this->makeContext($cookie, 99));

        self::assertSame([], $otherCartStorage->get(self::ALLOWED_FIELDS));
    }

    public function testItDropsAnExpiredDraft(): void
    {
        $cookie = $this->makeCookie();
        $cookie->values[self::COOKIE_KEY] = json_encode([
            'ts' => time() - 86401,
            'cart_id' => 42,
            'values' => ['city' => 'Paris'],
        ]);

        $storage = new AddressDraftStorage($this->makeContext($cookie, 42));

        self::assertSame([], $storage->get(self::ALLOWED_FIELDS));
        self::assertArrayNotHasKey(self::COOKIE_KEY, $cookie->values);
    }

    public function testEmptyResultClearsExistingDraft(): void
    {
        $cookie = $this->makeCookie();
        $storage = new AddressDraftStorage($this->makeContext($cookie));
        $storage->saveFromRequest(['city' => 'Paris'], self::ALLOWED_FIELDS);

        $storage->saveFromRequest(['id_address_delivery' => '5', 'token' => 'x'], self::ALLOWED_FIELDS);

        self::assertSame([], $storage->get(self::ALLOWED_FIELDS));
        self::assertArrayNotHasKey(self::COOKIE_KEY, $cookie->values);
    }

    public function testAnOversizedDraftDropsThePreviousOne(): void
    {
        $cookie = $this->makeCookie();
        $storage = new AddressDraftStorage($this->makeContext($cookie));
        $storage->saveFromRequest(['city' => 'Paris'], self::ALLOWED_FIELDS);
        self::assertNotSame([], $storage->get(self::ALLOWED_FIELDS));

        // Several maxed-out fields push the serialized payload past MAX_SERIALIZED_LENGTH,
        // so the previous draft must be dropped rather than left behind as stale data.
        $oversized = [];
        foreach (['firstname', 'lastname', 'address1', 'city', 'invoice_city', 'custom_module_field'] as $field) {
            $oversized[$field] = str_repeat('a', 255);
        }
        $storage->saveFromRequest($oversized, self::ALLOWED_FIELDS);

        self::assertSame([], $storage->get(self::ALLOWED_FIELDS));
        self::assertArrayNotHasKey(self::COOKIE_KEY, $cookie->values);
    }

    public function testClearRemovesTheDraft(): void
    {
        $cookie = $this->makeCookie();
        $storage = new AddressDraftStorage($this->makeContext($cookie));
        $storage->saveFromRequest(['city' => 'Paris'], self::ALLOWED_FIELDS);

        $storage->clear();

        self::assertSame([], $storage->get(self::ALLOWED_FIELDS));
        self::assertArrayNotHasKey(self::COOKIE_KEY, $cookie->values);
    }
}
