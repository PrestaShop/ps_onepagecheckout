<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class OpcTempAddress
{
    public const TEMPORARY_ADDRESS_ALIAS_PREFIX = 'temp_opc_';

    private \Context $context;
    private int $tempInvoiceAddressId = 0;
    private int $originalDeliveryAddressId = 0;
    private int $originalInvoiceAddressId = 0;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    public function createFromRequest(array $requestParameters = [], bool $allowInvoiceAddressWithoutDelivery = false): int
    {
        $tempAddressId = 0;
        $idCountry = (int) ($requestParameters['id_country'] ?? $requestParameters['delivery_id_country'] ?? 0);

        if ($idCountry <= 0 && !$allowInvoiceAddressWithoutDelivery) {
            return 0;
        }

        // Capture the pre-swap pointers from a FRESH DB read (not from the request's
        // in-memory cart, loaded potentially hundreds of ms ago): a concurrent savedraft
        // may have committed newer pointers meanwhile, and restoring a stale capture in
        // cleanup() would silently undo that write.
        $freshPointers = \Db::getInstance()->getRow(
            'SELECT id_address_delivery, id_address_invoice FROM ' . _DB_PREFIX_ . 'cart'
            . ' WHERE id_cart = ' . (int) $this->context->cart->id
        ) ?: [];
        $this->originalDeliveryAddressId = (int) ($freshPointers['id_address_delivery'] ?? 0);

        if ($idCountry > 0) {
            $tempAddressId = $this->insert(
                $idCountry,
                (int) ($requestParameters['id_state'] ?? $requestParameters['delivery_id_state'] ?? 0),
                (string) ($requestParameters['postcode'] ?? $requestParameters['delivery_postcode'] ?? '00000'),
                (string) ($requestParameters['city'] ?? $requestParameters['delivery_city'] ?? '-')
            );

            $this->context->cart->id_address_delivery = $tempAddressId;
        }

        $tempInvoiceAddressId = $this->createInvoiceFromRequest($requestParameters, $idCountry, (int) ($freshPointers['id_address_invoice'] ?? 0));

        // Swap the cart to the temp pointers with pointer-only UPDATEs. The previous
        // full-row Cart::save() re-wrote EVERY cart column from this request's
        // possibly-stale object, silently clobbering pointer writes committed by
        // concurrent requests since this one loaded its cart.
        if ($tempAddressId > 0 || $tempInvoiceAddressId > 0) {
            $swap = [];
            if ($tempAddressId > 0) {
                $swap['id_address_delivery'] = $tempAddressId;
            }
            if ($tempInvoiceAddressId > 0) {
                $swap['id_address_invoice'] = $tempInvoiceAddressId;
            }
            \Db::getInstance()->update('cart', $swap, 'id_cart = ' . (int) $this->context->cart->id);
        }

        return $tempAddressId;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function createInvoiceFromRequest(array $requestParameters = [], int $fallbackIdCountry = 0, int $freshInvoiceAddressId = 0): int
    {
        if ((string) \Configuration::get('PS_TAX_ADDRESS_TYPE') !== 'id_address_invoice') {
            return 0;
        }

        $useSameAddress = (string) ($requestParameters['use_same_address'] ?? '1') !== '0';
        $invoiceIdCountry = $fallbackIdCountry;
        if (!$useSameAddress) {
            $requestedInvoiceIdCountry = (int) ($requestParameters['invoice_id_country'] ?? 0);
            if ($requestedInvoiceIdCountry > 0) {
                $invoiceIdCountry = $requestedInvoiceIdCountry;
            }
        }

        if ($invoiceIdCountry <= 0) {
            return 0;
        }

        $this->originalInvoiceAddressId = $freshInvoiceAddressId > 0 ? $freshInvoiceAddressId : (int) $this->context->cart->id_address_invoice;
        $this->tempInvoiceAddressId = $this->insert(
            $invoiceIdCountry,
            (int) ($requestParameters['invoice_id_state'] ?? $requestParameters['id_state'] ?? $requestParameters['delivery_id_state'] ?? 0),
            (string) ($requestParameters['invoice_postcode'] ?? $requestParameters['postcode'] ?? $requestParameters['delivery_postcode'] ?? '00000'),
            (string) ($requestParameters['invoice_city'] ?? $requestParameters['city'] ?? $requestParameters['delivery_city'] ?? '-')
        );
        $this->context->cart->id_address_invoice = $this->tempInvoiceAddressId;

        return $this->tempInvoiceAddressId;
    }

    public function hasTemporaryInvoiceAddress(): bool
    {
        return $this->tempInvoiceAddressId > 0;
    }

    public function cleanup(int $tempAddressId, int $originalAddressId): void
    {
        if ($tempAddressId <= 0 && $this->tempInvoiceAddressId <= 0) {
            return;
        }

        // Restore each cart pointer ONLY if it still holds OUR temp address (atomic
        // compare-and-swap in SQL). A concurrent writer (a use_same re-check savedraft,
        // a selectaddress) may have committed a newer pointer while this request held
        // its temp swap — restoring the stale pre-swap original would clobber it and
        // could leave the cart on a deleted address. No concurrent writer => the WHERE
        // matches and the restore behaves exactly as before.
        $cartId = (int) $this->context->cart->id;
        if ($tempAddressId > 0) {
            // Prefer the fresh capture made at swap time; the caller's capture comes from
            // its request-start cart object and may predate concurrent commits.
            $restoreDeliveryId = $this->originalDeliveryAddressId > 0 ? $this->originalDeliveryAddressId : (int) $originalAddressId;
            \Db::getInstance()->update(
                'cart',
                ['id_address_delivery' => $restoreDeliveryId],
                'id_cart = ' . $cartId . ' AND id_address_delivery = ' . (int) $tempAddressId
            );
        }

        if ($this->tempInvoiceAddressId > 0) {
            \Db::getInstance()->update(
                'cart',
                ['id_address_invoice' => (int) $this->originalInvoiceAddressId],
                'id_cart = ' . $cartId . ' AND id_address_invoice = ' . (int) $this->tempInvoiceAddressId
            );
        }

        // Sync the in-memory cart with whatever won in the DB, so later reads in this
        // request never see a temp id.
        $row = \Db::getInstance()->getRow(
            'SELECT id_address_delivery, id_address_invoice FROM ' . _DB_PREFIX_ . 'cart WHERE id_cart = ' . $cartId
        );
        if ($row) {
            $this->context->cart->id_address_delivery = (int) $row['id_address_delivery'];
            $this->context->cart->id_address_invoice = (int) $row['id_address_invoice'];
        }

        if ($tempAddressId > 0) {
            \Db::getInstance()->delete('address', 'id_address = ' . (int) $tempAddressId);
        }

        if ($this->tempInvoiceAddressId > 0) {
            \Db::getInstance()->delete('address', 'id_address = ' . (int) $this->tempInvoiceAddressId);
            $this->tempInvoiceAddressId = 0;
            $this->originalInvoiceAddressId = 0;
        }
    }

    private function insert(int $idCountry, int $idState, string $postcode, string $city): int
    {
        \Db::getInstance()->insert('address', [
            'id_country' => $idCountry,
            'id_state' => $idState,
            'id_customer' => (int) $this->context->customer->id,
            'alias' => self::TEMPORARY_ADDRESS_ALIAS_PREFIX . bin2hex(random_bytes(8)),
            'firstname' => '-',
            'lastname' => '-',
            'address1' => '-',
            'city' => \pSQL($city) ?: '-',
            'postcode' => \pSQL($postcode) ?: '00000',
            'active' => 1,
            'deleted' => 0,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return (int) \Db::getInstance()->Insert_ID();
    }
}
