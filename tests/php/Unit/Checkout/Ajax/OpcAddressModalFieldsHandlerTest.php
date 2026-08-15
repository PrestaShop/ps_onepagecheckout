<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressModalFieldsHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;

class OpcAddressModalFieldsHandlerTest extends TestCase
{
    // Note: the delivery-default and invoice-rebuild-for-country scenarios are covered end to end by
    // OpcAddressModalFieldsHandlerIntegrationTest (tests/php/Integration). This unit test keeps only the
    // guard/edge cases that integration test does not exercise: a present but non-positive country id,
    // and a form that returns no matching field key.

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
