<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressModalFieldsHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;

class OpcAddressModalFieldsHandlerTest extends TestCase
{
    public function testItBuildsDeliveryModalVariablesByDefaultWithoutRefillingWhenNoCountryIsSent(): void
    {
        $opcForm = $this->createMock(OnePageCheckoutForm::class);
        $opcForm->expects(self::never())->method('fillWith');
        $opcForm->method('getTemplateVariables')->willReturn([
            'deliveryFields' => ['delivery-field'],
            'invoiceFields' => ['invoice-field'],
        ]);

        $result = (new OnePageCheckoutAddressModalFieldsHandler($opcForm))->getTemplateVariables([]);

        self::assertSame([
            'formFields' => ['delivery-field'],
            'prefix' => '',
            'modal_id' => 'modal-delivery',
            'address_type' => 'delivery',
        ], $result);
    }

    public function testItRebuildsInvoiceModalForTheRequestedCountry(): void
    {
        $opcForm = $this->createMock(OnePageCheckoutForm::class);
        $opcForm->expects(self::once())->method('fillWith')->with(['invoice_id_country' => 8]);
        $opcForm->method('getTemplateVariables')->willReturn([
            'deliveryFields' => ['delivery-field'],
            'invoiceFields' => ['invoice-field'],
        ]);

        $result = (new OnePageCheckoutAddressModalFieldsHandler($opcForm))->getTemplateVariables([
            'address_type' => 'invoice',
            'id_country' => 8,
        ]);

        self::assertSame([
            'formFields' => ['invoice-field'],
            'prefix' => 'invoice_',
            'modal_id' => 'modal-invoice',
            'address_type' => 'invoice',
        ], $result);
    }

    public function testItDoesNotRefillWhenTheCountryIdIsNotPositive(): void
    {
        $opcForm = $this->createMock(OnePageCheckoutForm::class);
        $opcForm->expects(self::never())->method('fillWith');
        $opcForm->method('getTemplateVariables')->willReturn(['deliveryFields' => []]);

        (new OnePageCheckoutAddressModalFieldsHandler($opcForm))->getTemplateVariables(['id_country' => 0]);
    }

    public function testItFallsBackToAnEmptyFieldListWhenTheFormHasNoMatchingKey(): void
    {
        $opcForm = $this->createMock(OnePageCheckoutForm::class);
        $opcForm->method('getTemplateVariables')->willReturn([]);

        $result = (new OnePageCheckoutAddressModalFieldsHandler($opcForm))->getTemplateVariables([]);

        self::assertSame([], $result['formFields']);
    }
}
