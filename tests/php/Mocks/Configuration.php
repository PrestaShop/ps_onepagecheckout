<?php

if (!class_exists('Configuration', false)) {
    /**
     * Minimal in-memory configuration store for module unit tests.
     * This keeps unit tests independent from the shop and database configuration.
     */
    class Configuration
    {
        /** @var array<string, mixed> */
        public static array $values = [];

        public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            return self::$values[$key] ?? $default;
        }

        public static function updateValue($key, $values, $html = false, $idShopGroup = null, $idShop = null)
        {
            self::$values[$key] = $values;

            return true;
        }

        public static function updateGlobalValue($key, $values, $html = false)
        {
            self::$values[$key] = $values;

            return true;
        }

        public static function deleteByName($key)
        {
            unset(self::$values[$key]);

            return true;
        }

        public static function loadConfiguration()
        {
            return true;
        }
    }
}
