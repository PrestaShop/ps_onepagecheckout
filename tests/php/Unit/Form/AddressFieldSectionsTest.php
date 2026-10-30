<?php

declare(strict_types=1);

namespace Tests\Unit\Form;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\AddressFieldRows;
use PrestaShop\Module\PsOnePageCheckout\Form\AddressFieldSections;

class AddressFieldSectionsTest extends TestCase
{
    public function testTheDefaultGuestFormKeepsOnlyTheEssentialFieldsVisible(): void
    {
        $sections = $this->sections([
            'id_country', 'alias', 'firstname', 'lastname', 'company', 'vat_number',
            'address1', 'address2', 'city', 'postcode', 'phone',
        ]);

        self::assertSame([
            [null, true, [['id_country'], ['firstname', 'lastname']]],
            ['company', false, [['company'], ['vat_number']]],
            [null, true, [['address1']]],
            ['complement', false, [['address2']]],
            [null, true, [['city', 'postcode'], ['phone']]],
        ], $this->summary($sections));
    }

    public function testAFilledOrInvalidFieldOpensItsDisclosure(): void
    {
        $sections = $this->sections(['company', 'vat_number', 'address2'], [
            'vat_number' => ['value' => 'FR123'],
            'address2' => ['errors' => ['Invalid']],
        ]);

        self::assertSame([
            ['company', true, [['company'], ['vat_number']]],
            ['complement', true, [['address2']]],
        ], $this->summary($sections));
    }

    public function testARequiredFieldIsNeverHidden(): void
    {
        $sections = $this->sections(['company', 'vat_number', 'address2'], [
            'company' => ['required' => true],
            'address2' => ['required' => true],
        ]);

        self::assertSame([
            [null, true, [['company'], ['vat_number'], ['address2']]],
        ], $this->summary($sections));
    }

    public function testTheAliasStaysWhenItNeedsTheBuyer(): void
    {
        self::assertSame(
            [[null, true, [['alias'], ['address1']]]],
            $this->summary($this->sections(['alias', 'address1'], ['alias' => ['value' => 'Office']]))
        );
        self::assertSame(
            [[null, true, [['alias'], ['address1']]]],
            $this->summary($this->sections(['alias', 'address1'], ['alias' => ['required' => true]]))
        );
    }

    /**
     * A disclosure only wraps rows the configured format already puts next to each other.
     */
    public function testCompanyFieldsTheFormatSeparatesGetADisclosureEach(): void
    {
        $sections = $this->sections(['company', 'address1', 'vat_number']);

        self::assertSame([
            ['company', false, [['company']]],
            [null, true, [['address1']]],
            ['company', false, [['vat_number']]],
        ], $this->summary($sections));
    }

    public function testItStripsTheSectionPrefix(): void
    {
        $fields = $this->fields(['invoice_alias', 'invoice_address1', 'invoice_address2']);
        $sections = AddressFieldSections::build(AddressFieldRows::build($fields, 'invoice_'), 'invoice_');

        self::assertSame([
            [null, true, [['invoice_address1']]],
            ['complement', false, [['invoice_address2']]],
        ], $this->summary($sections));
    }

    /**
     * @param string[] $names
     * @param array<string, array<string, mixed>> $overrides
     *
     * @return array<int, array<string, mixed>>
     */
    private function sections(array $names, array $overrides = []): array
    {
        return AddressFieldSections::build(AddressFieldRows::build($this->fields($names, $overrides)));
    }

    /**
     * @param string[] $names
     * @param array<string, array<string, mixed>> $overrides
     *
     * @return array<string, array<string, mixed>>
     */
    private function fields(array $names, array $overrides = []): array
    {
        $fields = [];
        foreach ($names as $name) {
            $fields[$name] = ($overrides[$name] ?? []) + ['name' => $name, 'required' => false, 'value' => '', 'errors' => []];
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     *
     * @return array<int, array{0: string|null, 1: bool, 2: array<int, array<int, string>>}>
     */
    private function summary(array $sections): array
    {
        return array_map(
            static function (array $section): array {
                return [
                    $section['disclosure'],
                    $section['open'],
                    array_map(
                        static function (array $row): array {
                            return array_map(
                                static function (array $field): string {
                                    return (string) $field['name'];
                                },
                                $row
                            );
                        },
                        $section['rows']
                    ),
                ];
            },
            $sections
        );
    }
}
