# ps_onepagecheckout E2E Runbook (Core vs Module Parity)

## Mandatory preflight

1. Regenerate module autoload (required for FO runtime classes):
```bash
composer dump-autoload -d modules/ps_onepagecheckout
```

2. Fix container writable paths (BO/FO 500 if missing):
```bash
docker compose exec -T prestashop-git sh -lc 'chown -R www-data:www-data var && chmod -R ug+rwX var'
```

3. Ensure BO credentials used by UI tests exist:
- `demo@prestashop.com / Correct Horse Battery Staple`

4. Use real DB runtime values from docker defaults:
- `DB_NAME=prestashop`
- `DB_PASSWD=prestashop`
- `DB_PREFIX=ps_`

5. Ensure the checkout runtime loads the module-owned final submit asset:
- `views/public/opc-submit.bundle.js`
- browser console should expose the runtime event `opcFinalSubmitStarted` during final submit

6. Before moving to a new checkout migration lot, run the module unit suite:
```bash
cd modules/ps_onepagecheckout
./scripts/run-tests.sh unit
```

## Functional parity checkpoints

When validating a Core-to-module port, verify at minimum:

1. Guest init:
- anonymous cart + new email,
- anonymous cart + same email as an older guest must still create a new guest for the new cart,
- same anonymous cart + refresh or consent toggles must not create a new guest,
- anonymous cart + existing registered email,
- existing guest email update,
- invalid token,
- missing persisted cart row.

2. Address form refresh:
- country switch refreshes the form,
- delivery and billing form state are preserved,
- both `updatedOpcAddressForm` and structured OPC address events are emitted.

3. Address modal flow:
- delivery modal opens in create and edit mode,
- billing modal opens in create and edit mode,
- changing the country reloads state options in the modal,
- saving a delivery address refreshes the OPC form and keeps the selected address on cart,
- saving an invoice address refreshes the OPC form and keeps `use_same_address=0` visible when applicable.

4. Delivery dynamic:
- carriers reload on initial page load,
- carriers reload after a delivery address refresh,
- selecting a carrier refreshes the checkout summary preview,
- the selected carrier survives the refresh when still valid,
- an invalid carrier selection is reset when the delivery address changes.

5. Final submit parity:
- module JS emits `opcFinalSubmitStarted` on the final OPC submit,
- guest-init stops reacting once `opcFinalSubmitStarted` is emitted,
- delivery option persists before final submit,
- delivery message, recyclable, gift, and gift message persist on final submit,
- payment methods load after the delivery state becomes valid,
- selecting a payment method persists `selected_payment_module` across payment refreshes,
- payment methods refresh after delivery-address and carrier updates without leaving stale panels open,
- free carts render the payment section without blocking final submit,
- checkout still falls back to native flow when the provider module is disabled.

## Sandbox-specific workaround

In restricted environments, `maildev` import can fail with:
- `uv_interface_addresses returned Unknown system error 1`

Use a Node preload polyfill during UI runs:
```bash
cat > /tmp/node-networkinterfaces-polyfill.js <<'EOF'
const os = require('os');
if (typeof os.networkInterfaces === 'function') {
  const original = os.networkInterfaces.bind(os);
  os.networkInterfaces = () => {
    try { return original(); } catch (e) {
      return {lo: [{address: '127.0.0.1', netmask: '255.0.0.0', family: 'IPv4', mac: '00:00:00:00:00:00', internal: true, cidr: '127.0.0.1/8'}]};
    }
  };
}
EOF
```

## Known non-functional blockers

1. Locale-dependent BO assertions can fail on FR-only shops (`Tableau de bord` vs `Dashboard`).
2. Selector drift in FO summary blocks can create false negatives (`.cart-summary` vs legacy totals selectors).

When these happen, fix the E2E selector/assertion to be locale-agnostic and structure-agnostic, not the checkout behavior.

## Mandatory verification points

Before declaring Core-to-module migration behavior green, verify:
- final OPC submit emits `opcFinalSubmitStarted` exactly once per submit attempt,
- `opc-guest-init.js` still listens to `opcFinalSubmitStarted` and stops guest-init side effects after the event,
- guest init listeners stop reacting once final submit has started,
- the dedicated BO tab `AdminPsOnePageCheckout` is only usable by an employee with view access,
- the Module Manager `Configure` entry stays reachable for authorized employees and denied for unauthorized ones according to BO permissions,
- no module controller, override, or module UI path attempts to replace `RegistrationController` or its success flash.
