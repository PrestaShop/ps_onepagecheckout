<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

require_once __DIR__ . '/OpcGuestInitHandlerIntegrationTestCase.php';

class OpcGuestInitHandlerRaceRecoveryIntegrationTest extends AbstractOpcGuestInitHandlerIntegrationTest
{
    public function testItAlignsCookieAndContextWithWinnerWhenConcurrentWinnerAlreadyClaimedCart(): void
    {
        $this->scenarioItAlignsCookieAndContextWithWinnerWhenConcurrentWinnerAlreadyClaimedCart();
    }

    public function testItReturnsErrorWhenCartClaimUpdateFails(): void
    {
        $this->scenarioItReturnsErrorWhenCartClaimUpdateFails();
    }

    public function testItReturnsErrorWhenRaceHasNoResolvableWinner(): void
    {
        $this->scenarioItReturnsErrorWhenRaceHasNoResolvableWinner();
    }

    public function testItReturnsErrorWhenCartOwnerReferenceIsStaleDuringGuestCreation(): void
    {
        $this->scenarioItReturnsErrorWhenCartOwnerReferenceIsStaleDuringGuestCreation();
    }

    public function testItReturnsErrorWhenCartOwnerReferenceIsStaleDuringGuestUpdate(): void
    {
        $this->scenarioItReturnsErrorWhenCartOwnerReferenceIsStaleDuringGuestUpdate();
    }
}
