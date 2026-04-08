<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Js;

use PHPUnit\Framework\TestCase;

class OpcJavascriptContractTest extends TestCase
{
    public function testSharedOpcContractsExposeCentralizedEventsAndSelectors(): void
    {
        $events = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/events.js');
        $selectors = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/selectors.js');

        self::assertStringContainsString("opcCarrierSelected: 'opcCarrierSelected'", $events);
        self::assertStringContainsString("opcPaymentMethodsUpdated: 'opcPaymentMethodsUpdated'", $events);
        self::assertStringContainsString("updatedOpcAddressForm: 'updatedOpcAddressForm'", $events);
        self::assertStringContainsString("opcSubmitFailed: 'opcSubmitFailed'", $events);
        self::assertStringContainsString("deliveryMethods: '#opc-delivery-methods'", $selectors);
        self::assertStringContainsString("paymentMethods: '#opc-payment-methods'", $selectors);
        self::assertStringContainsString("checkoutFooter: '.one-page-checkout__footer'", $selectors);
        self::assertStringContainsString("address: '#opc-address-modal, #modal-delivery, #modal-invoice'", $selectors);
    }

    public function testSubmitScriptEmitsHistoricalFinalSubmitEvent(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-submit.js');
        self::assertStringContainsString("import OPC_EVENTS from './events';", $script);
        self::assertStringContainsString("import OPC_SELECTORS from './selectors';", $script);
        self::assertStringContainsString("import {getConfiguredOpcUrl} from './runtime/opc-runtime';", $script);
        self::assertStringContainsString('prestashop.emit(OPC_EVENTS.opcFinalSubmitStarted)', $script);
        self::assertStringContainsString('const OPC_FORM_ID_SELECTOR = OPC_SELECTORS.opc.form;', $script);
        self::assertStringContainsString('const PAY_BUTTON_SELECTOR = OPC_SELECTORS.opc.payButton;', $script);
        self::assertStringContainsString('prestashop.on(OPC_EVENTS.opcPaymentMethodsLoading', $script);
        self::assertStringContainsString('prestashop.on(OPC_EVENTS.opcPaymentMethodsFailed', $script);
        self::assertStringContainsString('const OPC_SUBMIT_URL_KEY = \'opcSubmit\';', $script);
        self::assertStringContainsString('function ajaxCheckCartStillOrderable()', $script);
        self::assertStringContainsString('HTMLFormElement.prototype.submit.call(innerForm)', $script);
        self::assertStringContainsString('const optionId = paymentRadio.id;', $script);
        self::assertStringContainsString('function bindScopedValidationListeners(form)', $script);
        self::assertStringContainsString('bindChangeValidationListeners(form, DELIVERY_OPTION_SELECTOR', $script);
        self::assertStringContainsString('bindChangeValidationListeners(paymentContainer, PAYMENT_OPTION_SELECTOR', $script);
        self::assertStringNotContainsString("document.addEventListener('change'", $script);
        self::assertStringNotContainsString("payload.set('submitOnePageCheckout', '1')", $script);
        self::assertStringContainsString('window.location.href = response.checkout_url || window.prestashop.urls.pages.order;', $script);
    }

    public function testGuestInitScriptUsesModuleRuntimeContract(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-guest-init.js');
        self::assertStringContainsString('import {getConfiguredOpcUrl}', $script);
        self::assertStringContainsString("import OPC_EVENTS from './events';", $script);
        self::assertStringContainsString("import OPC_SELECTORS from './selectors';", $script);
        self::assertStringContainsString('getConfiguredOpcUrl(MODULE_GUEST_INIT_URL_KEY)', $script);
        self::assertStringContainsString('prestashop.on(OPC_EVENTS.opcFinalSubmitStarted', $script);
        self::assertStringContainsString('const OPC_CONTACT_SECTION_SELECTOR = OPC_SELECTORS.opc.contactSection;', $script);
        self::assertStringNotContainsString('getOpcAddressContainer', $script);
    }

    public function testAddressFormScriptUsesModuleRuntimeContract(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-address.js');
        self::assertStringContainsString('import {getConfiguredOpcUrl}', $script);
        self::assertStringContainsString("import OPC_EVENTS from './events';", $script);
        self::assertStringContainsString("import OPC_SELECTORS from './selectors';", $script);
        self::assertStringContainsString('getConfiguredOpcUrl(MODULE_ADDRESS_FORM_URL_KEY)', $script);
        self::assertStringContainsString('prestashop.emit(OPC_EVENTS.opcDeliveryAddressUpdated', $script);
        self::assertStringContainsString('prestashop.emit(OPC_EVENTS.opcBillingAddressUpdated', $script);
    }

    public function testPaymentScriptsUseModuleRuntimeContracts(): void
    {
        $listScript = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-payment-list.js');
        $selectScript = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-payment-select.js');

        self::assertStringContainsString("import OPC_EVENTS from './events';", $listScript);
        self::assertStringContainsString("import OPC_SELECTORS from './selectors';", $listScript);
        self::assertStringContainsString('paymentMethods', $listScript);
        self::assertStringContainsString("prestashop.emit('handleError'", $listScript);
        self::assertStringContainsString('prestashop.on(OPC_EVENTS.opcCarrierSelected', $listScript);
        self::assertStringContainsString('prestashop.on(OPC_EVENTS.opcGuestInitSuccess', $listScript);
        self::assertStringContainsString('prestashop.on(OPC_EVENTS.opcCarriersLoading', $listScript);
        self::assertStringContainsString('prestashop.on(OPC_EVENTS.opcCarriersFailed', $listScript);
        self::assertStringContainsString('fetchGeneration', $listScript);
        self::assertStringNotContainsString("prestashop.on('opcCarriersUpdated'", $listScript);
        self::assertStringNotContainsString("prestashop.on('opcDeliveryAddressUpdated'", $listScript);
        self::assertStringContainsString("import OPC_EVENTS from './events';", $selectScript);
        self::assertStringContainsString("import OPC_SELECTORS from './selectors';", $selectScript);
        self::assertStringContainsString('selectPayment', $selectScript);
        self::assertStringContainsString('payment_selection_key', $selectScript);
        self::assertStringContainsString('selected_payment_selection_key', $selectScript);
        self::assertStringContainsString('OPC_EVENTS.opcPaymentMethodSelected', $selectScript);
    }
}
