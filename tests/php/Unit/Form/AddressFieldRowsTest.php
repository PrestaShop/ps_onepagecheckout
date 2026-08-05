<?php

declare(strict_types=1);

namespace Tests\Unit\Form;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\AddressFieldRows;

class AddressFieldRowsTest extends TestCase
{
    public function testItPutsEachFieldOnItsOwnRowByDefault(): void
    {
        $rows = AddressFieldRows::build($this->fields(['id_country', 'alias', 'address1']));

        self::assertSame(
            [['id_country'], ['alias'], ['address1']],
            $this->rowNames($rows)
        );
    }

    public function testItGroupsAdjacentFirstAndLastNameIntoOneRow(): void
    {
        $rows = AddressFieldRows::build($this->fields(['firstname', 'lastname', 'address1']));

        self::assertSame(
            [['firstname', 'lastname'], ['address1']],
            $this->rowNames($rows)
        );
    }

    public function testItGroupsAdjacentCityStateAndPostcodeIntoOneRowInTheConfiguredOrder(): void
    {
        $rows = AddressFieldRows::build($this->fields(['city', 'id_state', 'postcode']));

        self::assertSame([['city', 'id_state', 'postcode']], $this->rowNames($rows));
    }

    public function testItGroupsCityAndPostcodeWhenTheFormatHasNoStateField(): void
    {
        $rows = AddressFieldRows::build($this->fields(['postcode', 'city']));

        self::assertSame([['postcode', 'city']], $this->rowNames($rows));
    }

    /**
     * A merchant who moves a field into the middle of a group asked for that layout, so the
     * group does not become a row.
     */
    public function testItDoesNotGroupFieldsTheConfiguredFormatSeparates(): void
    {
        $rows = AddressFieldRows::build($this->fields(['firstname', 'address1', 'lastname']));

        self::assertSame(
            [['firstname'], ['address1'], ['lastname']],
            $this->rowNames($rows)
        );
    }

    public function testItStartsANewRowWhenTheNextFieldBelongsToAnotherGroup(): void
    {
        $rows = AddressFieldRows::build($this->fields(['firstname', 'lastname', 'city', 'postcode']));

        self::assertSame(
            [['firstname', 'lastname'], ['city', 'postcode']],
            $this->rowNames($rows)
        );
    }

    public function testItStripsTheSectionPrefixBeforeGrouping(): void
    {
        $rows = AddressFieldRows::build(
            $this->fields(['invoice_firstname', 'invoice_lastname', 'invoice_address1']),
            'invoice_'
        );

        self::assertSame(
            [['invoice_firstname', 'invoice_lastname'], ['invoice_address1']],
            $this->rowNames($rows)
        );
    }

    /**
     * The formatter keys module-added fields `<module>_<name>` while the field itself keeps its raw
     * name, so grouping has to key off the array key: two modules adding a field with the same name
     * must both keep their own row.
     */
    public function testItKeepsModuleAddedFieldsWithTheSameNameApart(): void
    {
        $rows = AddressFieldRows::build([
            'address1' => ['name' => 'address1'],
            'modulea_note' => ['name' => 'note', 'label' => 'Module A note'],
            'moduleb_note' => ['name' => 'note', 'label' => 'Module B note'],
        ]);

        self::assertCount(3, $rows);
        self::assertSame('Module A note', $rows[1][0]['label']);
        self::assertSame('Module B note', $rows[2][0]['label']);
    }

    public function testItReturnsNoRowsForAnEmptySection(): void
    {
        self::assertSame([], AddressFieldRows::build([]));
    }

    /**
     * @param array<int, string> $names
     *
     * @return array<string, array<string, mixed>>
     */
    private function fields(array $names): array
    {
        $fields = [];
        foreach ($names as $name) {
            $fields[$name] = ['name' => $name];
        }

        return $fields;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rows
     *
     * @return array<int, array<int, string>>
     */
    private function rowNames(array $rows): array
    {
        return array_map(
            static function (array $row): array {
                return array_map(
                    static function (array $field): string {
                        return (string) $field['name'];
                    },
                    $row
                );
            },
            $rows
        );
    }
}
