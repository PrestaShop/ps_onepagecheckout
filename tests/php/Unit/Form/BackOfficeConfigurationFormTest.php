<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Form;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\BackOfficeConfigurationForm;

class BackOfficeConfigurationFormTest extends TestCase
{
    public function testItPersistsConfigurationValueWhenEnabled(): void
    {
        $form = new SpyBackOfficeConfigurationForm($this->createMock(\Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');

        $form->callPersistConfigurationValue(1);

        self::assertSame([1], $form->updatedConfigurationValues);
    }

    public function testItPersistsConfigurationValueWhenDisabled(): void
    {
        $form = new SpyBackOfficeConfigurationForm($this->createMock(\Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');

        $form->callPersistConfigurationValue(0);

        self::assertSame([0], $form->updatedConfigurationValues);
    }

    public function testItLoadsCurrentConfigurationValue(): void
    {
        $form = new SpyBackOfficeConfigurationForm($this->createMock(\Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setNextReadValue(1);

        $value = $form->callGetCurrentConfigurationValue();

        self::assertSame(1, $value);
        self::assertSame(1, $form->readConfigurationCalls);
    }
}

class SpyBackOfficeConfigurationForm extends BackOfficeConfigurationForm
{
    /**
     * @var int[]
     */
    public array $updatedConfigurationValues = [];

    public int $readConfigurationCalls = 0;

    private int $nextReadValue = 0;

    public function setNextReadValue(int $value): void
    {
        $this->nextReadValue = $value;
    }

    public function callPersistConfigurationValue(int $value): void
    {
        $this->persistConfigurationValue($value);
    }

    public function callGetCurrentConfigurationValue(): int
    {
        return $this->getCurrentConfigurationValue();
    }

    protected function updateConfigurationValue(int $value): void
    {
        $this->updatedConfigurationValues[] = $value;
    }

    protected function readConfigurationValue(): int
    {
        ++$this->readConfigurationCalls;

        return $this->nextReadValue;
    }
}
