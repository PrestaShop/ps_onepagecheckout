<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressFormHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

class OpcAddressFormHandlerIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        self::resetTables();
        \Configuration::loadConfiguration();
    }

    public function testItLoadsAddressThenAppliesWhitelistedPayload(): void
    {
        $customer = $this->createCustomer($this->uniqueEmail('opc-address'));
        $address = $this->createAddressForCustomer((int) $customer->id);

        $formSpy = new IntegrationOpcAddressFormSpy();
        $formSpy->templateVars = ['address_form' => '<form>ok</form>'];
        $handler = new OnePageCheckoutAddressFormHandler($formSpy);

        $response = $handler->getTemplateVariables([
            'id_address' => (string) $address->id,
            'id_country' => '8',
            'invoice_id_country' => '8',
            'use_same_address' => '1',
            'ignored_key' => 'ignored',
        ]);

        self::assertSame([(int) $address->id], $formSpy->loadedAddressIds);
        self::assertSame([
            [
                'id_country' => '8',
                'invoice_id_country' => '8',
                'use_same_address' => '1',
            ],
        ], $formSpy->fillWithPayloads);
        self::assertSame(['address_form' => '<form>ok</form>'], $response);
    }

    public function testItDoesNotLoadAddressWhenIdIsNotPositive(): void
    {
        $formSpy = new IntegrationOpcAddressFormSpy();
        $formSpy->templateVars = ['address_form' => '<form>country-only</form>'];
        $handler = new OnePageCheckoutAddressFormHandler($formSpy);

        $response = $handler->getTemplateVariables([
            'id_address' => '0',
            'id_country' => '8',
        ]);

        self::assertSame([], $formSpy->loadedAddressIds);
        self::assertSame([
            ['id_country' => '8'],
        ], $formSpy->fillWithPayloads);
        self::assertSame(['address_form' => '<form>country-only</form>'], $response);
    }

    public function testItReturnsTemplateVariablesWithoutFormFillWhenPayloadIsIrrelevant(): void
    {
        $formSpy = new IntegrationOpcAddressFormSpy();
        $formSpy->templateVars = ['address_form' => '<form>initial</form>'];
        $handler = new OnePageCheckoutAddressFormHandler($formSpy);

        $response = $handler->getTemplateVariables([
            'foo' => 'bar',
        ]);

        self::assertSame([], $formSpy->loadedAddressIds);
        self::assertSame([], $formSpy->fillWithPayloads);
        self::assertSame(['address_form' => '<form>initial</form>'], $response);
    }

    private static function resetTables(): void
    {
        DatabaseDump::restoreTables([
            'configuration',
            'customer',
            'customer_group',
            'address',
        ]);
    }

    private function createCustomer(string $email): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = $email;
        $customer->is_guest = true;
        $customer->passwd = \Tools::hash('integration-password');

        self::assertTrue($customer->save());

        return $customer;
    }

    private function createAddressForCustomer(int $customerId): \Address
    {
        $address = new \Address();
        $address->id_customer = $customerId;
        $address->id_country = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $address->firstname = 'Integration';
        $address->lastname = 'Customer';
        $address->address1 = '1 rue Integration';
        $address->alias = 'opc_' . uniqid('', true);
        $address->city = 'Paris';
        self::assertTrue($address->save());

        return $address;
    }

    private function uniqueEmail(string $prefix): string
    {
        return sprintf('%s_%s@example.com', $prefix, uniqid('', true));
    }
}

class IntegrationOpcAddressFormSpy extends OnePageCheckoutForm
{
    /**
     * @var int[]
     */
    public array $loadedAddressIds = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $fillWithPayloads = [];

    /**
     * @var array<string, mixed>
     */
    public array $templateVars = [];

    public function __construct()
    {
    }

    public function fillFromAddress(\Address $address)
    {
        $this->loadedAddressIds[] = (int) $address->id;

        return $this;
    }

    public function fillWith(array $params = [])
    {
        $this->fillWithPayloads[] = $params;

        return $this;
    }

    public function getTemplateVariables()
    {
        return $this->templateVars;
    }
}
