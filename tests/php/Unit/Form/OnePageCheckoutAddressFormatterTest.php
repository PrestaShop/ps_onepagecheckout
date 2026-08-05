<?php

declare(strict_types=1);

namespace Tests\Unit\Form;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutAddressFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Fixtures\CheckoutTestFixtures;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class OnePageCheckoutAddressFormatterTest extends TestCase
{
    public function testItKeepsAliasOptionalInTheAddressModalFormat(): void
    {
        \Address::$definition = [
            'fields' => [
                'alias' => ['size' => 32],
                'firstname' => ['size' => 64],
                'id_country' => ['size' => 3],
            ],
        ];
        \AddressFormat::$orderedFields = ['firstname', 'Country:name'];
        \AddressFormat::$requiredFields = ['firstname', 'Country:name'];

        $formatter = new OnePageCheckoutAddressFormatter(
            CheckoutTestFixtures::country(['id' => 8, 'contains_states' => false]),
            $this->createTranslator(),
            [
                ['id_country' => 8, 'name' => 'France'],
            ]
        );

        $format = $formatter->getFormat();

        self::assertArrayHasKey('alias', $format);
        self::assertFalse($format['alias']->isRequired());
    }

    /**
     * Same contract as the checkout formatter: the configured per-country address format drives
     * the field order, with only the country select and the alias pinned ahead of it.
     */
    public function testItOrdersAddressFieldsByTheConfiguredCountryAddressFormat(): void
    {
        \Address::$definition = [
            'fields' => [
                'alias' => ['size' => 32],
                'firstname' => ['size' => 64],
                'address1' => ['size' => 128],
                'phone' => ['size' => 16],
                'id_country' => ['size' => 3],
            ],
        ];
        \AddressFormat::$orderedFields = ['firstname', 'phone', 'address1', 'Country:name'];
        \AddressFormat::$requiredFields = [];

        $formatter = new OnePageCheckoutAddressFormatter(
            CheckoutTestFixtures::country(['id' => 8, 'contains_states' => false]),
            $this->createTranslator(),
            [
                ['id_country' => 8, 'name' => 'Italy'],
            ]
        );

        self::assertSame(
            ['id_country', 'alias', 'firstname', 'phone', 'address1'],
            array_keys($formatter->getFormat())
        );
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
