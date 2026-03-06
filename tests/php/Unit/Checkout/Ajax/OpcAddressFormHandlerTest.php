<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressFormHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;

class OpcAddressFormHandlerTest extends TestCase
{
    private OnePageCheckoutForm|MockObject $opcForm;

    protected function setUp(): void
    {
        $this->opcForm = $this->getMockBuilder(OnePageCheckoutForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fillFromAddress', 'fillWith', 'getTemplateVariables'])
            ->getMock()
        ;

        $context = \Context::getContext();
        $context->language = new class extends \Language {
            public function __construct()
            {
            }
        };
        $context->language->id = 1;
    }

    public function testItBuildsTemplateVariablesFromWhitelistedPayload(): void
    {
        $handler = new OnePageCheckoutAddressFormHandler($this->opcForm);

        $this->opcForm
            ->expects($this->never())
            ->method('fillFromAddress')
        ;
        $this->opcForm
            ->expects($this->once())
            ->method('fillWith')
            ->with([
                'id_country' => '8',
                'invoice_id_country' => '8',
                'use_same_address' => '1',
            ])
        ;
        $this->opcForm
            ->expects($this->once())
            ->method('getTemplateVariables')
            ->willReturn(['address_form' => '<form>ok</form>'])
        ;

        $response = $handler->getTemplateVariables([
            'id_country' => '8',
            'invoice_id_country' => '8',
            'use_same_address' => '1',
            'ignored_key' => 'ignored',
        ]);

        self::assertSame(['address_form' => '<form>ok</form>'], $response);
    }

    public function testItIgnoresNonPositiveIdAddressFromPayload(): void
    {
        $handler = new OnePageCheckoutAddressFormHandler($this->opcForm);

        $this->opcForm
            ->expects($this->never())
            ->method('fillFromAddress')
        ;
        $this->opcForm
            ->expects($this->once())
            ->method('fillWith')
            ->with([
                'id_country' => '8',
            ])
        ;
        $this->opcForm
            ->expects($this->once())
            ->method('getTemplateVariables')
            ->willReturn(['address_form' => '<form>country-only</form>'])
        ;

        $response = $handler->getTemplateVariables([
            'id_address' => '0',
            'id_country' => '8',
        ]);

        self::assertSame(['address_form' => '<form>country-only</form>'], $response);
    }

    public function testItDoesNotFillAddressOrFormWhenPayloadHasNoExpectedKeys(): void
    {
        $handler = new OnePageCheckoutAddressFormHandler($this->opcForm);

        $this->opcForm
            ->expects($this->never())
            ->method('fillFromAddress')
        ;
        $this->opcForm
            ->expects($this->never())
            ->method('fillWith')
        ;
        $this->opcForm
            ->expects($this->once())
            ->method('getTemplateVariables')
            ->willReturn(['address_form' => '<form>initial</form>'])
        ;

        $response = $handler->getTemplateVariables([
            'foo' => 'bar',
        ]);

        self::assertSame(['address_form' => '<form>initial</form>'], $response);
    }
}
