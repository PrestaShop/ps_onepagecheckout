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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Fixtures\CheckoutTestFixtures;

class OnePageCheckoutFormTest extends TestCase
{
    private const DEFAULT_COUNTRY_ID = 8;

    private TranslatorInterface|MockObject $translator;
    private OnePageCheckoutFormatter|MockObject $formatter;
    private \CustomerPersister|MockObject $customerPersister;
    private \CustomerAddressPersister|MockObject $addressPersister;
    private LightweightContext $context;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $this->formatter = $this->getMockBuilder(OnePageCheckoutFormatter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormat', 'getCountry', 'setCountry', 'setInvoiceCountry', 'getFieldGroup'])
            ->getMock()
        ;
        $this->formatter
            ->method('getFormat')
            ->willReturnCallback([$this, 'getGuestInitFields'])
        ;
        $defaultCountry = CheckoutTestFixtures::country();
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
        $this->formatter
            ->method('getFieldGroup')
            ->willReturnCallback([$this, 'getFieldGroupForTest'])
        ;

        $this->customerPersister = $this->getMockBuilder(\CustomerPersister::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'getErrors'])
            ->getMock()
        ;
        $this->addressPersister = $this->getMockBuilder(\CustomerAddressPersister::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->context = new LightweightContext();
        $this->context->customer = new LightweightCustomer();
    }

    public function testItCreatesGuestCustomerWhenEmailAndRequiredCheckboxesAreValid(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->with(
                $this->callback(function (\Customer $customer): bool {
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

        self::assertTrue($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'communication_channel' => 'email',
        ])));
        self::assertNull($this->context->updatedCustomer);
        self::assertFalse($form->wasModuleValidationCalled());
    }

    public function testItDoesNotCreateGuestCustomerWhenThirdPartyRequiredCheckboxIsMissing(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->never())
            ->method('save')
        ;
        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
        ])));
        self::assertNull($this->context->updatedCustomer);

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
        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'not-an-email',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
        ])));
        self::assertNull($this->context->updatedCustomer);

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
        self::assertTrue($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'communication_channel' => 'email',
        ])));
        self::assertNull($this->context->updatedCustomer);
    }

    public function testItDoesNotCreateGuestCustomerWhenRequiredCheckboxIsExplicitlyZero(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->never())
            ->method('save')
        ;
        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '0',
        ])));
        self::assertNull($this->context->updatedCustomer);

        $errors = $form->getErrors();
        self::assertArrayHasKey('compliance_terms', $errors);
        self::assertNotEmpty($errors['compliance_terms']);
    }

    public function testGuestInitDoesNotCallModuleCustomerValidation(): void
    {
        $form = $this->createForm();
        $form->setModuleFieldErrors([
            'compliance_note' => ['Probe customer text is required.'],
        ]);

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturn(true)
        ;

        self::assertTrue($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest-no-module-validation@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'communication_channel' => 'email',
        ])));
        self::assertFalse($form->wasModuleValidationCalled());
        self::assertEmpty($form->getField('compliance_note')->getErrors());
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
        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'communication_channel' => 'email',
        ])));
        self::assertNull($this->context->updatedCustomer);
        self::assertSame(['Unable to save guest customer'], $form->getField('email')->getErrors());
    }

    public function testSubmitProjectsCustomerPersisterErrorsOntoFormFields(): void
    {
        $form = $this->createSubmitForm();
        $form->forceValidateResult(true);

        $this->context->cart = new LightweightCart();
        $this->context->cart->id = -1;

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturn(false)
        ;
        $this->customerPersister
            ->expects($this->once())
            ->method('getErrors')
            ->willReturn([
                'email' => ['Unable to save customer'],
            ])
        ;
        $this->addressPersister
            ->expects($this->never())
            ->method('save')
        ;

        $form->fillWith($this->withDefaultCountry([
            'email' => 'guest.submit@example.com',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'address1' => '1 Delivery street',
            'city' => 'Paris',
            'postcode' => '75001',
            'use_same_address' => '1',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'communication_channel' => 'email',
        ]));

        self::assertFalse($form->submit());
        self::assertSame(['Unable to save customer'], $form->getField('email')->getErrors());

        $persistedState = $form->buildPersistedSubmissionState(
            ['email' => 'guest.submit@example.com'],
            ['address' => ['email' => ['Unable to save customer']]]
        );

        self::assertSame(
            [
                '' => [],
                'email' => ['Unable to save customer'],
            ],
            $persistedState['form_errors']
        );
    }

    public function testItDoesNotCreateGuestCustomerWhenRequiredRadioConsentIsMissing(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->never())
            ->method('save')
        ;

        self::assertFalse($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest-radio-missing@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'compliance_note' => 'Ready',
        ])));

        $errors = $form->getErrors();
        self::assertArrayHasKey('communication_channel', $errors);
        self::assertNotEmpty($errors['communication_channel']);
    }

    public function testGuestInitIgnoresRequiredAddressConsentFields(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturn(true)
        ;

        self::assertTrue($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest-address-consent-ignored@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'compliance_note' => 'Ready',
            'communication_channel' => 'email',
            'marketing_preferences' => '0',
        ])));
    }

    public function testGuestInitIgnoresFinalSubmitVeto(): void
    {
        $form = $this->createForm();

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturn(true)
        ;

        self::assertTrue($form->submitGuestInit($this->withDefaultCountry([
            'email' => 'guest-veto@example.com',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'compliance_note' => 'Ready',
            'communication_channel' => 'email',
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

    public function testItSeparatesTemplateVariablesByBusinessOrigin(): void
    {
        $customerProbeText = (new \FormField())
            ->setName('opcinvariantprobe_customer_text')
            ->setType('text');
        $customerProbeSelect = (new \FormField())
            ->setName('opcinvariantprobe_customer_select')
            ->setType('select');

        $customerProbeTextarea = (new \FormField())
            ->setName('opcinvariantprobe_customer_textarea')
            ->setType('textarea');

        $customerProbeCheckbox = (new \FormField())
            ->setName('opcinvariantprobe_customer_checkbox')
            ->setType('checkbox');

        $customerProbeRadio = (new \FormField())
            ->setName('opcinvariantprobe_customer_radio')
            ->setType('radio-buttons');

        $addressProbeCheckbox = (new \FormField())
            ->setName('opcinvariantprobe_address_checkbox')
            ->setType('checkbox');

        $form = $this->createForm();
        $form->setFormFieldsForTest([
            'email' => (new \FormField())
                ->setName('email')
                ->setType('email'),
            'optin' => (new \FormField())
                ->setName('optin')
                ->setType('checkbox'),
            'customer_probe_text' => $customerProbeText,
            'customer_probe_select' => $customerProbeSelect,
            'customer_probe_textarea' => $customerProbeTextarea,
            'customer_probe_checkbox' => $customerProbeCheckbox,
            'customer_probe_radio' => $customerProbeRadio,
            'firstname' => (new \FormField())
                ->setName('firstname')
                ->setType('text'),
            'opcinvariantprobe_address_checkbox' => $addressProbeCheckbox,
            'invoice_address1' => (new \FormField())
                ->setName('invoice_address1')
                ->setType('text'),
            'use_same_address' => (new \FormField())
                ->setName('use_same_address')
                ->setType('checkbox'),
            'id_address_invoice' => (new \FormField())
                ->setName('id_address_invoice')
                ->setType('hidden'),
        ]);

        $templateVariables = $form->getTemplateVariables();

        self::assertSame(
            [
                'email',
                'optin',
                'customer_probe_text',
                'customer_probe_select',
                'customer_probe_textarea',
                'customer_probe_checkbox',
                'customer_probe_radio',
                'firstname',
                'opcinvariantprobe_address_checkbox',
                'invoice_address1',
                'use_same_address',
                'id_address_invoice',
            ],
            array_keys($templateVariables['formFields'])
        );
        self::assertArrayHasKey('contactFields', $templateVariables);
        self::assertArrayHasKey('additionalCustomerFields', $templateVariables);
        self::assertArrayHasKey('customer', $templateVariables);
        self::assertArrayHasKey('useSameAddressField', $templateVariables);
        self::assertArrayHasKey('deliveryFields', $templateVariables);
        self::assertArrayHasKey('invoiceFields', $templateVariables);
        self::assertArrayHasKey('invoiceMetaFields', $templateVariables);
        self::assertArrayHasKey('token', $templateVariables);
        self::assertArrayHasKey('addresses', $templateVariables['customer']);
        self::assertSame('email', $templateVariables['formFields']['email']['name']);
        self::assertSame('email', $templateVariables['contactFields']['email']['name']);
        self::assertSame('opcinvariantprobe_customer_text', $templateVariables['additionalCustomerFields']['customer_probe_text']['name']);
        self::assertSame('use_same_address', $templateVariables['formFields']['use_same_address']['name']);
        self::assertSame('firstname', $templateVariables['deliveryFields']['firstname']['name']);
        self::assertSame('invoice_address1', $templateVariables['invoiceFields']['invoice_address1']['name']);
        self::assertSame('id_address_invoice', $templateVariables['invoiceMetaFields']['id_address_invoice']['name']);
    }

    /**
     * Both address sections are handed to the templates already split into rows, so the layout is
     * decided (and tested) in PHP instead of in Smarty.
     */
    public function testTemplateVariablesExposeBothAddressSectionsSplitIntoRows(): void
    {
        $form = $this->createForm();
        $form->setFormFieldsForTest([
            'email' => (new \FormField())->setName('email')->setType('email'),
            'firstname' => (new \FormField())->setName('firstname')->setType('text'),
            'lastname' => (new \FormField())->setName('lastname')->setType('text'),
            'address1' => (new \FormField())->setName('address1')->setType('text'),
            'invoice_firstname' => (new \FormField())->setName('invoice_firstname')->setType('text'),
            'invoice_lastname' => (new \FormField())->setName('invoice_lastname')->setType('text'),
        ]);

        $templateVariables = $form->getTemplateVariables();

        self::assertSame(
            [['firstname', 'lastname'], ['address1']],
            $this->rowNames($templateVariables['deliveryFieldRows'])
        );
        self::assertSame(
            [['invoice_firstname', 'invoice_lastname']],
            $this->rowNames($templateVariables['invoiceFieldRows'])
        );
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

    public function testRestoreSubmissionStateRestoresErrorsOnModuleCustomerFieldsByInternalKey(): void
    {
        $customerProbeText = (new \FormField())
            ->setName('customer_probe_text')
            ->setType('text');

        $formatter = $this->getMockBuilder(OnePageCheckoutFormatter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormat', 'getCountry', 'setCountry', 'setInvoiceCountry', 'getFieldGroup'])
            ->getMock()
        ;
        $formatter
            ->method('getFormat')
            ->willReturn([
                'opce2efixtures_customer_probe_text' => $customerProbeText,
            ])
        ;
        $country = CheckoutTestFixtures::country();
        $country->id = self::DEFAULT_COUNTRY_ID;
        $formatter->method('getCountry')->willReturn($country);
        $formatter->method('setCountry')->willReturnSelf();
        $formatter->method('setInvoiceCountry')->willReturnSelf();
        $formatter
            ->method('getFieldGroup')
            ->willReturnCallback(static function (string $key): ?string {
                return $key === 'opce2efixtures_customer_probe_text'
                    ? OnePageCheckoutFormatter::FIELD_GROUP_CUSTOMER
                    : null;
            })
        ;

        $form = new TestableOnePageCheckoutForm(
            $this->createMock(\Smarty::class),
            $this->context,
            CheckoutTestFixtures::language(1),
            $this->translator,
            $formatter,
            $this->customerPersister,
            $this->addressPersister
        );

        $form->restoreSubmissionState(
            ['customer_probe_text' => ''],
            ['opce2efixtures_customer_probe_text' => ['Fixture customer note is required.']]
        );

        self::assertSame(
            ['Fixture customer note is required.'],
            $form->getField('opce2efixtures_customer_probe_text')->getErrors()
        );
    }

    public function testRestoreSubmissionStateRestoresErrorsOnNativeFieldsByInternalKey(): void
    {
        $form = $this->createSubmitForm();

        $form->restoreSubmissionState(
            ['firstname' => ''],
            ['firstname' => ['The firstname field is required.']]
        );

        self::assertSame(
            ['The firstname field is required.'],
            $form->getField('firstname')->getErrors()
        );
    }

    public function testBuildPersistedSubmissionStateUsesInternalFieldKeysForCustomFields(): void
    {
        $customerProbeText = (new \FormField())
            ->setName('customer_probe_text')
            ->setType('text')
            ->addError('Fixture customer note is required.');

        $firstname = (new \FormField())
            ->setName('firstname')
            ->setType('text')
            ->addError('The firstname field is required.');

        $formatter = $this->getMockBuilder(OnePageCheckoutFormatter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormat', 'getCountry', 'setCountry', 'setInvoiceCountry', 'getFieldGroup'])
            ->getMock()
        ;
        $formatter
            ->method('getFormat')
            ->willReturn([
                'firstname' => $firstname,
                'opce2efixtures_customer_probe_text' => $customerProbeText,
            ])
        ;
        $country = CheckoutTestFixtures::country();
        $country->id = self::DEFAULT_COUNTRY_ID;
        $formatter->method('getCountry')->willReturn($country);
        $formatter->method('setCountry')->willReturnSelf();
        $formatter->method('setInvoiceCountry')->willReturnSelf();
        $formatter
            ->method('getFieldGroup')
            ->willReturnCallback(static function (string $key): ?string {
                return $key === 'opce2efixtures_customer_probe_text'
                    ? OnePageCheckoutFormatter::FIELD_GROUP_CUSTOMER
                    : null;
            })
        ;

        $form = new TestableOnePageCheckoutForm(
            $this->createMock(\Smarty::class),
            $this->context,
            CheckoutTestFixtures::language(1),
            $this->translator,
            $formatter,
            $this->customerPersister,
            $this->addressPersister
        );
        $form->fillWith([
            'firstname' => '',
            'customer_probe_text' => '',
        ]);

        $persistedState = $form->buildPersistedSubmissionState(
            [
                'firstname' => '',
                'customer_probe_text' => '',
            ],
            [
                'address' => [
                    'firstname' => ['The firstname field is required.'],
                ],
            ]
        );

        self::assertSame(
            [
                '' => [],
                'firstname' => ['The firstname field is required.'],
                'opce2efixtures_customer_probe_text' => ['Fixture customer note is required.'],
            ],
            $persistedState['form_errors']
        );
    }

    public function testSubmitPersistsDeliveryAndInvoiceAddressesWhenUseSameAddressIsDisabled(): void
    {
        $form = $this->createSubmitForm();
        $form->forceValidateResult(true);

        $this->context->cart = new LightweightCart();
        $this->context->cart->id = -1;

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (\Customer $customer): bool {
                $customer->id = 42;

                return true;
            })
        ;

        $savedAddresses = [];
        $this->addressPersister
            ->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(static function (\Address $address) use (&$savedAddresses): bool {
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
        self::assertInstanceOf(\Customer::class, $this->context->updatedCustomer);
        self::assertSame(42, (int) $this->context->updatedCustomer->id);
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
        $this->context->cart->id = -1;

        $this->customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (\Customer $customer): bool {
                $customer->id = 43;

                return true;
            })
        ;

        $this->addressPersister
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (\Address $address): bool {
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
        self::assertInstanceOf(\Customer::class, $this->context->updatedCustomer);
        self::assertSame(43, (int) $this->context->updatedCustomer->id);
    }

    public function testSubmitUsesConnectedCustomerEmailWhenCheckoutPostOmitsIt(): void
    {
        $form = $this->createSubmitForm();
        $form->forceValidateResult(true);

        $this->context->customer = new LightweightCustomer();
        $this->context->customer->id = 77;
        $this->context->customer->is_guest = 0;
        $this->context->customer->email = 'registered@example.com';
        $this->context->cart = new LightweightCart();
        $this->context->cart->id = -1;
        $this->context->cart->id_customer = 77;

        $this->customerPersister
            ->expects($this->never())
            ->method('save')
        ;

        $this->addressPersister
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (\Address $address): bool {
                $address->id = 404;

                return true;
            })
        ;

        $form->fillWith($this->withDefaultCountry([
            'firstname' => 'Spec',
            'lastname' => 'FortyTwo',
            'address1' => '4 Registered street',
            'city' => 'Nantes',
            'postcode' => '44000',
            'psgdpr_privacy' => '1',
            'compliance_terms' => '1',
            'communication_channel' => 'email',
            'use_same_address' => '1',
        ]));
        self::assertSame('registered@example.com', (string) $form->getValue('email'));

        $result = $form->submit();

        self::assertSame([
            'id_address_delivery' => 404,
            'id_address_invoice' => 404,
        ], $result);
        self::assertSame('registered@example.com', (string) $form->getValue('email'));
    }

    public function testGetAddressUsesSelectedSavedDeliveryAddressIdFromDeliveryRadioPost(): void
    {
        $form = $this->createSubmitForm();

        $previousPost = $_POST ?? [];
        $previousRequest = $_REQUEST ?? [];

        $_POST['id_address_delivery'] = '505';
        $_REQUEST['id_address_delivery'] = '505';

        try {
            $form->fillWith($this->withDefaultCountry([
                'firstname' => 'Saved',
                'lastname' => 'Address',
                'address1' => '5 Existing street',
                'city' => 'Nantes',
                'postcode' => '44000',
                'psgdpr_privacy' => '1',
                'compliance_terms' => '1',
                'communication_channel' => 'email',
                'use_same_address' => '1',
            ]));
            $address = $form->getAddress();
        } finally {
            $_POST = $previousPost;
            $_REQUEST = $previousRequest;
        }

        self::assertSame(505, (int) $address->id);
    }

    public function testFillWithKeepsSelectedDeliveryAndInvoiceCountriesWithoutManualOverride(): void
    {
        $language = CheckoutTestFixtures::language(1);

        $formatter = $this->getMockBuilder(OnePageCheckoutFormatter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormat', 'getCountry', 'setCountry', 'setInvoiceCountry'])
            ->getMock()
        ;

        $formatter
            ->method('getFormat')
            ->willReturn([
                'id_country' => (new \FormField())
                    ->setName('id_country')
                    ->setType('select')
                    ->setRequired(true)
                    ->setAvailableValues([
                        ['id' => 8, 'label' => 'France'],
                        ['id' => 21, 'label' => 'Belgium'],
                    ]),
                'invoice_id_country' => (new \FormField())
                    ->setName('invoice_id_country')
                    ->setType('select')
                    ->setRequired(false)
                    ->setAvailableValues([
                        ['id' => 8, 'label' => 'France'],
                        ['id' => 21, 'label' => 'Belgium'],
                    ]),
            ])
        ;

        $defaultCountry = CheckoutTestFixtures::country();
        $defaultCountry->id = self::DEFAULT_COUNTRY_ID;

        $formatter
            ->method('getCountry')
            ->willReturn($defaultCountry)
        ;
        $formatter
            ->method('setCountry')
            ->willReturnSelf()
        ;
        $formatter
            ->method('setInvoiceCountry')
            ->willReturnSelf()
        ;

        $form = new TestableOnePageCheckoutForm(
            $this->createMock(\Smarty::class),
            $this->context,
            $language,
            $this->translator,
            $formatter,
            $this->customerPersister,
            $this->addressPersister
        );

        $form->fillWith([
            'id_country' => '21',
            'invoice_id_country' => '21',
        ]);

        self::assertSame('21', (string) $form->getValue('id_country'));
        self::assertSame('21', (string) $form->getValue('invoice_id_country'));
    }

    public function testFillWithKeepsHydratedCountryWhenSavedAddressSubmitOmitsCountry(): void
    {
        $previousCountries = \Country::$registry;
        \Country::$registry[21] = [
            'name' => 'Belgium',
            'need_zip_code' => true,
            'zip_code_format' => 'NNNN',
            'validZipCodes' => ['9513'],
        ];

        $language = CheckoutTestFixtures::language(1);
        $currentCountry = CheckoutTestFixtures::country();
        $currentCountry->id = self::DEFAULT_COUNTRY_ID;

        $formatter = $this->getMockBuilder(OnePageCheckoutFormatter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormat', 'getCountry', 'setCountry', 'setInvoiceCountry', 'getFieldGroup'])
            ->getMock()
        ;
        $formatter
            ->method('getFormat')
            ->willReturn($this->getSubmitFields())
        ;
        $formatter
            ->method('getCountry')
            ->willReturnCallback(static function () use (&$currentCountry): \Country {
                return $currentCountry;
            })
        ;
        $formatter
            ->method('setCountry')
            ->willReturnCallback(static function (\Country $country) use (&$currentCountry, $formatter): OnePageCheckoutFormatter {
                $currentCountry = $country;

                return $formatter;
            })
        ;
        $formatter
            ->method('setInvoiceCountry')
            ->willReturnSelf()
        ;
        $formatter
            ->method('getFieldGroup')
            ->willReturnCallback([$this, 'getFieldGroupForTest'])
        ;

        $form = new TestableOnePageCheckoutForm(
            $this->createMock(\Smarty::class),
            $this->context,
            $language,
            $this->translator,
            $formatter,
            $this->customerPersister,
            $this->addressPersister
        );

        try {
            $form->fillWith([
                'id_country' => '21',
                'postcode' => '9513',
            ]);
            $form->fillWith([
                'id_address_delivery' => '123',
                'id_address_invoice' => '123',
                'use_same_address' => '1',
            ]);

            self::assertSame(21, (int) $currentCountry->id);
        } finally {
            \Country::$registry = $previousCountries;
        }
    }

    /**
     * @return \FormField[]
     */
    public function getGuestInitFields(): array
    {
        $firstname = (new \FormField())
            ->setName('firstname')
            ->setType('text')
            ->setRequired(false)
            ->setLabel('First name')
        ;

        $lastname = (new \FormField())
            ->setName('lastname')
            ->setType('text')
            ->setRequired(false)
            ->setLabel('Last name')
        ;

        $email = (new \FormField())
            ->setName('email')
            ->setType('email')
            ->setRequired(true)
            ->setLabel('Email')
        ;

        $requiredConsent = (new \FormField())
            ->setName('psgdpr_privacy')
            ->setType('checkbox')
            ->setRequired(true)
        ;
        $requiredConsent->moduleName = 'psgdpr';

        $thirdPartyRequiredConsent = (new \FormField())
            ->setName('compliance_terms')
            ->setType('checkbox')
            ->setRequired(true)
        ;
        $thirdPartyRequiredConsent->moduleName = 'thirdpartygdpr';

        $thirdPartyRequiredTextField = (new \FormField())
            ->setName('compliance_note')
            ->setType('text')
            ->setRequired(true)
        ;
        $thirdPartyRequiredTextField->moduleName = 'thirdpartygdpr';

        $requiredRadioConsent = (new \FormField())
            ->setName('communication_channel')
            ->setType('radio-buttons')
            ->setRequired(true)
            ->setAvailableValues([
                'email' => 'Email',
                'sms' => 'SMS',
            ])
        ;
        $requiredRadioConsent->moduleName = 'thirdpartygdpr';

        $optionalCheckbox = (new \FormField())
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
            'communication_channel' => $requiredRadioConsent,
            'newsletter_optin' => $optionalCheckbox,
        ];
    }

    /**
     * @return \FormField[]
     */
    public function getSubmitFields(): array
    {
        $fields = $this->getGuestInitFields();
        $fields['id_country'] = (new \FormField())
            ->setName('id_country')
            ->setType('select')
            ->setRequired(true)
            ->setAvailableValues([
                ['id' => self::DEFAULT_COUNTRY_ID, 'label' => 'France'],
            ]);
        $fields['address1'] = (new \FormField())
            ->setName('address1')
            ->setType('text')
            ->setRequired(true);
        $fields['city'] = (new \FormField())
            ->setName('city')
            ->setType('text')
            ->setRequired(true);
        $fields['postcode'] = (new \FormField())
            ->setName('postcode')
            ->setType('text')
            ->setRequired(true);
        $fields['use_same_address'] = (new \FormField())
            ->setName('use_same_address')
            ->setType('checkbox')
            ->setRequired(false)
            ->setValue(true);
        $fields['id_address_invoice'] = (new \FormField())
            ->setName('id_address_invoice')
            ->setType('hidden')
            ->setRequired(false);
        $fields['invoice_firstname'] = (new \FormField())
            ->setName('invoice_firstname')
            ->setType('text')
            ->setRequired(false);
        $fields['invoice_lastname'] = (new \FormField())
            ->setName('invoice_lastname')
            ->setType('text')
            ->setRequired(false);
        $fields['invoice_id_country'] = (new \FormField())
            ->setName('invoice_id_country')
            ->setType('select')
            ->setRequired(false)
            ->setAvailableValues([
                ['id' => self::DEFAULT_COUNTRY_ID, 'label' => 'France'],
            ]);
        $fields['invoice_address1'] = (new \FormField())
            ->setName('invoice_address1')
            ->setType('text')
            ->setRequired(false);
        $fields['invoice_city'] = (new \FormField())
            ->setName('invoice_city')
            ->setType('text')
            ->setRequired(false);
        $fields['invoice_postcode'] = (new \FormField())
            ->setName('invoice_postcode')
            ->setType('text')
            ->setRequired(false);

        return $fields;
    }

    private function createForm(): OnePageCheckoutForm
    {
        $language = CheckoutTestFixtures::language(1);

        return new TestableOnePageCheckoutForm(
            $this->createMock(\Smarty::class),
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
        $language = CheckoutTestFixtures::language();
        $language->id = 1;

        $submitFormatter = $this->getMockBuilder(OnePageCheckoutFormatter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormat', 'getCountry', 'setCountry', 'setInvoiceCountry', 'getFieldGroup'])
            ->getMock()
        ;
        $submitFormatter
            ->method('getFormat')
            ->willReturn($this->getSubmitFields())
        ;

        $defaultCountry = CheckoutTestFixtures::country();
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
        $submitFormatter
            ->method('getFieldGroup')
            ->willReturnCallback([$this, 'getFieldGroupForTest'])
        ;

        return new TestableOnePageCheckoutForm(
            $this->createMock(\Smarty::class),
            $this->context,
            $language,
            $this->translator,
            $submitFormatter,
            $this->customerPersister,
            $this->addressPersister
        );
    }

    public function getFieldGroupForTest(string $key): ?string
    {
        return in_array($key, ['compliance_terms', 'compliance_note', 'communication_channel', 'newsletter_optin'], true)
            ? OnePageCheckoutFormatter::FIELD_GROUP_CUSTOMER
            : null;
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
    /**
     * @var array<string, array<int, string>>
     */
    private array $moduleFieldErrors = [];
    private ?bool $forcedValidationResult = null;
    private bool $moduleValidationCalled = false;

    /**
     * @param array<string, array<int, string>> $moduleFieldErrors
     */
    public function setModuleFieldErrors(array $moduleFieldErrors): self
    {
        $this->moduleFieldErrors = $moduleFieldErrors;

        return $this;
    }

    public function wasModuleValidationCalled(): bool
    {
        return $this->moduleValidationCalled;
    }

    /**
     * @param array<string, \FormField> $formFields
     */
    public function setFormFieldsForTest(array $formFields): self
    {
        $this->formFields = $formFields;

        return $this;
    }

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

    public function getCustomer(): \Customer
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

    protected function validateCustomerFieldsByModules(): void
    {
        $this->moduleValidationCalled = true;

        foreach ($this->moduleFieldErrors as $fieldName => $errors) {
            foreach ($this->formFields as $field) {
                if ($field->getName() !== $fieldName) {
                    continue;
                }

                foreach ($errors as $error) {
                    $field->addError($error);
                }
            }
        }
    }
}

class LightweightCustomer extends \Customer
{
    public function __construct()
    {
    }

    public function isGuest(): bool
    {
        return (bool) $this->is_guest;
    }
}

class LightweightCart extends \Cart
{
    public function __construct()
    {
    }
}

class LightweightContext extends \Context
{
    public ?\Customer $updatedCustomer = null;

    public function __construct()
    {
        $this->cart = new LightweightCart();
    }

    public function updateCustomer(\Customer $customer): void
    {
        $this->updatedCustomer = $customer;
        $this->customer = $customer;
    }
}
