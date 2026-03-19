<?php

if (!class_exists('Hook', false)) {
    /**
     * Deterministic hook stub for unit tests.
     */
    class Hook
    {
        /** @var array<string, mixed> */
        public static array $responses = [];

        public static function exec($hookName, $hookArgs = [], $idModule = null, $arrayReturn = false, $checkExceptions = true, $usePush = false, $idShop = null, $chain = false)
        {
            return self::$responses[$hookName] ?? ($arrayReturn ? [] : null);
        }
    }
}
