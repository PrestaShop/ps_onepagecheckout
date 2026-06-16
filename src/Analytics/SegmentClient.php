<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email to
 * license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace PrestaShop\Module\PsOnePageCheckout\Analytics;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SegmentClient
{
    private const TRACK_ENDPOINT = 'https://api.segment.io/v1/track';
    private const TIMEOUT = 3.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $writeKey
    ) {
    }

    /**
     * Send a track event to Segment. Blocks until the response status is received.
     *
     * @param array<string, mixed> $properties
     */
    public function track(string $userId, string $event, array $properties): void
    {
        $response = $this->httpClient->request('POST', self::TRACK_ENDPOINT, [
            'auth_basic' => [$this->writeKey, ''],
            'json' => [
                'userId' => $userId,
                'event' => $event,
                'properties' => $properties,
            ],
            'timeout' => self::TIMEOUT,
        ]);

        // Consume the status code to ensure the request is actually sent before the process exits.
        $response->getStatusCode();
    }
}
