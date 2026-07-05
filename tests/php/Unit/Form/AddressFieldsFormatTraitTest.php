<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Form;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\AddressFieldsFormatTrait;
use Tests\Fixtures\CheckoutTestFixtures;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AddressFieldsFormatTraitTest extends TestCase
{
    public function testItBuildsExpectedAddressFieldsFormatAndAppliesDefinitionMetadata(): void
    {
        \AddressFormat::$orderedFields = [
            'firstname',
            'lastname',
            'address1',
            'address2',
            'postcode',
            'city',
            'Country:name',
            'State:name',
            'phone',
            'phone_mobile',
            'company',
            'vat_number',
            'dni',
            'other',
            'Custom:name',
            'unknown',
        ];
        \AddressFormat::$requiredFields = [
            'firstname',
            'lastname',
            'address1',
            'city',
            'Country:name',
        ];
        \State::$statesByCountry = [
            33 => [
                ['id_state' => 10, 'name' => 'Ile-de-France'],
                ['id_state' => 20, 'name' => 'Normandie'],
            ],
        ];

        $sut = $this->createHarness(
            $this->createCountry([
                'id' => 33,
                'need_zip_code' => true,
                'zip_code_format' => 'NNNNN',
                'need_identification_number' => true,
                'contains_states' => true,
            ]),
            CheckoutTestFixtures::translator(static fn (string $message): string => $message),
            [
                ['id_country' => 33, 'name' => 'France'],
                ['id_country' => 34, 'name' => 'Spain'],
            ],
            [
                'invoice_firstname' => ['validate' => 'isName', 'size' => 64],
                'invoice_phone' => ['validate' => 'isPhoneNumber', 'size' => 16],
                'invoice_id_country' => ['size' => 3],
            ]
        );

        $format = $sut->exposeGetAddressFieldsFormat('invoice_', true);
        $format = $sut->exposeAddConstraints($sut->exposeAddMaxLength($format));

        self::assertTrue($format['invoice_alias']->isRequired());
        self::assertTrue($format['invoice_postcode']->isRequired());
        self::assertSame(5, $format['invoice_postcode']->getMinLength());
        self::assertSame('tel', $format['invoice_phone']->getType());
        self::assertSame(
            'Only used if we need to contact you about the order or its delivery.',
            $format['invoice_phone']->getAvailableValues()['comment'] ?? null
        );
        self::assertArrayNotHasKey('comment', $format['invoice_address1']->getAvailableValues());
        self::assertSame('countrySelect', $format['invoice_id_country']->getType());
        self::assertSame(33, $format['invoice_id_country']->getValue());
        self::assertSame(
            [33 => 'France', 34 => 'Spain'],
            $format['invoice_id_country']->getAvailableValues()
        );
        self::assertSame('select', $format['invoice_id_state']->getType());
        self::assertTrue($format['invoice_id_state']->isRequired());
        self::assertSame(
            [10 => 'Ile-de-France', 20 => 'Normandie'],
            $format['invoice_id_state']->getAvailableValues()
        );
        self::assertSame('select', $format['invoice_id_custom']->getType());
        self::assertTrue($format['invoice_dni']->isRequired());
        self::assertSame('unknown', $format['invoice_unknown']->getLabel());
        self::assertSame(['isName'], $format['invoice_firstname']->getConstraints());
        self::assertSame(64, $format['invoice_firstname']->getMaxLength());
        self::assertSame(['isPhoneNumber'], $format['invoice_phone']->getConstraints());
        self::assertSame(3, $format['invoice_id_country']->getMaxLength());
    }

    public function testItKeepsAliasOptionalWhenAliasRequiredIsFalse(): void
    {
        \AddressFormat::$orderedFields = [];
        \AddressFormat::$requiredFields = [];

        $sut = $this->createHarness(
            $this->createCountry(['id' => 1]),
            CheckoutTestFixtures::translator(static fn (string $message): string => $message),
            [],
            []
        );
        $format = $sut->exposeGetAddressFieldsFormat('', false);

        self::assertArrayHasKey('alias', $format);
        self::assertFalse($format['alias']->isRequired());
        self::assertSame('invoice_firstname', $sut->exposeGetDefinitionKey('invoice_firstname'));
    }

    /**
     * @dataProvider provideFieldLabels
     */
    public function testItReturnsExpectedFieldLabels(string $field, string $expected): void
    {
        $sut = $this->createHarness(
            $this->createCountry(['id' => 1]),
            CheckoutTestFixtures::translator(static fn (string $message): string => $message),
            [],
            []
        );

        self::assertSame($expected, $sut->exposeGetFieldLabel($field));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideFieldLabels(): iterable
    {
        yield 'alias' => ['alias', 'Alias'];
        yield 'firstname' => ['firstname', 'First name'];
        yield 'lastname' => ['lastname', 'Last name'];
        yield 'address1' => ['address1', 'Address'];
        yield 'address2' => ['address2', 'Address Complement'];
        yield 'postcode' => ['postcode', 'Zip/Postal Code'];
        yield 'city' => ['city', 'City'];
        yield 'country relation' => ['Country:name', 'Country'];
        yield 'state relation' => ['State:name', 'State'];
        yield 'phone' => ['phone', 'Phone'];
        yield 'phone mobile' => ['phone_mobile', 'Mobile phone'];
        yield 'company' => ['company', 'Company'];
        yield 'vat' => ['vat_number', 'VAT number'];
        yield 'dni' => ['dni', 'Identification number'];
        yield 'other' => ['other', 'Other'];
        yield 'fallback' => ['custom_field', 'custom_field'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCountry(array $overrides): \Country
    {
        return CheckoutTestFixtures::country($overrides);
    }

    /**
     * @param array<int, array<string, mixed>> $availableCountries
     * @param array<string, array<string, mixed>> $definition
     */
    private function createHarness(
        \Country $country,
        object $translator,
        array $availableCountries,
        array $definition,
    ): AddressFieldsFormatTraitHarness {
        return new AddressFieldsFormatTraitHarness($country, $translator, $availableCountries, $definition);
    }
}

class AddressFieldsFormatTraitHarness
{
    use AddressFieldsFormatTrait;

    public $country;
    public $translator;
    public $availableCountries;
    public $definition;

    public function __construct($country, $translator, array $availableCountries, array $definition)
    {
        $this->country = $country;
        $this->translator = $translator;
        $this->availableCountries = $availableCountries;
        $this->definition = $definition;
    }

    public function exposeGetAddressFieldsFormat(string $prefix = '', bool $aliasRequired = false): array
    {
        return $this->getAddressFieldsFormat($prefix, $aliasRequired);
    }

    public function exposeAddConstraints(array $format): array
    {
        return $this->addConstraints($format);
    }

    public function exposeAddMaxLength(array $format): array
    {
        return $this->addMaxLength($format);
    }

    public function exposeGetFieldLabel(string $field): string
    {
        return $this->getFieldLabel($field);
    }

    public function exposeGetDefinitionKey(string $name): string
    {
        return $this->getDefinitionKey($name);
    }
}
