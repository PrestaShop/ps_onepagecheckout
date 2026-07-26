<?php

declare(strict_types=1);

namespace Tests\Unit\Js;

use PHPUnit\Framework\TestCase;

/**
 * Contract for the isValid the OPC submit runtime reports.
 *
 * Payment modules (e.g. the ps_checkout FO SDK) read the `opcFormValidated` event to decide whether
 * the buyer may pay, so that event MUST carry the full form validity — native field validity plus the
 * carrier/payment selections — not just the section states + terms. At the same time the native OPC
 * Pay button must NOT be greyed out on those extra checks: it keeps the looser gate. These two must
 * therefore be computed separately.
 */
class OpcSubmitFormValidatedContractTest extends TestCase
{
    private function getScript(): string
    {
        return (string) file_get_contents(
            _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-submit.js'
        );
    }

    public function testOpcFormValidatedEventCarriesTheFullValidity(): void
    {
        $script = $this->getScript();

        // The event payload is the comprehensive validity, built from the loose button gate AND the
        // native field validity AND the carrier/payment selections (mirrors ensureSubmitPreconditions).
        self::assertStringContainsString('const isFormFullyValid = isValid', $script);
        self::assertStringContainsString('&& form.checkValidity()', $script);
        self::assertStringContainsString('&& isDeliverySelectionValid()', $script);
        self::assertStringContainsString('&& isPaymentSelectionValid(form)', $script);

        // The event emits the comprehensive validity, NOT the loose button gate.
        self::assertStringContainsString(
            'prestashop.emit(OPC_EVENTS.opcFormValidated, {isValid: isFormFullyValid});',
            $script
        );
    }

    public function testPayButtonStaysOnTheLooseGateAndIsNotGreyedByTheExtraChecks(): void
    {
        $script = $this->getScript();

        // The native Pay button keeps the unchanged (loose) gate: it must not be disabled on native
        // field validity or the selections, otherwise the button UX would regress.
        self::assertStringContainsString('payButton.disabled = !isValid;', $script);
        self::assertStringContainsString("payButton.classList.toggle('disabled', !isValid);", $script);

        // The comprehensive validity must never drive the button state.
        self::assertStringNotContainsString('payButton.disabled = !isFormFullyValid', $script);
        self::assertStringNotContainsString("payButton.classList.toggle('disabled', !isFormFullyValid", $script);

        // The loose gate itself is deliberately free of the extra checks (they only feed the event).
        self::assertDoesNotMatchRegularExpression(
            '/const isValid = [^;]*checkValidity/s',
            $script,
            'The button gate (isValid) must not include form.checkValidity(); that belongs to the event only.'
        );
    }

    public function testSelectionPredicatesAreSideEffectFree(): void
    {
        $script = $this->getScript();

        // Side-effect-free mirrors of resolveDeliverySelection / resolvePaymentSelection: they run on
        // every keystroke via validateForm, so they must not render alerts, focus fields or report
        // validity.
        self::assertStringContainsString('function isDeliverySelectionValid()', $script);
        self::assertStringContainsString('function isPaymentSelectionValid(form)', $script);

        foreach (['isDeliverySelectionValid', 'isPaymentSelectionValid'] as $predicate) {
            $body = $this->extractFunctionBody($script, $predicate);
            self::assertNotSame('', $body, "Could not locate the body of {$predicate}().");
            self::assertStringNotContainsString('renderSectionValidationAlert', $body);
            self::assertStringNotContainsString('reportValidity', $body);
            self::assertStringNotContainsString('.focus(', $body);
        }
    }

    /**
     * Returns the source of a top-level `function <name>(...) { ... }` (balanced braces).
     */
    private function extractFunctionBody(string $script, string $name): string
    {
        $start = strpos($script, "function {$name}(");
        if ($start === false) {
            return '';
        }

        $braceStart = strpos($script, '{', $start);
        if ($braceStart === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($script);
        for ($i = $braceStart; $i < $length; ++$i) {
            if ($script[$i] === '{') {
                ++$depth;
            } elseif ($script[$i] === '}') {
                --$depth;
                if ($depth === 0) {
                    return substr($script, $braceStart, $i - $braceStart + 1);
                }
            }
        }

        return '';
    }
}
