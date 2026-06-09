<?php

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

class OnePageCheckoutStatesHandler
{
    /**
     * @param array<string,mixed> $requestParameters
     *
     * @return array<string,mixed>
     */
    public function handle(array $requestParameters = []): array
    {
        $countryId = (int) ($requestParameters['id_country'] ?? 0);
        if ($countryId <= 0) {
            return [
                'success' => true,
                'id_country' => 0,
                'contains_states' => false,
                'states' => [],
            ];
        }

        $states = \State::getStatesByIdCountry($countryId);

        return [
            'success' => true,
            'id_country' => $countryId,
            'contains_states' => !empty($states),
            'states' => $states,
        ];
    }
}
