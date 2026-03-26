<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSaveAddressHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpcSaveAddressHandlerTest extends TestCase
{
    public function testItReturnsValidationErrorsWhenRequiredFieldsAreMissing(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->smarty = $this->createMock(\Smarty::class);
        $context->language = new class extends \Language {
            public function __construct()
            {
            }
        };
        $context->language->id = 1;
        $context->country = new class extends \Country {
            public function __construct()
            {
            }
        };
        $context->country->id = 8;

        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolveId')->willReturn(42);

        $handler = new OnePageCheckoutSaveAddressHandler($context, $translator, $resolver);
        $response = $handler->handle([]);

        self::assertFalse($response['success']);
        self::assertNotEmpty($response['errors']);
    }
}
