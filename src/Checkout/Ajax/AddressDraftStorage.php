<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

/**
 * Persists a guest's in-progress (not yet saved) checkout address form so it can be
 * restored when they navigate away and return. Stored in the visitor cookie.
 *
 * The set of fields to keep is not hardcoded: callers pass the address field names taken
 * from the live form definition (so configurable per-country formats and module-added
 * custom fields are covered). Size caps keep the cookie small regardless of that set.
 */
class AddressDraftStorage
{
    private const COOKIE_KEY = 'opc_address_draft';

    /** Drafts older than this are dropped so personal data does not linger on the device. */
    private const TTL_SECONDS = 86400;

    /**
     * Hard ceiling on the serialized payload. PrestaShop stores every cookie value inside one
     * shared, encrypted cookie, and browsers drop a cookie once it passes ~4 KB. Encryption
     * inflates the stored size, so the draft must stay a small slice of that budget — never the
     * whole thing — or it could overflow the session cookie and break checkout. A normal two
     * address draft is a few hundred bytes, so 1 KB leaves comfortable headroom.
     */
    private const MAX_SERIALIZED_LENGTH = 1024;

    /** Per-field length cap. */
    private const MAX_FIELD_LENGTH = 255;

    private \Context $context;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param array<int, string> $allowedFields address field names the draft may contain
     *
     * @return array<string, string>
     */
    public function get(array $allowedFields): array
    {
        if (!isset($this->context->cookie)) {
            return [];
        }

        $raw = (string) ($this->context->cookie->{self::COOKIE_KEY} ?: '');
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->clear();

            return [];
        }

        $timestamp = (int) ($data['ts'] ?? 0);
        if ($timestamp <= 0 || (time() - $timestamp) > self::TTL_SECONDS) {
            $this->clear();

            return [];
        }

        $cartId = (int) ($data['cart_id'] ?? 0);
        $currentCartId = $this->currentCartId();
        if ($cartId <= 0 || $currentCartId <= 0 || $cartId !== $currentCartId) {
            return [];
        }

        return is_array($data['values'] ?? null) ? $this->whitelist($data['values'], $allowedFields) : [];
    }

    /**
     * @param array<string, mixed> $requestParameters
     * @param array<int, string> $allowedFields address field names the draft may contain
     */
    public function saveFromRequest(array $requestParameters, array $allowedFields): void
    {
        if (!isset($this->context->cookie)) {
            return;
        }

        $cartId = $this->currentCartId();
        if ($cartId <= 0) {
            return;
        }

        $values = $this->whitelist($requestParameters, $allowedFields);
        if ($values === []) {
            $this->clear();

            return;
        }

        $payload = json_encode([
            'ts' => time(),
            'cart_id' => $cartId,
            'values' => $values,
        ]);

        if (!is_string($payload) || strlen($payload) > self::MAX_SERIALIZED_LENGTH) {
            return;
        }

        $this->context->cookie->{self::COOKIE_KEY} = $payload;
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

    /**
     * Keep only the allowed address field names with non-empty scalar values, length-capped.
     *
     * @param array<string, mixed> $values
     * @param array<int, string> $allowedFields
     *
     * @return array<string, string>
     */
    private function whitelist(array $values, array $allowedFields): array
    {
        $result = [];
        foreach ($allowedFields as $field) {
            if (!is_string($field) || !array_key_exists($field, $values)) {
                continue;
            }

            $value = $values[$field];
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $value = (string) $value;
            if ($value === '') {
                continue;
            }

            $result[$field] = mb_substr($value, 0, self::MAX_FIELD_LENGTH);
        }

        return $result;
    }

    private function currentCartId(): int
    {
        $cart = $this->context->cart ?? null;

        return (int) ($cart->id ?? 0);
    }
}
