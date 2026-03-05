<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Form;

use Module;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnepagecheckout\Form\BackOfficeConfigurationForm;

class BackOfficeConfigurationFormTest extends TestCase
{
    public function testItPersistsConfigurationWithResolvedShopScope(): void
    {
        $form = new SpyBackOfficeConfigurationForm($this->createMock(Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setResolvedScope(2, 9);

        $form->callPersistConfigurationValue(1);

        self::assertSame([
            [
                'value' => 1,
                'id_shop_group' => 2,
                'id_shop' => 9,
            ],
        ], $form->updatedConfiguration);
    }

    public function testItPersistsConfigurationWithResolvedGroupScope(): void
    {
        $form = new SpyBackOfficeConfigurationForm($this->createMock(Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setResolvedScope(3, null);

        $form->callPersistConfigurationValue(0);

        self::assertSame([
            [
                'value' => 0,
                'id_shop_group' => 3,
                'id_shop' => null,
            ],
        ], $form->updatedConfiguration);
    }

    public function testItLoadsConfigurationWithResolvedScope(): void
    {
        $form = new SpyBackOfficeConfigurationForm($this->createMock(Module::class), 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setResolvedScope(null, null);
        $form->setNextReadValue(1);

        $value = $form->callGetCurrentConfigurationValue();

        self::assertSame(1, $value);
        self::assertSame([
            [
                'id_shop_group' => null,
                'id_shop' => null,
            ],
        ], $form->readConfiguration);
    }
}

class SpyBackOfficeConfigurationForm extends BackOfficeConfigurationForm
{
    /**
     * @var array<int, array{value: int, id_shop_group: int|null, id_shop: int|null}>
     */
    public array $updatedConfiguration = [];

    /**
     * @var array<int, array{id_shop_group: int|null, id_shop: int|null}>
     */
    public array $readConfiguration = [];

    private ?int $resolvedShopGroup = null;
    private ?int $resolvedShop = null;
    private int $nextReadValue = 0;

    public function setResolvedScope(?int $idShopGroup, ?int $idShop): void
    {
        $this->resolvedShopGroup = $idShopGroup;
        $this->resolvedShop = $idShop;
    }

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

    protected function resolveConfigurationScope(): array
    {
        return [$this->resolvedShopGroup, $this->resolvedShop];
    }

    protected function updateConfigurationValue(int $value, ?int $idShopGroup, ?int $idShop): void
    {
        $this->updatedConfiguration[] = [
            'value' => $value,
            'id_shop_group' => $idShopGroup,
            'id_shop' => $idShop,
        ];
    }

    protected function readConfigurationValue(?int $idShopGroup, ?int $idShop): int
    {
        $this->readConfiguration[] = [
            'id_shop_group' => $idShopGroup,
            'id_shop' => $idShop,
        ];

        return $this->nextReadValue;
    }
}
