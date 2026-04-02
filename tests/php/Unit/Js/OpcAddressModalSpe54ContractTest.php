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
        self::assertStringContainsString('states', $script);
        self::assertStringContainsString('saveAddress', $script);
        self::assertStringContainsString('deleteAddress', $script);
        self::assertStringContainsString('updatedOpcAddressForm', $script);
        self::assertStringContainsString('deleteAddressConfirmTitle', $script);
        self::assertStringContainsString("$(document).on('input change', MODAL_FIELD_SELECTOR", $script);
        self::assertStringContainsString("$(document).on('shown.bs.modal', MODAL_SELECTOR", $script);
        self::assertStringContainsString('setModalFieldsDisabled($modal, false);', $script);
        self::assertStringNotContainsString('`${MODAL_SELECTOR} input, ${MODAL_SELECTOR} select, ${MODAL_SELECTOR} textarea`', $script);
        self::assertStringNotContainsString('window.confirm', $script);
    }
}
