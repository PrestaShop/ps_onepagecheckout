<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class TempAddressStorage
{
    private const COOKIE_KEY = 'opc_temp_delivery_address';

    private \Context $context;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    /**
     * @return array<string,string>
     */
    public function get(): array
    {
        if (!isset($this->context->cookie)) {
            return [];
        }

        $raw = (string) ($this->context->cookie->{self::COOKIE_KEY} ?: '');
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    public function saveFromRequest(array $requestParameters): void
    {
        if (!isset($this->context->cookie)) {
            return;
        }

        $fields = [
            'id_country' => (string) ($requestParameters['id_country'] ?? $requestParameters['delivery_id_country'] ?? ''),
            'id_state' => (string) ($requestParameters['id_state'] ?? $requestParameters['delivery_id_state'] ?? ''),
            'postcode' => (string) ($requestParameters['postcode'] ?? $requestParameters['delivery_postcode'] ?? ''),
            'city' => (string) ($requestParameters['city'] ?? $requestParameters['delivery_city'] ?? ''),
            'use_same_address' => (string) ($requestParameters['use_same_address'] ?? ''),
            'invoice_id_country' => (string) ($requestParameters['invoice_id_country'] ?? ''),
            'invoice_id_state' => (string) ($requestParameters['invoice_id_state'] ?? ''),
            'invoice_postcode' => (string) ($requestParameters['invoice_postcode'] ?? ''),
            'invoice_city' => (string) ($requestParameters['invoice_city'] ?? ''),
        ];

        $params = [];
        foreach ($fields as $field => $value) {
            if ($value !== '') {
                $params[$field] = $value;
            }
        }

        if (empty($params)) {
            return;
        }

        $this->context->cookie->{self::COOKIE_KEY} = json_encode($params);
        $this->context->cookie->write();
    }

    public function clear(): void
    {
        if (!isset($this->context->cookie)) {
            return;
        }

        unset($this->context->cookie->{self::COOKIE_KEY});
        $this->context->cookie->write();
    }
}
