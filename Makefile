# Build tooling for ps_onepagecheckout.
#
# The `scoper` target is invoked by the shared release workflow
# (PrestaShop/.github build-release.yml -> build-module-artifact), which runs
# `composer install --no-dev -o` and then `make <makefile_rule>`. See
# .github/workflows/build-release.yml.
#
# It scopes the Segment analytics SDK (and the module code that imports it)
# under the module-specific namespace PrestaShop\Module\PsOnePageCheckout\Vendor
# so Segment is shipped inside the module without being a runtime dependency of
# PrestaShop Core. Configuration lives in scoper.inc.php.

PHP ?= php
COMPOSER ?= composer

PHP_SCOPER_VERSION ?= 0.18.19
PHP_SCOPER_PHAR := php-scoper.phar
PHP_SCOPER_URL := https://github.com/humbug/php-scoper/releases/download/$(PHP_SCOPER_VERSION)/php-scoper.phar
SCOPED_OUTPUT_DIR := build-scoped

.PHONY: scoper scoper-clean

# Download the pinned php-scoper PHAR (self-contained, mirrors ps_accounts/ps_metrics).
# A version-pinned PHAR avoids dragging php-scoper's own dependencies into the
# module's vendor tree and keeps the build reproducible.
$(PHP_SCOPER_PHAR):
	curl -s -f -L -o $(PHP_SCOPER_PHAR) "$(PHP_SCOPER_URL)"
	chmod +x $(PHP_SCOPER_PHAR)

scoper: $(PHP_SCOPER_PHAR)
	# The release workflow already installed production deps only (--no-dev); the
	# Segment SDK lives in require-dev, so pull dev deps to obtain it before scoping.
	$(COMPOSER) install --prefer-dist --no-interaction --quiet
	# Rewrite the Segment namespace into the module's Vendor prefix.
	$(PHP) $(PHP_SCOPER_PHAR) add-prefix --output-dir=$(SCOPED_OUTPUT_DIR) --force --quiet
	# Apply the scoped module code (rewrites `use Segment\Segment;`).
	cp -a $(SCOPED_OUTPUT_DIR)/src/Analytics/. src/Analytics/
	# Prune every dev dependency from vendor/ (Segment included) so the released
	# artifact stays lean and never ships the build tooling.
	$(COMPOSER) install --no-dev --optimize-autoloader --no-interaction --quiet
	# Re-place the SCOPED Segment SDK that the prune above just removed. It is now
	# owned by the module, mapped through the psr-4 entry in composer.json rather
	# than by Composer's package metadata.
	rm -rf vendor/segmentio
	mv $(SCOPED_OUTPUT_DIR)/vendor/segmentio vendor/segmentio
	# Regenerate the production autoloader so the scoped Segment classes are mapped.
	$(COMPOSER) dump-autoload --no-dev --classmap-authoritative --optimize --no-interaction
	$(MAKE) scoper-clean

scoper-clean:
	rm -rf $(SCOPED_OUTPUT_DIR) $(PHP_SCOPER_PHAR)
