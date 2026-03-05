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
    public function testGuestInitScriptUsesModuleRuntimeContractWithoutLegacyFallback(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-guest-init.js');

        self::assertStringContainsString('window.ps_onepagecheckout', $script);
        self::assertStringNotContainsString('window.prestashopOpc', $script);
        self::assertStringNotContainsString('LEGACY_GUEST_INIT_ACTION', $script);
        self::assertStringNotContainsString('fallbackToLegacyCheckout', $script);
        self::assertStringNotContainsString('action=opcGuestInit', $script);
        self::assertStringNotContainsString('prestashop.urls.pages.order', $script);
    }

    public function testAddressFormScriptUsesModuleRuntimeContractWithoutLegacyFallback(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-address.js');

        self::assertStringContainsString('window.ps_onepagecheckout', $script);
        self::assertStringNotContainsString('window.prestashopOpc', $script);
        self::assertStringNotContainsString('LEGACY_ADDRESS_FORM_ACTION', $script);
        self::assertStringNotContainsString('fallbackToLegacyCheckout', $script);
        self::assertStringNotContainsString('action=opcAddressForm', $script);
        self::assertStringNotContainsString('prestashop.urls.pages.order', $script);
    }
}

