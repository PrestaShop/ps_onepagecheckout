<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

use PrestaShop\Module\PsOnePageCheckout\Checkout\Ajax\OnePageCheckoutGuestInitHandler;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutForm;
use PrestaShop\Module\PsOnePageCheckout\Form\OnePageCheckoutFormatter;
use PrestaShop\PrestaShop\Core\Crypto\Hashing;
use Symfony\Contracts\Translation\TranslatorInterface;

if ($argc !== 6 && $argc !== 7) {
    fwrite(STDERR, "Usage: php CheckoutGuestInitRealSubmitDiagnosticConcurrentWorker.php <cartId> <email> <token> <barrierId> <workerId> [workersCount]\n");
    exit(2);
}

[$script, $cartIdArg, $email, $expectedToken, $barrierId, $workerId] = $argv;
$cartId = (int) $cartIdArg;
$workersCount = $argc === 7 ? (int) $argv[6] : 2;

if ($cartId <= 0 || !preg_match('/^[a-z]{1,3}$/', $workerId) || $workersCount < 2 || $workersCount > 26) {
    fwrite(STDERR, "Invalid worker parameters\n");
    exit(2);
}

require_once dirname(__DIR__, 4) . '/bootstrap-integration.php';

final class ConcurrentRealSubmitDiagnosticTranslator implements TranslatorInterface
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

final class ConcurrentRealSubmitDiagnosticEligibleCart extends Cart
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

final class ConcurrentRealSubmitDiagnosticCheckoutForm extends OnePageCheckoutForm
{
    private string $barrierId;
    private string $workerId;
    private int $workersCount;

    public function __construct(
        Smarty $smarty,
        Context $context,
        Language $language,
        TranslatorInterface $translator,
        OnePageCheckoutFormatter $formatter,
        CustomerPersister $customerPersister,
        CustomerAddressPersister $addressPersister,
        string $barrierId,
        string $workerId,
        int $workersCount,
    ) {
        parent::__construct(
            $smarty,
            $context,
            $language,
            $translator,
            $formatter,
            $customerPersister,
            $addressPersister
        );
        $this->barrierId = $barrierId;
        $this->workerId = $workerId;
        $this->workersCount = $workersCount;
    }

    public function submitGuestInit(array $params = []): bool
    {
        if (!$this->waitForPeerWorkers()) {
            $this->errors[''][] = 'Concurrent worker barrier timeout.';

            return false;
        }

        return parent::submitGuestInit($params);
    }

    private function waitForPeerWorkers(): bool
    {
        $barrierPrefix = sprintf('%s/opc_guest_init_barrier_%s', sys_get_temp_dir(), $this->barrierId);
        $currentReadyFile = sprintf('%s.%s.ready', $barrierPrefix, $this->workerId);

        file_put_contents($currentReadyFile, '1');

        $deadline = microtime(true) + 6.0;
        while (true) {
            $readyWorkers = glob(sprintf('%s.*.ready', $barrierPrefix));
            if (is_array($readyWorkers) && count($readyWorkers) >= $this->workersCount) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(10000);
        }
    }
}

final class ConcurrentRealSubmitDiagnosticCheckoutGuestInitHandler extends OnePageCheckoutGuestInitHandler
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

/**
 * @return array<string, mixed>
 */
function buildGuestInitRequestParamsForDiagnostic(
    ConcurrentRealSubmitDiagnosticCheckoutForm $form,
    string $email,
    string $token,
): array {
    $params = [
        'email' => $email,
        'token' => $token,
    ];

    $form->fillWith(['email' => $email]);
    $templateVariables = $form->getTemplateVariables();
    $formFields = $templateVariables['formFields'] ?? [];
    if (!is_array($formFields)) {
        return $params;
    }

    foreach ($formFields as $field) {
        if (!is_array($field)) {
            continue;
        }

        if (($field['type'] ?? '') !== 'checkbox' || empty($field['required']) || empty($field['name'])) {
            continue;
        }

        $params[(string) $field['name']] = 1;
    }

    return $params;
}

try {
    $context = Context::getContext();
    $context->customer = new Customer();

    $contextCart = new ConcurrentRealSubmitDiagnosticEligibleCart();
    $contextCart->id = $cartId;
    $contextCart->id_customer = 0;
    $contextCart->productsCount = 1;
    $context->cart = $contextCart;

    Configuration::loadConfiguration();
    Configuration::updateValue('PS_GUEST_CHECKOUT_ENABLED', true);

    $translator = new ConcurrentRealSubmitDiagnosticTranslator();
    if (Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES')) {
        $availableCountries = Carrier::getDeliveredCountries($context->language->id, true, true);
    } else {
        $availableCountries = Country::getCountries($context->language->id, true);
    }

    $customerPersister = new CustomerPersister(
        $context,
        new Hashing(),
        $translator,
        true
    );
    $addressPersister = new CustomerAddressPersister(
        $context->customer,
        $context->cart,
        Tools::getToken(true, $context)
    );
    $formatter = new OnePageCheckoutFormatter(
        $context->country,
        $translator,
        $availableCountries
    );
    $opcForm = new ConcurrentRealSubmitDiagnosticCheckoutForm(
        $context->smarty,
        $context,
        $context->language,
        $translator,
        $formatter,
        $customerPersister,
        $addressPersister,
        $barrierId,
        $workerId,
        $workersCount
    );
    $opcForm->setAction('');

    $handler = new ConcurrentRealSubmitDiagnosticCheckoutGuestInitHandler(
        $context,
        $opcForm,
        $translator,
        $customerPersister,
        true,
        $expectedToken
    );

    $requestParameters = buildGuestInitRequestParamsForDiagnostic($opcForm, $email, $expectedToken);
    $response = $handler->handle($requestParameters);
    $cartOwnerAfter = (int) Db::getInstance()->getValue(sprintf(
        'SELECT `id_customer` FROM `%scart` WHERE `id_cart` = %d',
        _DB_PREFIX_,
        $cartId
    ));

    echo json_encode([
        'response' => $response,
        'cart_owner_after' => $cartOwnerAfter,
        'created_customer_id' => (int) Customer::customerExists($email, true, false),
        'context_customer_id' => (int) $context->customer->id,
        'cookie_id_customer' => (int) $context->cookie->id_customer,
        'cookie_email' => (string) $context->cookie->email,
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
