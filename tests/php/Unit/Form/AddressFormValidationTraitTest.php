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
use PrestaShop\Module\PsOnePageCheckout\Form\AddressFormValidationTrait;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AddressFormValidationTraitTest extends TestCase
{
    protected function setUp(): void
    {
        $this->defineGlobalStubs();
    }

    public function testItFailsWhenDeliveryPostcodeIsInvalid(): void
    {
        $sut = new AddressFormValidationTraitHarness($this->buildTranslator(), (object) ['id' => 1]);

        $deliveryCountry = new \Country();
        $deliveryCountry->need_zip_code = true;
        $deliveryCountry->zip_code_format = 'NNNNN';
        $deliveryCountry->validZipCodes = ['75001'];

        $postcode = (new \FormField())
            ->setRequired(true)
            ->setValue('ABCDE');

        $isValid = $sut->exposeValidateAddressPostcode($postcode, $deliveryCountry);

        self::assertFalse($isValid);
        self::assertNotEmpty($postcode->getErrors());
    }

    public function testItValidatesInvoicePostcodeAgainstInvoiceCountryField(): void
    {
        $sut = new AddressFormValidationTraitHarness($this->buildTranslator(), (object) ['id' => 1]);

        $deliveryCountry = new \Country();
        $deliveryCountry->need_zip_code = true;
        $deliveryCountry->zip_code_format = 'NNNNN';
        $deliveryCountry->validZipCodes = ['75001'];

        \Country::$registry[2] = [
            'need_zip_code' => true,
            'zip_code_format' => 'NNNNN',
            'validZipCodes' => ['13001'],
        ];

        $invoicePostcode = (new \FormField())
            ->setRequired(true)
            ->setValue('99999');
        $invoiceCountryField = (new \FormField())->setValue(2);

        $isValid = $sut->exposeValidateAddressPostcode(
            null,
            $deliveryCountry,
            $invoicePostcode,
            $invoiceCountryField
        );

        self::assertFalse($isValid);
        self::assertNotEmpty($invoicePostcode->getErrors());
    }

    public function testItSkipsInvoicePostcodeCheckWhenInvoiceCountryDoesNotNeedZipCode(): void
    {
        $sut = new AddressFormValidationTraitHarness($this->buildTranslator(), (object) ['id' => 1]);

        $deliveryCountry = new \Country();
        $deliveryCountry->need_zip_code = true;
        $deliveryCountry->zip_code_format = 'NNNNN';
        $deliveryCountry->validZipCodes = ['75001'];

        \Country::$registry[3] = [
            'need_zip_code' => false,
            'zip_code_format' => '',
            'validZipCodes' => [],
        ];

        $invoicePostcode = (new \FormField())
            ->setRequired(true)
            ->setValue('invalid');
        $invoiceCountryField = (new \FormField())->setValue(3);

        $isValid = $sut->exposeValidateAddressPostcode(
            null,
            $deliveryCountry,
            $invoicePostcode,
            $invoiceCountryField
        );

        self::assertTrue($isValid);
        self::assertSame([], $invoicePostcode->getErrors());
    }

    public function testItReturnsTrueWhenNoRequiredPostcodesAreProvided(): void
    {
        $sut = new AddressFormValidationTraitHarness($this->buildTranslator(), (object) ['id' => 1]);

        $country = new \Country();
        $country->need_zip_code = true;
        $country->zip_code_format = 'NNNNN';
        $country->validZipCodes = [];

        $deliveryPostcode = (new \FormField())
            ->setRequired(false)
            ->setValue('bad');
        $invoicePostcode = (new \FormField())
            ->setRequired(false)
            ->setValue('bad');

        $isValid = $sut->exposeValidateAddressPostcode($deliveryPostcode, $country, $invoicePostcode);

        self::assertTrue($isValid);
        self::assertSame([], $deliveryPostcode->getErrors());
        self::assertSame([], $invoicePostcode->getErrors());
    }

    public function testItReturnsFalseWhenValidationHookReturnsFalse(): void
    {
        $sut = new AddressFormValidationTraitHarness($this->buildTranslator(), (object) ['id' => 1]);
        \Hook::$responses['actionValidateCustomerAddressForm'] = false;

        self::assertFalse($sut->exposeValidateAddressFormHook());
    }

    public function testItReturnsTrueWhenValidationHookReturnsNull(): void
    {
        $sut = new AddressFormValidationTraitHarness($this->buildTranslator(), (object) ['id' => 1]);
        \Hook::$responses['actionValidateCustomerAddressForm'] = null;

        self::assertTrue($sut->exposeValidateAddressFormHook());
    }

    private function buildTranslator(): object
    {
        return new class {
            public function trans(string $message, array $parameters = [], string $domain = ''): string
            {
                return strtr($message, $parameters);
            }
        };
    }

    private function defineGlobalStubs(): void
    {
        if (!class_exists('FormField', false)) {
            eval(<<<'PHP'
class FormField
{
    private bool $required = false;
    private $value = null;
    private array $errors = [];

    public function setRequired($required) { $this->required = (bool) $required; return $this; }
    public function isRequired() { return $this->required; }
    public function setValue($value) { $this->value = $value; return $this; }
    public function getValue() { return $this->value; }
    public function addError($error) { $this->errors[] = $error; return $this; }
    public function getErrors() { return $this->errors; }
}
PHP
            );
        }

        if (!class_exists('Country', false)) {
            eval(<<<'PHP'
class Country
{
    public static array $registry = [];
    public bool $need_zip_code = false;
    public string $zip_code_format = '';
    public array $validZipCodes = [];

    public function __construct(int $id = 0, int $idLang = 0)
    {
        if ($id > 0 && isset(self::$registry[$id])) {
            $data = self::$registry[$id];
            $this->need_zip_code = (bool) ($data['need_zip_code'] ?? false);
            $this->zip_code_format = (string) ($data['zip_code_format'] ?? '');
            $this->validZipCodes = (array) ($data['validZipCodes'] ?? []);
        }
    }

    public function checkZipCode($postcode): bool
    {
        return in_array((string) $postcode, $this->validZipCodes, true);
    }
}
PHP
            );
        }

        if (!class_exists('Hook', false)) {
            eval(<<<'PHP'
class Hook
{
    public static array $responses = [];

    public static function exec($hookName, $params = [])
    {
        return self::$responses[$hookName] ?? null;
    }
}
PHP
            );
        }
    }
}

class AddressFormValidationTraitHarness
{
    use AddressFormValidationTrait;

    public $translator;
    public $language;

    public function __construct($translator, $language)
    {
        $this->translator = $translator;
        $this->language = $language;
    }

    public function exposeValidateAddressPostcode(
        $postcode = null,
        $country = null,
        $invoicePostcode = null,
        $invoiceCountryField = null,
    ): bool {
        return $this->validateAddressPostcode($postcode, $country, $invoicePostcode, $invoiceCountryField);
    }

    public function exposeValidateAddressFormHook(): bool
    {
        return $this->validateAddressFormHook();
    }
}
