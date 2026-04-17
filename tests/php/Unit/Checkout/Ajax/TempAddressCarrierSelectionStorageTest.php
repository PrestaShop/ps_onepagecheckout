<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\TempAddressCarrierSelectionStorage;

class TempAddressCarrierSelectionStorageTest extends TestCase
{
    public function testItSavesAndClearsTheTemporaryCarrierSelection(): void
    {
        $cookie = new class {
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

        $context = new \Context();
        $context->cookie = $cookie;

        $storage = new TempAddressCarrierSelectionStorage($context);

        self::assertSame('', $storage->get());

        $storage->save('2,');
        self::assertSame('2,', $storage->get());

        $storage->clear();
        self::assertSame('', $storage->get());
        self::assertSame(2, $cookie->writes);
    }
}
