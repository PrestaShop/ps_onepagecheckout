<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormatter;
use PrestaShop\PrestaShop\Core\Crypto\Hashing;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

/**
 * Final-submit address resolution contract.
 *
 * The OPC client strips the hidden technical id_address_* fields from the final submit payload
 * (buildSubmitPayload / buildSelectAddressPayload only resend a VISIBLE saved-address selection),
 * so every inline-flow submit reaches the server without address ids. The server may then reuse
 * the cart-attached address id to avoid inserting a duplicate — but the TYPED fields are the only
 * thing the buyer sees, so they must always end up on the submitted address. Silently reusing the
 * cart address CONTENT ships/bills the order to a stale address whenever the submit races the
 * debounced autosave (the autosave is what keeps the cart address in sync while typing).
 */
class OnePageCheckoutFormSubmitIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    /** @var array<string,mixed> */
    private $previousPost = [];

    /** @var array<string,mixed> */
    private $previousGet = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        DatabaseDump::restoreTables(['configuration', 'customer', 'customer_group', 'address', 'cart']);
        \Configuration::loadConfiguration();

        $this->previousPost = $_POST;
        $this->previousGet = $_GET;
    }

    protected function tearDown(): void
    {
        $_POST = $this->previousPost;
        $_GET = $this->previousGet;

        parent::tearDown();
    }

    public function testSubmitWithoutPostedIdAppliesTypedDeliveryFieldsToTheCartAttachedAddress(): void
    {
        $customer = $this->createRegisteredCustomer();
        $stale = $this->createAddress($customer, 'Stale', '1 rue Ancienne', 'Lyon', '69001');
        $cart = $this->createCart($customer, (int) $stale->id, (int) $stale->id);
        $context = $this->createCheckoutContext($customer, $cart);

        $result = $this->submitForm($context, $this->typedParams([
            'address1' => '2 rue Nouvelle',
            'city' => 'Paris',
            'postcode' => '75002',
        ]));

        $submitted = new \Address((int) $result['id_address_delivery']);
        self::assertSame('2 rue Nouvelle', $submitted->address1, 'the typed street must be on the submitted delivery address');
        self::assertSame('Paris', $submitted->city, 'the typed city must be on the submitted delivery address');
        self::assertSame('75002', $submitted->postcode, 'the typed postcode must be on the submitted delivery address');
        // Dedup contract: the cart-attached address is updated in place, not duplicated.
        self::assertSame((int) $stale->id, (int) $result['id_address_delivery'], 'the cart-attached address must be reused (updated in place)');
    }

    public function testSubmitWithoutPostedInvoiceIdAppliesTypedInvoiceFieldsToTheCartAttachedInvoiceAddress(): void
    {
        $customer = $this->createRegisteredCustomer();
        $delivery = $this->createAddress($customer, 'Delivery', '1 rue Livraison', 'Lyon', '69001');
        $staleInvoice = $this->createAddress($customer, 'StaleInvoice', '3 rue Vieille Facture', 'Lille', '59000');
        $cart = $this->createCart($customer, (int) $delivery->id, (int) $staleInvoice->id); // separate billing persisted
        $context = $this->createCheckoutContext($customer, $cart);

        $result = $this->submitForm($context, $this->typedParams([
            'address1' => '1 rue Livraison',
            'city' => 'Lyon',
            'postcode' => '69001',
            'use_same_address' => '0',
            'invoice_firstname' => 'Integration',
            'invoice_lastname' => 'Customer',
            'invoice_address1' => '9 avenue Facture',
            'invoice_city' => 'Nice',
            'invoice_postcode' => '06000',
            'invoice_id_country' => (string) (int) \Configuration::get('PS_COUNTRY_DEFAULT'),
        ]));

        $submittedInvoice = new \Address((int) $result['id_address_invoice']);
        self::assertSame('9 avenue Facture', $submittedInvoice->address1, 'the typed billing street must be on the submitted invoice address');
        self::assertSame('Nice', $submittedInvoice->city, 'the typed billing city must be on the submitted invoice address');
        self::assertSame('06000', $submittedInvoice->postcode, 'the typed billing postcode must be on the submitted invoice address');
        // Dedup contract: the separate cart invoice address is updated in place, not duplicated.
        self::assertSame((int) $staleInvoice->id, (int) $result['id_address_invoice'], 'the cart-attached invoice address must be reused (updated in place)');
    }

    public function testSubmitNeverFallsBackToASoftDeletedCartAddress(): void
    {
        $customer = $this->createRegisteredCustomer();
        $deleted = $this->createAddress($customer, 'Deleted', '1 rue Supprimee', 'Lyon', '69001');
        $cart = $this->createCart($customer, (int) $deleted->id, (int) $deleted->id);
        $this->softDelete($deleted);
        $context = $this->createCheckoutContext($customer, $cart);

        $result = $this->submitForm($context, $this->typedParams([
            'address1' => '2 rue Nouvelle',
            'city' => 'Paris',
            'postcode' => '75002',
        ]));

        $submitted = new \Address((int) $result['id_address_delivery']);
        self::assertSame('2 rue Nouvelle', $submitted->address1, 'the typed street must be on the submitted delivery address');
        self::assertNotSame((int) $deleted->id, (int) $result['id_address_delivery'], 'a soft-deleted cart address must never carry the order');
        self::assertSame(0, (int) $submitted->deleted, 'the submitted delivery address must not be soft-deleted');
    }

    public function testSubmitNeverReusesAnExplicitlyPostedSoftDeletedAddressId(): void
    {
        $customer = $this->createRegisteredCustomer();
        $deleted = $this->createAddress($customer, 'Deleted', '1 rue Supprimee', 'Lyon', '69001');
        $cart = $this->createCart($customer, (int) $deleted->id, (int) $deleted->id);
        $this->softDelete($deleted);
        $context = $this->createCheckoutContext($customer, $cart);

        $result = $this->submitForm($context, $this->typedParams([
            'id_address_delivery' => (string) $deleted->id,
            'address1' => '2 rue Nouvelle',
            'city' => 'Paris',
            'postcode' => '75002',
        ]));

        $submitted = new \Address((int) $result['id_address_delivery']);
        self::assertNotSame((int) $deleted->id, (int) $result['id_address_delivery'], 'a posted soft-deleted address id must never carry the order');
        self::assertSame('2 rue Nouvelle', $submitted->address1, 'the typed street must be on the submitted delivery address');
        self::assertSame(0, (int) $submitted->deleted, 'the submitted delivery address must not be soft-deleted');
    }

    /**
     * @param array<string,string> $overrides
     *
     * @return array<string,string>
     */
    private function typedParams(array $overrides): array
    {
        $params = $overrides + [
            'firstname' => 'Integration',
            'lastname' => 'Customer',
            'address1' => '2 rue Nouvelle',
            'city' => 'Paris',
            'postcode' => '75002',
            'id_country' => (string) (int) \Configuration::get('PS_COUNTRY_DEFAULT'),
            'use_same_address' => '1',
        ];

        // The shop's default country may require a state (e.g. US): provide one so the form
        // validation exercises the address-resolution contract instead of failing on id_state.
        $params += $this->stateParams((int) $params['id_country'], '');
        if (($params['use_same_address'] ?? '1') === '0' && isset($params['invoice_id_country'])) {
            $params += $this->stateParams((int) $params['invoice_id_country'], 'invoice_');
        }

        return $params;
    }

    /**
     * @return array<string,string>
     */
    private function stateParams(int $countryId, string $prefix): array
    {
        $country = new \Country($countryId);
        if (!\Validate::isLoadedObject($country) || !$country->contains_states) {
            return [];
        }

        $states = \State::getStatesByIdCountry($countryId);

        return $states ? [$prefix . 'id_state' => (string) (int) $states[0]['id_state']] : [];
    }

    /**
     * @param array<string,string> $params
     *
     * @return array{id_address_delivery:int,id_address_invoice:int}
     */
    private function submitForm(\Context $context, array $params): array
    {
        // resolveSelected*AddressId reads the raw request (Tools::getValue), while the field values
        // go through fillWith — mirror the opcsubmit controller by providing both.
        $_POST = $params;
        $_GET = [];

        $form = $this->createForm($context);
        $form->fillWith($params);

        $result = $form->submit();
        if ($result === false) {
            self::fail('submit() failed: ' . var_export($form->getErrors(), true));
        }

        return $result;
    }

    private function createForm(\Context $context): OnePageCheckoutForm
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $country = new \Country((int) \Configuration::get('PS_COUNTRY_DEFAULT'), (int) $context->language->id);
        $formatter = new OnePageCheckoutFormatter(
            $country,
            $translator,
            \Country::getCountries((int) $context->language->id, true)
        );

        return new OnePageCheckoutForm(
            $context->smarty,
            $context,
            $context->language,
            $translator,
            $formatter,
            new \CustomerPersister($context, new Hashing(), $translator, (bool) \Configuration::get('PS_GUEST_CHECKOUT_ENABLED')),
            new \CustomerAddressPersister($context->customer, $context->cart, \Tools::getToken(true, $context))
        );
    }

    private function createRegisteredCustomer(): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = sprintf('opc-submit-%s@example.com', uniqid('', true));
        $customer->is_guest = false;
        $customer->passwd = \Tools::hash('integration-password');
        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddress(\Customer $customer, string $alias, string $address1, string $city, string $postcode): \Address
    {
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->alias = $alias;
        $address->firstname = 'Integration';
        $address->lastname = 'Customer';
        $address->address1 = $address1;
        $address->city = $city;
        $address->postcode = $postcode;
        $address->id_country = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        self::assertTrue($address->save());

        return $address;
    }

    private function softDelete(\Address $address): void
    {
        $address->deleted = true;
        self::assertTrue($address->save());
    }

    private function createCart(\Customer $customer, int $deliveryId, int $invoiceId): \Cart
    {
        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop_group = 1;
        $cart->id_shop = 1;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = $deliveryId;
        $cart->id_address_invoice = $invoiceId;
        self::assertTrue($cart->add());

        return $cart;
    }

    private function createCheckoutContext(\Customer $customer, \Cart $cart): \Context
    {
        $context = self::getContext();
        $context->customer = $customer;
        $context->cart = $cart;
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        return $context;
    }
}
