<?php

declare(strict_types=1);

namespace PrestaShop\Module\PsOnePageCheckout\Checkout;

class PaymentSelectionKeyBuilder
{
    /**
     * @param array<int|string, mixed> $paymentOptions
     *
     * @return array<int|string, mixed>
     */
    public function enrichPaymentOptions(array $paymentOptions): array
    {
        foreach ($paymentOptions as $moduleName => $moduleOptions) {
            if (!is_array($moduleOptions)) {
                continue;
            }

            foreach ($moduleOptions as $optionIndex => $option) {
                if (!is_array($option)) {
                    continue;
                }

                $paymentOptions[$moduleName][$optionIndex]['selection_key'] = $this->buildSelectionKey($option);
            }
        }

        return $paymentOptions;
    }

    /**
     * Build a stable payment selection key from business-level option attributes.
     * `option.id` is intentionally excluded because it is only a render identifier.
     *
     * @param array<string, mixed> $option
     */
    public function buildSelectionKey(array $option): string
    {
        $moduleName = (string) ($option['module_name'] ?? '');
        $signature = [
            'module_name' => $moduleName,
            'action' => (string) ($option['action'] ?? ''),
            'binary' => !empty($option['binary']),
            'call_to_action_text' => $this->normalizeString((string) ($option['call_to_action_text'] ?? '')),
            'inputs' => $this->normalizeInputs($option['inputs'] ?? []),
        ];

        return sprintf(
            '%s:%s',
            $moduleName !== '' ? $moduleName : 'unknown',
            substr(hash('sha256', (string) json_encode($signature, JSON_INVALID_UTF8_SUBSTITUTE)), 0, 24)
        );
    }

    private function normalizeString(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?: '';
    }

    /**
     * @param mixed $inputs
     *
     * @return array<int, array<string, string>>
     */
    private function normalizeInputs($inputs): array
    {
        if (!is_array($inputs)) {
            return [];
        }

        $normalized = [];
        foreach ($inputs as $input) {
            if (!is_array($input)) {
                continue;
            }

            $normalized[] = [
                'name' => (string) ($input['name'] ?? ''),
                'type' => (string) ($input['type'] ?? ''),
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            return [$left['name'], $left['type']] <=> [$right['name'], $right['type']];
        });

        return $normalized;
    }
}
