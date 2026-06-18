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
use Tests\Fixtures\CheckoutTestFixtures;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AddressFormValidationTraitTest extends TestCase
{
    public function testItFailsWhenDeliveryPostcodeIsInvalid(): void
    {
        $sut = $this->createHarness();

        $deliveryCountry = $this->createCountry([
            'need_zip_code' => true,
            'zip_code_format' => 'NNNNN',
            'validZipCodes' => ['75001'],
        ]);

        $postcode = (new \FormField())
            ->setRequired(true)
            ->setValue('ABCDE');

        $isValid = $sut->exposeValidateAddressPostcode($postcode, $deliveryCountry);

        self::assertFalse($isValid);
        self::assertNotEmpty($postcode->getErrors());
    }

    public function testItValidatesInvoicePostcodeAgainstInvoiceCountryField(): void
    {
        $sut = $this->createHarness();

        $deliveryCountry = $this->createCountry([
            'need_zip_code' => true,
            'zip_code_format' => 'NNNNN',
            'validZipCodes' => ['75001'],
        ]);

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

    public function testItValidatesInvoicePostcodeAgainstDeliveryCountryWhenNoInvoiceCountryIsProvided(): void
    {
        $sut = $this->createHarness();

        $deliveryCountry = $this->createCountry([
            'need_zip_code' => true,
            'zip_code_format' => 'NNNNN',
            'validZipCodes' => ['75001'],
        ]);

        $invoicePostcode = (new \FormField())
            ->setRequired(true)
            ->setValue('99999');

        $isValid = $sut->exposeValidateAddressPostcode(
            null,
            $deliveryCountry,
            $invoicePostcode
        );

        self::assertFalse($isValid);
        self::assertNotEmpty($invoicePostcode->getErrors());
    }

    public function testItSkipsInvoicePostcodeCheckWhenInvoiceCountryDoesNotNeedZipCode(): void
    {
        $sut = $this->createHarness();

        $deliveryCountry = $this->createCountry([
            'need_zip_code' => true,
            'zip_code_format' => 'NNNNN',
            'validZipCodes' => ['75001'],
        ]);

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
        $sut = $this->createHarness();

        $country = $this->createCountry([
            'need_zip_code' => true,
            'zip_code_format' => 'NNNNN',
            'validZipCodes' => [],
        ]);

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
        $sut = $this->createHarness();
        \Hook::$responses['actionValidateCustomerAddressForm'] = false;

        self::assertFalse($sut->exposeValidateAddressFormHook());
    }

    public function testItReturnsTrueWhenValidationHookReturnsNull(): void
    {
        $sut = $this->createHarness();
        \Hook::$responses['actionValidateCustomerAddressForm'] = null;

        self::assertTrue($sut->exposeValidateAddressFormHook());
    }

    private function buildTranslator(): object
    {
        return CheckoutTestFixtures::translator();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCountry(array $overrides): \Country
    {
        return CheckoutTestFixtures::country($overrides);
    }

    private function createHarness(): AddressFormValidationTraitHarness
    {
        return new AddressFormValidationTraitHarness($this->buildTranslator(), (object) ['id' => 1]);
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
