<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerTemplateBuilder;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OpcTempAddress;
use Tests\Fixtures\CheckoutTestFixtures;

class CheckoutCustomerTemplateBuilderTest extends TestCase
{
    public function testItBuildsTheLoggedCustomerTemplatePayload(): void
    {
        $smarty = $this->getMockBuilder(\Smarty::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTemplateVars'])
            ->getMock();
        $smarty->method('getTemplateVars')->with('customer')->willReturn([
            'firstname' => 'Template',
        ]);

        $customer = CheckoutTestFixtures::customer(
            [
                [
                    'id' => 10,
                    'alias' => 'Home',
                ],
            ],
            true,
            false,
            [
                'id' => 42,
                'firstname' => 'Alice',
                'lastname' => 'Doe',
                'email' => 'alice@example.com',
            ]
        );
        $context = CheckoutTestFixtures::context([
            'language' => CheckoutTestFixtures::language(1),
            'smarty' => $smarty,
            'customer' => $customer,
        ]);

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolve')->willReturn($customer);

        $builder = $this->createBuilder($context, $resolver);

        $result = $builder->build();

        self::assertSame('Alice', $result['firstname']);
        self::assertSame(42, $result['id']);
        self::assertFalse($result['is_guest']);
        self::assertTrue($result['is_logged']);
        self::assertSame(
            [
                [
                    'id' => 10,
                    'alias' => 'Home',
                ],
            ],
            $result['addresses']
        );
    }

    public function testItFallsBackToTheContextCustomerWhenResolverDoesNotReturnACustomer(): void
    {
        $context = CheckoutTestFixtures::context([
            'language' => CheckoutTestFixtures::language(1),
            'customer' => CheckoutTestFixtures::customer([], false, false),
        ]);

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $builder = $this->createBuilder($context, $resolver);

        self::assertSame(
            [
                'is_logged' => false,
                'addresses' => [],
            ],
            $builder->build()
        );
    }

    public function testItUsesTheResolvedCustomerPayload(): void
    {
        $resolvedCustomer = CheckoutTestFixtures::customer(
            [['id' => 22, 'alias' => 'Resolved home']],
            true,
            false,
            [
                'id' => 42,
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => 'john@example.com',
            ]
        );
        $context = CheckoutTestFixtures::context([
            'language' => CheckoutTestFixtures::language(1),
            'customer' => CheckoutTestFixtures::customer([], false, false),
        ]);

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolve')->willReturn($resolvedCustomer);

        $builder = $this->createBuilder($context, $resolver);
        $result = $builder->build();

        self::assertSame(42, $result['id']);
        self::assertSame('John', $result['firstname']);
        self::assertSame('Doe', $result['lastname']);
        self::assertSame('john@example.com', $result['email']);
        self::assertTrue($result['is_logged']);
        self::assertFalse($result['is_guest']);
        self::assertSame(
            [
                [
                    'id' => 22,
                    'alias' => 'Resolved home',
                ],
            ],
            $result['addresses']
        );
    }

    public function testItNormalizesIdFromLegacyIdAddressWhenNeeded(): void
    {
        $customer = CheckoutTestFixtures::customer(
            [
                [
                    'id_address' => 15,
                    'alias' => 'Legacy address',
                ],
            ],
            false,
            false,
            ['id' => 99]
        );
        $context = CheckoutTestFixtures::context([
            'language' => CheckoutTestFixtures::language(1),
            'customer' => $customer,
        ]);

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolve')->willReturn($customer);

        $builder = $this->createBuilder($context, $resolver);

        $result = $builder->build();

        self::assertFalse($result['is_logged']);
        self::assertSame(
            [
                [
                    'id_address' => 15,
                    'alias' => 'Legacy address',
                    'id' => 15,
                ],
            ],
            $result['addresses']
        );
    }

    public function testItFiltersTemporaryAddressesFromTheTemplatePayload(): void
    {
        $customer = CheckoutTestFixtures::customer(
            [
                [
                    'id' => 10,
                    'alias' => 'Home',
                ],
                [
                    'id' => 11,
                    'alias' => OpcTempAddress::TEMPORARY_ADDRESS_ALIAS_PREFIX . 'deadbeef',
                ],
            ],
            true,
            false
        );
        $context = CheckoutTestFixtures::context([
            'language' => CheckoutTestFixtures::language(1),
            'customer' => $customer,
        ]);

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolve')->willReturn($customer);

        $result = $this->createBuilder($context, $resolver)->build();

        self::assertSame(
            [
                [
                    'id' => 10,
                    'alias' => 'Home',
                ],
            ],
            $result['addresses']
        );
    }

    public function testItReturnsNoAddressesForAGuestSoTheInlineFormIsShown(): void
    {
        // A guest must never be switched to the saved-address list: they stay on the inline form
        // (parity with the native checkout, and it prevents the guest from accumulating phantom
        // addresses). The guard empties the address list for guests even though the autosaved
        // address still exists on the customer record (submit reads it directly, not via this payload).
        $customer = CheckoutTestFixtures::customer(
            [
                ['id' => 10, 'alias' => 'My Address'],
                ['id' => 11, 'alias' => 'Office'],
            ],
            false,
            true,
            [
                'id' => 7,
                'firstname' => 'Guest',
                'lastname' => 'Buyer',
                'email' => 'guest-buyer@example.com',
            ]
        );
        $context = CheckoutTestFixtures::context([
            'language' => CheckoutTestFixtures::language(1),
            'customer' => $customer,
        ]);

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolve')->willReturn($customer);

        $result = $this->createBuilder($context, $resolver)->build();

        self::assertSame([], $result['addresses']);
        self::assertTrue($result['is_guest']);
        // The rest of the customer payload is still exposed (used by the contact section, etc.).
        self::assertSame(7, $result['id']);
        self::assertSame('guest-buyer@example.com', $result['email']);
    }

    private function createBuilder(
        \Context $context,
        CheckoutCustomerContextResolver $resolver,
    ): CheckoutCustomerTemplateBuilder {
        return new CheckoutCustomerTemplateBuilder($context, $resolver);
    }
}
