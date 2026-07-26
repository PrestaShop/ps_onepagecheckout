<?php

use PrestaShop\Module\PsOnePageCheckout\Checkout\Context\OpcContextRefreshBuilder;

abstract class Ps_OnepagecheckoutAbstractOpcJsonFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $response = $this->handleOpcRequest();

        // OPC never reloads the page, so the page-load window.prestashop snapshot
        // (country/currency/cart) and the body classes go stale after each AJAX update.
        // Attach the fresh context so the front helper can re-sync them before emitting
        // OPC events. A handler that resolves a more precise country (e.g. an inline-typed
        // address) provides its own 'context_refresh', which we never overwrite.
        if (!array_key_exists('context_refresh', $response)) {
            $response['context_refresh'] = (new OpcContextRefreshBuilder())->build($this->context);
        }

        $this->renderJsonResponse($response);
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleOpcRequest(): array
    {
        if (!$this->isOpcAvailable()) {
            return $this->buildTechnicalErrorResponse();
        }

        // Serialize OPC ajax requests PER CART with a MySQL advisory lock. Concurrent
        // requests (autosave, carriers/payment refreshes, selectaddress) all mutate the
        // same cart row — several through full-row Cart::save() calls (including core's
        // carrier computation) built from each request's own snapshot, so interleavings
        // lose writes: duplicated/orphaned inline addresses, invoice pointer reverted to
        // a stale value. Sequential flows only ever see an uncontended lock (~sub-ms);
        // on timeout — or any error acquiring the lock — the request proceeds unlocked,
        // i.e. degrades to today's behavior, never worse.
        $lockName = null;
        $lockTaken = false;
        $cartId = (int) ($this->context->cart->id ?? 0);
        if ($cartId > 0) {
            try {
                // GET_LOCK's namespace is server-wide, so two PrestaShop installs sharing one
                // MySQL server would contend on bare cart ids. Discriminate by database + table
                // prefix, hashed to a fixed length: lock names are capped at 64 characters and
                // an over-long name is an ERROR (which would silently disable the lock). Built
                // inside the try so an environment without the constants degrades unlocked too.
                $lockName = 'opc_' . substr(md5(_DB_NAME_ . '/' . _DB_PREFIX_), 0, 8) . '_cart_' . $cartId;
                $lockTaken = (bool) Db::getInstance()->getValue(
                    "SELECT GET_LOCK('" . pSQL($lockName) . "', 10)"
                );
            } catch (Throwable $lockException) {
                $lockTaken = false;
            }

            if (!$lockTaken) {
                // Degrading is deliberate (never worse than the unserialized behavior), but a
                // shop that degrades repeatedly is running the old race windows again — make
                // that observable. Logging must never break the request it instruments.
                try {
                    PrestaShopLogger::addLog(
                        sprintf('ps_onepagecheckout: per-cart lock not acquired for cart %d — request proceeds unserialized', $cartId),
                        2,
                        null,
                        'Cart',
                        $cartId,
                        true
                    );
                } catch (Throwable $logException) {
                    // Nothing to do: the request itself must proceed.
                }
            }
        }

        try {
            return $this->handleAvailableOpcRequest();
        } catch (Throwable $exception) {
            return $this->handleRuntimeException($exception);
        } finally {
            if ($lockTaken && $lockName !== null) {
                try {
                    Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
                } catch (Throwable $releaseException) {
                    // The lock auto-releases when the connection closes.
                }
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    abstract protected function handleAvailableOpcRequest(): array;

    protected function isOpcAvailable(): bool
    {
        assert($this->module instanceof Ps_Onepagecheckout);

        return $this->module->isOnePageCheckoutEnabled();
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildTechnicalErrorResponse(): array
    {
        return $this->getTechnicalErrorResponseExtra() + [
            'success' => false,
            'errors' => [
                '' => [
                    $this->trans('One-page checkout is currently unavailable.', [], 'Modules.Onepagecheckout.Shop'),
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function getTechnicalErrorResponseExtra(): array
    {
        return [];
    }

    /**
     * Render a module-owned Smarty template by relative path under views/templates/front/.
     * Goes through the `module:` Smarty resource so theme overrides under
     * `themes/<active>/modules/ps_onepagecheckout/...` still take precedence.
     *
     * @param array<string,mixed> $params
     */
    protected function renderModuleTemplate(string $relativePath, array $params): string
    {
        $this->context->smarty->assign($params);

        return $this->context->smarty->fetch(
            'module:ps_onepagecheckout/views/templates/front/' . ltrim($relativePath, '/') . '.tpl'
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleRuntimeException(Throwable $exception): array
    {
        PrestaShopLogger::addLog(
            sprintf('ps_onepagecheckout runtime exception: %s', $exception->getMessage()),
            3,
            null,
            'Module',
            (int) $this->module->id,
            true
        );

        return $this->buildTechnicalErrorResponse();
    }

    /**
     * @param array<string,mixed> $response
     */
    protected function renderJsonResponse(array $response): void
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        $this->ajaxRender(json_encode($response));
        exit;
    }
}
