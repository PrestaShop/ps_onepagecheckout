<?php

declare(strict_types=1);

namespace Tests\Unit\Form;

use PHPUnit\Framework\TestCase;

/**
 * address-fields.tpl renders the rows PHP built in AddressFieldRows and nothing else, so every
 * include chain that reaches it has to carry `fieldRows` all the way down. A missing hand-over
 * renders an empty form instead of failing loudly, which is invisible to a mocked render test.
 */
class AddressFieldRowsTemplateContractTest extends TestCase
{
    public function testAddressesSectionPassesTheSectionRowsToBothAddressModals(): void
    {
        $source = $this->templateSource('addresses-section.tpl');
        $includes = $this->includesOf($source, 'address-modal.tpl');

        self::assertCount(2, $includes);

        $delivery = $this->includeWith($includes, "modal_id='modal-delivery'");
        $invoice = $this->includeWith($includes, "modal_id='modal-invoice'");

        self::assertStringContainsString('fieldRows=$deliveryFieldRows', $delivery);
        self::assertStringContainsString('fieldRows=$invoiceFieldRows', $invoice);
    }

    public function testAddressesSectionPassesTheSectionRowsToBothInlineForms(): void
    {
        $source = $this->templateSource('addresses-section.tpl');
        $includes = $this->includesOf($source, 'address-fields.tpl');

        self::assertCount(2, $includes);

        $delivery = $this->includeWith($includes, 'formFields=$deliveryFields');
        $invoice = $this->includeWith($includes, 'formFields=$invoiceFields');

        self::assertStringContainsString('fieldRows=$deliveryFieldRows', $delivery);
        self::assertStringContainsString('fieldRows=$invoiceFieldRows', $invoice);
    }

    public function testAddressModalForwardsFieldRowsToItsFieldsPartial(): void
    {
        $source = $this->templateSource('address-modal.tpl');
        $includes = $this->includesOf($source, 'address-modal-fields.tpl');

        self::assertCount(1, $includes);
        self::assertStringContainsString('fieldRows=$fieldRows', $includes[0]);
    }

    public function testAddressModalFieldsForwardsFieldRowsToTheSharedPartial(): void
    {
        $source = $this->templateSource('address-modal-fields.tpl');
        $includes = $this->includesOf($source, 'address-fields.tpl');

        self::assertCount(1, $includes);
        self::assertStringContainsString('fieldRows=$fieldRows', $includes[0]);
    }

    private function templateSource(string $name): string
    {
        $path = _PS_ROOT_DIR_
            . '/modules/ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/'
            . $name;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return string[] every `{include}` tag of $templateName, brace-balanced so that nested
     *                  `{l s='...'}` attributes do not cut the tag short
     */
    private function includesOf(string $source, string $templateName): array
    {
        $needle = "{include file='module:";
        $suffix = '/' . $templateName . "'";
        $includes = [];
        $offset = 0;

        while (($start = strpos($source, $needle, $offset)) !== false) {
            $end = $this->closingBrace($source, $start);
            $tag = substr($source, $start, $end - $start + 1);
            $offset = $end + 1;

            if (strpos($tag, $suffix) !== false) {
                $includes[] = $tag;
            }
        }

        return $includes;
    }

    private function closingBrace(string $source, int $start): int
    {
        $depth = 0;
        $length = strlen($source);

        for ($position = $start; $position < $length; ++$position) {
            if ($source[$position] === '{') {
                ++$depth;
            } elseif ($source[$position] === '}') {
                --$depth;

                if ($depth === 0) {
                    return $position;
                }
            }
        }

        self::fail('Unterminated {include} tag at offset ' . $start);
    }

    /**
     * @param string[] $includes
     */
    private function includeWith(array $includes, string $marker): string
    {
        foreach ($includes as $tag) {
            if (strpos($tag, $marker) !== false) {
                return $tag;
            }
        }

        self::fail('No {include} tag containing ' . $marker);
    }
}
