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
- Decision: do not ship a module-owned `upgrade/` migration for this rename yet, because the module has not been released and there is no released-to-released upgrade path to support at this stage.
- Impact: no speculative upgrade file is added before it is needed, but module-owned `upgrade/` scripts remain expected later whenever a real released version transition requires a migration.

### D-013
- Context: module targets PrestaShop `9.2.0`, where BO rendering is Twig-first.
- Decision: `BackOfficeConfigurationForm::renderConfigurationForm()` now requires Twig and no longer falls back to Smarty templates.
- Impact: BO configuration rendering is simpler, explicit, and aligned with `9.2.0` expectations.

## 2026-03-17

### D-014
- Context: `PS_ONE_PAGE_CHECKOUT_ENABLED` is no longer provisioned by Core and must be fully owned by the module lifecycle.
- Decision: create `PS_ONE_PAGE_CHECKOUT_ENABLED` during module install with value `0`, and remove it during module uninstall instead of recreating it with value `0`.
- Impact: module installation remains self-sufficient without activating OPC by default, and uninstall leaves no stale OPC configuration entry behind.

## 2026-03-23

### D-015
- Context: `opcFinalSubmitStarted` was still treated as a Core-side runtime dependency while the checkout flow had already moved into `ps_onepagecheckout`.
- Decision: the module owns the emission of `opcFinalSubmitStarted`, and must ship the runtime asset that emits it during final checkout submit.
- Impact: the JS contract required by guest-init and final-submit protections stays available even when the module owns the checkout process.

### D-016
- Context: registration success messaging was discussed during OPC migration, but the module does not own the registration controller lifecycle.
- Decision: `RegistrationController` and the `Account successfully created` success message remain Core-owned and must not be duplicated or overridden by `ps_onepagecheckout`.
- Impact: the module stays focused on checkout behavior and avoids reintroducing registration logic outside Core.

### D-017
- Context: the dedicated BO tab `AdminPsOnePageCheckout` appends module configuration content after the legacy admin controller initialization step.
- Decision: only append configuration content when BO view access is granted.
- Impact: unauthorized employees cannot render the module configuration content through the dedicated BO controller.

### D-018
- Context: the migrated checkout runtime depends on an existing JS event contract that has two distinct responsibilities.
- Decision: document and test `opcFinalSubmitStarted` as both a listener contract for existing runtime code and an emitter contract owned by `ps_onepagecheckout`.
- Impact: the module preserves compatibility with current listeners while making ownership of the final-submit event explicit.

### D-019
- Context: guest-init legacy behavior could rebind a brand new anonymous cart to an older guest account when the submitted email already matched an existing guest.
- Decision: ownership is `1 anonymous cart = 1 guest customer`. A guest may be reused only for the same cart already linked to that guest, never for a fresh anonymous cart.
- Impact: a new anonymous cart must create a new guest even when the submitted email matches an older guest account; legacy reuse scenarios must not be reintroduced.
