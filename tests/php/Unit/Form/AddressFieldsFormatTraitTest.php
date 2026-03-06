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

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AddressFieldsFormatTraitTest extends TestCase
{
    protected function setUp(): void
    {
        $this->defineGlobalStubs();
    }

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

        $country = new \Country();
        $country->id = 33;
        $country->need_zip_code = true;
        $country->zip_code_format = 'NNNNN';
        $country->need_identification_number = true;
        $country->contains_states = true;

        $translator = new class {
            public function trans(string $message, array $parameters = [], string $domain = ''): string
            {
                return $message;
            }
        };

        $sut = new AddressFieldsFormatTraitHarness(
            $country,
            $translator,
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

        $country = new \Country();
        $country->id = 1;

        $translator = new class {
            public function trans(string $message, array $parameters = [], string $domain = ''): string
            {
                return $message;
            }
        };

        $sut = new AddressFieldsFormatTraitHarness($country, $translator, [], []);
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
        $country = new \Country();
        $country->id = 1;
        $translator = new class {
            public function trans(string $message, array $parameters = [], string $domain = ''): string
            {
                return $message;
            }
        };

        $sut = new AddressFieldsFormatTraitHarness($country, $translator, [], []);

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

    private function defineGlobalStubs(): void
    {
        if (!class_exists('FormField', false)) {
            eval(<<<'PHP'
class FormField
{
    public ?string $moduleName = null;
    private string $name = '';
    private string $type = 'text';
    private bool $required = false;
    private string $label = '';
    private $value = null;
    private array $availableValues = [];
    private ?int $minLength = null;
    private ?int $maxLength = null;
    private array $constraints = [];

    public function setName($name) { $this->name = (string) $name; return $this; }
    public function getName() { return $this->name; }
    public function setType($type) { $this->type = (string) $type; return $this; }
    public function getType() { return $this->type; }
    public function setRequired($required) { $this->required = (bool) $required; return $this; }
    public function isRequired() { return $this->required; }
    public function setLabel($label) { $this->label = (string) $label; return $this; }
    public function getLabel() { return $this->label; }
    public function setValue($value) { $this->value = $value; return $this; }
    public function getValue() { return $this->value; }
    public function addAvailableValue($value, $label = null) { $this->availableValues[$value] = $label ?? $value; return $this; }
    public function getAvailableValues() { return $this->availableValues; }
    public function setMinLength($minLength) { $this->minLength = (int) $minLength; return $this; }
    public function getMinLength() { return $this->minLength; }
    public function setMaxLength($maxLength) { $this->maxLength = (int) $maxLength; return $this; }
    public function getMaxLength() { return $this->maxLength; }
    public function addConstraint($constraint) { $this->constraints[] = $constraint; return $this; }
    public function getConstraints() { return $this->constraints; }
}
PHP
            );
        }

        if (!class_exists('Country', false)) {
            eval(<<<'PHP'
class Country
{
    public int $id = 0;
    public bool $need_zip_code = false;
    public string $zip_code_format = '';
    public bool $need_identification_number = false;
    public bool $contains_states = false;
}
PHP
            );
        }

        if (!class_exists('AddressFormat', false)) {
            eval(<<<'PHP'
class AddressFormat
{
    public static array $orderedFields = [];
    public static array $requiredFields = [];

    public static function getOrderedAddressFields($idCountry = 0, $splitAll = false, $cleaned = false)
    {
        return self::$orderedFields;
    }

    public static function getFieldsRequired()
    {
        return self::$requiredFields;
    }
}
PHP
            );
        }

        if (!class_exists('State', false)) {
            eval(<<<'PHP'
class State
{
    public static array $statesByCountry = [];

    public static function getStatesByIdCountry($idCountry, $active = false, $orderBy = null, $sort = 'ASC')
    {
        return self::$statesByCountry[(int) $idCountry] ?? [];
    }
}
PHP
            );
        }
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
