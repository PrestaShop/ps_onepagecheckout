<?php

if (!class_exists('PrestaShopLogger', false)) {
    /**
     * Log stub for unit tests.
     */
    class PrestaShopLogger
    {
        /** @var list<array{message: string, severity: int|null}> */
        public static array $logs = [];

        public static function addLog($message, $severity = null, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, $idEmployee = null)
        {
            self::$logs[] = [
                'message' => (string) $message,
                'severity' => $severity !== null ? (int) $severity : null,
            ];

            return true;
        }
    }
}
