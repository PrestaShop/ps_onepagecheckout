# Maintenance tooling for ps_onepagecheckout.
#
# `scope-segment` regenerates the committed, namespace-prefixed Segment SDK under
# src/Vendor/Segment. It is a MANUAL step, run only when upgrading the SDK — it is
# deliberately NOT part of the release workflow. The scoped SDK is committed to the
# repository so every install path (release zip, Packagist/Composer source, Core
# bundling) ships runnable, conflict-free code without any build-time scoping.
#
# Upgrade procedure:
#   1. bump "segmentio/analytics-php" in composer.json (require-dev)
#   2. composer update segmentio/analytics-php
#   3. make scope-segment
#   4. review & commit the regenerated src/Vendor/Segment
#
# Requires PHP >= 8.2 (php-scoper requirement).

PHP ?= php
COMPOSER ?= composer

PHP_SCOPER_VERSION ?= 0.18.19
# SHA-256 of the pinned release asset. GitHub release assets are immutable, so a
# mismatch means a corrupted or tampered download — abort.
PHP_SCOPER_SHA256 := 170fb84bd3390defb30f99f7dc39c9a89d10c29973accc26f31c00abc5b25933
PHP_SCOPER_PHAR := php-scoper.phar
PHP_SCOPER_URL := https://github.com/humbug/php-scoper/releases/download/$(PHP_SCOPER_VERSION)/php-scoper.phar
SCOPED_OUTPUT_DIR := build-scoped
SEGMENT_TARGET_DIR := src/Vendor/Segment

.PHONY: scope-segment scope-clean

# Download the pinned php-scoper PHAR and verify its checksum before execution.
$(PHP_SCOPER_PHAR):
	curl -s -f -L -o $(PHP_SCOPER_PHAR) "$(PHP_SCOPER_URL)"
	@command -v sha256sum >/dev/null 2>&1 && SHA="sha256sum" || SHA="shasum -a 256"; \
		echo "$(PHP_SCOPER_SHA256)  $(PHP_SCOPER_PHAR)" | $$SHA -c -
	chmod +x $(PHP_SCOPER_PHAR)

# Regenerate src/Vendor/Segment from the installed segmentio/analytics-php SDK.
scope-segment: $(PHP_SCOPER_PHAR)
	# Segment lives in require-dev; ensure it is installed before scoping.
	$(COMPOSER) install --prefer-dist --no-interaction --quiet
	# Prefix the SDK namespace (config in scoper.inc.php) into a clean output dir.
	$(PHP) $(PHP_SCOPER_PHAR) add-prefix --output-dir=$(SCOPED_OUTPUT_DIR) --force --quiet
	# Replace the committed copy with the freshly scoped sources.
	rm -rf $(SEGMENT_TARGET_DIR)
	mkdir -p $(SEGMENT_TARGET_DIR)
	cp -a $(SCOPED_OUTPUT_DIR)/. $(SEGMENT_TARGET_DIR)/
	$(MAKE) scope-clean

scope-clean:
	rm -rf $(SCOPED_OUTPUT_DIR) $(PHP_SCOPER_PHAR)
