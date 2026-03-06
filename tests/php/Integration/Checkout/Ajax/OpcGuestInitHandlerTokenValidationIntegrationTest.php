<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

require_once __DIR__ . '/OpcGuestInitHandlerIntegrationTestCase.php';

class OpcGuestInitHandlerTokenValidationIntegrationTest extends AbstractOpcGuestInitHandlerIntegrationTest
{
    public function testItRejectsInvalidTokenBeforeAnyMutation(): void
    {
        $this->scenarioItRejectsInvalidTokenBeforeAnyMutation();
    }

    public function testItRejectsMissingTokenBeforeAnyMutation(): void
    {
        $this->scenarioItRejectsMissingTokenBeforeAnyMutation();
    }

    public function testItRejectsRequestWhenTokenIsInvalidEvenIfStaticTokenIsValid(): void
    {
        $this->scenarioItRejectsRequestWhenTokenIsInvalidEvenIfStaticTokenIsValid();
    }

    public function testItAcceptsStaticTokenWhenTokenIsAbsent(): void
    {
        $this->scenarioItAcceptsStaticTokenWhenTokenIsAbsent();
    }
}
