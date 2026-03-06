<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutGuestInitHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\PrestaShop\Core\Crypto\Hashing;
use Symfony\Contracts\Translation\TranslatorInterface;

if ($argc !== 8 && $argc !== 9) {
    fwrite(STDERR, "Usage: php CheckoutGuestEmailUpdateDiagnosticConcurrentWorker.php <cartId> <cartOwnerGuestId> <contextCustomerId> <email> <token> <barrierId> <workerId> [workersCount]\n");
    exit(2);
}

[$script, $cartIdArg, $cartOwnerGuestIdArg, $contextCustomerIdArg, $email, $expectedToken, $barrierId, $workerId] = $argv;
$cartId = (int) $cartIdArg;
$cartOwnerGuestId = (int) $cartOwnerGuestIdArg;
$contextCustomerId = (int) $contextCustomerIdArg;
$workersCount = $argc === 9 ? (int) $argv[8] : 2;
if (
    $cartId <= 0
    || $cartOwnerGuestId <= 0
    || $contextCustomerId <= 0
    || !preg_match('/^[a-z]{1,3}$/', $workerId)
    || $workersCount < 2
    || $workersCount > 26
) {
    fwrite(STDERR, "Invalid worker parameters\n");
    exit(2);
}

require_once dirname(__DIR__, 4) . '/bootstrap-integration.php';

final class ConcurrentEmailUpdateDiagnosticTranslator implements TranslatorInterface
{
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }

    public function getLocale(): string
    {
        return 'en-US';
    }
}

final class ConcurrentEmailUpdateDiagnosticCheckoutForm extends OnePageCheckoutForm
{
    /**
     * @var array<string, array<int, string>>
     */
    protected $errors = [];

    public function __construct()
    {
    }

    public function submitGuestInit(array $params = []): bool
    {
        $this->errors[''][] = 'submitGuestInit should not be called during guest email update race.';

        return false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

final class ConcurrentEmailUpdateDiagnosticEligibleCart extends Cart
{
    public int $productsCount = 1;

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

final class ConcurrentEmailUpdateDiagnosticCheckoutGuestInitHandler extends OnePageCheckoutGuestInitHandler
{
    private string $expectedToken;

    public function __construct(
        Context $context,
        OnePageCheckoutForm $opcForm,
        TranslatorInterface $translator,
        CustomerPersister $customerPersister,
        bool $isOnePageCheckoutEnabled,
        string $expectedToken,
    ) {
        parent::__construct($context, $opcForm, $translator, $customerPersister, $isOnePageCheckoutEnabled);
        $this->expectedToken = $expectedToken;
    }

    protected function getExpectedToken(): string
    {
        return $this->expectedToken;
    }
}

function waitForConcurrentPeersDiagnostic(string $barrierId, string $workerId, int $workersCount): bool
{
    $barrierPrefix = sprintf('%s/opc_guest_init_barrier_%s', sys_get_temp_dir(), $barrierId);
    $currentReadyFile = sprintf('%s.%s.ready', $barrierPrefix, $workerId);

    file_put_contents($currentReadyFile, '1');

    $deadline = microtime(true) + 5.0;
    while (true) {
        $readyWorkers = glob(sprintf('%s.*.ready', $barrierPrefix));
        if (is_array($readyWorkers) && count($readyWorkers) >= $workersCount) {
            return true;
        }

        if (microtime(true) >= $deadline) {
            return false;
        }

        usleep(10000);
    }
}

try {
    $context = Context::getContext();
    $context->customer = new Customer($contextCustomerId);
    if (!Validate::isLoadedObject($context->customer) || !$context->customer->isGuest()) {
        throw new RuntimeException('Context customer is not a valid guest for concurrent update worker.');
    }

    $contextCart = new ConcurrentEmailUpdateDiagnosticEligibleCart();
    $contextCart->id = $cartId;
    $contextCart->id_customer = $cartOwnerGuestId;
    $contextCart->productsCount = 1;
    $context->cart = $contextCart;

    Configuration::loadConfiguration();
    Configuration::updateValue('PS_GUEST_CHECKOUT_ENABLED', true);

    if (!waitForConcurrentPeersDiagnostic($barrierId, $workerId, $workersCount)) {
        throw new RuntimeException('Concurrent worker barrier timeout.');
    }

    $translator = new ConcurrentEmailUpdateDiagnosticTranslator();
    $customerPersister = new CustomerPersister(
        $context,
        new Hashing(),
        $translator,
        true
    );
    $opcForm = new ConcurrentEmailUpdateDiagnosticCheckoutForm();
    $handler = new ConcurrentEmailUpdateDiagnosticCheckoutGuestInitHandler(
        $context,
        $opcForm,
        $translator,
        $customerPersister,
        true,
        $expectedToken
    );

    $response = $handler->handle([
        'email' => $email,
        'token' => $expectedToken,
    ]);

    $cartOwnerAfter = (int) Db::getInstance()->getValue(sprintf(
        'SELECT `id_customer` FROM `%scart` WHERE `id_cart` = %d',
        _DB_PREFIX_,
        $cartId
    ));
    $cartOwnerCustomer = new Customer($cartOwnerGuestId);
    $contextCustomer = new Customer($contextCustomerId);

    echo json_encode([
        'response' => $response,
        'cart_owner_after' => $cartOwnerAfter,
        'request_context_customer_id' => $contextCustomerId,
        'context_customer_id' => (int) $context->customer->id,
        'cookie_id_customer' => (int) $context->cookie->id_customer,
        'cookie_email' => (string) $context->cookie->email,
        'cart_owner_customer_email' => (string) $cartOwnerCustomer->email,
        'context_customer_email' => (string) $contextCustomer->email,
        'submitted_email' => $email,
    ], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "%s\n%s\n",
            $exception->getMessage(),
            $exception->getTraceAsString()
        )
    );
    exit(1);
}
