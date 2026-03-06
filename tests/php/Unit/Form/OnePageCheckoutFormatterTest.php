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
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class OnePageCheckoutFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        $this->defineGlobalStubs();
    }

    public function testItBuildsExpectedFormatWhenOptinHooksAndInvoiceCountryAreConfigured(): void
    {
        \Address::$definition = [
            'fields' => [
                'firstname' => ['validate' => 'isName', 'size' => 64],
                'postcode' => ['validate' => 'isPostCode', 'size' => 12],
                'id_country' => ['size' => 3],
                'id_state' => ['size' => 10],
                'phone' => ['validate' => 'isPhoneNumber', 'size' => 16],
                'dni' => ['validate' => 'isDniLite', 'size' => 24],
                'alias' => ['size' => 32],
            ],
        ];
        \AddressFormat::$orderedFields = ['firstname', 'postcode', 'Country:name', 'State:name', 'phone', 'dni'];
        \AddressFormat::$requiredFields = ['firstname', 'Country:name'];
        \State::$statesByCountry = [
            33 => [
                ['id_state' => 1, 'name' => 'Delivery state'],
            ],
            44 => [
                ['id_state' => 2, 'name' => 'Invoice state'],
            ],
        ];
        \Configuration::$values['PS_CUSTOMER_OPTIN'] = true;
        \Customer::$requiredFields = ['optin'];

        $customerExtraField = (new \FormField())
            ->setName('consent')
            ->setType('checkbox');
        $addressExtraField = (new \FormField())
            ->setName('landmark')
            ->setType('text');
        \Hook::$responses['additionalCustomerFormFields'] = [
            'modterms' => [$customerExtraField, 'ignored'],
            'broken' => 'not-an-array',
        ];
        \Hook::$responses['additionalCustomerAddressFields'] = [
            'modgeo' => [$addressExtraField],
            'broken' => 123,
        ];

        $deliveryCountry = new \Country();
        $deliveryCountry->id = 33;
        $deliveryCountry->need_zip_code = true;
        $deliveryCountry->zip_code_format = 'NNNNN';
        $deliveryCountry->need_identification_number = true;
        $deliveryCountry->contains_states = true;

        $invoiceCountry = new \Country();
        $invoiceCountry->id = 44;
        $invoiceCountry->need_zip_code = true;
        $invoiceCountry->zip_code_format = 'NNNN';
        $invoiceCountry->need_identification_number = true;
        $invoiceCountry->contains_states = true;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $formatter = new OnePageCheckoutFormatter(
            $deliveryCountry,
            $translator,
            [
                ['id_country' => 33, 'name' => 'France'],
                ['id_country' => 44, 'name' => 'Belgium'],
            ]
        );

        self::assertSame($formatter, $formatter->setCountry($deliveryCountry));
        self::assertSame($deliveryCountry, $formatter->getCountry());
        self::assertSame($formatter, $formatter->setInvoiceCountry($invoiceCountry));

        $format = $formatter->getFormat();

        self::assertArrayHasKey('email', $format);
        self::assertArrayHasKey('optin', $format);
        self::assertTrue($format['optin']->isRequired());
        self::assertArrayHasKey('use_same_address', $format);
        self::assertTrue($format['use_same_address']->getValue());
        self::assertArrayHasKey('id_address_invoice', $format);
        self::assertArrayHasKey('alias', $format);
        self::assertArrayHasKey('invoice_alias', $format);
        self::assertTrue($format['alias']->isRequired());
        self::assertTrue($format['invoice_alias']->isRequired());
        self::assertSame(33, $format['id_country']->getValue());
        self::assertSame(44, $format['invoice_id_country']->getValue());
        self::assertSame([33 => 'France', 44 => 'Belgium'], $format['id_country']->getAvailableValues());
        self::assertSame([33 => 'France', 44 => 'Belgium'], $format['invoice_id_country']->getAvailableValues());
        self::assertTrue($format['id_state']->isRequired());
        self::assertTrue($format['invoice_id_state']->isRequired());
        self::assertSame([1 => 'Delivery state'], $format['id_state']->getAvailableValues());
        self::assertSame([2 => 'Invoice state'], $format['invoice_id_state']->getAvailableValues());
        self::assertSame('tel', $format['phone']->getType());
        self::assertSame('tel', $format['invoice_phone']->getType());
        self::assertSame(['isPhoneNumber'], $format['phone']->getConstraints());
        self::assertSame(['isPhoneNumber'], $format['invoice_phone']->getConstraints());
        self::assertSame(16, $format['phone']->getMaxLength());
        self::assertSame(16, $format['invoice_phone']->getMaxLength());

        self::assertArrayHasKey('modterms_consent', $format);
        self::assertSame('modterms', $format['modterms_consent']->moduleName);
        self::assertArrayHasKey('modgeo_landmark', $format);
        self::assertSame('modgeo', $format['modgeo_landmark']->moduleName);

        self::assertSame($deliveryCountry, $formatter->getCountry());
    }

    public function testItBuildsFormatWithoutOptinAndUsesDeliveryCountryForInvoiceWhenInvoiceCountryIsNotSet(): void
    {
        \Address::$definition = [
            'fields' => [
                'firstname' => ['validate' => 'isName', 'size' => 64],
                'id_country' => ['size' => 3],
            ],
        ];
        \AddressFormat::$orderedFields = ['firstname', 'Country:name'];
        \AddressFormat::$requiredFields = ['firstname'];
        \State::$statesByCountry = [];
        \Configuration::$values['PS_CUSTOMER_OPTIN'] = false;
        \Customer::$requiredFields = [];
        \Hook::$responses['additionalCustomerFormFields'] = [];
        \Hook::$responses['additionalCustomerAddressFields'] = [];

        $deliveryCountry = new \Country();
        $deliveryCountry->id = 99;
        $deliveryCountry->contains_states = false;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $formatter = new OnePageCheckoutFormatter(
            $deliveryCountry,
            $translator,
            [['id_country' => 99, 'name' => 'Portugal']]
        );

        $format = $formatter->getFormat();

        self::assertArrayNotHasKey('optin', $format);
        self::assertSame(99, $format['id_country']->getValue());
        self::assertSame(99, $format['invoice_id_country']->getValue());

        $getDefinitionKey = new \ReflectionMethod(OnePageCheckoutFormatter::class, 'getDefinitionKey');
        self::assertSame('firstname', $getDefinitionKey->invoke($formatter, 'invoice_firstname'));
        self::assertSame('firstname', $getDefinitionKey->invoke($formatter, 'firstname'));
    }

    private function defineGlobalStubs(): void
    {
        if (!interface_exists('FormFormatterInterface', false)) {
            eval(<<<'PHP'
interface FormFormatterInterface
{
    public function getFormat();
}
PHP
            );
        }

        if (!class_exists('FormField', false)) {
            eval(<<<'PHP'
class FormField
{
    public ?string $moduleName = null;
    private string $name = '';
    private string $type = 'text';
    private bool $required = false;
    private $value = null;
    private string $label = '';
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
    public function setValue($value) { $this->value = $value; return $this; }
    public function getValue() { return $this->value; }
    public function setLabel($label) { $this->label = (string) $label; return $this; }
    public function getLabel() { return $this->label; }
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

        if (!class_exists('Address', false)) {
            eval(<<<'PHP'
class Address
{
    public static array $definition = ['fields' => []];
}
PHP
            );
        }

        if (!class_exists('Customer', false)) {
            eval(<<<'PHP'
class Customer
{
    public static array $requiredFields = [];

    public function isFieldRequired($fieldName)
    {
        return in_array($fieldName, self::$requiredFields, true);
    }
}
PHP
            );
        }

        if (!class_exists('Configuration', false)) {
            eval(<<<'PHP'
class Configuration
{
    public static array $values = [];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
    }
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

        if (!class_exists('Hook', false)) {
            eval(<<<'PHP'
class Hook
{
    public static array $responses = [];

    public static function exec($hookName, $hookArgs = [], $idModule = null, $arrayReturn = false)
    {
        $response = self::$responses[$hookName] ?? ($arrayReturn ? [] : null);

        return $response;
    }
}
PHP
            );
        }
    }
}
