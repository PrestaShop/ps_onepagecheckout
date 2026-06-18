<?php

declare(strict_types=1);

namespace Tests\Unit\Js;

use PHPUnit\Framework\TestCase;

class OpcAddressModalSpe54ContractTest extends TestCase
{
    public function testAddressModalScriptReferencesExpectedOpcEndpoints(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-address-modal.js');

        self::assertStringContainsString('addressesList', $script);
        self::assertStringContainsString('addressModal', $script);
        self::assertStringContainsString('saveAddress', $script);
        self::assertStringContainsString('deleteAddress', $script);
        self::assertStringContainsString('updatedOpcAddressForm', $script);
        self::assertStringContainsString('normalizeErrorEventResponse', $script);
        self::assertStringContainsString('retry-addresses', $script);
        self::assertStringContainsString('opc-delivery-address-loader', $script);
        self::assertStringContainsString('opc-billing-address-loader', $script);
        self::assertStringContainsString('refreshAddressLists', $script);
        self::assertStringContainsString("$(document).on('input change', MODAL_FIELD_SELECTOR", $script);
        self::assertStringContainsString('const $trigger = $(event.relatedTarget);', $script);
        self::assertStringContainsString("$(document).on('shown.bs.modal', MODAL_SELECTOR", $script);
        self::assertStringContainsString('setModalFieldsDisabled($modal, false);', $script);
        self::assertStringContainsString('const $modal = $(`#${DELETE_CONFIRM_MODAL_ID}`);', $script);
        self::assertStringNotContainsString('ADDRESSES_FEEDBACK_SELECTOR', $script);
        self::assertStringNotContainsString('captureAddressListMarkup', $script);
        self::assertStringNotContainsString('restoreAddressListMarkup', $script);
        self::assertStringNotContainsString('ensureDeleteConfirmModal', $script);
        self::assertStringNotContainsString('syncDeliveryMethodsContainerAddressId', $script);
        self::assertStringNotContainsString('syncHiddenAddressIdsFromSavedSelections', $script);
        self::assertStringNotContainsString('recoverStaleSavedAddressSelections', $script);
        self::assertStringNotContainsString('buildAddressesRefreshState', $script);
        self::assertStringNotContainsString('opc-template-addresses-loader', $script);
        self::assertStringNotContainsString('`${MODAL_SELECTOR} input, ${MODAL_SELECTOR} select, ${MODAL_SELECTOR} textarea`', $script);
        self::assertStringNotContainsString('window.confirm', $script);
    }

    public function testModalCountryRefreshContract(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-address-modal.js');

        // A country change re-renders the modal fields via the addressModal endpoint and injects fields_html.
        self::assertStringContainsString('function refreshModalFields(', $script);
        self::assertStringContainsString('response.fields_html', $script);
        self::assertStringContainsString('$container.html(response.fields_html);', $script);
        self::assertStringContainsString("$(document).on('change', MODAL_COUNTRY_SELECTOR", $script);

        // Out-of-order responses are guarded by a per-modal refresh generation.
        self::assertStringContainsString("$modal.data('opcRefreshGeneration', generation);", $script);
        self::assertStringContainsString('if (isStale()) {', $script);

        // Field values are preserved across the swap with the shared group-aware helpers,
        // not an ad-hoc snapshot that would drop grouped checkbox/radio values.
        self::assertStringContainsString('preserveAddressesSectionFields($container)', $script);
        self::assertStringContainsString('restoreAddressesSectionFields($container, preservedFields)', $script);
        self::assertStringNotContainsString('captureModalFieldValues', $script);
        self::assertStringNotContainsString('applyModalFieldValues', $script);

        // Save state is recomputed after the fields are re-rendered, and minLength/maxLength are
        // enforced manually because they are skipped by checkValidity() for programmatic values.
        self::assertStringContainsString('updateModalSaveState($modal);', $script);
        self::assertStringContainsString("getAttribute('minlength')", $script);
        self::assertStringContainsString("getAttribute('maxlength')", $script);
    }
}
