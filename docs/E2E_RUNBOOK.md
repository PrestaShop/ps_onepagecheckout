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

## Campaign commands

Run with explicit env (do not rely on defaults):
```bash
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null
cd tests/UI

NODE_OPTIONS='--require /tmp/node-networkinterfaces-polyfill.js' \
URL_FO='http://localhost:8001/' \
URL_BO='http://localhost:8001/admin-dev/' \
URL_API='http://localhost:8001/admin-api/' \
URL_INSTALL='http://localhost:8001/install-dev/' \
LOGIN='demo@prestashop.com' \
PASSWD='Correct Horse Battery Staple' \
FIRSTNAME='Marc' \
LASTNAME='Beier' \
DB_SERVER='127.0.0.1' \
DB_NAME='prestashop' \
DB_USER='root' \
DB_PASSWD='prestashop' \
DB_PREFIX='ps_' \
SHOP_NAME='thb' \
BROWSER='chromium' \
BROWSER_LANG='en-GB' \
BROWSER_WIDTH='1680' \
BROWSER_HEIGHT='900' \
HEADLESS='true' \
SLOW_MO='5' \
SMTP_PORT='1025' \
SMTP_SERVER='localhost' \
TEST_PATH='regression/checkout/101_opcGuestInit_FO.ts' \
EXTRA_TEST_PARAMS='--reporter spec' \
npm run test:specific:fast-fail
```

Same command for parity campaign with:
- `TEST_PATH='regression/checkout/102_opcBoConfigurationParityAndOneStepE2E.ts'`

## Known non-functional blockers

1. Locale-dependent BO assertions can fail on FR-only shops (`Tableau de bord` vs `Dashboard`).
2. Selector drift in FO summary blocks can create false negatives (`.cart-summary` vs legacy totals selectors).

When these happen, fix the E2E selector/assertion to be locale-agnostic and structure-agnostic, not the checkout behavior.
