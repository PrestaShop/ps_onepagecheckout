# Core Porting Playbook

## Goal

Use this playbook when a checkout behavior exists in PrestaShop Core and must be migrated to `ps_onepagecheckout` with minimal Core changes.

## Ownership rules

Put a change in the module when it is:
- checkout business logic,
- OPC AJAX endpoint logic,
- checkout runtime JS,
- module BO configuration,
- test coverage specific to the module.

The module also owns runtime event emission needed by its checkout flow, including `opcFinalSubmitStarted`.
When a Core checkout event already exists, document separately:
- listener compatibility already expected by the existing runtime,
- emitter ownership once the module becomes responsible for triggering the event.

Put a change in the active theme only when it is page chrome unrelated to OPC step content (the surrounding `checkout.tpl` wrapper, breadcrumb, notifications, cart-summary panel). All OPC-step templates, partials, styles, and runtime JS live in this module.

Put a change in Core only when:
- the module would otherwise need an override,
- the module cannot inject itself through an existing hook or service contract,
- fallback native checkout behavior must stay consistent when the module is disabled.

Keep Core ownership for registration and authentication entry points that are not module-specific. `RegistrationController` and its success messaging remain Core responsibilities unless a future scope explicitly changes that architecture.
Do not introduce a module `RegistrationController` or override the Core controller as part of OPC migration work.
Do not port legacy guest-init behaviors that reattach a fresh anonymous cart to an older guest account. When parity conflicts with the validated product rule `1 anonymous cart = 1 guest customer`, keep the module on the validated product rule and document the divergence.

## Analysis procedure

1. Identify the Core PR behavior and its observable contracts.
2. Classify each change as `Core`, `module`, or `theme`.
3. Compare the current module behavior to the Core behavior already in production.
4. Correct parity gaps already present in the module before adding new features.
5. Add tests before or alongside each functional lot.

## Mandatory parity checklist

Before porting a new Core change, verify:
- PHP payload shape,
- AJAX JSON keys,
- template variables,
- JS event names,
- runtime URL definitions,
- native checkout fallback when the provider module is disabled,
- BO access control for both the dedicated module tab and the Module Manager `Configure` entry,
- whether the behavior belongs to module runtime or must remain Core-owned.

## Recommended lot order

1. parity fixes,
2. address modal,
3. delivery dynamic,
4. payment dynamic,
5. documentation and runbook updates.

## Test workflow

Each functional lot must provide:
- at least one unit test for the new handler/controller or runtime contract,
- at least one integration test for the business behavior,
- explicit coverage for any JS event contract introduced or migrated into the module,
- rebuilt bundles when `views/js/*` changes,
- a runbook update when FO verification points change.

## Expected output for future plans

Every future migration plan should state:
- source PR or Core behavior,
- target ownership (`Core`, `module`, `theme`),
- blocking points,
- files to add or update,
- unit tests,
- integration tests,
- regression risks.
