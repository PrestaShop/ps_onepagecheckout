<?php

declare(strict_types=1);

namespace Tests\Unit\Js;

use PHPUnit\Framework\TestCase;

/**
 * Contract for the value `window.ps_onepagecheckout.submitBeforePayment()` resolves.
 *
 * A payment module that pays with its own button calls it before paying, and pays only when it
 * resolves true: true means the server answered `success: true`. Every other ending resolves false,
 * and only a missing form rejects, so a caller that ignores the value keeps working.
 */
class OpcSubmitBeforePaymentResultContractTest extends TestCase
{
    public function testOnlyASavedCheckoutResolvesTrue(): void
    {
        $body = $this->getSubmitBeforePaymentSource();

        self::assertSame(1, substr_count($body, 'return true;'));
        self::assertMatchesRegularExpression(
            '/if \(!response \|\| response\.success !== true\) \{\s*handleOpcSubmitFailure\(response\);\s*return false;\s*\}\s*return true;/',
            $body
        );
    }

    public function testEveryOtherEndingResolvesFalse(): void
    {
        $body = $this->getSubmitBeforePaymentSource();

        self::assertStringNotContainsString('return;', $body);
        self::assertSame(5, substr_count($body, 'return false;'));
        self::assertMatchesRegularExpression(
            '/emit\(OPC_EVENTS\.opcSubmitFailed, \{error\}\);\s*return false;\s*\} finally \{/',
            $body
        );
    }

    public function testTheExposedFunctionStillRejectsOnlyWithoutAForm(): void
    {
        $script = $this->getScript();

        self::assertStringContainsString("return Promise.reject(new Error('OPC form not found.'));", $script);
        self::assertStringContainsString('return submitBeforePayment(form);', $script);
    }

    private function getScript(): string
    {
        return (string) file_get_contents(
            _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/views/js/opc-submit.js'
        );
    }

    private function getSubmitBeforePaymentSource(): string
    {
        $body = $this->extractFunctionBody($this->getScript(), 'submitBeforePayment');
        self::assertNotSame('', $body, 'Could not locate the body of submitBeforePayment().');

        return $body;
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
