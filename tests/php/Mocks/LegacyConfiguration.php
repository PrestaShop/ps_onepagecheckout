<?php

namespace Tests\PHP\Mocks;

use PrestaShop\PrestaShop\Core\ConfigurationInterface;

class LegacyConfiguration implements ConfigurationInterface
{
    public function get($key)
    {
        return null;
    }

    public function set($key, $value)
    {
        return null;
    }
}
