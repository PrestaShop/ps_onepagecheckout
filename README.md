# One-page checkout (`ps_onepagecheckout`)

## About

`ps_onepagecheckout` is the native PrestaShop module that provides the one-page checkout experience.

> [!WARNING]
> This module is under heavy development. It is not production-ready and should not be used in live environments.

## Compatibility

PrestaShop: `9.0.0` or later.

## What the module owns

- checkout process injection through `actionCheckoutBuildProcess`,
- front office AJAX endpoints for guest initialization and address form refresh,
- back office configuration for enabling or disabling one-page checkout,
- checkout layout configuration assets and templates shipped by the module,
- checkout runtime flag exposure for the order page,
- optional Segment PHP client bootstrap (`segmentio/analytics-php`) on module configuration pages (see [`docs/SEGMENT.md`](./docs/SEGMENT.md)).

## Code map

- [`ps_onepagecheckout.php`](./ps_onepagecheckout.php): module entry point, hook registration, runtime wiring.
- [`src/Checkout`](./src/Checkout): checkout process, availability checks, AJAX handlers.
- [`src/Form`](./src/Form): back office configuration form and checkout form helpers.
- [`controllers/front`](./controllers/front): FO endpoints used by the one-page checkout runtime.
- [`controllers/admin`](./controllers/admin): BO entry point for module configuration.
- [`views/js`](./views/js): checkout client-side behavior.
- [`views/templates`](./views/templates): BO templates and module-owned checkout assets.
- [`tests/php`](./tests/php): unit and integration coverage for the module.

## Contributing

Before opening a PR:

1. Regenerate the module autoload when PHP classes move or are added.
2. Rebuild front assets when changing files under `views/js`, and commit the generated bundles from `views/public` (including `.LICENSE.txt` files).
3. Run the relevant PHP tests for the area you touched.
4. Use the decision log and implementation rules as the source of truth for sensitive changes.

## Local development

### PHP dependencies and autoload

From the repository root, install Composer dependencies (required for `segmentio/analytics-php` and the module autoload):

```bash
composer install -d
composer dump-autoload -d
```

### Front assets

From `ps_onepagecheckout/views`:

```bash
npm install

# Development: rebuild assets on every change under views/js
npm run watch

# Production build: generate bundles consumed by the module
npm run build
```

When you change any file under `views/js`:

- run `npm run build` to regenerate:
  - `views/public/opc-guest-init.bundle.js`
  - `views/public/opc-address.bundle.js`
  - their corresponding `*.LICENSE.txt` files,
- and commit both the source changes and the updated files in `views/public`.

> The CI workflow `js.yml` runs `npm run build` to ensure the assets remain buildable,
> and the shared `build-release` workflow packages the module using the files present in the repository (including the generated bundles).

## Testing

PHP test configuration lives in [`tests/php`](./tests/php):

- unit suite: `modules/ps_onepagecheckout/tests/php/phpunit.xml`
- integration suite: `modules/ps_onepagecheckout/tests/php/phpunit-integration.xml`
- static analysis helpers: `modules/ps_onepagecheckout/tests/php/phpstan.sh`

Run PHPUnit in the same isolated Docker environment locally and in GitHub Actions:

```bash
./scripts/run-tests.sh unit
./scripts/run-tests.sh integration
```

The runner expects a prepared PrestaShop checkout in `../prestashop` by default.
Override it with `PS_ROOT_DIR_HOST=/path/to/prestashop` when needed.
Integration tests provision MySQL automatically inside Docker.

For end-to-end checks, use the dedicated runbook:

- [`docs/E2E_RUNBOOK.md`](./docs/E2E_RUNBOOK.md)

## Project references

- implementation rules: [`docs/RULES.md`](./docs/RULES.md)
- architectural decisions: [`docs/DECISIONS.md`](./docs/DECISIONS.md)
- Segment (configuration & usage): [`docs/SEGMENT.md`](./docs/SEGMENT.md)
- translations (i18n): [`docs/TRANSLATIONS.md`](./docs/TRANSLATIONS.md)
- contributors: [`CONTRIBUTORS.md`](./CONTRIBUTORS.md)

## License

This module is released under the [Academic Free License 3.0][AFL-3.0].

[AFL-3.0]: https://opensource.org/licenses/AFL-3.0
