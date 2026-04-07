<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSaveAddressHandler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Fixtures\CheckoutTestFixtures;

class OpcSaveAddressHandlerTest extends TestCase
{
    public function testItReturnsTechnicalErrorsUnderTheErrorsKeyWhenCustomerCannotBeResolved(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $resolver = $this->createMock(CheckoutCustomerContextResolver::class);
        $resolver->method('resolveId')->willReturn(0);

        $handler = $this->createHandler(CheckoutTestFixtures::context(), $translator, $resolver);
        $response = $handler->handle([]);

        self::assertSame(
            [
                'success' => false,
                'errors' => [
                    '' => ['Unable to resolve checkout customer.'],
                ],
            ],
            $response
        );
    }

    private function createHandler(
        \Context $context,
        TranslatorInterface $translator,
        CheckoutCustomerContextResolver $resolver,
    ): OnePageCheckoutSaveAddressHandler {
        return new OnePageCheckoutSaveAddressHandler($context, $translator, $resolver);
    }
}
