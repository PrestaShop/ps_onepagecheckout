# Releasing ps_onepagecheckout

How a release is built, published, and — critically — **which file installs in the back
office**. Written after SPE-152, where the 0.6.2/0.6.3 zips were reported as impossible to
upload through BO > Modules > Module Manager.

## Publish a release

1. Bump the version everywhere the workflow validates it: `config.xml`,
   `ps_onepagecheckout.php` (`$this->version`), `views/package.json`,
   `views/package-lock.json`.
2. Run the **Publish release** workflow (`.github/workflows/publish-release.yml`) from
   `main`, with the version without a leading `v`.
3. The workflow creates a **draft** release by default. Review it (notes, asset), then
   **publish it — this is part of the release, not optional**: a draft's assets are only
   downloadable by authenticated maintainers; merchants, QA shops and `curl`/`wget` get an
   error page instead of the zip. Releases 0.4.0 → 0.6.3 stayed drafts, which is how
   "the zip cannot be installed" reports (SPE-152) get fed.
4. `prerelease` stays `true` while the module is 0.x.

The workflow rebuilds the module (composer no-dev + webpack assets), zips it as
`ps_onepagecheckout/…` and validates the exact contract the PrestaShop Module Manager
enforces before creating the tag and the release.

## Which file installs in the BO

On the release page, **only the uploaded asset `ps_onepagecheckout.zip` can be installed**
via BO > Modules > Module Manager > Upload a module.

The two "Source code (zip / tar.gz)" archives GitHub generates automatically **cannot**:

- their root folder is version-suffixed (`ps_onepagecheckout-0.6.3/`), so the core's
  `ZipSourceHandler` (which resolves the module from a `<module>/<module>.php` entry) rejects
  them with *"This file does not seem to be a valid module zip"*;
- they contain the raw sources (no `vendor/`, no built `views/public/` assets), so even
  extracted by hand the module cannot run.

The `.tar.gz` source archive is additionally refused by the upload dropzone (only `.zip` and
`.tar` are accepted).

## Upload contract (verified against PrestaShop 9.2 / develop)

The asset zip is checked by the workflow against what the BO upload actually requires:

- a single root folder named exactly `ps_onepagecheckout/` (every entry beneath it);
- `ps_onepagecheckout/ps_onepagecheckout.php` present (module-name resolution);
- `ps_onepagecheckout/vendor/autoload.php` present (built module).

Both the fresh-install upload and the re-upload of the same zip over an installed module
(upgrade path) were verified working with the 0.6.3 artifact on a vanilla 9.2 shop.

## Known exotic failure mode (not addressed)

The vendor autoloader class name (`ComposerAutoloaderInit<hash>`) is deterministic per
`composer.lock`, so if two coherently-renamed copies of the module end up loaded in one
request (dev/QA shops with duplicated module folders), the second `require vendor/autoload.php`
fatals with an HTTP 500 "Cannot declare class ComposerAutoloaderInit…" on the upload
endpoint. No stock PrestaShop flow triggers this — a naively copied backup folder is never
instantiated, since its folder and main-file names no longer match — so it is documented here
instead of being guarded against in code.
