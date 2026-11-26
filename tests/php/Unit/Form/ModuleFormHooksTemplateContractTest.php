<?php

declare(strict_types=1);

namespace Tests\Unit\Form;

use PHPUnit\Framework\TestCase;

/**
 * A module may print its own <form> in displayPersonalInformationTop or displayAddressSelectorBottom, as it can
 * on the core checkout. Inside #opc-form the browser drops the nested start tag and the module's </form> ends
 * #opc-form early: the fields after it are no longer sent with the checkout, so these hooks stay outside it.
 */
class ModuleFormHooksTemplateContractTest extends TestCase
{
    private const HOOKS = ['displayPersonalInformationTop', 'displayAddressSelectorBottom'];

    public function testTheHooksAreRenderedOutsideTheCheckoutForm(): void
    {
        $source = $this->templateSource('steps/one-page-checkout.tpl');
        $formStart = strpos($source, '<form id="opc-form"');
        self::assertNotFalse($formStart);
        $formEnd = strpos($source, '</form>', $formStart);
        self::assertNotFalse($formEnd);

        foreach (self::HOOKS as $hook) {
            $position = strpos($source, "{hook h='" . $hook . "'");

            self::assertNotFalse($position, $hook . ' is no longer rendered by the one-page checkout');
            self::assertTrue($position < $formStart || $position > $formEnd, $hook . ' is rendered inside #opc-form');
        }
    }

    public function testTheSectionsRenderedInsideTheCheckoutFormDoNotPrintTheHooks(): void
    {
        foreach (['contact-section.tpl', 'addresses-section.tpl'] as $section) {
            $source = $this->templateSource('one-page-checkout/' . $section);

            foreach (self::HOOKS as $hook) {
                self::assertStringNotContainsString("{hook h='" . $hook . "'", $source, $section . ' prints ' . $hook);
            }
        }
    }

    private function templateSource(string $name): string
    {
        $path = _PS_ROOT_DIR_
            . '/modules/ps_onepagecheckout/views/templates/front/checkout/_partials/'
            . $name;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
