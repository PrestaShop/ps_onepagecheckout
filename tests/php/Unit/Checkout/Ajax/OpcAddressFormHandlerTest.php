<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Checkout\Ajax;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\CheckoutCustomerContextResolver;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutAddressFormHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use Tests\Fixtures\CheckoutTestFixtures;

class OpcAddressFormHandlerTest extends TestCase
{
    private OnePageCheckoutForm|MockObject $opcForm;

    protected function setUp(): void
    {
        $this->opcForm = $this->getMockBuilder(OnePageCheckoutForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fillFromCustomer', 'fillFromAddress', 'fillWith', 'getTemplateVariables'])
            ->getMock()
        ;

        $context = \Context::getContext();
        $context->language = CheckoutTestFixtures::language();
        $context->cart = CheckoutTestFixtures::cart();
        $context->customer = CheckoutTestFixtures::customer();
    }

    public function testItBuildsTemplateVariablesFromWhitelistedPayload(): void
    {
        $resolver = $this->createResolverReturning(null);
        $handler = new OnePageCheckoutAddressFormHandler($this->opcForm, \Context::getContext(), $resolver);

        $this->opcForm
            ->expects($this->never())
            ->method('fillFromCustomer')
        ;
        $this->opcForm
            ->expects($this->never())
            ->method('fillFromAddress')
        ;
        $this->opcForm
            ->expects($this->once())
            ->method('fillWith')
            ->with([
                'id_country' => '8',
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

        self::assertAddressSectionContext($response);
        self::assertSame('<form>ok</form>', $response['address_form']);
    }

    public function testItIgnoresNonPositiveDeliveryAddressIdFromPayload(): void
    {
        $resolver = $this->createResolverReturning(null);
        $handler = new OnePageCheckoutAddressFormHandler($this->opcForm, \Context::getContext(), $resolver);

        $this->opcForm
            ->expects($this->never())
            ->method('fillFromCustomer')
        ;
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
            'id_address_delivery' => '0',
            'id_country' => '8',
        ]);

        self::assertAddressSectionContext($response);
        self::assertSame('<form>country-only</form>', $response['address_form']);
    }

    public function testItDoesNotFillAddressOrFormWhenPayloadHasNoExpectedKeys(): void
    {
        $resolver = $this->createResolverReturning(null);
        $handler = new OnePageCheckoutAddressFormHandler($this->opcForm, \Context::getContext(), $resolver);

        $this->opcForm
            ->expects($this->never())
            ->method('fillFromCustomer')
        ;
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

        self::assertAddressSectionContext($response);
        self::assertSame('<form>initial</form>', $response['address_form']);
    }

    public function testItIgnoresInvoiceCountryWhenUseSameAddressIsEnabled(): void
    {
        $resolver = $this->createResolverReturning(null);
        $handler = new OnePageCheckoutAddressFormHandler($this->opcForm, \Context::getContext(), $resolver);

        $this->opcForm
            ->expects($this->never())
            ->method('fillFromCustomer')
        ;
        $this->opcForm
            ->expects($this->never())
            ->method('fillFromAddress')
        ;
        $this->opcForm
            ->expects($this->once())
            ->method('fillWith')
            ->with([
                'id_country' => '21',
                'use_same_address' => '1',
            ])
        ;
        $this->opcForm
            ->expects($this->once())
            ->method('getTemplateVariables')
            ->willReturn(['address_form' => '<form>same-address</form>'])
        ;

        $response = $handler->getTemplateVariables([
            'id_country' => '21',
            'invoice_id_country' => '8',
            'use_same_address' => '1',
        ]);

        self::assertAddressSectionContext($response);
        self::assertSame('<form>same-address</form>', $response['address_form']);
    }

    private function createResolverReturning(?\Customer $customer): CheckoutCustomerContextResolver
    {
        $resolver = $this->getMockBuilder(CheckoutCustomerContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn($customer);

        return $resolver;
    }

    /**
     * @param array<string,mixed> $response
     */
    private static function assertAddressSectionContext(array $response): void
    {
        self::assertArrayHasKey('is_virtual_cart', $response);
        self::assertArrayHasKey('cart', $response);
        self::assertIsArray($response['cart']);
        self::assertArrayHasKey('id_address_delivery', $response['cart']);
        self::assertArrayHasKey('id_address_invoice', $response['cart']);
    }
}
