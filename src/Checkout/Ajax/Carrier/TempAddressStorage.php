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

        $raw = (string) ($this->context->cookie->__get(self::COOKIE_KEY) ?: '');
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

        $params = [];
        foreach (['id_country', 'id_state', 'postcode', 'city'] as $field) {
            $value = (string) ($requestParameters[$field]
                ?? $requestParameters["delivery_{$field}"]
                ?? '');
            if ($value !== '') {
                $params[$field] = $value;
            }
        }

        if (empty($params)) {
            return;
        }

        $this->context->cookie->__set(self::COOKIE_KEY, json_encode($params));
        $this->context->cookie->write();
    }

    public function clear(): void
    {
        if (!isset($this->context->cookie)) {
            return;
        }

        $this->context->cookie->__unset(self::COOKIE_KEY);
        $this->context->cookie->write();
    }
}
