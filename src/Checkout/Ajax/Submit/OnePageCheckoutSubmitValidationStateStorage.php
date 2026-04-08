<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit;

class OnePageCheckoutSubmitValidationStateStorage
{
    private const COOKIE_KEY = 'opc_submit_validation_state';

    private \Context $context;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param array<string,mixed> $state
     */
    public function save(array $state): void
    {
        if (!$this->hasCookie()) {
            return;
        }

        $encodedState = json_encode($state);
        if ($encodedState === false) {
            return;
        }

        $this->context->cookie->__set(self::COOKIE_KEY, $encodedState);
        $this->writeCookie();
    }

    /**
     * @return array<string,mixed>
     */
    public function consume(): array
    {
        if (!$this->hasCookie()) {
            return [];
        }

        $rawState = (string) ($this->context->cookie->__get(self::COOKIE_KEY) ?: '');
        $this->clear();

        if ($rawState === '') {
            return [];
        }

        $decodedState = json_decode($rawState, true);

        return is_array($decodedState) ? $decodedState : [];
    }

    public function clear(): void
    {
        if (!$this->hasCookie()) {
            return;
        }

        if (method_exists($this->context->cookie, '__unset')) {
            $this->context->cookie->__unset(self::COOKIE_KEY);
        } else {
            $this->context->cookie->__set(self::COOKIE_KEY, '');
        }

        $this->writeCookie();
    }

    private function hasCookie(): bool
    {
        return isset($this->context->cookie);
    }

    private function writeCookie(): void
    {
        if (method_exists($this->context->cookie, 'write')) {
            $this->context->cookie->write();
        }
    }
}
