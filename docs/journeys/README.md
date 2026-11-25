# Journeys

The buyer and merchant journeys this module keeps working, for anyone who tests a change, by hand or with the
`opc-qa` skill. Each journey's steps and expected results live in its source document, a file of this folder or
`docs/E2E_RUNBOOK.md`; this index maps a changed file to the journeys it feeds, so QA walks what the change can break.
A journey enters this folder with the change that makes it pass, as one file and one row. When a source document
changes, this index follows it.

## Journeys

| Id | Journey | Source (steps and expected results) | Files that feed it |
|---|---|---|---|
| `email` | Guest e-mail field explains what is wrong with the typed value | `docs/journeys/adaptive-email.md` | `views/js/opc-guest-init.js`, `views/js/runtime/form/opc-field-errors.js`, `views/templates/front/checkout/_partials/one-page-checkout/contact-section.tpl` |
| `express` | Express checkout slot above the contact section, absent and present | `docs/journeys/express-checkout-slot.md` | `views/templates/front/checkout/checkout.tpl` |
| `guest-init` | Guest init: new, repeated and registered e-mails, token and cart edge cases | `docs/E2E_RUNBOOK.md`, "Functional parity checkpoints" 1 | `views/js/opc-guest-init.js`, `views/js/runtime/address/opc-contact-consent.js`, `controllers/front/guestinit.php`, `src/Checkout/Ajax/Customer/**`, `src/Checkout/ExistingCustomerState.php`, `views/templates/front/checkout/_partials/one-page-checkout/contact-section.tpl`, `views/templates/front/checkout/_partials/one-page-checkout/connected-account-info.tpl` |
| `address-form` | Address form refresh: country switch, delivery and billing state kept, address events emitted | `docs/E2E_RUNBOOK.md`, "Functional parity checkpoints" 2 | `views/js/opc-address.js`, `views/js/address-events.js`, `views/js/runtime/address/**`, `controllers/front/addressform.php`, `controllers/front/addresseslist.php`, `controllers/front/selectaddress.php`, `controllers/front/saveaddress.php`, `controllers/front/savedraft.php`, `controllers/front/states.php`, `src/Checkout/Ajax/Address/**`, `src/Checkout/Ajax/TempAddressStorage.php`, `src/Checkout/Ajax/AddressDraftStorage.php`, `src/Checkout/Context/**`, `src/Form/Address*`, `src/Form/OnePageCheckoutAddress*`, `views/templates/front/_partials/form-fields-row.tpl`, `views/templates/front/checkout/_partials/one-page-checkout/address*.tpl`, `views/templates/front/checkout/_partials/one-page-checkout/awaiting-address.tpl` |
| `address-modal` | Address modal: create and edit, delivery and billing, country reload, save keeps the selection | `docs/E2E_RUNBOOK.md`, "Functional parity checkpoints" 3 | `views/js/opc-address-modal.js`, `controllers/front/addressmodal.php`, `controllers/front/saveaddress.php`, `controllers/front/deleteaddress.php`, `controllers/front/states.php`, `src/Checkout/Ajax/Address/**`, `views/templates/front/checkout/_partials/one-page-checkout/address-modal*.tpl`, `views/templates/front/checkout/_partials/one-page-checkout/delete-address-modal.tpl` |
| `delivery` | Carriers reload, carrier choice refreshes the summary and survives a refresh | `docs/E2E_RUNBOOK.md`, "Functional parity checkpoints" 4 | `views/js/opc-carrier-list.js`, `views/js/opc-carrier-select.js`, `views/js/opc-cart-summary-state.js`, `views/js/runtime/opc-option-list-state.js`, `controllers/front/carriers.php`, `controllers/front/selectcarrier.php`, `controllers/front/carttotals.php`, `src/Checkout/Ajax/Carrier/**`, `views/templates/front/checkout/_partials/one-page-checkout/delivery-section.tpl`, `views/templates/front/checkout/_partials/one-page-checkout/carriers.tpl` |
| `submit` | Final submit: payment methods, order options, one `opcFinalSubmitStarted` per attempt, order placed | `docs/E2E_RUNBOOK.md`, "Functional parity checkpoints" 5 and "Mandatory verification points" | `views/js/opc-submit.js`, `views/js/opc-payment-list.js`, `views/js/opc-payment-select.js`, `views/js/opc-gift-wrapping.js`, `controllers/front/opcsubmit.php`, `controllers/front/paymentmethods.php`, `controllers/front/selectpayment.php`, `controllers/front/giftwrapping.php`, `src/Checkout/Ajax/Payment/**`, `src/Checkout/Ajax/OrderOptions/**`, `src/Checkout/Ajax/Submit/**`, `src/Checkout/PaymentSelectionKeyBuilder.php`, `views/templates/front/checkout/_partials/one-page-checkout/payment-*.tpl`, `views/templates/front/checkout/_partials/one-page-checkout/order-options.tpl`, `views/templates/front/checkout/_partials/one-page-checkout/opc-error.tpl` |
| `back-office` | Back-office tab and Module Manager "Configure" entry, allowed and denied by permissions | `docs/E2E_RUNBOOK.md`, "Mandatory verification points" | `ps_onepagecheckout.php`, `controllers/admin/**`, `src/Form/BackOfficeConfigurationForm.php`, `views/js/opc-checkout-layout.js`, `views/templates/admin/**`, `views/img/checkout/**`, `views/css/**` |

## Files every buyer journey depends on

A change to one of these maps to `submit`, a whole guest checkout from cart to order, plus any row above it also
matches: `ps_onepagecheckout.php`, `views/js/events.js`, `views/js/selectors.js`,
`views/js/runtime/opc-runtime.js`, `views/js/runtime/opc-context-sync.js`, `views/js/runtime/form/**`,
`views/scss/**`, `views/templates/front/checkout/checkout.tpl`,
`views/templates/front/checkout/_partials/steps/one-page-checkout.tpl`,
`views/templates/front/checkout/_partials/one-page-checkout/opc-loader.tpl`,
`controllers/front/AbstractOpcJsonFrontController.php`, `src/Checkout/*.php`, `src/Checkout/Ajax/Shared/**`,
`src/Form/OnePageCheckoutForm*.php`, `views/webpack.config.js`, `views/package.json`, `views/package-lock.json`.

## Built files and files with no journey

- `views/public/<entry>.bundle.js` (and its `.LICENSE.txt`) maps like its source in the `entry` list of
  `views/webpack.config.js`;
  `views/public/one-page-checkout.css` like `views/scss/**`.
- No journey: `docs/**`, `tests/**`, `scripts/**`, `.github/**`, `.agents/**`, `.claude/**`, `translations/**`,
  `upgrade/**`, `composer.json`, `composer.lock`, `config.xml`, `README.md`, every `index.php` guard file.
- A changed file that matches no row and is not in this list is a gap in this map: the report lists it under
  "Unmapped files", never dropped.
