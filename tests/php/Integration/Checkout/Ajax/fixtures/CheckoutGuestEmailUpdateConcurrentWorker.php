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

if ($argc !== 7 && $argc !== 8) {
    fwrite(STDERR, "Usage: php CheckoutGuestEmailUpdateConcurrentWorker.php <cartId> <guestId> <email> <token> <barrierId> <workerId> [workersCount]\n");
    exit(2);
}

[$script, $cartIdArg, $guestIdArg, $email, $expectedToken, $barrierId, $workerId] = $argv;
$cartId = (int) $cartIdArg;
$guestId = (int) $guestIdArg;
$workersCount = $argc === 8 ? (int) $argv[7] : 2;
if ($cartId <= 0 || $guestId <= 0 || !preg_match('/^[a-z]{1,3}$/', $workerId) || $workersCount < 2 || $workersCount > 26) {
    fwrite(STDERR, "Invalid worker parameters\n");
    exit(2);
}

require_once dirname(__DIR__, 4) . '/bootstrap-integration.php';

final class ConcurrentEmailUpdateTranslator implements TranslatorInterface
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

final class ConcurrentEmailUpdateCheckoutForm extends OnePageCheckoutForm
{
    public function __construct()
    {
    }

    public function submitGuestInit(array $params = []): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getErrors(): array
    {
        return [];
    }
}

final class ConcurrentEmailUpdateEligibleCart extends Cart
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

final class ConcurrentEmailUpdateCheckoutGuestInitHandler extends OnePageCheckoutGuestInitHandler
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

function waitForConcurrentPeers(string $barrierId, string $workerId, int $workersCount): bool
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
    $context->customer = new Customer($guestId);
    if (!Validate::isLoadedObject($context->customer) || !$context->customer->isGuest()) {
        throw new RuntimeException('Guest customer is not available for concurrent update worker.');
    }

    $contextCart = new ConcurrentEmailUpdateEligibleCart();
    $contextCart->id = $cartId;
    $contextCart->id_customer = $guestId;
    $contextCart->productsCount = 1;
    $context->cart = $contextCart;

    Configuration::loadConfiguration();
    Configuration::updateValue('PS_GUEST_CHECKOUT_ENABLED', true);

    if (!waitForConcurrentPeers($barrierId, $workerId, $workersCount)) {
        throw new RuntimeException('Concurrent worker barrier timeout.');
    }

    $translator = new ConcurrentEmailUpdateTranslator();
    $customerPersister = new CustomerPersister(
        $context,
        new Hashing(),
        $translator,
        true
    );
    $opcForm = new ConcurrentEmailUpdateCheckoutForm();
    $handler = new ConcurrentEmailUpdateCheckoutGuestInitHandler(
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

    echo json_encode($response, JSON_THROW_ON_ERROR);
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
