<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;

class CoreParityCoverageTest extends TestCase
{
    /**
     * @dataProvider provideMirroredCheckoutTestSuites
     */
    public function testModuleSuiteKeepsSameTestCasesAsCoreSuite(string $coreTestFile, string $moduleTestFile): void
    {
        $coreMethodSegments = $this->extractTestMethodSegmentsFromFile($coreTestFile);
        $moduleMethodSegments = $this->extractTestMethodSegmentsFromFile($moduleTestFile);

        $coreMethods = array_keys($coreMethodSegments);
        $moduleMethods = array_keys($moduleMethodSegments);

        sort($coreMethods);
        sort($moduleMethods);
        $missingMethods = array_values(array_diff($coreMethods, $moduleMethods));

        self::assertSame(
            [],
            $missingMethods,
            sprintf('Checkout parity drift detected between "%s" and "%s".', $coreTestFile, $moduleTestFile)
        );

        foreach ($coreMethodSegments as $methodName => $coreSegment) {
            $moduleSegment = $moduleMethodSegments[$methodName] ?? '';
            self::assertSame(
                $this->countAssertionCalls($coreSegment),
                $this->countAssertionCalls($moduleSegment),
                sprintf(
                    'Assertion count drift detected for "%s" between "%s" and "%s".',
                    $methodName,
                    $coreTestFile,
                    $moduleTestFile
                )
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideMirroredCheckoutTestSuites(): iterable
    {
        yield 'unit form guest-init contract' => [
            _PS_ROOT_DIR_ . '/tests/Unit/Classes/form/OnePageCheckoutFormTest.php',
            _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/tests/php/Unit/Form/OnePageCheckoutFormTest.php',
        ];

        yield 'unit guest-init handler contract' => [
            _PS_ROOT_DIR_ . '/tests/Unit/Core/Checkout/CheckoutGuestInitHandlerTest.php',
            _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout/tests/php/Unit/Checkout/Ajax/OpcGuestInitHandlerTest.php',
        ];
    }

    /**
     * @return string[]
     */
    private function extractTestMethodSegmentsFromFile(string $filename): array
    {
        $content = (string) file_get_contents($filename);
        preg_match_all(
            '/public\s+function\s+(test[A-Za-z0-9_]+)\s*\([^)]*\)\s*(?::\s*void)?\s*\{/',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        /** @var array<int, array{0: string, 1: int}> $methodCaptures */
        $methodCaptures = $matches[1] ?? [];

        $segments = [];
        foreach ($methodCaptures as $index => $capture) {
            $methodName = $capture[0];
            $methodStart = $matches[0][$index][1];
            $nextMethodStart = isset($matches[0][$index + 1][1]) ? (int) $matches[0][$index + 1][1] : strlen($content);

            $segments[$methodName] = substr($content, (int) $methodStart, $nextMethodStart - (int) $methodStart);
        }

        return $segments;
    }

    private function countAssertionCalls(string $methodSegment): int
    {
        preg_match_all('/\bassert[A-Za-z0-9_]*\s*\(/', $methodSegment, $assertionCalls);

        return count($assertionCalls[0] ?? []);
    }
}
