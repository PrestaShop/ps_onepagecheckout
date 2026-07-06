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

### D-020
- Context: Core fires `actionCarrierProcess` on every order-controller request — including each carrier radio selection (`Controller.php:297-323` run order + `OrderController.php:253` + `CheckoutDeliveryStep.php:143`) — while the module fired it only in the Pay-click submit pipeline, so binary payment orders (`PaymentOption::setBinary(true)`) never received it.
- Decision: fire `actionCarrierProcess` in `OnePageCheckoutSelectCarrierHandler` right after the delivery option is persisted, guarded to never fire against a mounted temp address; keep the submit-time call. Full parity matrix and accepted deltas in [`docs/MODULE_COMPATIBILITY.md`](./MODULE_COMPATIBILITY.md).
- Impact: carrier modules receive the hook at selection time exactly like Core, including for binary orders; no fire ever exposes a `temp_opc_*` placeholder.

### D-021
- Context: the payment endpoint observes the persisted inline address through the cart pointer set by the autosave (the client cannot read the hidden persisted id — `getVisibleInlineAddressId` excludes `type="hidden"` inputs to protect the "typed fields are authoritative" submit contract), but the carriers and selectcarrier endpoints mount a temp address from the raw inline fields, so every inline-journey shipping computation ran against a throwaway `temp_opc_*` placeholder even after a real address was persisted on the cart.
- Decision: add a dedicated `getPersistedInlineAddressId` helper (reads the hidden persisted-id field while the inline form is visible; `getVisibleInlineAddressId` stays untouched), source `buildCarriersUrl` and the selectcarrier payload from it, mirror the carriers-handler id-branch in the selectcarrier handler via a shared ownership/invoice guard, prefer a front-sent invoice id server-side, and keep the temp branch untouched as fallback for the pre-persist window. Developed TDD-first with fixture modules listening to `actionCarrierProcess`, `paymentOptions`, `displayPaymentByBinaries` and `displayCarrierExtraContent`, asserting they observe the persisted address (not a temp placeholder) when payment methods render or delivery methods are listed/selected. Details in [`docs/MODULE_COMPATIBILITY.md`](./MODULE_COMPATIBILITY.md).
- Impact: carrier and payment modules always compute against real persisted addresses in nominal flows, with a minimal regression surface (inline journey routed onto the already-exercised saved-address path).

### D-022
- Context: while an already persisted inline address is being edited, the autosave is debounced/serialized internally with no pending signal, so a fetch triggered by `updatedCart` during that window computes carrier and payment options against the pre-edit address row (only the country is compensated from request parameters).
- Decision: emit a draft-pending signal from the autosave and chain `updatedCart`-triggered carrier/payment fetches behind the settling of the current draft save only — ordering-only, one hop, fetch guaranteed on success and failure. Details and TDD assertion in [`docs/MODULE_COMPATIBILITY.md`](./MODULE_COMPATIBILITY.md).
- Impact: modules never observe pre-edit address data on cart-mutation refreshes, and a failed autosave can never strand a section loader.

### D-023
- Context: the Core theme binary machinery (`themes/_core/js/checkout-payment.js:41-44`) only reacts to real `change` events, which OPC's AJAX payment-list injections never re-dispatch; binary containers are also not gated during refreshes while the native Pay button is.
- Decision: re-dispatch a synthetic `change` on the checked payment radio after every payment-list DOM replacement (`toggleOrderButton` itself handles the no-selection case; manual hide only when no radio remains), gate the canonical `prestashop.selectors.checkout.paymentBinary` blocks on the same `isCheckoutRefreshing` condition as the native Pay button through a dedicated class (Core's terms gating co-writes `disabled`), and guarantee amount freshness through `context_refresh`-before-events (D-024) since OPC cannot reproduce Core's full-page reload on carrier change at the payment step. Gated red-first: the desync scenarios must fail as Playwright specs against unfixed HEAD before this code lands. Details in [`docs/MODULE_COMPATIBILITY.md`](./MODULE_COMPATIBILITY.md) section 4.
- Impact: `PaymentOption::setBinary(true)` modules keep a consistent button state across OPC AJAX refreshes, with no stale clickable block and no payable-button dead-end.

### D-024
- Context: `emitWithContext` applies a response's `context_refresh` before emitting, but several mutating flows (saved-address selection, carrier selection, address-form re-render, modal save, delete flows) emit without it, and module HTML injected during re-renders executes before the same response's sync; `tax-display-*` and `prestashop.customer` are never re-synced.
- Decision: route every mutating-response emission through `emitWithContext`, apply the context sync before injecting response HTML in render paths, and extend the `context_refresh` payload with the tax-display class and a post-guest-init customer refresh. `prestashop.cart` freshness is guaranteed for `totals` and carried fields only (documented boundary). Details in [`docs/MODULE_COMPATIBILITY.md`](./MODULE_COMPATIBILITY.md) section 5.
- Impact: fraud tools and carrier/payment SDKs reading body classes or `window.prestashop.*` inside event handlers always observe post-mutation values, matching the native checkout's full-render consistency.
