<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

require_once __DIR__ . '/OpcGuestInitHandlerIntegrationTestCase.php';

class OpcGuestInitHandlerConcurrencyIntegrationTest extends AbstractOpcGuestInitHandlerIntegrationTest
{
    public function testItKeepsGuestOwnerDuringConcurrentEmailUpdates(): void
    {
        $this->scenarioItKeepsGuestOwnerDuringConcurrentEmailUpdates();
    }

    public function testItReturnsSingleWinnerWhenThreeConcurrentGuestCreationsCompete(): void
    {
        $this->scenarioItReturnsSingleWinnerWhenThreeConcurrentGuestCreationsCompete();
    }

    public function testItKeepsGuestOwnerDuringThreeConcurrentEmailUpdates(): void
    {
        $this->scenarioItKeepsGuestOwnerDuringThreeConcurrentEmailUpdates();
    }

    public function testItReturnsCurrentOwnerWithThreeConcurrentRequestsWhenCartAlreadyClaimed(): void
    {
        $this->scenarioItReturnsCurrentOwnerWithThreeConcurrentRequestsWhenCartAlreadyClaimed();
    }

    public function testItReturnsSingleWinnerWhenTenConcurrentGuestCreationsCompete(): void
    {
        $this->scenarioItReturnsSingleWinnerWhenTenConcurrentGuestCreationsCompete();
    }

    public function testItResolvesWinnerWhenCreationAndGuestEmailUpdateRaceTogether(): void
    {
        $this->scenarioItResolvesWinnerWhenCreationAndGuestEmailUpdateRaceTogether();
    }

    public function testItHandlesFiveConcurrentRequestsUsingRealSubmitGuestInitFlow(): void
    {
        $this->scenarioItHandlesFiveConcurrentRequestsUsingRealSubmitGuestInitFlow();
    }

    public function testItKeepsCookieAndContextAlignedWithResolvedOwnerDuringRealConcurrentGuestInit(): void
    {
        $this->scenarioItKeepsCookieAndContextAlignedWithResolvedOwnerDuringRealConcurrentGuestInit();
    }

    public function testItKeepsCheckoutIdentityAlignedDuringConcurrentGuestEmailUpdatesWithMismatchedContexts(): void
    {
        $this->scenarioItKeepsCheckoutIdentityAlignedDuringConcurrentGuestEmailUpdatesWithMismatchedContexts();
    }

    public function testItReturnsConcurrentWinnerWhenCartIsClaimedDuringGuestCreation(): void
    {
        $this->scenarioItReturnsConcurrentWinnerWhenCartIsClaimedDuringGuestCreation();
    }
}
