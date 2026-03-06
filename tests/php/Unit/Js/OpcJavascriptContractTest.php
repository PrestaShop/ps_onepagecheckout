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
    public function testGuestInitScriptUsesModuleRuntimeContract(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-guest-init.js');
        self::assertStringContainsString('window.ps_onepagecheckout', $script);
    }

    public function testAddressFormScriptUsesModuleRuntimeContract(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-address.js');
        self::assertStringContainsString('window.ps_onepagecheckout', $script);
    }

    public function testFrontCoreBundleDoesNotOwnOpcRuntimeGlobal(): void
    {
        $script = (string) file_get_contents(_PS_ROOT_DIR_ . '/themes/core.js');
        self::assertStringNotContainsString('window.ps_onepagecheckout', $script);
        self::assertStringNotContainsString('window.prestashopOpc', $script);
    }
}
