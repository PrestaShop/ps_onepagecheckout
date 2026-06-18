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
use Tests\Fixtures\CheckoutTestFixtures;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class OnePageCheckoutFormatterTest extends TestCase
{
    public function testItBuildsExpectedFormatWhenOptinHooksAndInvoiceCountryAreConfigured(): void
    {
        \Context::getContext()->customer = CheckoutTestFixtures::customer([], true);

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

        $deliveryCountry = $this->createCountry([
            'id' => 33,
            'need_zip_code' => true,
            'zip_code_format' => 'NNNNN',
            'need_identification_number' => true,
            'contains_states' => true,
        ]);

        $invoiceCountry = $this->createCountry([
            'id' => 44,
            'need_zip_code' => true,
            'zip_code_format' => 'NNNN',
            'need_identification_number' => true,
            'contains_states' => true,
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $formatter = $this->createFormatter(
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
        self::assertArrayHasKey('id_address_delivery', $format);
        self::assertArrayHasKey('id_address_invoice', $format);
        self::assertArrayNotHasKey('id_address', $format);
        self::assertArrayHasKey('alias', $format);
        self::assertArrayHasKey('invoice_alias', $format);
        self::assertFalse($format['alias']->isRequired());
        self::assertFalse($format['invoice_alias']->isRequired());
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
        \Context::getContext()->customer = CheckoutTestFixtures::customer([], false);

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

        $deliveryCountry = $this->createCountry([
            'id' => 99,
            'contains_states' => false,
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $formatter = $this->createFormatter(
            $deliveryCountry,
            $translator,
            [['id_country' => 99, 'name' => 'Portugal']]
        );

        $format = $formatter->getFormat();

        self::assertArrayNotHasKey('optin', $format);
        self::assertFalse($format['alias']->isRequired());
        self::assertFalse($format['invoice_alias']->isRequired());
        self::assertSame(99, $format['id_country']->getValue());
        self::assertSame(99, $format['invoice_id_country']->getValue());

        $getDefinitionKey = new \ReflectionMethod(OnePageCheckoutFormatter::class, 'getDefinitionKey');
        self::assertSame('firstname', $getDefinitionKey->invoke($formatter, 'invoice_firstname'));
        self::assertSame('firstname', $getDefinitionKey->invoke($formatter, 'firstname'));
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
     */
    private function createFormatter(
        \Country $country,
        TranslatorInterface $translator,
        array $availableCountries,
    ): OnePageCheckoutFormatter {
        return new OnePageCheckoutFormatter($country, $translator, $availableCountries);
    }
}
