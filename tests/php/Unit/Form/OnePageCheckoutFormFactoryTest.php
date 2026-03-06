<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Form;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormatter;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutFormFactoryTest extends TestCase
{
    public function testItCreatesFormAndSetsOrderActionUrl(): void
    {
        $context = new class extends \Context {
            public function __construct()
            {
            }
        };
        $context->smarty = $this->getMockBuilder(\Smarty::class)->disableOriginalConstructor()->getMock();
        $context->language = new class extends \Language {
            public function __construct()
            {
            }
        };
        $context->country = new class extends \Country {
            public function __construct()
            {
            }
        };
        $context->link = new class {
            public function getPageLink(string $page, bool $ssl = false): string
            {
                return '/order';
            }
        };

        $module = $this->getMockBuilder(\Ps_Onepagecheckout::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTranslator'])
            ->getMock();
        $translator = $this->createMock(TranslatorInterface::class);
        $module->method('getTranslator')->willReturn($translator);

        $form = $this->getMockBuilder(OnePageCheckoutForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setAction'])
            ->getMock();
        $form
            ->expects($this->once())
            ->method('setAction')
            ->with('/order');

        $customerPersister = $this->getMockBuilder(\CustomerPersister::class)
            ->disableOriginalConstructor()
            ->getMock();
        $addressPersister = $this->getMockBuilder(\CustomerAddressPersister::class)
            ->disableOriginalConstructor()
            ->getMock();

        $factory = new SpyOnePageCheckoutFormFactory(
            $context,
            $module,
            $form,
            $customerPersister,
            $addressPersister
        );

        $result = $factory->create();

        self::assertSame($form, $result);
        self::assertSame([['id_country' => 8, 'name' => 'France']], $factory->capturedAvailableCountries);
    }
}

class SpyOnePageCheckoutFormFactory extends OnePageCheckoutFormFactory
{
    /**
     * @var array<int, mixed>
     */
    public array $capturedAvailableCountries = [];

    private OnePageCheckoutForm $form;
    private \CustomerPersister $customerPersister;
    private \CustomerAddressPersister $addressPersister;

    public function __construct(
        \Context $context,
        \Ps_Onepagecheckout $module,
        OnePageCheckoutForm $form,
        \CustomerPersister $customerPersister,
        \CustomerAddressPersister $addressPersister,
    ) {
        parent::__construct($context, $module);
        $this->form = $form;
        $this->customerPersister = $customerPersister;
        $this->addressPersister = $addressPersister;
    }

    /**
     * @return array<int, mixed>
     */
    protected function getAvailableCountries(): array
    {
        return [['id_country' => 8, 'name' => 'France']];
    }

    protected function createFormatter(array $availableCountries): OnePageCheckoutFormatter
    {
        $this->capturedAvailableCountries = $availableCountries;

        return new class extends OnePageCheckoutFormatter {
            public function __construct()
            {
            }
        };
    }

    protected function createFormInstance(
        OnePageCheckoutFormatter $formatter,
        \CustomerPersister $customerPersister,
        \CustomerAddressPersister $addressPersister,
    ): OnePageCheckoutForm {
        return $this->form;
    }

    public function createCustomerPersister(): \CustomerPersister
    {
        return $this->customerPersister;
    }

    public function createAddressPersister(): \CustomerAddressPersister
    {
        return $this->addressPersister;
    }
}
