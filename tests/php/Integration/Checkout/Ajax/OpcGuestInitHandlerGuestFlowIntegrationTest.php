<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

require_once __DIR__ . '/OpcGuestInitHandlerIntegrationTestCase.php';

class OpcGuestInitHandlerGuestFlowIntegrationTest extends AbstractOpcGuestInitHandlerIntegrationTest
{
    public function testItReturnsErrorWhenOpcIsDisabled(): void
    {
        $this->scenarioItReturnsErrorWhenOpcIsDisabled();
    }

    public function testItReturnsNoopWhenGuestCheckoutIsDisabled(): void
    {
        $this->scenarioItReturnsNoopWhenGuestCheckoutIsDisabled();
    }

    public function testItReturnsNoopWhenCartIsNotLoaded(): void
    {
        $this->scenarioItReturnsNoopWhenCartIsNotLoaded();
    }

    public function testItReturnsNoopWhenCartIsEmpty(): void
    {
        $this->scenarioItReturnsNoopWhenCartIsEmpty();
    }

    public function testItKeepsRegisteredCustomerAsCurrentOwner(): void
    {
        $this->scenarioItKeepsRegisteredCustomerAsCurrentOwner();
    }

    public function testItUsesFreshCartOwnerWhenContextCartSnapshotIsOutdated(): void
    {
        $this->scenarioItUsesFreshCartOwnerWhenContextCartSnapshotIsOutdated();
    }

    public function testItUsesContextCartOwnerWhenFreshCartRowIsMissing(): void
    {
        $this->scenarioItUsesContextCartOwnerWhenFreshCartRowIsMissing();
    }

    public function testItReturnsNoopWhenEmailBelongsToExistingAccountAndNoGuestIsLinked(): void
    {
        $this->scenarioItReturnsNoopWhenEmailBelongsToExistingAccountAndNoGuestIsLinked();
    }

    public function testItCreatesGuestAndClaimsUnassignedCart(): void
    {
        $this->scenarioItCreatesGuestAndClaimsUnassignedCart();
    }

    public function testItReturnsFormErrorsWhenSubmitGuestInitFails(): void
    {
        $this->scenarioItReturnsFormErrorsWhenSubmitGuestInitFails();
    }

    public function testItUpdatesExistingGuestEmailWithoutCreatingNewCustomer(): void
    {
        $this->scenarioItUpdatesExistingGuestEmailWithoutCreatingNewCustomer();
    }

    public function testItUpdatesCartOwnerGuestAndRealignsContextWhenUpdatingEmailFromMismatchedContextCustomer(): void
    {
        $this->scenarioItUpdatesCartOwnerGuestAndRealignsContextWhenUpdatingEmailFromMismatchedContextCustomer();
    }

    public function testItReturnsNoopWhenGuestEmailIsAlreadySynced(): void
    {
        $this->scenarioItReturnsNoopWhenGuestEmailIsAlreadySynced();
    }

    public function testItReturnsErrorWhenExistingGuestEmailUpdateFails(): void
    {
        $this->scenarioItReturnsErrorWhenExistingGuestEmailUpdateFails();
    }

    public function testItFallsBackToSubmitGuestInitWhenSubmittedEmailIsInvalidForExistingGuest(): void
    {
        $this->scenarioItFallsBackToSubmitGuestInitWhenSubmittedEmailIsInvalidForExistingGuest();
    }

    public function testItKeepsCurrentGuestWhenSubmittedEmailBelongsToAnotherCustomer(): void
    {
        $this->scenarioItKeepsCurrentGuestWhenSubmittedEmailBelongsToAnotherCustomer();
    }

    public function testItReturnsCurrentOwnerWhenCartWasAlreadyClaimedBeforeGuestCreation(): void
    {
        $this->scenarioItReturnsCurrentOwnerWhenCartWasAlreadyClaimedBeforeGuestCreation();
    }
}
