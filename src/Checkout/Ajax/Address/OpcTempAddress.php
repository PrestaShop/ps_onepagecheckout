<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class OpcTempAddress
{
    private \Context $context;
    private int $tempInvoiceAddressId = 0;
    private int $originalInvoiceAddressId = 0;

    public function __construct(\Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    public function createFromRequest(array $requestParameters = []): int
    {
        $idCountry = (int) ($requestParameters['id_country'] ?? $requestParameters['delivery_id_country'] ?? 0);
        if ($idCountry <= 0) {
            return 0;
        }

        $tempAddressId = $this->insert(
            $idCountry,
            (int) ($requestParameters['id_state'] ?? $requestParameters['delivery_id_state'] ?? 0),
            (string) ($requestParameters['postcode'] ?? $requestParameters['delivery_postcode'] ?? '00000'),
            (string) ($requestParameters['city'] ?? $requestParameters['delivery_city'] ?? '-')
        );

        $this->context->cart->id_address_delivery = $tempAddressId;

        if ((string) \Configuration::get('PS_TAX_ADDRESS_TYPE') === 'id_address_invoice') {
            $invoiceIdCountry = (int) ($requestParameters['invoice_id_country'] ?? 0) ?: $idCountry;
            $this->originalInvoiceAddressId = (int) $this->context->cart->id_address_invoice;
            $this->tempInvoiceAddressId = $this->insert(
                $invoiceIdCountry,
                (int) ($requestParameters['invoice_id_state'] ?? $requestParameters['id_state'] ?? $requestParameters['delivery_id_state'] ?? 0),
                (string) ($requestParameters['invoice_postcode'] ?? $requestParameters['postcode'] ?? $requestParameters['delivery_postcode'] ?? '00000'),
                (string) ($requestParameters['invoice_city'] ?? $requestParameters['city'] ?? $requestParameters['delivery_city'] ?? '-')
            );
            $this->context->cart->id_address_invoice = $this->tempInvoiceAddressId;
        }

        $this->context->cart->save();

        return $tempAddressId;
    }

    public function cleanup(int $tempAddressId, int $originalAddressId): void
    {
        $this->context->cart->id_address_delivery = $originalAddressId;

        if ($this->tempInvoiceAddressId > 0) {
            $this->context->cart->id_address_invoice = $this->originalInvoiceAddressId;
        }

        $this->context->cart->save();
        \Db::getInstance()->delete('address', 'id_address = ' . (int) $tempAddressId);

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
            'alias' => 'temp_opc_' . bin2hex(random_bytes(8)),
            'firstname' => '-',
            'lastname' => '-',
            'address1' => '-',
            'city' => $city ?: '-',
            'postcode' => $postcode ?: '00000',
            'active' => 1,
            'deleted' => 0,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return (int) \Db::getInstance()->Insert_ID();
    }
}
