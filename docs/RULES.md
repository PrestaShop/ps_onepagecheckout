# ps_onepagecheckout Implementation Rules

## Scope

These rules are mandatory for one-page checkout implementation in the native `ps_onepagecheckout` module.
The migration reference document is [`docs/CORE_PORTING_PLAYBOOK.md`](./CORE_PORTING_PLAYBOOK.md).

## Architecture

1. The module must not create or mutate Core hooks at runtime.
2. The module must not use custom autoloaders.
3. Runtime class loading must rely on namespaced classes and Composer autoload.
4. No module-side legacy fallback logic (`fallback_url`, `error_code`, redirect to legacy checkout).

## Back Office

1. Single source of truth for one-page checkout activation:
- `PS_ONE_PAGE_CHECKOUT_ENABLED`

2. Two BO entry points must remain available and consistent:
- module manager `Configure` page,
- dedicated BO tab `AdminPsOnePageCheckout`.
Both entry points must render the same module-owned configuration flow (no redirect-only BO tab).

3. `install()` must provision `PS_ONE_PAGE_CHECKOUT_ENABLED` with value `0` by default.
4. `disable()` must set `PS_ONE_PAGE_CHECKOUT_ENABLED` to `0`, and `uninstall()` must remove `PS_ONE_PAGE_CHECKOUT_ENABLED`.
5. BO form rendering/submit handling must be implemented in a dedicated form class under `src/Form`, not inline in `ps_onepagecheckout.php`.
6. BO layout selector UI must keep parity with Core checkout layout experience (title, descriptions, feature lists, recommended badge, illustrations), rendered from module-owned Twig template/assets.
7. OPC form creation must go through a dedicated factory (`src/Form/OnePageCheckoutFormFactory.php`) to avoid duplicated setup logic in controllers/builders.
8. Runtime checkout flag (`is_one_page_checkout_enabled`) must be aligned with module state when module-owned checkout process is injected.

## JS

1. AJAX URLs must come from module links (`getModuleLink`) injected with `Media::addJsDef`.
2. JS toolchain lives in `views/` and must provide:
- `npm run watch`
- `npm run build`
3. When changing files under `views/js`, developers must regenerate and commit the built assets shipped by the module from `views/public` (including `*.LICENSE.txt` files). See [`README.md` → Front assets](../README.md#front-assets).

## Core to module migration

1. Triage every future checkout change using the playbook before coding.
2. Correct existing parity gaps in the module before porting new Core behavior.
3. Keep Core changes to the minimal no-override surface only.
4. Keep checkout business logic in the module and DOM/visual ownership in Hummingbird.

## Test workflow

1. Each migration lot must be implemented with a story/test pair and incremental automated verification.
2. Every lot must ship unit tests for local logic and integration tests for observable behavior.
3. After JS changes, rebuild `views/public/*` and verify the runtime contracts through tests.

## Delivery checklist

1. Unit tests updated for changed behavior.
2. Integration tests updated for changed behavior.
3. Decision log updated for every architectural choice.
4. E2E preflight and troubleshooting must be kept up to date in `docs/E2E_RUNBOOK.md`.
5. Module PHPUnit entrypoints must stay reproducible between local and CI through `./scripts/run-tests.sh`.
