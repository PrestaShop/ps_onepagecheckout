<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax;

use Cart;
use Configuration;
use Context;
use Customer;
use Db;
use PrestaShop\Module\PsOnePageCheckout\Checkout\ExistingCustomerState;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\PrestaShop\Core\Util\InternationalizedDomainNameConverter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @phpstan-type GuestInitResponse array{
 *   success: bool,
 *   customer_created: bool,
 *   id_customer: int,
 *   errors: array<string, array<int, string>>,
 *   token?: string,
 *   static_token?: string
 * }
 *
 * Handles guest initialization in one-page checkout.
 * Keep checkout idempotent and link the cart to the right customer.
 */
class OnePageCheckoutGuestInitHandler
{
    private const ERROR_FIELD_GLOBAL = '';
    private const ERROR_FIELD_EMAIL = 'email';
    private const ERROR_FIELD_TOKEN = 'token';

    private const ERROR_GUEST_EMAIL_UPDATE_FAILED = 'Unable to update guest email.';
    private const ERROR_CART_CUSTOMER_SYNC_FAILED = 'Unable to synchronize cart customer link.';

    private const CART_UPDATE_APPLIED = 1;
    private const CART_UPDATE_NOT_APPLIED = 0;
    private const CART_UPDATE_FAILED = -1;
    /**
     * Customer id used when no customer is resolved.
     */
    private const CUSTOMER_ID_NONE = 0;

    private const RESPONSE_DEFAULT = [
        'success' => false,
        'customer_created' => false,
        'id_customer' => 0,
        'errors' => [],
    ];

    /**
     * @var \Context
     */
    private $context;

    /**
     * @var OnePageCheckoutForm
     */
    private $opcForm;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var \CustomerPersister
     */
    private $customerPersister;

    /**
     * @var bool
     */
    private $isOnePageCheckoutEnabled;

    /**
     * @var InternationalizedDomainNameConverter
     */
    private $idnConverter;

    /**
     * @param \Context $context
     * @param OnePageCheckoutForm $opcForm
     * @param TranslatorInterface $translator
     * @param \CustomerPersister $customerPersister
     * @param bool $isOnePageCheckoutEnabled
     */
    public function __construct(
        \Context $context,
        OnePageCheckoutForm $opcForm,
        TranslatorInterface $translator,
        \CustomerPersister $customerPersister,
        bool $isOnePageCheckoutEnabled,
    ) {
        $this->context = $context;
        $this->opcForm = $opcForm;
        $this->translator = $translator;
        $this->customerPersister = $customerPersister;
        $this->isOnePageCheckoutEnabled = $isOnePageCheckoutEnabled;
        $this->idnConverter = new InternationalizedDomainNameConverter();
    }

    /**
     * Entry point for guest init AJAX payload.
     *
     * @param array<string, mixed> $requestParameters
     *
     * @return GuestInitResponse
     */
    public function handle(array $requestParameters): array
    {
        // Only available in one-page checkout mode.
        if (!$this->isOnePageCheckoutEnabled) {
            return $this->errorResponse(
                self::ERROR_FIELD_GLOBAL,
                $this->translator->trans('One-page checkout is not enabled.', [], 'Modules.Onepagecheckout.Shop')
            );
        }

        // No eligible cart or guest checkout disabled: nothing to initialize.
        if (!$this->hasEligibleCart() || !$this->isGuestCheckoutEnabled()) {
            return $this->successResponse();
        }

        // This endpoint can create/update customer data, token is mandatory.
        if (!$this->isTokenValid($requestParameters)) {
            return $this->errorResponse(
                self::ERROR_FIELD_TOKEN,
                $this->translator->trans('Invalid security token.', [], 'Modules.Onepagecheckout.Shop'),
                false
            );
        }

        if (!$this->hasFreshPersistedCartRow()) {
            return $this->cartSyncErrorResponse();
        }

        $existingCustomerState = $this->resolveExistingCustomerState();

        $existingCustomerResponse = $this->handleExistingCustomer($existingCustomerState);
        if ($existingCustomerResponse !== null) {
            return $existingCustomerResponse;
        }

        $submittedEmail = $this->getSubmittedEmail($requestParameters);
        $emailDecisionResponse = $this->resolveGuestEmail($submittedEmail, $existingCustomerState);
        if ($emailDecisionResponse !== null) {
            return $emailDecisionResponse;
        }

        // Keep customer creation in the existing OPC form workflow.
        if (!$this->opcForm->submitGuestInit($requestParameters)) {
            $response = self::RESPONSE_DEFAULT;
            $response['errors'] = $this->opcForm->getErrors();

            return $this->withSecurityToken($response);
        }

        // Attach the created guest to the cart.
        $createdCustomerId = (int) $this->context->customer->id;
        $resolvedCustomerId = $this->persistCartCustomerId($createdCustomerId);
        if ($resolvedCustomerId === self::CUSTOMER_ID_NONE) {
            return $this->cartSyncErrorResponse();
        }

        $this->syncResolvedCustomerContext($resolvedCustomerId);

        // If another request already claimed the cart, return that owner.
        return $this->successResponse($resolvedCustomerId, $resolvedCustomerId === $createdCustomerId);
    }

    /**
     * Read guest checkout configuration flag.
     *
     * @return bool
     */
    protected function isGuestCheckoutEnabled(): bool
    {
        return (bool) \Configuration::get('PS_GUEST_CHECKOUT_ENABLED');
    }

    /**
     * @param int $customerId
     *
     * @return \Customer
     */
    protected function loadCustomerById(int $customerId): \Customer
    {
        return new \Customer($customerId);
    }

    /**
     * Check whether an email already belongs to a customer.
     *
     * @param string $email
     *
     * @return int
     */
    protected function findCustomerByEmail(string $email): int
    {
        return (int) \Customer::customerExists($email, true, false);
    }

    /**
     * Read the security token source used by request and response.
     *
     * @return string
     */
    protected function getExpectedToken(): string
    {
        return (string) \Tools::getToken(false);
    }

    /**
     * @param int $cartId
     *
     * @return \Cart
     */
    protected function loadCartById(int $cartId): \Cart
    {
        return new \Cart($cartId);
    }

    /**
     * Change cart owner only if the cart still belongs to the expected customer.
     *
     * @param int $cartId
     * @param int $expectedCustomerId
     * @param int $newCustomerId
     *
     * @return int One of CART_UPDATE_APPLIED, CART_UPDATE_NOT_APPLIED, CART_UPDATE_FAILED
     */
    protected function replaceCartCustomerIdIfMatches(int $cartId, int $expectedCustomerId, int $newCustomerId): int
    {
        // If a concurrent request already changed id_customer, this update matches no row (0 affected rows).
        $query = sprintf(
            'UPDATE `%scart` SET `id_customer` = %d WHERE `id_cart` = %d AND `id_customer` = %d',
            _DB_PREFIX_,
            $newCustomerId,
            $cartId,
            $expectedCustomerId
        );

        $db = \Db::getInstance();
        if (!$db->execute($query)) {
            return self::CART_UPDATE_FAILED;
        }

        return $db->Affected_Rows() > 0 ? self::CART_UPDATE_APPLIED : self::CART_UPDATE_NOT_APPLIED;
    }

    /**
     * Check if this customer exists.
     *
     * @param int $customerId
     *
     * @return bool
     */
    private function isLoadedCustomerId(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        $customer = $this->loadCustomerById($customerId);

        return \Validate::isLoadedObject($customer);
    }

    /**
     * Resolve checkout customer id with cart owner as source of truth.
     *
     * @return int Existing customer id, or CUSTOMER_ID_NONE when no customer can be resolved
     */
    private function resolveExistingCustomerId(): int
    {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return (int) $this->context->customer->id;
        }

        $freshCartCustomerId = $this->getFreshCartCustomerId();
        if ($freshCartCustomerId > 0 && $this->isLoadedCustomerId($freshCartCustomerId)) {
            return $freshCartCustomerId;
        }

        if ($freshCartCustomerId > 0) {
            return self::CUSTOMER_ID_NONE;
        }

        return self::CUSTOMER_ID_NONE;
    }

    /**
     * Read latest persisted cart owner from database.
     *
     * @return int Customer id linked to cart in DB, or CUSTOMER_ID_NONE when unavailable
     */
    protected function getFreshCartCustomerId(): int
    {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return self::CUSTOMER_ID_NONE;
        }

        $cartId = (int) $this->context->cart->id;
        if ($cartId <= 0) {
            return self::CUSTOMER_ID_NONE;
        }

        // Read cart ownership directly from DB to avoid stale in-memory cart state.
        $customerId = \Db::getInstance()->getValue(sprintf(
            'SELECT `id_customer` FROM `%scart` WHERE `id_cart` = %d',
            _DB_PREFIX_,
            $cartId
        ));
        if ($customerId === false) {
            return self::CUSTOMER_ID_NONE;
        }

        return (int) $customerId;
    }

    protected function hasFreshPersistedCartRow(): bool
    {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return false;
        }

        $cartId = (int) $this->context->cart->id;
        if ($cartId <= 0) {
            return false;
        }

        return \Validate::isLoadedObject($this->loadCartById($cartId));
    }

    /**
     * Guest init applies only when cart exists and has products.
     *
     * @return bool
     */
    private function hasEligibleCart(): bool
    {
        if (!\Validate::isLoadedObject($this->context->cart)) {
            return false;
        }

        return (int) $this->context->cart->nbProducts() > 0;
    }

    /**
     * Accept both `token` and `static_token`.
     *
     * @param array<string, mixed> $requestParameters
     *
     * @return bool
     */
    private function isTokenValid(array $requestParameters): bool
    {
        $submittedToken = (string) ($requestParameters['token'] ?? $requestParameters['static_token'] ?? '');
        if ($submittedToken === '') {
            return false;
        }

        return hash_equals($this->getExpectedToken(), $submittedToken);
    }

    /**
     * Resolve the current customer state for guest init.
     * Return an empty state when no valid customer is attached.
     *
     * @return ExistingCustomerState
     */
    private function resolveExistingCustomerState(): ExistingCustomerState
    {
        $existingCustomerId = $this->resolveExistingCustomerId();
        if ($existingCustomerId <= 0) {
            return ExistingCustomerState::empty();
        }

        $existingCustomer = $this->loadCustomerById($existingCustomerId);
        if (!\Validate::isLoadedObject($existingCustomer)) {
            // Stale owner id: continue as if no customer was attached.
            return ExistingCustomerState::empty();
        }

        return ExistingCustomerState::fromCustomer($existingCustomer);
    }

    /**
     * If the cart is already linked to a registered customer, skip guest init and return success.
     *
     * @param ExistingCustomerState $existingCustomerState
     *
     * @return GuestInitResponse|null
     */
    private function handleExistingCustomer(ExistingCustomerState $existingCustomerState): ?array
    {
        if (!$existingCustomerState->hasCustomer()) {
            return null;
        }

        if (!$existingCustomerState->isGuestCustomer()) {
            return $this->successResponse($existingCustomerState->getId());
        }

        return null;
    }

    /**
     * Normalize submitted email before validation and comparison.
     *
     * @param array<string, mixed> $requestParameters
     *
     * @return string
     */
    private function getSubmittedEmail(array $requestParameters): string
    {
        $submittedEmail = trim((string) ($requestParameters['email'] ?? ''));
        if ($submittedEmail === '') {
            return '';
        }

        return $this->idnConverter->emailToUtf8($submittedEmail);
    }

    /**
     * Update the customer email and prevent cart ownership from being switched to another account.
     *
     * @param string $submittedEmail
     * @param ExistingCustomerState $existingCustomerState
     *
     * @return GuestInitResponse|null Terminal response or null to continue submit flow
     */
    private function resolveGuestEmail(string $submittedEmail, ExistingCustomerState $existingCustomerState): ?array
    {
        $existingCustomerId = $existingCustomerState->getId();
        $existingCustomer = $existingCustomerState->getCustomer();

        if ($submittedEmail === '' || !\Validate::isEmail($submittedEmail)) {
            return null;
        }

        if (!$existingCustomerState->hasCustomer()) {
            return null;
        }

        if (!$existingCustomerState->isGuestCustomer()) {
            return $this->resolveEmailForNonGuest($submittedEmail, $existingCustomerId);
        }

        // Same email: nothing to change.
        if (strcasecmp((string) $existingCustomer->email, $submittedEmail) === 0) {
            return $this->successResponse($existingCustomerId);
        }

        $existingCustomerForEmailId = $this->findCustomerByEmail($submittedEmail);
        // Email already used by another account: keep current guest.
        if ($existingCustomerForEmailId > 0 && $existingCustomerForEmailId !== $existingCustomerId) {
            return $this->successResponse($existingCustomerId);
        }

        // Align context with target guest so the email update is saved on the right account.
        $this->ensureContextCustomerMatches($existingCustomer);

        $existingCustomer->email = $submittedEmail;
        if (!$this->customerPersister->save($existingCustomer, '', '', false)) {
            return $this->errorResponse(
                self::ERROR_FIELD_EMAIL,
                $this->translator->trans(self::ERROR_GUEST_EMAIL_UPDATE_FAILED, [], 'Modules.Onepagecheckout.Shop')
            );
        }

        $resolvedCustomerId = $this->persistCartCustomerId($existingCustomerId);
        if ($resolvedCustomerId === self::CUSTOMER_ID_NONE) {
            return $this->cartSyncErrorResponse();
        }

        $this->syncResolvedCustomerContext($resolvedCustomerId);

        return $this->successResponse($resolvedCustomerId);
    }

    /**
     * In non-guest, submitted email must never change checkout identity:
     * if it belongs to another account, keep the current cart owner.
     *
     * @param string $submittedEmail
     * @param int $existingCustomerId
     *
     * @return GuestInitResponse|null
     */
    private function resolveEmailForNonGuest(string $submittedEmail, int $existingCustomerId): ?array
    {
        $existingCustomerForEmailId = $this->findCustomerByEmail($submittedEmail);
        if ($existingCustomerForEmailId <= 0) {
            return null;
        }

        if ($existingCustomerId === self::CUSTOMER_ID_NONE) {
            return null;
        }

        // Keep current owner if email points to another account.
        if ($existingCustomerForEmailId !== $existingCustomerId) {
            return $this->successResponse($existingCustomerId);
        }

        return null;
    }

    /**
     * Keep cart ownership consistent, including concurrent requests.
     *
     * @param int $customerId
     *
     * @return int resolved customer id
     */
    private function persistCartCustomerId(int $customerId): int
    {
        if ($customerId <= 0 || !\Validate::isLoadedObject($this->context->cart)) {
            return $customerId;
        }

        $cartId = (int) $this->context->cart->id;
        if ($cartId <= 0) {
            return $customerId;
        }

        $freshCartCustomerId = $this->getFreshCartCustomerId();

        // Another request already claimed cart ownership: keep winner for deterministic result.
        if ($freshCartCustomerId > self::CUSTOMER_ID_NONE) {
            return $this->isLoadedCustomerId($freshCartCustomerId)
                ? $freshCartCustomerId
                : self::CUSTOMER_ID_NONE;
        }

        return $this->claimCartForCustomer($cartId, $customerId);
    }

    /**
     * Ensure context customer matches the guest being updated so persistence targets the right account.
     *
     * @param \Customer $customer
     *
     * @return void
     */
    private function ensureContextCustomerMatches(\Customer $customer): void
    {
        if ((int) $this->context->customer->id === (int) $customer->id) {
            return;
        }

        $this->context->customer = $customer;
    }

    /**
     * Preserve checkout identity after cart owner resolution.
     * Prevent next requests from reusing stale cookie data and rebinding the cart.
     *
     * @param int $customerId
     *
     * @return void
     */
    private function syncResolvedCustomerContext(int $customerId): void
    {
        if ($customerId <= 0) {
            return;
        }

        $customer = $this->loadCustomerById($customerId);
        if (!\Validate::isLoadedObject($customer)) {
            return;
        }

        $this->context->customer = $customer;

        if (!isset($this->context->cookie)) {
            return;
        }

        $this->context->cookie->id_customer = (int) $customer->id;
        $this->context->cookie->customer_lastname = (string) $customer->lastname;
        $this->context->cookie->customer_firstname = (string) $customer->firstname;
        $this->context->cookie->passwd = (string) $customer->passwd;
        $this->context->cookie->logged = true;
        $this->context->cookie->email = (string) $customer->email;
        $this->context->cookie->is_guest = (bool) $customer->isGuest();
        $this->context->cookie->write();
    }

    /**
     * Claim unassigned cart for customer, or return the winner if race is lost.
     *
     * @param int $cartId
     * @param int $customerId
     *
     * @return int
     */
    private function claimCartForCustomer(int $cartId, int $customerId): int
    {
        $claimResult = $this->replaceCartCustomerIdIfMatches(
            $cartId,
            self::CUSTOMER_ID_NONE,
            $customerId
        );

        if ($claimResult === self::CART_UPDATE_FAILED) {
            return self::CUSTOMER_ID_NONE;
        }

        if ($claimResult === self::CART_UPDATE_APPLIED) {
            return $customerId;
        }

        return $this->resolveWinnerAfterRace();
    }

    /**
     * Reload cart owner after a race to return the effective owner.
     *
     * @return int resolved owner id, or CUSTOMER_ID_NONE when owner cannot be resolved
     */
    private function resolveWinnerAfterRace(): int
    {
        $resolvedCustomerId = $this->getFreshCartCustomerId();
        if (!$this->isLoadedCustomerId($resolvedCustomerId)) {
            return self::CUSTOMER_ID_NONE;
        }

        return $resolvedCustomerId;
    }

    /**
     * Return a success response in the frontend.
     *
     * @param int $customerId
     * @param bool $customerCreated
     *
     * @return GuestInitResponse
     */
    private function successResponse(int $customerId = self::CUSTOMER_ID_NONE, bool $customerCreated = false): array
    {
        $response = self::RESPONSE_DEFAULT;
        $response['success'] = true;
        $response['id_customer'] = $customerId;
        $response['customer_created'] = $customerCreated;

        return $this->withSecurityToken($response);
    }

    /**
     * Return an error response in the frontend.
     *
     * @param string $field
     * @param string $message
     * @param bool $withSecurityToken
     *
     * @return GuestInitResponse
     */
    private function errorResponse(string $field, string $message, bool $withSecurityToken = true): array
    {
        $response = self::RESPONSE_DEFAULT;
        $response['errors'][$field][] = $message;

        if (!$withSecurityToken) {
            return $response;
        }

        return $this->withSecurityToken($response);
    }

    /**
     * Return an error when cart ownership cannot be synchronized with the customer.
     *
     * @return GuestInitResponse
     */
    private function cartSyncErrorResponse(): array
    {
        return $this->errorResponse(
            self::ERROR_FIELD_GLOBAL,
            $this->translator->trans(self::ERROR_CART_CUSTOMER_SYNC_FAILED, [], 'Modules.Onepagecheckout.Shop')
        );
    }

    /**
     * Always return fresh security tokens in response payload.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function withSecurityToken(array $response): array
    {
        $securityToken = $this->getExpectedToken();
        $response['token'] = $securityToken;
        $response['static_token'] = $securityToken;

        return $response;
    }
}
