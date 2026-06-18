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
        self::assertStringContainsString("\$modal.data('opcRefreshGeneration', generation);", $script);
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

        // Save stays disabled while a refresh is in flight, and an in-flight response is not
        // applied to a modal that has since been closed.
        self::assertStringContainsString("\$modal.data('opcRefreshPending', true);", $script);
        self::assertStringContainsString('isModalRefreshPending($modal)', $script);
        self::assertStringContainsString("if (!\$modal.hasClass('show')) {", $script);

        // A failed/invalid refresh keeps Save disabled until a successful retry instead of letting
        // the customer submit against the previous country's field set.
        self::assertStringContainsString("\$modal.data('opcRefreshFailed', true);", $script);
        self::assertStringContainsString('isModalRefreshFailed($modal)', $script);

        // The snapshot is taken inside the success handler (right before the swap) so edits made
        // while the request was in flight are not overwritten by a pre-request snapshot.
        self::assertStringContainsString('const preservedFields = preserveAddressesSectionFields($container);', $script);

        // A theme that overrides the legacy modal markup (no wrapper) is migrated rather than no-oped.
        self::assertStringContainsString('function ensureModalFieldsContainer(', $script);
    }

    public function testModalSaveButtonValidationFeedbackContract(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-address-modal.js');

        // Mirror the guest Pay form: clicking the disabled Save button (which has pointer-events:none)
        // falls through to the footer and surfaces field validation feedback.
        self::assertStringContainsString("$(document).on('click', MODAL_FOOTER_SELECTOR", $script);
        self::assertStringContainsString('function markModalFieldsValidity(', $script);
        self::assertStringContainsString('function reportModalFirstInvalidField(', $script);
        self::assertStringContainsString("toggleClass('is-invalid'", $script);

        // Clicking the enabled Save button with invalid fields shows validation + an error
        // notification instead of posting.
        self::assertStringContainsString('if (!isModalFormValid($modal)) {', $script);
        self::assertStringContainsString('addressFieldsInvalid', $script);

        $scss = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/scss/one-page-checkout.scss');
        self::assertStringContainsString('#submit-address-modal:disabled', $scss);
        self::assertStringContainsString('pointer-events: none;', $scss);
    }
}
