# ps_onepagecheckout Implementation Rules

## Scope

These rules are mandatory for one-page checkout implementation in the native `ps_onepagecheckout` module.

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

3. `disable()` and `uninstall()` must set `PS_ONE_PAGE_CHECKOUT_ENABLED` to `0`.
4. BO form rendering/submit handling must be implemented in a dedicated form class under `src/Form`, not inline in `ps_onepagecheckout.php`.
5. BO layout selector UI must keep parity with Core checkout layout experience (title, descriptions, feature lists, recommended badge, illustrations), rendered from module-owned Twig template/assets.
6. OPC form creation must go through a dedicated factory (`src/Form/OnePageCheckoutFormFactory.php`) to avoid duplicated setup logic in controllers/builders.
7. Runtime checkout flag (`is_one_page_checkout_enabled`) must be aligned with module state when module-owned checkout process is injected.

## JS

1. AJAX URLs must come from module links (`getModuleLink`) injected with `Media::addJsDef`.
2. JS toolchain lives in `views/` and must provide:
- `npm run watch`
- `npm run build`
3. When changing files under `views/js`, developers must regenerate and commit the built assets shipped by the module from `views/public` (including `*.LICENSE.txt` files). See [`README.md` → Front assets](../README.md#front-assets).

## Delivery checklist

1. Unit tests updated for changed behavior.
2. Integration tests updated for changed behavior.
3. Decision log updated for every architectural choice.
4. E2E preflight and troubleshooting must be kept up to date in `docs/E2E_RUNBOOK.md`.
