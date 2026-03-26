<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class TempAddressCarrierSelectionStorage
{
    private const COOKIE_KEY = 'opc_selected_temp_delivery_option';

    private \Context $context;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    public function get(): string
    {
        if (!isset($this->context->cookie)) {
            return '';
        }

        return (string) ($this->context->cookie->__get(self::COOKIE_KEY) ?: '');
    }

    public function save(string $deliveryOption): void
    {
        if (!isset($this->context->cookie)) {
            return;
        }

        $this->context->cookie->__set(self::COOKIE_KEY, $deliveryOption);
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
