<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

final class CheckoutAjaxResponse
{
    /**
     * @return array<string,mixed>
     */
    public static function error(string $message, string $field = ''): array
    {
        return [
            'success' => false,
            'errors' => [
                $field => [$message],
            ],
        ];
    }

    /**
     * @param array<string,array<int,string>> $errors
     *
     * @return array<string,mixed>
     */
    public static function validation(array $errors): array
    {
        return [
            'success' => false,
            'errors' => $errors,
        ];
    }
}
