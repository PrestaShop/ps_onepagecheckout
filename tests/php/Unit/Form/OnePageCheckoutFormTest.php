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

use Context;
use Country;
use Customer;
use Address;
use Cart;
use CustomerAddressPersister;
use CustomerPersister;
use FormField;
use Language;
use PrestaShop\Module\PsOnepagecheckout\Form\OnePageCheckoutFormatter;
use PrestaShop\Module\PsOnepagecheckout\Form\OnePageCheckoutForm;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smarty;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutFormTest extends TestCase
{
    private const DEFAULT_COUNTRY_ID = 8;

    private TranslatorInterface|MockObject $translator;
    private OnePageCheckoutFormatter|MockObject $formatter;
    private CustomerPersister|MockObject $customerPersister;
    private CustomerAddressPersister|MockObject $addressPersister;
    private Context|MockObject $context;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $this->formatter = $this->getMockBuilder(OnePageCheckoutFormatter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormat', 'getCountry', 'setCountry', 'setInvoiceCountry'])
            ->getMock()
        ;
        $this->formatter
            ->method('getFormat')
            ->willReturnCallback([$this, 'getGuestInitFields'])
        ;
        $defaultCountry = new class() extends Country {
            public function __construct()
            {
            }
        };
        $defaultCountry->id = self::DEFAULT_COUNTRY_ID;
        $this->formatter
            ->method('getCountry')
            ->willReturn($defaultCountry)
        ;
        $this->formatter
            ->method('setCountry')
            ->willReturnSelf()
        ;
        $this->formatter
            ->method('setInvoiceCountry')
            ->willReturnSelf()
        ;

        $this->customerPersister = $this->getMockBuilder(CustomerPersister::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'getErrors'])
            ->getMock()
        ;
        $this->addressPersister = $this->getMockBuilder(CustomerAddressPersister::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->context = $this->createMock(Context::class);
        $this->context->customer = new LightweightCustomer();
    }

    public function testItCreatesGuestCustomerWhenEmailAndRequiredCheckboxesAreValid(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->with(
                $this->callback(function (Customer $customer): bool {
                    return $customer->is_guest
                        && $customer->email === 'guest@example.com'
                        && $customer->firstname === 'Guest'
                        && $customer->lastname === 'Guest';
                }),
                '',
                '',
                false
            )
            ->willReturn(true)
        ;

        $this->context
            ->expects($this->never())
            ->method('updateCustomer')
        ;

        self::assertTrue($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
        ])));
    }

    public function testItDoesNotCreateGuestCustomerWhenThirdPartyRequiredCheckboxIsMissing(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->never())
            ->method('save')
        ;
        $this->context
            ->expects($this->never())
            ->method('updateCustomer')
        ;

        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
        ])));

        $errors = $form->getErrors();
        self::assertArrayHasKey('compliance_terms', $errors);
        self::assertNotEmpty($errors['compliance_terms']);
    }

    public function testItDoesNotCreateGuestCustomerWhenEmailIsInvalid(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->never())
            ->method('save')
        ;
        $this->context
            ->expects($this->never())
            ->method('updateCustomer')
        ;

        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'not-an-email',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
        ])));

        $errors = $form->getErrors();
        self::assertArrayHasKey('email', $errors);
        self::assertNotEmpty($errors['email']);
    }

    public function testItAllowsGuestInitWhenRequiredNonCheckboxFieldIsMissing(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturn(true)
        ;
        $this->context
            ->expects($this->never())
            ->method('updateCustomer')
        ;

        self::assertTrue($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
        ])));
    }

    public function testItDoesNotCreateGuestCustomerWhenRequiredCheckboxIsExplicitlyZero(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->never())
            ->method('save')
        ;
        $this->context
            ->expects($this->never())
            ->method('updateCustomer')
        ;

        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '0',
        ])));

        $errors = $form->getErrors();
        self::assertArrayHasKey('compliance_terms', $errors);
        self::assertNotEmpty($errors['compliance_terms']);
    }

    public function testItReturnsPersisterErrorsWhenGuestSaveFails(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturn(false)
        ;
        $this->customerPersister
            ->expects($this->once())
            ->method('getErrors')
            ->willReturn([
                'email' => ['Unable to save guest customer'],
            ])
        ;
        $this->context
            ->expects($this->never())
            ->method('updateCustomer')
        ;

        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
        ])));
    }

    public function testItHidesGuestPlaceholderNamesWhenFillingFromGuestCustomer(): void
    {
        $form = $this->createForm();
        $customer = new LightweightCustomer();
        $customer->is_guest = 1;
        $customer->firstname = 'Guest';
        $customer->lastname = 'Guest';
        $customer->email = 'guest@example.com';
        $customer->id_country = self::DEFAULT_COUNTRY_ID;

        $form->fillFromCustomer($customer);

        self::assertSame('', (string) $form->getValue('firstname'));
        self::assertSame('', (string) $form->getValue('lastname'));
    }

    public function testItKeepsLiteralGuestNamesForRegisteredCustomerWhenFillingFromCustomer(): void
    {
        $form = $this->createForm();
        $customer = new LightweightCustomer();
        $customer->is_guest = 0;
        $customer->firstname = 'Guest';
        $customer->lastname = 'Guest';
        $customer->email = 'registered@example.com';
        $customer->id_country = self::DEFAULT_COUNTRY_ID;

        $form->fillFromCustomer($customer);

        self::assertSame('Guest', (string) $form->getValue('firstname'));
        self::assertSame('Guest', (string) $form->getValue('lastname'));
    }

    public function testItKeepsRealNamesForGuestWhenFillingFromCustomer(): void
    {
        $form = $this->createForm();
        $customer = new LightweightCustomer();
        $customer->is_guest = 1;
        $customer->firstname = 'Alice';
        $customer->lastname = 'Martin';
        $customer->email = 'alice@example.com';
        $customer->id_country = self::DEFAULT_COUNTRY_ID;

        $form->fillFromCustomer($customer);

        self::assertSame('Alice', (string) $form->getValue('firstname'));
        self::assertSame('Martin', (string) $form->getValue('lastname'));
    }

    public function testSubmitPersistsDeliveryAndInvoiceAddressesWhenUseSameAddressIsDisabled(): void
    {
        $form = $this->createSubmitForm();
        $form->forceValidateResult(true);

        $this->context->cart = new LightweightCart();
        $this->context->cart->id = 0;

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Customer $customer): bool {
                $customer->id = 42;

                return true;
            })
        ;

        $this->context
            ->expects($this->once())
            ->method('updateCustomer')
            ->with($this->callback(static function (Customer $customer): bool {
                return (int) $customer->id === 42;
            }))
        ;

        $savedAddresses = [];
        $this->addressPersister
            ->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(static function (Address $address) use (&$savedAddresses): bool {
                $address->id = count($savedAddresses) === 0 ? 101 : 202;
                $savedAddresses[] = clone $address;

                return true;
            })
        ;

        $form->fillWith($this->withDefaultCountry([
            'email' => 'guest.submit@example.com',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'address1' => '1 Delivery street',
            'city' => 'Paris',
            'postcode' => '75001',
            'use_same_address' => false,
            'invoice_firstname' => 'Jane',
            'invoice_lastname' => 'Doe',
            'invoice_address1' => '2 Invoice street',
            'invoice_city' => 'Lyon',
            'invoice_postcode' => '69001',
        ]));

        $result = $form->submit();

        self::assertSame([
            'id_address_delivery' => 101,
            'id_address_invoice' => 202,
        ], $result);
        self::assertCount(2, $savedAddresses);
        self::assertSame('John', (string) $savedAddresses[0]->firstname);
        self::assertSame('Doe', (string) $savedAddresses[0]->lastname);
        self::assertSame('1 Delivery street', (string) $savedAddresses[0]->address1);
        self::assertSame('Jane', (string) $savedAddresses[1]->firstname);
        self::assertSame('Doe', (string) $savedAddresses[1]->lastname);
        self::assertSame('2 Invoice street', (string) $savedAddresses[1]->address1);
    }

    public function testSubmitPersistsOnlyDeliveryAddressWhenUseSameAddressIsEnabled(): void
    {
        $form = $this->createSubmitForm();
        $form->forceValidateResult(true);

        $this->context->cart = new LightweightCart();
        $this->context->cart->id = 0;

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Customer $customer): bool {
                $customer->id = 43;

                return true;
            })
        ;

        $this->context
            ->expects($this->once())
            ->method('updateCustomer')
        ;

        $this->addressPersister
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (Address $address): bool {
                $address->id = 303;

                return true;
            })
        ;

        $form->fillWith($this->withDefaultCountry([
            'email' => 'guest.same@example.com',
            'firstname' => 'Alice',
            'lastname' => 'Martin',
            'address1' => '3 Main street',
            'city' => 'Marseille',
            'postcode' => '13001',
            'use_same_address' => '1',
        ]));

        $result = $form->submit();

        self::assertSame([
            'id_address_delivery' => 303,
            'id_address_invoice' => 303,
        ], $result);
    }

    /**
     * @return FormField[]
     */
    public function getGuestInitFields(): array
    {
        $firstname = (new FormField())
            ->setName('firstname')
            ->setType('text')
            ->setRequired(false)
            ->setLabel('First name')
        ;

        $lastname = (new FormField())
            ->setName('lastname')
            ->setType('text')
            ->setRequired(false)
            ->setLabel('Last name')
        ;

        $email = (new FormField())
            ->setName('email')
            ->setType('email')
            ->setRequired(true)
            ->setLabel('Email')
        ;

        $requiredConsent = (new FormField())
            ->setName('psgdpr_privacy')
            ->setType('checkbox')
            ->setRequired(true)
        ;
        $requiredConsent->moduleName = 'psgdpr';

        $thirdPartyRequiredConsent = (new FormField())
            ->setName('compliance_terms')
            ->setType('checkbox')
            ->setRequired(true)
        ;
        $thirdPartyRequiredConsent->moduleName = 'thirdpartygdpr';

        $thirdPartyRequiredTextField = (new FormField())
            ->setName('compliance_note')
            ->setType('text')
            ->setRequired(true)
        ;
        $thirdPartyRequiredTextField->moduleName = 'thirdpartygdpr';

        $optionalCheckbox = (new FormField())
            ->setName('newsletter_optin')
            ->setType('checkbox')
            ->setRequired(false)
        ;
        $optionalCheckbox->moduleName = 'anothermodule';

        return [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'psgdpr_privacy' => $requiredConsent,
            'compliance_terms' => $thirdPartyRequiredConsent,
            'compliance_note' => $thirdPartyRequiredTextField,
            'newsletter_optin' => $optionalCheckbox,
        ];
    }

    /**
     * @return FormField[]
     */
    public function getSubmitFields(): array
    {
        $fields = $this->getGuestInitFields();
        $fields['id_country'] = (new FormField())
            ->setName('id_country')
            ->setType('select')
            ->setRequired(true)
            ->setAvailableValues([
                ['id' => self::DEFAULT_COUNTRY_ID, 'label' => 'France'],
            ]);
        $fields['address1'] = (new FormField())
            ->setName('address1')
            ->setType('text')
            ->setRequired(true);
        $fields['city'] = (new FormField())
            ->setName('city')
            ->setType('text')
            ->setRequired(true);
        $fields['postcode'] = (new FormField())
            ->setName('postcode')
            ->setType('text')
            ->setRequired(true);
        $fields['use_same_address'] = (new FormField())
            ->setName('use_same_address')
            ->setType('checkbox')
            ->setRequired(false)
            ->setValue(true);
        $fields['id_address_invoice'] = (new FormField())
            ->setName('id_address_invoice')
            ->setType('hidden')
            ->setRequired(false);
        $fields['invoice_firstname'] = (new FormField())
            ->setName('invoice_firstname')
            ->setType('text')
            ->setRequired(false);
        $fields['invoice_lastname'] = (new FormField())
            ->setName('invoice_lastname')
            ->setType('text')
            ->setRequired(false);
        $fields['invoice_id_country'] = (new FormField())
            ->setName('invoice_id_country')
            ->setType('select')
            ->setRequired(false)
            ->setAvailableValues([
                ['id' => self::DEFAULT_COUNTRY_ID, 'label' => 'France'],
            ]);
        $fields['invoice_address1'] = (new FormField())
            ->setName('invoice_address1')
            ->setType('text')
            ->setRequired(false);
        $fields['invoice_city'] = (new FormField())
            ->setName('invoice_city')
            ->setType('text')
            ->setRequired(false);
        $fields['invoice_postcode'] = (new FormField())
            ->setName('invoice_postcode')
            ->setType('text')
            ->setRequired(false);

        return $fields;
    }

    private function createForm(): OnePageCheckoutForm
    {
        $language = new class() extends Language {
            public function __construct()
            {
            }
        };
        $language->id = 1;

        return new TestableOnePageCheckoutForm(
            $this->createMock(Smarty::class),
            $this->context,
            $language,
            $this->translator,
            $this->formatter,
            $this->customerPersister,
            $this->addressPersister
        );
    }

    private function createSubmitForm(): OnePageCheckoutForm
    {
        $language = new class() extends Language {
            public function __construct()
            {
            }
        };
        $language->id = 1;

        $submitFormatter = $this->getMockBuilder(OnePageCheckoutFormatter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormat', 'getCountry', 'setCountry', 'setInvoiceCountry'])
            ->getMock()
        ;
        $submitFormatter
            ->method('getFormat')
            ->willReturn($this->getSubmitFields())
        ;

        $defaultCountry = new class() extends Country {
            public function __construct()
            {
            }
        };
        $defaultCountry->id = self::DEFAULT_COUNTRY_ID;

        $submitFormatter
            ->method('getCountry')
            ->willReturn($defaultCountry)
        ;
        $submitFormatter
            ->method('setCountry')
            ->willReturnSelf()
        ;
        $submitFormatter
            ->method('setInvoiceCountry')
            ->willReturnSelf()
        ;

        return new TestableOnePageCheckoutForm(
            $this->createMock(Smarty::class),
            $this->context,
            $language,
            $this->translator,
            $submitFormatter,
            $this->customerPersister,
            $this->addressPersister
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function withDefaultCountry(array $params): array
    {
        $params['id_country'] = self::DEFAULT_COUNTRY_ID;

        return $params;
    }
}

class TestableOnePageCheckoutForm extends OnePageCheckoutForm
{
    private ?bool $forcedValidationResult = null;

    public function forceValidateResult(bool $result): void
    {
        $this->forcedValidationResult = $result;
    }

    public function validate()
    {
        if ($this->forcedValidationResult !== null) {
            return $this->forcedValidationResult;
        }

        return parent::validate();
    }

    public function getCustomer(): Customer
    {
        $customer = new LightweightCustomer();
        $customer->id = 0;

        foreach ($this->formFields as $field) {
            $customerField = $field->getName();
            if (property_exists($customer, $customerField)) {
                $customer->$customerField = $field->getValue();
            }
        }

        return $customer;
    }
}

class LightweightCustomer extends Customer
{
    public function __construct()
    {
    }
}

class LightweightCart extends Cart
{
    public function __construct()
    {
    }
}
