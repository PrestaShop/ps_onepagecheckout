# ps_onepagecheckout Decision Log

## 2026-03-05

### D-001
- Context: hook creation was attempted in module runtime.
- Decision: module only registers existing Core hooks.
- Impact: respects native module responsibilities.

### D-002
- Context: module used a custom `spl_autoload_register`.
- Decision: remove custom autoloader, use namespaced classes + Composer autoload.
- Impact: aligns with native module standards.

### D-003
- Context: duplicated OPC config flags existed.
- Decision: keep only `PS_ONE_PAGE_CHECKOUT_ENABLED` as activation flag.
- Impact: BO behavior is consistent across module manager and module BO tab.

### D-004
- Context: BO direct navigation to OPC config was missing.
- Decision: add `AdminPsOnePageCheckout` tab rendering the same module-owned configuration flow as Module Manager `Configure`.
- Impact: config is reachable from both entries with the same behavior and without relying on redirect-only navigation.

### D-005
- Context: module had legacy fallback payload and redirect behavior.
- Decision: remove fallback payload/redirect logic from module.
- Impact: native module owns OPC behavior without legacy coupling.

### D-006
- Context: no JS watch/build workflow in module.
- Decision: add webpack/npm toolchain under `views/` and GitHub workflow.
- Impact: reproducible JS build for development and CI.

### D-007
- Context: BO configuration flow logic was implemented inline in `ps_onepagecheckout.php`.
- Decision: move BO configuration submit/render logic to `src/Form/BackOfficeConfigurationForm.php`.
- Impact: clearer separation of concerns and easier BO configuration maintenance.

### D-008
- Context: OPC form construction was duplicated in builder and module front controllers.
- Decision: centralize OPC form/persister creation in `src/Form/OnePageCheckoutFormFactory.php`.
- Impact: single source of truth for OPC form wiring and easier future Core-to-module feature ports.

### D-009
- Context: runtime checkout flag could be inconsistent when module injects its checkout process.
- Decision: use a module-owned checkout process (`OnePageCheckoutProcess`) that overrides `isOnePageCheckoutEnabled()` with module flag.
- Impact: `is_one_page_checkout_enabled` stays coherent with module activation in checkout runtime without module dependency on Core OPC checker interface.

### D-010
- Context: BO configuration class attempted to access protected `Module` internals (`$table`, `trans()`), triggering HTTP 500.
- Decision: BO form uses module-safe/public data and `Context` translator instead of protected module API.
- Impact: `AdminPsOnePageCheckout` configuration renders reliably from both BO entry points.

### D-011
- Context: BO module form used a basic `HelperForm` radio and did not match historical Core checkout-layout UX.
- Decision: move BO rendering to a module Twig template with enriched checkout layout options (badge, descriptions, features, illustrations), and ship CSS/illustrations in module assets.
- Impact: BO UX parity is preserved while keeping all implementation ownership in `ps_onepagecheckout`.

### D-012
- Context: BO tab was historically stored as `AdminPsOnepagecheckout` in existing databases.
- Decision: add module upgrade `1.0.1` to rename tab class name in DB to `AdminPsOnePageCheckout`.
- Impact: naming conventions are respected without breaking existing installs.

### D-013
- Context: module targets PrestaShop `9.2.0`, where BO rendering is Twig-first.
- Decision: `BackOfficeConfigurationForm::renderConfigurationForm()` now requires Twig and no longer falls back to Smarty templates.
- Impact: BO configuration rendering is simpler, explicit, and aligned with `9.2.0` expectations.
