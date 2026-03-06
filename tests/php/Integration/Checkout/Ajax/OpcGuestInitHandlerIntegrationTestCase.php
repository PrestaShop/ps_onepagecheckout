<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Checkout\Ajax;

use Cart;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutGuestInitHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\PrestaShop\Core\Crypto\Hashing;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Integration\Utility\ContextMockerTrait;
use Tests\Resources\DatabaseDump;

abstract class AbstractOpcGuestInitHandlerIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    private const EXPECTED_TOKEN = 'expected-token';

    /**
     * @var TranslatorInterface&MockObject
     */
    private $translator;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        self::resetTables();
        \Configuration::loadConfiguration();
        \Configuration::updateValue('PS_GUEST_CHECKOUT_ENABLED', true);

        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator
            ->method('trans')
            ->willReturnArgument(0)
        ;
    }

    private static function resetTables(): void
    {
        DatabaseDump::restoreTables([
            'configuration',
            'cart',
            'customer',
            'customer_group',
        ]);
    }

    protected function scenarioItRejectsInvalidTokenBeforeAnyMutation(): void
    {
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('invalid-token'),
            'token' => 'wrong-token',
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('token', $response['errors']);
        self::assertArrayNotHasKey('token', $response);
        self::assertArrayNotHasKey('static_token', $response);
    }

    protected function scenarioItRejectsMissingTokenBeforeAnyMutation(): void
    {
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('missing-token'),
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('token', $response['errors']);
        self::assertArrayNotHasKey('token', $response);
        self::assertArrayNotHasKey('static_token', $response);
    }

    protected function scenarioItRejectsRequestWhenTokenIsInvalidEvenIfStaticTokenIsValid(): void
    {
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('token-precedence'),
            'token' => 'wrong-token',
            'static_token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('token', $response['errors']);
        self::assertArrayNotHasKey('token', $response);
        self::assertArrayNotHasKey('static_token', $response);
    }

    protected function scenarioItReturnsErrorWhenOpcIsDisabled(): void
    {
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm, false);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('opc-disabled'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertSame(self::EXPECTED_TOKEN, $response['token']);
        self::assertSame(self::EXPECTED_TOKEN, $response['static_token']);
    }

    protected function scenarioItReturnsNoopWhenGuestCheckoutIsDisabled(): void
    {
        \Configuration::updateValue('PS_GUEST_CHECKOUT_ENABLED', false);
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('guest-checkout-disabled'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(0, $response['id_customer']);
        self::assertSame(self::EXPECTED_TOKEN, $response['token']);
        self::assertSame(self::EXPECTED_TOKEN, $response['static_token']);
    }

    protected function scenarioItReturnsNoopWhenCartIsNotLoaded(): void
    {
        $cart = new IntegrationEligibleCart();
        $cart->id = 0;
        $cart->productsCount = 1;
        self::getContext()->cart = $cart;
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('cart-not-loaded'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(0, $response['id_customer']);
    }

    protected function scenarioItReturnsNoopWhenCartIsEmpty(): void
    {
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();
        self::getContext()->cart->productsCount = 0;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('cart-empty'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(0, $response['id_customer']);
    }

    protected function scenarioItAcceptsStaticTokenWhenTokenIsAbsent(): void
    {
        $registeredCustomer = $this->createCustomer($this->uniqueEmail('registered-static-token'), false);
        $this->prepareEligibleCartContext((int) $registeredCustomer->id);
        self::getContext()->customer = $registeredCustomer;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $registeredCustomer->email,
            'static_token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertSame((int) $registeredCustomer->id, $response['id_customer']);
    }

    protected function scenarioItKeepsRegisteredCustomerAsCurrentOwner(): void
    {
        $registeredCustomer = $this->createCustomer($this->uniqueEmail('registered'), false);
        $this->prepareEligibleCartContext((int) $registeredCustomer->id);
        self::getContext()->customer = $registeredCustomer;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $registeredCustomer->email,
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertSame((int) $registeredCustomer->id, $response['id_customer']);
        self::assertFalse($response['customer_created']);
        self::assertSame(self::EXPECTED_TOKEN, $response['token']);
        self::assertSame(self::EXPECTED_TOKEN, $response['static_token']);
    }

    protected function scenarioItUsesFreshCartOwnerWhenContextCartSnapshotIsOutdated(): void
    {
        $winner = $this->createCustomer($this->uniqueEmail('fresh-cart-owner'), false);
        $persistedCart = $this->prepareEligibleCartContext(0);
        self::assertTrue(\Db::getInstance()->update(
            'cart',
            ['id_customer' => (int) $winner->id],
            '`id_cart` = ' . (int) $persistedCart->id
        ));
        self::getContext()->customer = new \Customer();
        self::getContext()->cart->id_customer = 0;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('fresh-cart-owner-submitted-email'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame((int) $winner->id, $response['id_customer']);
    }

    protected function scenarioItUsesContextCartOwnerWhenFreshCartRowIsMissing(): void
    {
        $winner = $this->createCustomer($this->uniqueEmail('missing-row-owner'), false);
        $persistedCart = $this->prepareEligibleCartContext((int) $winner->id);
        self::assertTrue(\Db::getInstance()->delete(
            'cart',
            '`id_cart` = ' . (int) $persistedCart->id
        ));
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('missing-row-submitted-email'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame((int) $winner->id, $response['id_customer']);
    }

    protected function scenarioItReturnsNoopWhenEmailBelongsToExistingAccountAndNoGuestIsLinked(): void
    {
        $registered = $this->createCustomer($this->uniqueEmail('existing-no-guest-linked'), false);
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => (string) $registered->email,
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame(0, $response['id_customer']);
    }

    protected function scenarioItCreatesGuestAndClaimsUnassignedCart(): void
    {
        $persistedCart = $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $createdGuest = $this->createCustomer($this->uniqueEmail('created-guest'), true);

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturnCallback(function () use ($createdGuest): bool {
                self::getContext()->customer = $createdGuest;

                return true;
            })
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('new-guest'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertSame((int) $createdGuest->id, $response['id_customer']);
        self::assertTrue($response['customer_created']);
        self::assertSame((int) $createdGuest->id, $this->getPersistedCartCustomerId((int) $persistedCart->id));
    }

    protected function scenarioItAlignsCookieAndContextWithWinnerWhenConcurrentWinnerAlreadyClaimedCart(): void
    {
        $winner = $this->createCustomer($this->uniqueEmail('winner-before-cas'), true);
        $persistedCart = $this->prepareEligibleCartContext(0);

        $context = self::getContext();
        $context->customer = new \Customer();
        unset($context->cookie->id_customer, $context->cookie->email, $context->cookie->is_guest);

        $customerPersister = new \CustomerPersister(
            $context,
            new Hashing(),
            $this->translator,
            true
        );

        $createdGuestId = 0;
        $createdGuestEmail = $this->uniqueEmail('created-before-cas');
        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturnCallback(function () use (
                $customerPersister,
                $createdGuestEmail,
                $winner,
                $persistedCart,
                &$createdGuestId
            ): bool {
                $createdGuest = new \Customer();
                $createdGuest->firstname = 'Guest';
                $createdGuest->lastname = 'Guest';
                $createdGuest->email = $createdGuestEmail;
                $createdGuest->is_guest = true;

                self::assertTrue($customerPersister->save($createdGuest, '', '', false));
                $createdGuestId = (int) $createdGuest->id;
                self::assertGreaterThan(0, $createdGuestId);

                // Simulate a concurrent request claiming cart ownership before CAS.
                self::assertTrue(\Db::getInstance()->update(
                    'cart',
                    ['id_customer' => (int) $winner->id],
                    '`id_cart` = ' . (int) $persistedCart->id
                ));

                return true;
            })
        ;

        $handler = $this->buildHandler($opcForm, true, $customerPersister);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('submitted-concurrent-winner'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame((int) $winner->id, $response['id_customer']);
        self::assertSame((int) $winner->id, $this->getPersistedCartCustomerId((int) $persistedCart->id));
        self::assertGreaterThan(0, $createdGuestId);
        self::assertNotSame((int) $winner->id, $createdGuestId);

        self::assertSame((int) $winner->id, (int) $context->customer->id);
        self::assertSame((int) $winner->id, (int) $context->cookie->id_customer);
        self::assertSame((string) $winner->email, (string) $context->cookie->email);
    }

    protected function scenarioItReturnsFormErrorsWhenSubmitGuestInitFails(): void
    {
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturn(false)
        ;
        $opcForm
            ->expects($this->once())
            ->method('getErrors')
            ->willReturn([
                'email' => ['Unable to save guest customer'],
            ])
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('submit-fail'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertSame(['Unable to save guest customer'], $response['errors']['email']);
        self::assertSame(self::EXPECTED_TOKEN, $response['token']);
        self::assertSame(self::EXPECTED_TOKEN, $response['static_token']);
    }

    protected function scenarioItUpdatesExistingGuestEmailWithoutCreatingNewCustomer(): void
    {
        $guest = $this->createCustomer($this->uniqueEmail('existing-guest'), true);
        $this->prepareEligibleCartContext((int) $guest->id);
        self::getContext()->customer = $guest;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);
        $updatedEmail = $this->uniqueEmail('updated-guest');

        $response = $handler->handle([
            'email' => $updatedEmail,
            'token' => self::EXPECTED_TOKEN,
        ]);

        $freshGuest = new \Customer((int) $guest->id);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame((int) $guest->id, $response['id_customer']);
        self::assertSame($updatedEmail, (string) $freshGuest->email);
    }

    protected function scenarioItUpdatesCartOwnerGuestAndRealignsContextWhenUpdatingEmailFromMismatchedContextCustomer(): void
    {
        $guestOwner = $this->createCustomer($this->uniqueEmail('guest-owner-mismatch'), true);
        $foreignGuest = $this->createCustomer($this->uniqueEmail('foreign-context-guest'), true);
        $persistedCart = $this->prepareEligibleCartContext((int) $guestOwner->id);

        $context = self::getContext();
        $context->customer = $foreignGuest;
        $context->cookie->id_customer = (int) $foreignGuest->id;
        $context->cookie->email = (string) $foreignGuest->email;
        $context->cookie->is_guest = true;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);
        $updatedEmail = $this->uniqueEmail('updated-owner-from-mismatch');

        $response = $handler->handle([
            'email' => $updatedEmail,
            'token' => self::EXPECTED_TOKEN,
        ]);

        $freshOwner = new \Customer((int) $guestOwner->id);
        $freshForeignGuest = new \Customer((int) $foreignGuest->id);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame((int) $guestOwner->id, $response['id_customer']);
        self::assertSame((int) $guestOwner->id, $this->getPersistedCartCustomerId((int) $persistedCart->id));
        self::assertSame($updatedEmail, (string) $freshOwner->email);
        self::assertNotSame($updatedEmail, (string) $freshForeignGuest->email);

        self::assertSame((int) $guestOwner->id, (int) $context->customer->id);
        self::assertSame((int) $guestOwner->id, (int) $context->cookie->id_customer);
        self::assertSame($updatedEmail, (string) $context->cookie->email);
    }

    protected function scenarioItReturnsNoopWhenGuestEmailIsAlreadySynced(): void
    {
        $guest = $this->createCustomer($this->uniqueEmail('already-synced-guest'), true);
        $this->prepareEligibleCartContext((int) $guest->id);
        self::getContext()->customer = $guest;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => (string) $guest->email,
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame((int) $guest->id, $response['id_customer']);
    }

    protected function scenarioItReturnsErrorWhenExistingGuestEmailUpdateFails(): void
    {
        $guest = $this->createCustomer($this->uniqueEmail('guest-update-fail'), true);
        $this->prepareEligibleCartContext((int) $guest->id);
        self::getContext()->customer = $guest;

        $customerPersister = $this->getMockBuilder(\CustomerPersister::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock()
        ;
        $customerPersister
            ->expects($this->once())
            ->method('save')
            ->willReturn(false)
        ;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm, true, $customerPersister);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('guest-update-fail-new'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('email', $response['errors']);
    }

    protected function scenarioItFallsBackToSubmitGuestInitWhenSubmittedEmailIsInvalidForExistingGuest(): void
    {
        $guest = $this->createCustomer($this->uniqueEmail('guest-invalid-email'), true);
        $this->prepareEligibleCartContext((int) $guest->id);
        self::getContext()->customer = $guest;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturn(false)
        ;
        $opcForm
            ->expects($this->once())
            ->method('getErrors')
            ->willReturn([
                'email' => ['Invalid email'],
            ])
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => 'not-an-email',
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertSame(['Invalid email'], $response['errors']['email']);
    }

    protected function scenarioItKeepsCurrentGuestWhenSubmittedEmailBelongsToAnotherCustomer(): void
    {
        $guest = $this->createCustomer($this->uniqueEmail('current-guest'), true);
        $registered = $this->createCustomer($this->uniqueEmail('existing-account'), false);
        $this->prepareEligibleCartContext((int) $guest->id);
        self::getContext()->customer = $guest;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => (string) $registered->email,
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame((int) $guest->id, $response['id_customer']);
    }

    protected function scenarioItReturnsCurrentOwnerWhenCartWasAlreadyClaimedBeforeGuestCreation(): void
    {
        $winner = $this->createCustomer($this->uniqueEmail('winner'), true);
        $this->prepareEligibleCartContext((int) $winner->id);
        self::getContext()->customer = new \Customer();

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('brand-new'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertTrue($response['success']);
        self::assertFalse($response['customer_created']);
        self::assertSame((int) $winner->id, $response['id_customer']);
    }

    protected function scenarioItReturnsErrorWhenCartClaimUpdateFails(): void
    {
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $createdGuest = $this->createCustomer($this->uniqueEmail('created-claim-fail'), true);

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturnCallback(function () use ($createdGuest): bool {
                self::getContext()->customer = $createdGuest;

                return true;
            })
        ;

        $handler = $this->buildHandler($opcForm);
        $handler->forceNextReplaceCartResult(-1);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('claim-fail'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertSame(self::EXPECTED_TOKEN, $response['token']);
        self::assertSame(self::EXPECTED_TOKEN, $response['static_token']);
    }

    protected function scenarioItKeepsGuestOwnerDuringConcurrentEmailUpdates(): void
    {
        $guest = $this->createCustomer($this->uniqueEmail('concurrent-email-owner'), true);
        $persistedCart = $this->prepareEligibleCartContext((int) $guest->id);
        self::getContext()->customer = $guest;

        $firstEmail = $this->uniqueEmail('concurrent-email-a');
        $secondEmail = $this->uniqueEmail('concurrent-email-b');

        [$firstResponse, $secondResponse] = $this->runConcurrentGuestEmailUpdateWorkers(
            (int) $persistedCart->id,
            (int) $guest->id,
            $firstEmail,
            $secondEmail
        );

        self::assertTrue(
            (bool) $firstResponse['success'],
            sprintf(
                'first=%s second=%s',
                json_encode($firstResponse) ?: 'invalid',
                json_encode($secondResponse) ?: 'invalid'
            )
        );
        self::assertTrue(
            (bool) $secondResponse['success'],
            sprintf(
                'first=%s second=%s',
                json_encode($firstResponse) ?: 'invalid',
                json_encode($secondResponse) ?: 'invalid'
            )
        );

        self::assertFalse((bool) $firstResponse['customer_created']);
        self::assertFalse((bool) $secondResponse['customer_created']);
        self::assertSame((int) $guest->id, (int) $firstResponse['id_customer']);
        self::assertSame((int) $guest->id, (int) $secondResponse['id_customer']);

        $freshGuest = new \Customer((int) $guest->id);
        self::assertContains((string) $freshGuest->email, [$firstEmail, $secondEmail]);
        self::assertSame((int) $guest->id, $this->getPersistedCartCustomerId((int) $persistedCart->id));
    }

    protected function scenarioItReturnsSingleWinnerWhenThreeConcurrentGuestCreationsCompete(): void
    {
        $persistedCart = $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $firstCreatedGuest = $this->createCustomer($this->uniqueEmail('created-first-three'), true);
        $secondCreatedGuest = $this->createCustomer($this->uniqueEmail('created-second-three'), true);
        $thirdCreatedGuest = $this->createCustomer($this->uniqueEmail('created-third-three'), true);

        $responses = $this->runConcurrentGuestInitWorkersBatch(
            (int) $persistedCart->id,
            [
                (int) $firstCreatedGuest->id,
                (int) $secondCreatedGuest->id,
                (int) $thirdCreatedGuest->id,
            ]
        );

        self::assertCount(3, $responses);

        $resolvedCustomerIds = [];
        $createdCount = 0;
        foreach ($responses as $response) {
            self::assertTrue((bool) $response['success'], json_encode($response) ?: 'invalid');
            $resolvedCustomerIds[] = (int) $response['id_customer'];
            $createdCount += (int) (bool) $response['customer_created'];
        }

        self::assertCount(1, array_unique($resolvedCustomerIds));
        $winnerId = $resolvedCustomerIds[0];
        self::assertContains(
            $winnerId,
            [
                (int) $firstCreatedGuest->id,
                (int) $secondCreatedGuest->id,
                (int) $thirdCreatedGuest->id,
            ]
        );
        self::assertSame(1, $createdCount);
        self::assertSame($winnerId, $this->getPersistedCartCustomerId((int) $persistedCart->id));
    }

    protected function scenarioItKeepsGuestOwnerDuringThreeConcurrentEmailUpdates(): void
    {
        $guest = $this->createCustomer($this->uniqueEmail('concurrent-email-owner-three'), true);
        $persistedCart = $this->prepareEligibleCartContext((int) $guest->id);
        self::getContext()->customer = $guest;

        $emails = [
            $this->uniqueEmail('concurrent-email-a-three'),
            $this->uniqueEmail('concurrent-email-b-three'),
            $this->uniqueEmail('concurrent-email-c-three'),
        ];

        $responses = $this->runConcurrentGuestEmailUpdateWorkersBatch(
            (int) $persistedCart->id,
            (int) $guest->id,
            $emails
        );

        self::assertCount(3, $responses);
        foreach ($responses as $response) {
            self::assertTrue((bool) $response['success'], json_encode($response) ?: 'invalid');
            self::assertFalse((bool) $response['customer_created']);
            self::assertSame((int) $guest->id, (int) $response['id_customer']);
        }

        $freshGuest = new \Customer((int) $guest->id);
        self::assertContains((string) $freshGuest->email, $emails);
        self::assertSame((int) $guest->id, $this->getPersistedCartCustomerId((int) $persistedCart->id));
    }

    protected function scenarioItReturnsCurrentOwnerWithThreeConcurrentRequestsWhenCartAlreadyClaimed(): void
    {
        $owner = $this->createCustomer($this->uniqueEmail('concurrent-claimed-owner'), false);
        $persistedCart = $this->prepareEligibleCartContext((int) $owner->id);
        self::getContext()->customer = new \Customer();

        $firstCreatedGuest = $this->createCustomer($this->uniqueEmail('concurrent-claimed-created-first'), true);
        $secondCreatedGuest = $this->createCustomer($this->uniqueEmail('concurrent-claimed-created-second'), true);
        $thirdCreatedGuest = $this->createCustomer($this->uniqueEmail('concurrent-claimed-created-third'), true);

        $responses = $this->runConcurrentGuestInitWorkersBatch(
            (int) $persistedCart->id,
            [
                (int) $firstCreatedGuest->id,
                (int) $secondCreatedGuest->id,
                (int) $thirdCreatedGuest->id,
            ]
        );

        self::assertCount(3, $responses);
        foreach ($responses as $response) {
            self::assertTrue((bool) $response['success'], json_encode($response) ?: 'invalid');
            self::assertFalse((bool) $response['customer_created']);
            self::assertSame((int) $owner->id, (int) $response['id_customer']);
        }

        self::assertSame((int) $owner->id, $this->getPersistedCartCustomerId((int) $persistedCart->id));
    }

    protected function scenarioItReturnsSingleWinnerWhenTenConcurrentGuestCreationsCompete(): void
    {
        $persistedCart = $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $createdCustomerIds = [];
        for ($i = 0; $i < 10; ++$i) {
            $createdCustomerIds[] = (int) $this->createCustomer($this->uniqueEmail(sprintf('created-ten-%d', $i)), true)->id;
        }

        $responses = $this->runConcurrentGuestInitWorkersBatch(
            (int) $persistedCart->id,
            $createdCustomerIds
        );

        self::assertCount(10, $responses);

        $resolvedCustomerIds = [];
        $createdCount = 0;
        foreach ($responses as $response) {
            self::assertTrue((bool) $response['success'], json_encode($response) ?: 'invalid');
            $resolvedCustomerIds[] = (int) $response['id_customer'];
            $createdCount += (int) (bool) $response['customer_created'];
        }

        self::assertCount(1, array_unique($resolvedCustomerIds));
        $winnerId = $resolvedCustomerIds[0];
        self::assertContains($winnerId, $createdCustomerIds);
        self::assertSame(1, $createdCount);
        self::assertSame($winnerId, $this->getPersistedCartCustomerId((int) $persistedCart->id));
    }

    protected function scenarioItResolvesWinnerWhenCreationAndGuestEmailUpdateRaceTogether(): void
    {
        $persistedCart = $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $guestUpdater = $this->createCustomer($this->uniqueEmail('mixed-existing-guest'), true);
        $guestUpdaterOriginalEmail = (string) $guestUpdater->email;
        $createdCustomerIds = [
            (int) $this->createCustomer($this->uniqueEmail('mixed-created-a'), true)->id,
            (int) $this->createCustomer($this->uniqueEmail('mixed-created-b'), true)->id,
            (int) $this->createCustomer($this->uniqueEmail('mixed-created-c'), true)->id,
        ];
        $updateEmails = [
            $this->uniqueEmail('mixed-update-a'),
            $this->uniqueEmail('mixed-update-b'),
        ];

        [$createResponses, $updateResponses] = $this->runMixedConcurrentGuestInitAndEmailUpdateWorkers(
            (int) $persistedCart->id,
            $createdCustomerIds,
            (int) $guestUpdater->id,
            $updateEmails
        );

        self::assertCount(3, $createResponses);
        self::assertCount(2, $updateResponses);

        $allResponses = array_merge($createResponses, $updateResponses);
        $resolvedCustomerIds = [];
        foreach ($allResponses as $response) {
            self::assertTrue((bool) $response['success'], json_encode($response) ?: 'invalid');
            $resolvedCustomerIds[] = (int) $response['id_customer'];
        }

        self::assertCount(1, array_unique($resolvedCustomerIds));
        $winnerId = $resolvedCustomerIds[0];
        self::assertContains($winnerId, array_merge($createdCustomerIds, [(int) $guestUpdater->id]));
        self::assertSame($winnerId, $this->getPersistedCartCustomerId((int) $persistedCart->id));

        $createWinnerCount = 0;
        foreach ($createResponses as $response) {
            $createWinnerCount += (int) (bool) $response['customer_created'];
        }
        if (in_array($winnerId, $createdCustomerIds, true)) {
            self::assertSame(1, $createWinnerCount);
        } else {
            self::assertSame(0, $createWinnerCount);
        }

        foreach ($updateResponses as $response) {
            self::assertFalse((bool) $response['customer_created']);
        }

        $freshGuestUpdater = new \Customer((int) $guestUpdater->id);
        self::assertContains(
            (string) $freshGuestUpdater->email,
            array_merge([$guestUpdaterOriginalEmail], $updateEmails)
        );
    }

    protected function scenarioItHandlesFiveConcurrentRequestsUsingRealSubmitGuestInitFlow(): void
    {
        $persistedCart = $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $emails = [];
        for ($i = 0; $i < 5; ++$i) {
            $emails[] = $this->uniqueEmail(sprintf('real-submit-%d', $i));
        }

        $responses = $this->runConcurrentRealSubmitGuestInitWorkersBatch(
            (int) $persistedCart->id,
            $emails
        );

        self::assertCount(5, $responses);

        $resolvedCustomerIds = [];
        $createdCount = 0;
        foreach ($responses as $response) {
            self::assertTrue((bool) $response['success'], json_encode($response) ?: 'invalid');
            $resolvedCustomerIds[] = (int) $response['id_customer'];
            $createdCount += (int) (bool) $response['customer_created'];
        }

        self::assertCount(1, array_unique($resolvedCustomerIds));
        $winnerId = $resolvedCustomerIds[0];
        self::assertGreaterThan(0, $winnerId);
        self::assertSame($winnerId, $this->getPersistedCartCustomerId((int) $persistedCart->id));
        self::assertSame(1, $createdCount);

        $winner = new \Customer($winnerId);
        self::assertTrue((bool) $winner->id);
        self::assertTrue((bool) $winner->is_guest);
    }

    protected function scenarioItKeepsCookieAndContextAlignedWithResolvedOwnerDuringRealConcurrentGuestInit(): void
    {
        $persistedCart = $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $emails = [
            $this->uniqueEmail('real-submit-diagnostic-a'),
            $this->uniqueEmail('real-submit-diagnostic-b'),
        ];

        $diagnostics = $this->runConcurrentRealSubmitGuestInitDiagnosticWorkersBatch(
            (int) $persistedCart->id,
            $emails
        );

        self::assertCount(2, $diagnostics);

        $resolvedCustomerIds = [];
        $createdCount = 0;
        $reboundWorkers = 0;

        foreach ($diagnostics as $diagnostic) {
            self::assertArrayHasKey('response', $diagnostic, json_encode($diagnostic) ?: 'invalid');
            self::assertIsArray($diagnostic['response'], json_encode($diagnostic) ?: 'invalid');

            /** @var array<string, mixed> $response */
            $response = $diagnostic['response'];
            self::assertTrue((bool) $response['success'], json_encode($diagnostic) ?: 'invalid');

            $resolvedCustomerIds[] = (int) $response['id_customer'];
            $createdCount += (int) (bool) $response['customer_created'];

            self::assertGreaterThan(0, (int) $diagnostic['created_customer_id']);
            self::assertSame((int) $response['id_customer'], (int) $diagnostic['cart_owner_after']);
            self::assertSame((int) $response['id_customer'], (int) $diagnostic['cookie_id_customer']);
            self::assertSame((int) $response['id_customer'], (int) $diagnostic['context_customer_id']);

            if ((int) $diagnostic['created_customer_id'] !== (int) $response['id_customer']) {
                ++$reboundWorkers;
            }
        }

        self::assertCount(1, array_unique($resolvedCustomerIds));
        $winnerId = $resolvedCustomerIds[0];
        self::assertGreaterThan(0, $winnerId);
        self::assertSame($winnerId, $this->getPersistedCartCustomerId((int) $persistedCart->id));
        self::assertSame(1, $createdCount);
        self::assertGreaterThanOrEqual(1, $reboundWorkers);
    }

    protected function scenarioItKeepsCheckoutIdentityAlignedDuringConcurrentGuestEmailUpdatesWithMismatchedContexts(): void
    {
        $cartOwnerGuest = $this->createCustomer($this->uniqueEmail('diag-owner-guest'), true);
        $foreignContextGuest = $this->createCustomer($this->uniqueEmail('diag-foreign-guest'), true);
        $foreignContextGuestOriginalEmail = (string) $foreignContextGuest->email;
        $persistedCart = $this->prepareEligibleCartContext((int) $cartOwnerGuest->id);
        self::getContext()->customer = new \Customer();

        $emailsByContext = [
            (int) $cartOwnerGuest->id => $this->uniqueEmail('diag-email-owner'),
            (int) $foreignContextGuest->id => $this->uniqueEmail('diag-email-foreign'),
        ];

        $requests = [
            [
                'context_customer_id' => (int) $cartOwnerGuest->id,
                'email' => $emailsByContext[(int) $cartOwnerGuest->id],
            ],
            [
                'context_customer_id' => (int) $foreignContextGuest->id,
                'email' => $emailsByContext[(int) $foreignContextGuest->id],
            ],
        ];

        $diagnostics = $this->runConcurrentGuestEmailUpdateDiagnosticWorkersBatch(
            (int) $persistedCart->id,
            (int) $cartOwnerGuest->id,
            $requests
        );

        self::assertCount(2, $diagnostics);

        foreach ($diagnostics as $diagnostic) {
            self::assertArrayHasKey('response', $diagnostic, json_encode($diagnostic) ?: 'invalid');
            self::assertIsArray($diagnostic['response'], json_encode($diagnostic) ?: 'invalid');

            /** @var array<string, mixed> $response */
            $response = $diagnostic['response'];
            self::assertTrue((bool) $response['success'], json_encode($diagnostic) ?: 'invalid');
            self::assertSame((int) $cartOwnerGuest->id, (int) $response['id_customer']);
            self::assertSame((int) $cartOwnerGuest->id, (int) $diagnostic['cart_owner_after']);
            self::assertSame((int) $cartOwnerGuest->id, (int) $diagnostic['context_customer_id']);
            self::assertSame((int) $cartOwnerGuest->id, (int) $diagnostic['cookie_id_customer']);

            if ((int) $diagnostic['request_context_customer_id'] === (int) $foreignContextGuest->id) {
                self::assertSame($foreignContextGuestOriginalEmail, (string) $diagnostic['context_customer_email']);
                self::assertNotSame((string) $diagnostic['submitted_email'], (string) $diagnostic['context_customer_email']);
            }
        }

        self::assertSame((int) $cartOwnerGuest->id, $this->getPersistedCartCustomerId((int) $persistedCart->id));
        self::assertContains(
            (string) (new \Customer((int) $cartOwnerGuest->id))->email,
            array_values($emailsByContext)
        );
        self::assertSame(
            $foreignContextGuestOriginalEmail,
            (string) (new \Customer((int) $foreignContextGuest->id))->email
        );
    }

    protected function scenarioItReturnsConcurrentWinnerWhenCartIsClaimedDuringGuestCreation(): void
    {
        $persistedCart = $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $firstCreatedGuest = $this->createCustomer($this->uniqueEmail('created-first'), true);
        $secondCreatedGuest = $this->createCustomer($this->uniqueEmail('created-second'), true);

        [$firstResponse, $secondResponse] = $this->runConcurrentGuestInitWorkers(
            (int) $persistedCart->id,
            (int) $firstCreatedGuest->id,
            (int) $secondCreatedGuest->id
        );

        $firstResolvedCustomerId = (int) $firstResponse['id_customer'];
        $secondResolvedCustomerId = (int) $secondResponse['id_customer'];

        self::assertTrue(
            (bool) $firstResponse['success'],
            sprintf(
                'first=%s second=%s',
                json_encode($firstResponse) ?: 'invalid',
                json_encode($secondResponse) ?: 'invalid'
            )
        );
        self::assertTrue(
            (bool) $secondResponse['success'],
            sprintf(
                'first=%s second=%s',
                json_encode($firstResponse) ?: 'invalid',
                json_encode($secondResponse) ?: 'invalid'
            )
        );
        self::assertSame($firstResolvedCustomerId, $secondResolvedCustomerId);
        self::assertContains(
            $firstResolvedCustomerId,
            [(int) $firstCreatedGuest->id, (int) $secondCreatedGuest->id]
        );

        $createdCount = (int) (bool) $firstResponse['customer_created'] + (int) (bool) $secondResponse['customer_created'];
        self::assertSame(1, $createdCount);
        self::assertSame($firstResolvedCustomerId, $this->getPersistedCartCustomerId((int) $persistedCart->id));
    }

    protected function scenarioItReturnsErrorWhenRaceHasNoResolvableWinner(): void
    {
        $this->prepareEligibleCartContext(0);
        self::getContext()->customer = new \Customer();

        $createdGuest = $this->createCustomer($this->uniqueEmail('created-no-winner'), true);

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturnCallback(function () use ($createdGuest): bool {
                self::getContext()->customer = $createdGuest;

                return true;
            })
        ;

        $handler = $this->buildHandler($opcForm);
        $handler->forceNextReplaceCartResult(0);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('no-winner'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
    }

    protected function scenarioItReturnsErrorWhenCartOwnerReferenceIsStaleDuringGuestCreation(): void
    {
        $this->prepareEligibleCartContext(999999);
        self::getContext()->customer = new \Customer();

        $createdGuest = $this->createCustomer($this->uniqueEmail('created-stale-owner'), true);

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->once())
            ->method('submitGuestInit')
            ->willReturnCallback(function () use ($createdGuest): bool {
                self::getContext()->customer = $createdGuest;

                return true;
            })
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('stale-owner-created-email'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertNotEmpty($response['errors']['']);
        self::assertSame(self::EXPECTED_TOKEN, $response['token']);
        self::assertSame(self::EXPECTED_TOKEN, $response['static_token']);
    }

    protected function scenarioItReturnsErrorWhenCartOwnerReferenceIsStaleDuringGuestUpdate(): void
    {
        $guest = $this->createCustomer($this->uniqueEmail('guest-stale'), true);
        $this->prepareEligibleCartContext(999999);
        self::getContext()->customer = $guest;

        $opcForm = $this->buildOpcFormMock();
        $opcForm
            ->expects($this->never())
            ->method('submitGuestInit')
        ;

        $handler = $this->buildHandler($opcForm);

        $response = $handler->handle([
            'email' => $this->uniqueEmail('guest-stale-updated'),
            'token' => self::EXPECTED_TOKEN,
        ]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('', $response['errors']);
        self::assertNotEmpty($response['errors']['']);
        self::assertSame(self::EXPECTED_TOKEN, $response['token']);
        self::assertSame(self::EXPECTED_TOKEN, $response['static_token']);
    }

    private function getPersistedCartCustomerId(int $cartId): int
    {
        return (int) \Db::getInstance()->getValue(sprintf(
            'SELECT `id_customer` FROM `%scart` WHERE `id_cart` = %d',
            _DB_PREFIX_,
            $cartId
        ));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function runConcurrentGuestInitWorkers(
        int $cartId,
        int $firstCreatedCustomerId,
        int $secondCreatedCustomerId,
    ): array {
        $responses = $this->runConcurrentGuestInitWorkersBatch(
            $cartId,
            [$firstCreatedCustomerId, $secondCreatedCustomerId]
        );

        return [$responses[0], $responses[1]];
    }

    /**
     * @param array<int, int> $createdCustomerIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentGuestInitWorkersBatch(
        int $cartId,
        array $createdCustomerIds,
    ): array {
        $workerCount = count($createdCustomerIds);
        self::assertGreaterThanOrEqual(2, $workerCount);
        self::assertLessThanOrEqual(26, $workerCount);

        $barrierId = uniqid('opc-guest-race-', true);
        self::cleanupConcurrentWorkerBarrier($barrierId);

        $workers = [];
        foreach (array_values($createdCustomerIds) as $index => $createdCustomerId) {
            self::assertGreaterThan(0, $createdCustomerId);
            $workerId = $this->buildWorkerId($index);
            $workers[] = $this->launchConcurrentWorker(
                $cartId,
                $createdCustomerId,
                $this->uniqueEmail(sprintf('concurrent-request-%s', $workerId)),
                $workerId,
                $barrierId,
                $workerCount
            );
        }

        try {
            $responses = [];
            foreach ($workers as $worker) {
                $responses[] = $this->waitForConcurrentWorker($worker);
            }
        } finally {
            self::cleanupConcurrentWorkerBarrier($barrierId);
        }

        return $responses;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function runConcurrentGuestEmailUpdateWorkers(
        int $cartId,
        int $guestId,
        string $firstEmail,
        string $secondEmail,
    ): array {
        $responses = $this->runConcurrentGuestEmailUpdateWorkersBatch(
            $cartId,
            $guestId,
            [$firstEmail, $secondEmail]
        );

        return [$responses[0], $responses[1]];
    }

    /**
     * @param array<int, string> $emails
     *
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentGuestEmailUpdateWorkersBatch(
        int $cartId,
        int $guestId,
        array $emails,
    ): array {
        $workerCount = count($emails);
        self::assertGreaterThanOrEqual(2, $workerCount);
        self::assertLessThanOrEqual(26, $workerCount);

        $barrierId = uniqid('opc-email-race-', true);
        self::cleanupConcurrentWorkerBarrier($barrierId);

        $workers = [];
        foreach (array_values($emails) as $index => $email) {
            $workerId = $this->buildWorkerId($index);
            $workers[] = $this->launchConcurrentEmailUpdateWorker(
                $cartId,
                $guestId,
                $email,
                $workerId,
                $barrierId,
                $workerCount
            );
        }

        try {
            $responses = [];
            foreach ($workers as $worker) {
                $responses[] = $this->waitForConcurrentWorker($worker);
            }
        } finally {
            self::cleanupConcurrentWorkerBarrier($barrierId);
        }

        return $responses;
    }

    /**
     * @param array<int, array{context_customer_id: int, email: string}> $requests
     *
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentGuestEmailUpdateDiagnosticWorkersBatch(
        int $cartId,
        int $cartOwnerGuestId,
        array $requests,
    ): array {
        $workerCount = count($requests);
        self::assertGreaterThanOrEqual(2, $workerCount);
        self::assertLessThanOrEqual(26, $workerCount);

        $barrierId = uniqid('opc-email-diagnostic-race-', true);
        self::cleanupConcurrentWorkerBarrier($barrierId);

        $workers = [];
        foreach (array_values($requests) as $index => $request) {
            self::assertArrayHasKey('context_customer_id', $request);
            self::assertArrayHasKey('email', $request);
            self::assertGreaterThan(0, (int) $request['context_customer_id']);
            self::assertIsString($request['email']);

            $workerId = $this->buildWorkerId($index);
            $workers[] = $this->launchConcurrentEmailUpdateDiagnosticWorker(
                $cartId,
                $cartOwnerGuestId,
                (int) $request['context_customer_id'],
                (string) $request['email'],
                $workerId,
                $barrierId,
                $workerCount
            );
        }

        try {
            $responses = [];
            foreach ($workers as $worker) {
                $responses[] = $this->waitForConcurrentWorker($worker);
            }
        } finally {
            self::cleanupConcurrentWorkerBarrier($barrierId);
        }

        return $responses;
    }

    /**
     * @param array<int, int> $createdCustomerIds
     * @param array<int, string> $updateEmails
     *
     * @return array{
     *   0: array<int, array<string, mixed>>,
     *   1: array<int, array<string, mixed>>
     * }
     */
    private function runMixedConcurrentGuestInitAndEmailUpdateWorkers(
        int $cartId,
        array $createdCustomerIds,
        int $guestId,
        array $updateEmails,
    ): array {
        $createWorkerCount = count($createdCustomerIds);
        $updateWorkerCount = count($updateEmails);
        self::assertGreaterThanOrEqual(1, $createWorkerCount);
        self::assertGreaterThanOrEqual(1, $updateWorkerCount);
        $workerCount = $createWorkerCount + $updateWorkerCount;
        self::assertLessThanOrEqual(26, $workerCount);

        $barrierId = uniqid('opc-mixed-race-', true);
        self::cleanupConcurrentWorkerBarrier($barrierId);

        $workers = [];
        $workerIndex = 0;
        foreach (array_values($createdCustomerIds) as $createdCustomerId) {
            self::assertGreaterThan(0, $createdCustomerId);
            $workerId = $this->buildWorkerId($workerIndex);
            ++$workerIndex;
            $workers[] = [
                'kind' => 'create',
                'worker' => $this->launchConcurrentWorker(
                    $cartId,
                    $createdCustomerId,
                    $this->uniqueEmail(sprintf('mixed-create-%s', $workerId)),
                    $workerId,
                    $barrierId,
                    $workerCount
                ),
            ];
        }

        foreach (array_values($updateEmails) as $email) {
            $workerId = $this->buildWorkerId($workerIndex);
            ++$workerIndex;
            $workers[] = [
                'kind' => 'update',
                'worker' => $this->launchConcurrentEmailUpdateWorker(
                    $cartId,
                    $guestId,
                    $email,
                    $workerId,
                    $barrierId,
                    $workerCount
                ),
            ];
        }

        try {
            $createResponses = [];
            $updateResponses = [];
            foreach ($workers as $workerData) {
                $response = $this->waitForConcurrentWorker($workerData['worker']);
                if ($workerData['kind'] === 'create') {
                    $createResponses[] = $response;
                } else {
                    $updateResponses[] = $response;
                }
            }
        } finally {
            self::cleanupConcurrentWorkerBarrier($barrierId);
        }

        return [$createResponses, $updateResponses];
    }

    /**
     * @param array<int, string> $emails
     *
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentRealSubmitGuestInitWorkersBatch(
        int $cartId,
        array $emails,
    ): array {
        $workerCount = count($emails);
        self::assertGreaterThanOrEqual(2, $workerCount);
        self::assertLessThanOrEqual(26, $workerCount);

        $barrierId = uniqid('opc-real-submit-race-', true);
        self::cleanupConcurrentWorkerBarrier($barrierId);

        $workers = [];
        foreach (array_values($emails) as $index => $email) {
            $workerId = $this->buildWorkerId($index);
            $workers[] = $this->launchConcurrentRealSubmitGuestInitWorker(
                $cartId,
                $email,
                $workerId,
                $barrierId,
                $workerCount
            );
        }

        try {
            $responses = [];
            foreach ($workers as $worker) {
                $responses[] = $this->waitForConcurrentWorker($worker);
            }
        } finally {
            self::cleanupConcurrentWorkerBarrier($barrierId);
        }

        return $responses;
    }

    /**
     * @param array<int, string> $emails
     *
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentRealSubmitGuestInitDiagnosticWorkersBatch(
        int $cartId,
        array $emails,
    ): array {
        $workerCount = count($emails);
        self::assertGreaterThanOrEqual(2, $workerCount);
        self::assertLessThanOrEqual(26, $workerCount);

        $barrierId = uniqid('opc-real-submit-diagnostic-race-', true);
        self::cleanupConcurrentWorkerBarrier($barrierId);

        $workers = [];
        foreach (array_values($emails) as $index => $email) {
            $workerId = $this->buildWorkerId($index);
            $workers[] = $this->launchConcurrentRealSubmitGuestInitDiagnosticWorker(
                $cartId,
                $email,
                $workerId,
                $barrierId,
                $workerCount
            );
        }

        try {
            $responses = [];
            foreach ($workers as $worker) {
                $responses[] = $this->waitForConcurrentWorker($worker);
            }
        } finally {
            self::cleanupConcurrentWorkerBarrier($barrierId);
        }

        return $responses;
    }

    /**
     * @return array{
     *   process: resource,
     *   stdout: resource,
     *   stderr: resource,
     *   command: string
     * }
     */
    private function launchConcurrentWorkerProcess(string $workerScriptPath, array $arguments): array
    {
        $commandParts = [
            escapeshellarg((string) PHP_BINARY),
            escapeshellarg($workerScriptPath),
        ];

        foreach ($arguments as $argument) {
            $commandParts[] = escapeshellarg((string) $argument);
        }

        $command = implode(' ', $commandParts);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, _PS_ROOT_DIR_);

        self::assertIsResource($process, sprintf('Unable to launch concurrent worker: %s', $command));
        fclose($pipes[0]);

        return [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'command' => $command,
        ];
    }

    /**
     * @return array{
     *   process: resource,
     *   stdout: resource,
     *   stderr: resource,
     *   command: string
     * }
     */
    private function launchConcurrentWorker(
        int $cartId,
        int $createdCustomerId,
        string $email,
        string $workerId,
        string $barrierId,
        int $workersCount,
    ): array {
        return $this->launchConcurrentWorkerProcess(
            __DIR__ . '/fixtures/CheckoutGuestInitConcurrentWorker.php',
            [
                $cartId,
                $createdCustomerId,
                $email,
                self::EXPECTED_TOKEN,
                $barrierId,
                $workerId,
                $workersCount,
            ]
        );
    }

    /**
     * @return array{
     *   process: resource,
     *   stdout: resource,
     *   stderr: resource,
     *   command: string
     * }
     */
    private function launchConcurrentEmailUpdateWorker(
        int $cartId,
        int $guestId,
        string $email,
        string $workerId,
        string $barrierId,
        int $workersCount,
    ): array {
        return $this->launchConcurrentWorkerProcess(
            __DIR__ . '/fixtures/CheckoutGuestEmailUpdateConcurrentWorker.php',
            [
                $cartId,
                $guestId,
                $email,
                self::EXPECTED_TOKEN,
                $barrierId,
                $workerId,
                $workersCount,
            ]
        );
    }

    /**
     * @return array{
     *   process: resource,
     *   stdout: resource,
     *   stderr: resource,
     *   command: string
     * }
     */
    private function launchConcurrentRealSubmitGuestInitWorker(
        int $cartId,
        string $email,
        string $workerId,
        string $barrierId,
        int $workersCount,
    ): array {
        return $this->launchConcurrentWorkerProcess(
            __DIR__ . '/fixtures/CheckoutGuestInitRealSubmitConcurrentWorker.php',
            [
                $cartId,
                $email,
                self::EXPECTED_TOKEN,
                $barrierId,
                $workerId,
                $workersCount,
            ]
        );
    }

    /**
     * @return array{
     *   process: resource,
     *   stdout: resource,
     *   stderr: resource,
     *   command: string
     * }
     */
    private function launchConcurrentRealSubmitGuestInitDiagnosticWorker(
        int $cartId,
        string $email,
        string $workerId,
        string $barrierId,
        int $workersCount,
    ): array {
        return $this->launchConcurrentWorkerProcess(
            __DIR__ . '/fixtures/CheckoutGuestInitRealSubmitDiagnosticConcurrentWorker.php',
            [
                $cartId,
                $email,
                self::EXPECTED_TOKEN,
                $barrierId,
                $workerId,
                $workersCount,
            ]
        );
    }

    /**
     * @return array{
     *   process: resource,
     *   stdout: resource,
     *   stderr: resource,
     *   command: string
     * }
     */
    private function launchConcurrentEmailUpdateDiagnosticWorker(
        int $cartId,
        int $cartOwnerGuestId,
        int $contextCustomerId,
        string $email,
        string $workerId,
        string $barrierId,
        int $workersCount,
    ): array {
        return $this->launchConcurrentWorkerProcess(
            __DIR__ . '/fixtures/CheckoutGuestEmailUpdateDiagnosticConcurrentWorker.php',
            [
                $cartId,
                $cartOwnerGuestId,
                $contextCustomerId,
                $email,
                self::EXPECTED_TOKEN,
                $barrierId,
                $workerId,
                $workersCount,
            ]
        );
    }

    /**
     * @param array{
     *   process: resource,
     *   stdout: resource,
     *   stderr: resource,
     *   command: string
     * } $worker
     *
     * @return array<string, mixed>
     */
    private function waitForConcurrentWorker(array $worker): array
    {
        $startedAt = microtime(true);
        $timeoutSeconds = 15;
        $lastStatus = null;

        while (true) {
            $status = proc_get_status($worker['process']);
            $lastStatus = $status;
            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $startedAt) > $timeoutSeconds) {
                proc_terminate($worker['process']);
                self::fail(sprintf('Concurrent worker timed out: %s', $worker['command']));
            }

            usleep(20000);
        }

        $stdout = trim((string) stream_get_contents($worker['stdout']));
        $stderr = trim((string) stream_get_contents($worker['stderr']));
        fclose($worker['stdout']);
        fclose($worker['stderr']);

        $exitCode = proc_close($worker['process']);
        if ($exitCode === -1 && is_array($lastStatus) && isset($lastStatus['exitcode']) && $lastStatus['exitcode'] !== -1) {
            $exitCode = (int) $lastStatus['exitcode'];
        }
        self::assertSame(
            0,
            $exitCode,
            sprintf(
                "Concurrent worker failed (exit=%d): %s\nSTDOUT:\n%s\nSTDERR:\n%s",
                $exitCode,
                $worker['command'],
                $stdout,
                $stderr
            )
        );

        $jsonPayload = $stdout;
        if (str_contains($stdout, PHP_EOL)) {
            $lines = array_filter(array_map('trim', explode(PHP_EOL, $stdout)), static function (string $line): bool {
                return $line !== '';
            });
            $jsonPayload = (string) end($lines);
        }

        $decoded = json_decode($jsonPayload, true);
        self::assertIsArray(
            $decoded,
            sprintf("Concurrent worker did not return JSON.\nSTDOUT:\n%s\nSTDERR:\n%s", $stdout, $stderr)
        );

        return $decoded;
    }

    private function buildWorkerId(int $index): string
    {
        self::assertGreaterThanOrEqual(0, $index);
        self::assertLessThan(26, $index);

        return chr(ord('a') + $index);
    }

    private static function cleanupConcurrentWorkerBarrier(string $barrierId): void
    {
        $pattern = sprintf('%s/opc_guest_init_barrier_%s.*.ready', sys_get_temp_dir(), $barrierId);
        $barrierFiles = glob($pattern);
        if ($barrierFiles === false) {
            return;
        }

        foreach ($barrierFiles as $barrierFile) {
            @unlink($barrierFile);
        }
    }

    /**
     * @return OnePageCheckoutForm&MockObject
     */
    private function buildOpcFormMock(): OnePageCheckoutForm
    {
        return $this->getMockBuilder(OnePageCheckoutForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['submitGuestInit', 'getErrors'])
            ->getMock()
        ;
    }

    private function buildHandler(
        OnePageCheckoutForm $opcForm,
        bool $isOpcEnabled = true,
        ?\CustomerPersister $customerPersister = null,
    ): IntegrationCheckoutGuestInitHandler {
        if ($customerPersister === null) {
            $customerPersister = new \CustomerPersister(
                self::getContext(),
                new Hashing(),
                $this->translator,
                true
            );
        }

        $handler = new IntegrationCheckoutGuestInitHandler(
            self::getContext(),
            $opcForm,
            $this->translator,
            $customerPersister,
            $isOpcEnabled
        );
        $handler->setExpectedToken(self::EXPECTED_TOKEN);

        return $handler;
    }

    private function createCustomer(string $email, bool $isGuest): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = 'Integration';
        $customer->lastname = 'Customer';
        $customer->email = $email;
        $customer->is_guest = $isGuest;
        $customer->passwd = \Tools::hash('integration-password');

        self::assertTrue($customer->save());

        return $customer;
    }

    private function prepareEligibleCartContext(int $ownerCustomerId): \Cart
    {
        $context = self::getContext();

        $persistedCart = new \Cart();
        $persistedCart->id_customer = $ownerCustomerId;
        $persistedCart->id_currency = (int) $context->currency->id;
        $persistedCart->id_lang = (int) $context->language->id;
        $persistedCart->id_shop = (int) $context->shop->id;
        $persistedCart->id_shop_group = (int) $context->shop->id_shop_group;

        self::assertTrue($persistedCart->save());

        $contextCart = new IntegrationEligibleCart();
        $contextCart->id = (int) $persistedCart->id;
        $contextCart->id_customer = $ownerCustomerId;
        $contextCart->productsCount = 1;
        $context->cart = $contextCart;

        return $persistedCart;
    }

    private function uniqueEmail(string $prefix): string
    {
        return sprintf('%s_%s@example.com', $prefix, uniqid('', true));
    }
}

class IntegrationCheckoutGuestInitHandler extends OnePageCheckoutGuestInitHandler
{
    private string $expectedToken = '';
    private ?int $forcedNextReplaceCartResult = null;

    public function setExpectedToken(string $expectedToken): void
    {
        $this->expectedToken = $expectedToken;
    }

    public function forceNextReplaceCartResult(int $result): void
    {
        $this->forcedNextReplaceCartResult = $result;
    }

    protected function getExpectedToken(): string
    {
        return $this->expectedToken;
    }

    protected function replaceCartCustomerIdIfMatches(int $cartId, int $expectedCustomerId, int $newCustomerId): int
    {
        if ($this->forcedNextReplaceCartResult !== null) {
            $forcedResult = $this->forcedNextReplaceCartResult;
            $this->forcedNextReplaceCartResult = null;

            return $forcedResult;
        }

        return parent::replaceCartCustomerIdIfMatches($cartId, $expectedCustomerId, $newCustomerId);
    }
}

class IntegrationEligibleCart extends \Cart
{
    public int $productsCount = 0;

    public function __construct()
    {
    }

    public function nbProducts($id_product = false)
    {
        return $this->productsCount;
    }

    public function update($nullValues = false)
    {
        return true;
    }
}
