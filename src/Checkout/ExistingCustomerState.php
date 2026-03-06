<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\Module\PsOnePageCheckout\Checkout;

use Customer;

/**
 * Centralizes customer resolution to prevent customer data reloads.
 */
class ExistingCustomerState
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var \Customer|null
     */
    private $customer;

    private function __construct(int $id, ?\Customer $customer)
    {
        $this->id = $id;
        $this->customer = $customer;
    }

    public static function empty(): static
    {
        return new static(0, null);
    }

    public static function fromCustomer(\Customer $customer): static
    {
        return new static((int) $customer->id, $customer);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCustomer(): ?\Customer
    {
        return $this->customer;
    }

    public function hasCustomer(): bool
    {
        return \Validate::isLoadedObject($this->customer);
    }

    public function isGuestCustomer(): bool
    {
        return $this->hasCustomer() && $this->customer->isGuest();
    }
}
