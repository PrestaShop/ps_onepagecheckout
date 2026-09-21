<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutAjaxResponse;

class CheckoutAjaxResponseTest extends TestCase
{
    public function testErrorWrapsTheMessageUnderTheEmptyFieldByDefault(): void
    {
        self::assertSame(
            [
                'success' => false,
                'errors' => [
                    '' => ['Something went wrong.'],
                ],
            ],
            CheckoutAjaxResponse::error('Something went wrong.')
        );
    }

    public function testErrorAttachesTheMessageToTheGivenField(): void
    {
        self::assertSame(
            [
                'success' => false,
                'errors' => [
                    'email' => ['Invalid email.'],
                ],
            ],
            CheckoutAjaxResponse::error('Invalid email.', 'email')
        );
    }

    public function testValidationForwardsTheErrorMapUnchanged(): void
    {
        $errors = [
            'email' => ['Invalid email.'],
            'postcode' => ['Invalid postcode.', 'Too short.'],
        ];

        self::assertSame(
            [
                'success' => false,
                'errors' => $errors,
            ],
            CheckoutAjaxResponse::validation($errors)
        );
    }
}
