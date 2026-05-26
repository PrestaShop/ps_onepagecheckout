<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\Submit;

class OnePageCheckoutSubmitValidationStateStorage
{
    private const STORAGE_KEY = '_opc_submit_validation_state';

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
        if ($this->getCartId() <= 0) {
            return;
        }

        $checkoutSessionData = $this->readCheckoutSessionData();
        $checkoutSessionData[self::STORAGE_KEY] = $state;

        $this->writeCheckoutSessionData($checkoutSessionData);
    }

    /**
     * @return array<string,mixed>
     */
    public function consume(): array
    {
        $checkoutSessionData = $this->readCheckoutSessionData();
        $submitState = $checkoutSessionData[self::STORAGE_KEY] ?? null;

        if (!is_array($submitState)) {
            return [];
        }

        unset($checkoutSessionData[self::STORAGE_KEY]);
        $this->writeCheckoutSessionData($checkoutSessionData);

        return $submitState;
    }

    public function clear(): void
    {
        $checkoutSessionData = $this->readCheckoutSessionData();
        if (!array_key_exists(self::STORAGE_KEY, $checkoutSessionData)) {
            return;
        }

        unset($checkoutSessionData[self::STORAGE_KEY]);
        $this->writeCheckoutSessionData($checkoutSessionData);
    }

    /**
     * @return array<string,mixed>
     */
    private function readCheckoutSessionData(): array
    {
        $rawCheckoutSessionData = '';

        if (isset($this->context->cart->checkout_session_data) && is_string($this->context->cart->checkout_session_data)) {
            $rawCheckoutSessionData = $this->context->cart->checkout_session_data;
        } elseif ($this->getCartId() > 0) {
            $rawCheckoutSessionData = (string) $this->getDb()->getValue(sprintf(
                'SELECT checkout_session_data FROM %scart WHERE id_cart = %d',
                _DB_PREFIX_,
                $this->getCartId()
            ));
        }

        if ($rawCheckoutSessionData === '') {
            return [];
        }

        $decodedCheckoutSessionData = json_decode($rawCheckoutSessionData, true);

        return is_array($decodedCheckoutSessionData) ? $decodedCheckoutSessionData : [];
    }

    /**
     * @param array<string,mixed> $checkoutSessionData
     */
    private function writeCheckoutSessionData(array $checkoutSessionData): void
    {
        if ($this->getCartId() <= 0) {
            return;
        }

        $encodedCheckoutSessionData = $checkoutSessionData === [] ? null : json_encode($checkoutSessionData);
        if ($checkoutSessionData !== [] && $encodedCheckoutSessionData === false) {
            return;
        }

        if (isset($this->context->cart)) {
            // Legacy PrestaShop ObjectModel instances expose table-backed fields dynamically at runtime.
            /* @phpstan-ignore-next-line */
            $this->context->cart->checkout_session_data = $encodedCheckoutSessionData ?? '';
        }

        $serializedCheckoutSessionData = $encodedCheckoutSessionData === null
            ? 'NULL'
            : '"' . $this->getDb()->escape($encodedCheckoutSessionData) . '"';

        $this->getDb()->execute(sprintf(
            'UPDATE %scart SET checkout_session_data = %s WHERE id_cart = %d',
            _DB_PREFIX_,
            $serializedCheckoutSessionData,
            $this->getCartId()
        ));
    }

    private function getCartId(): int
    {
        return isset($this->context->cart) ? (int) ($this->context->cart->id ?? 0) : 0;
    }

    protected function getDb()
    {
        return \Db::getInstance();
    }
}
