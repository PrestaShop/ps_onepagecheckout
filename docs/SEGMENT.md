# Segment in `ps_onepagecheckout`

This document describes the **configuration** and **technical integration** of Segment via the **PHP SDK** (`segmentio/analytics-php`), following the same approach as the [PrestaShop autoupgrade](https://github.com/PrestaShop/autoupgrade) module ([`Analytics`](https://github.com/PrestaShop/autoupgrade/blob/dev/classes/Analytics.php) class).

**Current behavior**: `Segment::init()` is initialized when conditions are met. **No** `track` / `flush` calls are sent from the module yet — this will be addressed in future tickets.

**Summary**: write key read from a file/environment variables (`SEGMENT_PREPROD_KEY`, `SEGMENT_PROD_KEY`) → `Segment::init()` is executed **on demand**, on the **first tracking call** (no hook dependency).

The PHP class is named **`Analytics`** (intentionally **generic**) to minimize renames if the provider changes.

**Front office**: no browser SDK loaded (`analytics.js`); the old `opc-segment-init` bundle has been removed.

## Composer Dependency

- `segmentio/analytics-php` (see `composer.json`). After cloning: run `composer install` at the module root to get the vendor directory and autoload.

## Keys and Constants

| Source | Identifier | Role | Default |
|--------|------------|------|---------|
| Environment | `SEGMENT_PREPROD_KEY` | Write key for the Segment **PHP source** (preprod) — **single source of truth** (no `configuration`). | `''` |
| Environment | `SEGMENT_PROD_KEY` | Write key for the Segment **PHP source** (prod) — **single source of truth** (no `configuration`). | `''` |

### Key Selection Rule

- If `_PS_MODE_DEV_` is `false` → uses `SEGMENT_PROD_KEY`
- If `_PS_MODE_DEV_` is `true` → uses `SEGMENT_PREPROD_KEY`

## PHP Architecture

| File | Role |
|------|------|
| `src/Analytics/Analytics.php` | `bootstrap(bool $moduleSegmentEnabled)`: checks module activation and non-empty key, then calls `Segment\Segment::init($writeKey)`. `trackEvent(...)` initializes Segment on the fly and sends the event on a best-effort basis. |

The module no longer depends on a hook to initialize Segment: the client is initialized on demand (on the first `trackEvent`).

### Notable Differences from the Old (Browser) Version

- No more `window.psopc_segment` or `opc-segment-init.bundle.js`.
- The **PHP** keys (`SEGMENT_PREPROD_KEY` / `SEGMENT_PROD_KEY`) are not the same Segment source as the old **JavaScript** key; configure it in the Segment workspace (PHP source).

## Execution Context

`Segment::init()` is triggered on the first tracking call (via `Analytics::trackEvent(...)`) if a write key is present.

## Back Office Configuration

Segment is considered **enabled** as long as the module is enabled (and a non-empty write key is provided).

## Events (`track`) — Coming Soon

`Segment::track()` / `flush()` calls will be added in future tickets, once the business events are defined.

## Module Lifecycle

No dedicated Segment configuration key: activation follows module activation.

## Useful Files

- `src/Analytics/Analytics.php`
- `composer.json` — `segmentio/analytics-php` dependency

## Limitations

- The write key is provided via environment (local / preprod / prod): configure it at the platform level (environment variable / secret).
- `Segment::init` is called in the context of requests that trigger the hook (typically module config pages in the back office).
