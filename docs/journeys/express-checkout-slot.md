# E2E user journey — express checkout slot

Feature: the `displayExpressCheckout` hook renders at the top of the one-page checkout,
above the contact section, so express/wallet payment modules can appear on the checkout.
The wrapper renders only when a module returns content, so it must be tested both ways.

## Scenario A — no express-capable module (default install)

Preconditions: one-page checkout with a cart, and no payment module hooked to
`displayExpressCheckout` returning content.

Steps / assertions:

1. Open `/order`.
2. `document.querySelector('.opc-express-checkout')` is `null` — the wrapper is not in the DOM.
3. The checkout renders normally: `.js-opc-contact-section` is present and the flow works
   end to end. The page is byte-identical to before the feature.

## Scenario B — an express module returns content

Preconditions: any module hooked to `displayExpressCheckout` that renders a button on this
page (e.g. a wallet/PayPal-express module; for a pure UI test a fixture module that outputs
a single `<button>` on the hook is enough).

Steps / assertions:

1. Open `/order`.
2. `.opc-express-checkout` is present and visible.
3. The module's button(s) render inside `.opc-express-checkout__buttons`.
4. `.opc-express-checkout__separator` follows, containing the localized "or" label
   (`.opc-express-checkout__separator-label`).
5. The whole `.opc-express-checkout` block sits above `.js-opc-contact-section`
   (compare bounding-box `top`, or DOM order within `.checkout-grid__content`).

## Notes for automation

- The "or" label is translated (`Modules.Onepagecheckout.Shop`); assert the resolved
  shop-language text.
- This PR covers the render slot only (issue #105 phase 0). The express quote/commit
  contract (`expressquote` / `expresscommit`) that would let a wallet drive the checkout is
  a separate phase and not part of this journey.
