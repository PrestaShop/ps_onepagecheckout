<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressModalFieldsHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;

class OpcAddressModalFieldsHandlerIntegrationTest extends TestCase
{
    public function testItRebuildsDeliveryFieldsForRequestedCountry(): void
    {
        $formSpy = new IntegrationOpcAddressModalFormSpy();
        $formSpy->templateVars = [
            'deliveryFields' => ['postcode' => ['name' => 'postcode']],
            'invoiceFields' => ['invoice_postcode' => ['name' => 'invoice_postcode']],
        ];
        $handler = new OnePageCheckoutAddressModalFieldsHandler($formSpy);

        $response = $handler->getTemplateVariables([
            'id_country' => '8',
            'address_type' => 'delivery',
        ]);

        self::assertSame([['id_country' => 8]], $formSpy->fillWithPayloads);
        self::assertSame(['postcode' => ['name' => 'postcode']], $response['formFields']);
        self::assertSame('', $response['prefix']);
        self::assertSame('modal-delivery', $response['modal_id']);
        self::assertSame('delivery', $response['address_type']);
    }

    public function testItRebuildsInvoiceFieldsWithInvoiceCountryAndPrefix(): void
    {
        $formSpy = new IntegrationOpcAddressModalFormSpy();
        $formSpy->templateVars = [
            'deliveryFields' => ['postcode' => ['name' => 'postcode']],
            'invoiceFields' => ['invoice_postcode' => ['name' => 'invoice_postcode']],
        ];
        $handler = new OnePageCheckoutAddressModalFieldsHandler($formSpy);

        $response = $handler->getTemplateVariables([
            'id_country' => '8',
            'address_type' => 'invoice',
        ]);

        self::assertSame([['invoice_id_country' => 8]], $formSpy->fillWithPayloads);
        self::assertSame(['invoice_postcode' => ['name' => 'invoice_postcode']], $response['formFields']);
        self::assertSame('invoice_', $response['prefix']);
        self::assertSame('modal-invoice', $response['modal_id']);
        self::assertSame('invoice', $response['address_type']);
    }

    public function testItDoesNotRebuildWhenCountryIsMissing(): void
    {
        $formSpy = new IntegrationOpcAddressModalFormSpy();
        $formSpy->templateVars = [
            'deliveryFields' => ['postcode' => ['name' => 'postcode']],
            'invoiceFields' => [],
        ];
        $handler = new OnePageCheckoutAddressModalFieldsHandler($formSpy);

        $response = $handler->getTemplateVariables([
            'address_type' => 'delivery',
        ]);

        self::assertSame([], $formSpy->fillWithPayloads);
        self::assertSame(['postcode' => ['name' => 'postcode']], $response['formFields']);
        self::assertSame('modal-delivery', $response['modal_id']);
    }

    public function testItDefaultsToDeliveryForUnknownAddressType(): void
    {
        $formSpy = new IntegrationOpcAddressModalFormSpy();
        $formSpy->templateVars = [
            'deliveryFields' => ['postcode' => ['name' => 'postcode']],
            'invoiceFields' => ['invoice_postcode' => ['name' => 'invoice_postcode']],
        ];
        $handler = new OnePageCheckoutAddressModalFieldsHandler($formSpy);

        $response = $handler->getTemplateVariables([
            'id_country' => '8',
            'address_type' => 'something-else',
        ]);

        self::assertSame([['id_country' => 8]], $formSpy->fillWithPayloads);
        self::assertSame('', $response['prefix']);
        self::assertSame('modal-delivery', $response['modal_id']);
        self::assertSame('delivery', $response['address_type']);
    }
}

class IntegrationOpcAddressModalFormSpy extends OnePageCheckoutForm
{
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
