# One-page checkout (ps_onepagecheckout)

## About

`ps_onepagecheckout` migrates the native one-page checkout logic from Core to a dedicated native module.

## Compatibility

PrestaShop: `9.0.0` or later.

## What this module provides in MVP

- checkout process injection through `actionCheckoutBuildProcessBefore`,
- guest initialization endpoint with parity contract,
- OPC address form refresh endpoint with parity contract,
- one-step checkout toggle based on `PS_ONE_PAGE_CHECKOUT_ENABLED`.

## Back office behavior

- `Configure` from Module Manager edits `PS_ONE_PAGE_CHECKOUT_ENABLED`.
- dedicated BO tab (`AdminPsOnePageCheckout`) renders the same module-owned configuration flow.
- layout selector UI keeps parity with the historical Core checkout layout experience (recommended badge, feature list, illustrations), fully owned by module templates/assets.
- disable/uninstall forces `PS_ONE_PAGE_CHECKOUT_ENABLED=0` (4-step checkout).

## JS Development

From `modules/ps_onepagecheckout/views`:

```bash
npm install
npm run watch
npm run build
```

## Decision tracking

- implementation rules: [docs/RULES.md](./docs/RULES.md)
- decision log: [docs/DECISIONS.md](./docs/DECISIONS.md)

## License

This module is released under the [Academic Free License 3.0][AFL-3.0].

[AFL-3.0]: https://opensource.org/licenses/AFL-3.0
