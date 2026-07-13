<?php

/**
 * AJAX endpoint that autosaves the inline address form. Complete and valid input is persisted
 * as a real address attached to the cart; incomplete input is kept as a visitor-cookie draft,
 * but only until that first real address exists — from then on the persisted address is the
 * source of truth, and an edit left incomplete when the buyer navigates away is deliberately
 * dropped (the form re-prefills from the persisted address on their return).
 */

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\AddressDraftStorage;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutSaveAddressHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutAddressFormatter;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;

require_once __DIR__ . '/AbstractOpcJsonFrontController.php';

class Ps_OnepagecheckoutSaveDraftModuleFrontController extends Ps_OnepagecheckoutAbstractOpcJsonFrontController
{
    /**
     * @return array<string,mixed>
     */
    protected function handleAvailableOpcRequest(): array
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            header('Allow: POST');

            return ['success' => false];
        }

        if (!$this->isTokenValid()) {
            header('HTTP/1.1 403 Forbidden');

            return ['success' => false];
        }

        // Non-scalar values (e.g. `address1[]=x`) are never legitimate address form input.
        $requestParameters = array_filter(Tools::getAllValues(), 'is_scalar');

        // When the typed address is complete and valid and a checkout customer exists, save it as a
        // real address attached to the cart: created on the first complete save, then updated in
        // place on later edits (a session never leaves more than one in-progress address behind).
        // The returned ids let the final submit reuse the same addresses instead of creating new ones.
        $persistedAddressIds = $this->persistAddressIfComplete($requestParameters);

        // A complete address rejected by the authoritative validation: surface the field errors so the
        // inline form can show them (this replaces the old save-button validation). Checked BEFORE the
        // persisted short-circuit so an invalid SEPARATE BILLING address (use_same_address off) is not
        // dropped just because the delivery address persisted — otherwise an invalid invoice would reach
        // the carrier/payment refresh (wrong methods when PS_TAX_ADDRESS_TYPE=id_address_invoice). The
        // client marks the offending inline fields invalid and the readiness gate withholds the options.
        if (!empty($persistedAddressIds['validation_errors'])) {
            return [
                'success' => true,
                'address_persisted' => false,
                'validation_errors' => $persistedAddressIds['validation_errors'],
            ];
        }

        // Re-checking "use same address" abandons any separate inline billing: the invoice now mirrors
        // the delivery address (persistAddressIfComplete has already reverted the cart invoice via the
        // delivery save), so remove the throwaway inline billing address the autosave created. Core has
        // no such orphan — it only persists an address on an explicit submit.
        if ((string) ($requestParameters['use_same_address'] ?? '1') === '1') {
            $this->removeAbandonedInlineInvoiceAddress($requestParameters);
        }

        // Any address persisted this round (a separate billing only when use_same_address is off):
        // saved as a real address attached to the cart. The returned ids let the final submit reuse
        // them. An INVOICE-ONLY persist (the delivery came from the saved-address list, only the
        // separate billing was typed inline) must be reported too: the client remembers the invoice
        // id for in-place updates and the option sections need the terminal opcAddressPersisted
        // event — an unreported persist leaves them on a loader that never resolves.
        if ($persistedAddressIds['id_address_delivery'] > 0 || $persistedAddressIds['id_address_invoice'] > 0) {
            return [
                'success' => true,
                'address_persisted' => true,
                'id_address_delivery' => $persistedAddressIds['id_address_delivery'],
                'id_address_invoice' => $persistedAddressIds['id_address_invoice'],
            ];
        }

        // Otherwise keep the partial draft in the cookie, but only while no real address exists yet.
        // Once one is persisted it is the source of truth — the form re-prefills from it and the cart
        // already uses it for carriers/taxes — so a partial draft is deliberately dropped rather than
        // stored where it could mask the persisted address on the next render.
        if (!(new CheckoutCustomerContextResolver($this->context))->hasSavedAddress()) {
            $this->persistAddressDraft($requestParameters);
        }

        return ['success' => true];
    }

    /**
     * Save the typed inline address(es) as real address(es) attached to the cart, reusing the address
     * save handler. Updates the address already attached to the cart instead of inserting a new one,
     * so repeated autosaves keep a single in-progress address per type.
     *
     * This is an automatic background save: incomplete input simply leaves the cookie draft in place.
     * A COMPLETE-but-invalid address (rejected by per-country validation) returns its field errors so
     * the inline form can surface them; an incomplete address returns no errors.
     *
     * @param array<string,scalar> $requestParameters
     *
     * @return array{id_address_delivery:int,id_address_invoice:int,validation_errors:array<string,mixed>}
     */
    protected function persistAddressIfComplete(array $requestParameters): array
    {
        $ids = ['id_address_delivery' => 0, 'id_address_invoice' => 0, 'validation_errors' => []];

        if ((new CheckoutCustomerContextResolver($this->context))->resolveId() <= 0) {
            return $ids;
        }

        $delivery = $this->persistAddressOfType($requestParameters, 'delivery');
        $ids['id_address_delivery'] = $delivery['id_address'];
        if (!empty($delivery['errors'])) {
            $ids['validation_errors']['delivery'] = $delivery['errors'];
        }

        // A separate billing address (when "use same address" is off) is persisted the same way.
        if ((string) ($requestParameters['use_same_address'] ?? '1') === '0') {
            $invoice = $this->persistAddressOfType($requestParameters, 'invoice');
            $ids['id_address_invoice'] = $invoice['id_address'];
            if (!empty($invoice['errors'])) {
                // Re-key invoice errors to the inline invoice_* field names the form renders.
                $invoiceErrors = [];
                foreach ($invoice['errors'] as $field => $messages) {
                    $invoiceErrors['invoice_' . $field] = $messages;
                }
                $ids['validation_errors']['invoice'] = $invoiceErrors;
            }
        }

        return $ids;
    }

    /**
     * @param array<string,scalar> $requestParameters
     */
    private function persistAddressOfType(array $requestParameters, string $addressType): array
    {
        if (!$this->hasCompleteAddress($requestParameters, $addressType)) {
            return ['id_address' => 0, 'errors' => []];
        }

        /** @var Ps_Onepagecheckout $module */
        $module = $this->module;
        $handler = new OnePageCheckoutSaveAddressHandler(
            $this->context,
            $module->getTranslator(),
            new CheckoutCustomerContextResolver($this->context),
            new AddressDraftStorage($this->context)
        );

        // Reuse the address already attached to the cart so an edit updates it in place instead of
        // inserting a duplicate. Degrades to the cookie-draft fallback.
        try {
            $result = $handler->handle([
                'address_type' => $addressType,
                'id_address' => $this->reusableCartAddressId($addressType, $requestParameters),
            ] + $requestParameters);
        } catch (Throwable $exception) {
            return ['id_address' => 0, 'errors' => []];
        }

        if (!empty($result['success'])) {
            return ['id_address' => (int) ($result['id_address'] ?? 0), 'errors' => []];
        }

        // A complete address rejected by validation: pass the field errors up so the inline form can
        // surface them. Intentionally not logged (best-effort background save).
        return [
            'id_address' => 0,
            'errors' => (isset($result['errors']) && is_array($result['errors'])) ? $result['errors'] : [],
        ];
    }

    /**
     * The id of the address already attached to the cart for this type, when it belongs to the
     * current customer, so it is updated in place instead of inserting a duplicate. A billing id that
     * still mirrors the delivery address is not reused (that would overwrite the delivery address).
     */
    /**
     * @param array<string,scalar> $requestParameters
     */
    private function reusableCartAddressId(string $addressType, array $requestParameters): int
    {
        $cart = $this->context->cart;
        $deliveryId = (int) ($cart->id_address_delivery ?? 0);

        // Prefer the id the front reports for this type (the hidden field it kept from the last persist):
        // it survives a use_same toggle, unlike the cart's invoice pointer which reverts to the delivery
        // address, so the SAME inline billing address is updated in place instead of duplicated. Falls
        // back to the cart pointer (always holds the delivery address; holds the invoice while editing).
        $requestKey = $addressType === 'invoice' ? 'id_address_invoice' : 'id_address_delivery';
        $candidateId = (int) ($requestParameters[$requestKey] ?? 0);
        if ($candidateId <= 0) {
            $candidateId = $addressType === 'invoice' ? (int) ($cart->id_address_invoice ?? 0) : $deliveryId;
        }

        // A billing id that still mirrors the delivery address is not reused (would overwrite delivery).
        if ($candidateId <= 0 || ($addressType === 'invoice' && $candidateId === $deliveryId)) {
            return 0;
        }

        // Resolve the customer the same way the persistence path does (cart-owner aware), so the
        // idempotency anchor cannot drift from the customer the address is saved under. This also rejects
        // a stale front id (e.g. an address deleted on a prior re-check), falling back to a fresh insert.
        $customerId = (new CheckoutCustomerContextResolver($this->context))->resolveId();
        if ($customerId <= 0 || !Customer::customerHasAddress($customerId, $candidateId)) {
            return 0;
        }

        return $candidateId;
    }

    /**
     * Delete the throwaway inline billing address the autosave created, once "use same address" is
     * re-checked and the invoice mirrors the delivery address again. The id comes from the inline billing
     * fields' hidden input (front-sent), which the autosave alone ever writes, so this can never remove
     * an address the buyer picked from their saved-address list.
     *
     * @param array<string,scalar> $requestParameters
     */
    private function removeAbandonedInlineInvoiceAddress(array $requestParameters): void
    {
        $invoiceId = (int) ($requestParameters['id_address_invoice'] ?? 0);
        $deliveryId = (int) ($this->context->cart->id_address_delivery ?? 0);
        if ($invoiceId <= 0 || $invoiceId === $deliveryId) {
            return;
        }

        $customerId = (new CheckoutCustomerContextResolver($this->context))->resolveId();
        if ($customerId <= 0 || !Customer::customerHasAddress($customerId, $invoiceId)) {
            return;
        }

        // Only delete once the cart no longer references it (the invoice was reverted to the delivery
        // address by the delivery save above), so we never hard-delete an address still on the cart.
        if ((int) ($this->context->cart->id_address_invoice ?? 0) !== $invoiceId) {
            $address = new Address($invoiceId);
            if (Validate::isLoadedObject($address)) {
                $address->delete();
            }
        }
    }

    /**
     * Cheap pre-check so the full save handler only runs on input that can plausibly persist; the
     * handler's own validation remains authoritative (per-country postcode/state rules, etc.).
     *
     * @param array<string,scalar> $requestParameters
     */
    protected function hasCompleteAddress(array $requestParameters, string $addressType): bool
    {
        $prefix = $addressType === 'invoice' ? 'invoice_' : '';

        if ($addressType === 'invoice') {
            $idCountry = (int) ($requestParameters['invoice_id_country'] ?? 0);
        } else {
            $idCountry = (int) ($requestParameters['id_country'] ?? $requestParameters['delivery_id_country'] ?? 0);
        }

        if ($idCountry <= 0) {
            return false;
        }

        foreach ($this->requiredAddressFieldNames($idCountry) as $fieldName) {
            if (trim((string) ($requestParameters[$prefix . $fieldName] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The names of the address fields the FORM marks required for this country — the SAME source the
     * rendered form (and the client completeness gate) use: the per-country address format + the
     * merchant-configurable required-field list + the need_zip_code / contains_states /
     * need_identification_number rules. Deriving the pre-check from it (instead of a hard-coded list)
     * keeps it from ever diverging — it can neither demand a field the country never renders (e.g. a
     * postcode for a no-zip country, which would loop the option sections forever) nor miss a required
     * one (a state for a country with states, or a merchant-added required field).
     *
     * @return list<string>
     */
    private function requiredAddressFieldNames(int $idCountry): array
    {
        $country = new Country($idCountry, (int) $this->context->language->id);
        if (!Validate::isLoadedObject($country)) {
            return ['firstname', 'lastname', 'address1', 'city'];
        }

        /** @var Ps_Onepagecheckout $module */
        $module = $this->module;
        $availableCountries = Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES')
            ? Carrier::getDeliveredCountries((int) $this->context->language->id, true, true)
            : Country::getCountries((int) $this->context->language->id, true);

        $format = (new OnePageCheckoutAddressFormatter($country, $module->getTranslator(), $availableCountries))->getFormat();

        $names = [];
        foreach ($format as $field) {
            if ($field->isRequired()) {
                $names[] = (string) $field->getName();
            }
        }

        return $names;
    }

    /**
     * @param array<string,scalar> $requestParameters
     */
    protected function persistAddressDraft(array $requestParameters): void
    {
        /** @var Ps_Onepagecheckout $module */
        $module = $this->module;

        // Build the form from the submitted data so the allowed field names reflect the
        // configured per-country address format and any module-added custom address fields.
        $form = (new OnePageCheckoutFormFactory($this->context, $module))->create();
        $form->fillWith($requestParameters);

        (new AddressDraftStorage($this->context))->saveFromRequest(
            $requestParameters,
            $form->getAddressDraftFieldNames()
        );
    }
}
