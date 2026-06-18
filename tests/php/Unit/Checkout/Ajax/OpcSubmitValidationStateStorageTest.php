<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit\OnePageCheckoutSubmitValidationStateStorage;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

class OpcSubmitValidationStateStorageTest extends TestCase
{
    public function testSaveMergesIntoCheckoutSessionDataWithoutOverwritingOtherPersistedData(): void
    {
        $db = new InMemoryCheckoutSessionDataDb();
        $db->rows[42] = json_encode([
            'opc' => [
                'validation_errors' => ['legacy' => ['keep']],
            ],
            'checksum' => 'abc',
        ]);

        $state = [
            'cart_id' => 42,
            'validation_errors' => [
                'address' => [
                    'firstname' => ['This field is required.'],
                ],
            ],
            'form_errors' => [
                'firstname' => ['This field is required.'],
            ],
            'submitted_values' => [
                'firstname' => '',
            ],
        ];

        $storage = new TestableOnePageCheckoutSubmitValidationStateStorage(
            $this->createContextWithCart(42),
            $db
        );

        $storage->save($state);

        self::assertIsString($db->rows[42] ?? null);
        $persistedData = json_decode($db->rows[42], true);
        self::assertSame('abc', $persistedData['checksum'] ?? null);
        self::assertSame(['legacy' => ['keep']], $persistedData['opc']['validation_errors'] ?? null);
        self::assertSame($state, $persistedData['_opc_submit_validation_state'] ?? null);
    }

    public function testConsumeReturnsStoredStateAndClearsOnlyModuleKey(): void
    {
        $db = new InMemoryCheckoutSessionDataDb();
        $state = [
            'cart_id' => 42,
            'validation_errors' => [],
            'form_errors' => [
                'firstname' => ['Required'],
            ],
            'submitted_values' => [
                'firstname' => '',
            ],
        ];
        $db->rows[42] = json_encode([
            '_opc_submit_validation_state' => $state,
            'checksum' => 'keep-me',
        ]);

        $context = $this->createContextWithCart(42);
        $storage = new TestableOnePageCheckoutSubmitValidationStateStorage($context, $db);

        self::assertSame($state, $storage->consume());

        $persistedData = json_decode($db->rows[42], true);
        self::assertSame(['checksum' => 'keep-me'], $persistedData);
        self::assertSame('{"checksum":"keep-me"}', $context->cart->checkout_session_data);
        self::assertSame([], $storage->consume());
    }

    public function testConsumeReturnsEmptyWhenNoStateExists(): void
    {
        $storage = new TestableOnePageCheckoutSubmitValidationStateStorage(
            $this->createContextWithCart(42),
            new InMemoryCheckoutSessionDataDb()
        );

        self::assertSame([], $storage->consume());
    }

    public function testDifferentCartDoesNotConsumeAnotherCartState(): void
    {
        $db = new InMemoryCheckoutSessionDataDb();
        $db->rows[42] = json_encode([
            '_opc_submit_validation_state' => [
                'cart_id' => 42,
                'validation_errors' => [],
                'form_errors' => [
                    '' => ['One-page checkout is currently unavailable.'],
                ],
                'submitted_values' => [],
            ],
        ]);

        $storage = new TestableOnePageCheckoutSubmitValidationStateStorage(
            $this->createContextWithCart(99),
            $db
        );

        self::assertSame([], $storage->consume());
        self::assertArrayHasKey(42, $db->rows);
    }

    private function createContextWithCart(int $cartId): \Context
    {
        $context = $this->createMock(\Context::class);
        $context->cart = new \stdClass();
        $context->cart->id = $cartId;

        return $context;
    }
}

class InMemoryCheckoutSessionDataDb
{
    /**
     * @var array<int, string>
     */
    public array $rows = [];

    public function getValue(string $sql): string
    {
        preg_match('/id_cart = (\d+)/', $sql, $matches);
        $cartId = isset($matches[1]) ? (int) $matches[1] : 0;

        return $this->rows[$cartId] ?? '';
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function execute(string $sql): bool
    {
        preg_match('/id_cart = (\d+)/', $sql, $matches);
        $cartId = isset($matches[1]) ? (int) $matches[1] : 0;

        if (str_contains($sql, 'checkout_session_data = NULL')) {
            unset($this->rows[$cartId]);

            return true;
        }

        preg_match('/checkout_session_data = "(.*)" WHERE id_cart/s', $sql, $valueMatches);
        $checkoutSessionData = isset($valueMatches[1]) ? stripslashes($valueMatches[1]) : '';

        if ($checkoutSessionData === null || $checkoutSessionData === '') {
            unset($this->rows[$cartId]);

            return true;
        }

        $this->rows[$cartId] = $checkoutSessionData;

        return true;
    }
}

class TestableOnePageCheckoutSubmitValidationStateStorage extends OnePageCheckoutSubmitValidationStateStorage
{
    private object $db;

    public function __construct(\Context $context, object $db)
    {
        parent::__construct($context);
        $this->db = $db;
    }

    protected function getDb(): object
    {
        return $this->db;
    }
}
