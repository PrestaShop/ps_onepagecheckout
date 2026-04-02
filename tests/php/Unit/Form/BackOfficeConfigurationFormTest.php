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
    /**
     * @var array<string, mixed>
     */
    private array $backupPost = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupPost = $_POST;

        $context = \Context::getContext();
        $context->link = new class {
            public function getAdminLink(string $controller, bool $withToken = true, array $params = [], array $extraParams = []): string
            {
                return '/admin/index.php?controller=' . $controller;
            }
        };
    }

    protected function tearDown(): void
    {
        $_POST = $this->backupPost;
        \Shop::setContext(\Shop::CONTEXT_ALL);

        parent::tearDown();
    }

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

    public function testEnableMaintenanceModeIsCalledWhenRequested(): void
    {
        $form = new SpyBackOfficeConfigurationForm($this->createMock(\Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setMaintenanceModeResult(true);

        $result = $form->callEnableMaintenanceMode();

        self::assertTrue($result);
        self::assertSame(1, $form->enableMaintenanceModeCalls);
    }

    public function testEnableMaintenanceModeReturnsFalseOnFailure(): void
    {
        $form = new SpyBackOfficeConfigurationForm($this->createMock(\Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setMaintenanceModeResult(false);

        $result = $form->callEnableMaintenanceMode();

        self::assertFalse($result);
        self::assertSame(1, $form->enableMaintenanceModeCalls);
    }

    public function testItDoesNotPersistSubmittedValueOutsideSingleShopContext(): void
    {
        $_POST['submitPsOnePageCheckoutConfiguration'] = '1';
        $_POST['PS_ONE_PAGE_CHECKOUT_ENABLED'] = '1';
        \Shop::setContext(\Shop::CONTEXT_ALL);

        $form = new SpyBackOfficeConfigurationForm($this->createMock(\Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');

        $content = $form->renderBackOfficeConfiguration();

        self::assertSame('', $content);
        self::assertSame([], $form->updatedConfigurationValues);
        self::assertSame(1, $form->redirectCalls);
    }
}

class SpyBackOfficeConfigurationForm extends BackOfficeConfigurationForm
{
    /**
     * @var int[]
     */
    public array $updatedConfigurationValues = [];

    public int $readConfigurationCalls = 0;
    public int $redirectCalls = 0;

    public int $enableMaintenanceModeCalls = 0;

    private int $nextReadValue = 0;

    private bool $maintenanceModeResult = true;

    public function setNextReadValue(int $value): void
    {
        $this->nextReadValue = $value;
    }

    public function setMaintenanceModeResult(bool $result): void
    {
        $this->maintenanceModeResult = $result;
    }

    public function callPersistConfigurationValue(int $value): void
    {
        $this->persistConfigurationValue($value);
    }

    public function callGetCurrentConfigurationValue(): int
    {
        return $this->getCurrentConfigurationValue();
    }

    public function callEnableMaintenanceMode(): bool
    {
        return $this->enableMaintenanceMode();
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

    protected function enableMaintenanceMode(): bool
    {
        ++$this->enableMaintenanceModeCalls;

        return $this->maintenanceModeResult;
    }

    protected function redirectToConfigurationForm(): void
    {
        ++$this->redirectCalls;
    }
}
