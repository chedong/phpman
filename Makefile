# phpMan CI/CD tasks
# This Makefile is for the phpMan maintainer's CI/CD pipeline (staging, release,
# rollback, cache management). Requires SSH access to target servers.
#
# If you're looking to install phpMan on your own server, use install.sh instead:
#   curl -fsSL https://raw.githubusercontent.com/chedong/phpman/master/install.sh | bash
#
# Usage:
#   make test
#   make staging                  # staging: push code + CSS only
#   make staging-reindex          # staging: push code + rebuild search index
#   make release                  # production: push code + CSS only
#   make release-reindex          # production: push code + rebuild search index
#   make reindex                  # production: rebuild search index only (no code push)
#   make reindex-staging          # staging: rebuild search index only
#   make rollback
#   make verify
#   make logcheck
#   make cache-flush / cache-flush-staging / cache-stats
#
# Requires .deploy.mk — copy from .deploy.mk.example and configure
#
# ─── When to use release-reindex ───
# Only rebuild the search index when search logic changes:
#
#   NEED reindex:
#     - search_fts schema / tokenizer / prefix changes
#     - search_index_meta schema / dedup rule changes
#     - expandNameForFts() rule changes
#     - rebuildSearchIndex() data source, section, name normalization changes
#     - System man/pydoc/ri docs were installed/updated (new content needs indexing)
#
#   Do NOT need reindex (plain release is enough):
#     - HTML/CSS/UI changes
#     - Formatter output changes (HTML/Markdown/JSON/MCP)
#     - MCP/JSON wrapper logic changes
#     - PageCache TTL, logging, backup, config directory changes
#     - Security fixes that don't alter search index content
#
# Default release does NOT touch the database. Use release-reindex explicitly
# when index changes are needed — this keeps deployments fast and avoids
# taking the search-index write lock on every code fix.

-include .deploy.mk

ifeq ($(wildcard .deploy.mk),)
$(error Missing .deploy.mk — copy from .deploy.mk.example and configure your server settings)
endif

FILE ?= phpMan.php
CSS_FILE ?= phpman.css

.PHONY: test staging staging-reindex release release-reindex reindex reindex-staging rollback verify logcheck package upload-release clean cache-flush cache-flush-staging cache-stats tag _deploy-code _release-code

GIT_TAG     := $(shell git describe --tags --always --dirty 2>/dev/null || echo "local")
GIT_VERSION := $(shell (git describe --tags --abbrev=0 2>/dev/null || echo "v0.0.0") | sed 's/^v//')

# Resolve remote $HOME so config gets the literal path (mod_fcgid may not set HOME)
STAGING_HOME := $(shell ssh -p $(TEST_PORT) $(TEST_HOST) 'echo $$HOME')
DEMO_HOME    := $(shell ssh -p $(DEMO_PORT) $(DEMO_HOST) 'echo $$HOME')

test:
	php -l $(FILE)
	php -l phpman.config.php.example

# ─── Staging ───

# Internal: push code + CSS to staging (no index rebuild)
_deploy-code:
	@echo "=== Preparing staging server ==="
	@echo "--- Configuring staging home: ~/.phpman_test (debug ON) ---"
	@ssh -p $(TEST_PORT) $(TEST_HOST) " \
		if [ -f $(TEST_PATH)/phpman.config.php ] && [ -s $(TEST_PATH)/phpman.config.php ]; then \
			sed -i \"s|define('PHPMAN_HOME',.*|define('PHPMAN_HOME', '$(STAGING_HOME)/.phpman_test');|\" $(TEST_PATH)/phpman.config.php; \
			sed -i \"s|// define('PHPMAN_DEBUG'.*|define('PHPMAN_DEBUG', true);|\" $(TEST_PATH)/phpman.config.php; \
			sed -i \"s|define('PHPMAN_DEBUG',[[:space:]]*false.*|define('PHPMAN_DEBUG', true);|\" $(TEST_PATH)/phpman.config.php; \
			echo 'Updated phpman.config.php (preserved existing)'; \
		else \
			sed -e \"s|// define('PHPMAN_HOME'.*\\.phpman_test.*|define('PHPMAN_HOME', '$(STAGING_HOME)/.phpman_test');|\" \
			    -e \"s|// define('PHPMAN_DEBUG'.*|define('PHPMAN_DEBUG', true);|\" \
				$(TEST_PATH)/phpman.config.php.example | \
			cat > $(TEST_PATH)/phpman.config.php && chmod 644 $(TEST_PATH)/phpman.config.php && echo 'Created phpman.config.php'; \
		fi"
	sed -e "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" \
	    -e "s/define('PHPMAN_VERSION', '[^']*');/define('PHPMAN_VERSION', '$(GIT_VERSION)');/" $(FILE) | \
		ssh -p $(TEST_PORT) $(TEST_HOST) "cat > $(TEST_PATH)/$(FILE)"; \
	scp -P $(TEST_PORT) $(CSS_FILE) $(TEST_HOST):$(TEST_PATH)/$(CSS_FILE); \
	scp -P $(TEST_PORT) -r cli $(TEST_HOST):$(STAGING_HOME)/.phpman_test/; \
	scp -P $(TEST_PORT) -r tools $(TEST_HOST):$(STAGING_HOME)/.phpman_test/; \
	ssh -p $(TEST_PORT) $(TEST_HOST) "chmod 644 $(TEST_PATH)/$(FILE) $(TEST_PATH)/$(CSS_FILE) && chmod +x \$$HOME/.phpman_test/cli/*.php \$$HOME/.phpman_test/tools/*.php"; \
		ssh -p $(TEST_PORT) $(TEST_HOST) "ln -sf $(TEST_PATH)/phpman.config.php \$$HOME/.phpman_test/phpman.config.php"; \
		ssh -p $(TEST_PORT) $(TEST_HOST) "ln -sf $(TEST_PATH)/$(FILE) \$$HOME/.phpman_test/$(FILE)"
	@echo ""
	@echo "=== Deployed to staging ($(GIT_TAG)) ==="
	@echo "$(TEST_URL)"
	@echo ""

staging: test _deploy-code

staging-reindex: test _deploy-code
	@echo "=== Rebuilding staging search index ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"cd $(STAGING_HOME)/.phpman_test && php cli/build-index.php --cron"
	@echo "=== Staging index rebuild complete ==="

# ─── Production ───

# Internal: push code + CSS to production, with backup
_release-code:
	@echo "=== Deploying $(GIT_TAG) ==="
	@echo "=== Preparing production server ==="
	@echo "--- Configuring home: ~/.phpman ---"
	sed "s|// define('PHPMAN_HOME'.*\.phpman');|define('PHPMAN_HOME', '$(DEMO_HOME)/.phpman');|" \
		phpman.config.php.example | \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"test -f $(DEMO_PATH)/phpman.config.php && cat > /dev/null || cat > $(DEMO_PATH)/phpman.config.php && chmod 644 $(DEMO_PATH)/phpman.config.php && echo 'Created phpman.config.php'"; \
		ssh -p $(DEMO_PORT) $(DEMO_HOST) "ln -sf $(DEMO_PATH)/phpman.config.php \$$HOME/.phpman/phpman.config.php"; \
			ssh -p $(DEMO_PORT) $(DEMO_HOST) "ln -sf $(DEMO_PATH)/$(FILE) \$$HOME/.phpman/$(FILE)"
	@TIMESTAMP=$$(date +%Y%m%d-%H%M%S); \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"mkdir -p \"\$$HOME/.phpman/backups\" && cp $(DEMO_PATH)/$(FILE) \"\$$HOME/.phpman/backups/$(FILE).$${TIMESTAMP}.bak\" 2>/dev/null || true"; \
	echo "=== Pruning old backups (keeping last 5) ==="; \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"ls -1t \"\$$HOME/.phpman/backups/$(FILE).\"*.bak 2>/dev/null | tail -n +6 | xargs rm -f 2>/dev/null || true"; \
	sed -e "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" \
	    -e "s/define('PHPMAN_VERSION', '[^']*');/define('PHPMAN_VERSION', '$(GIT_VERSION)');/" $(FILE) | \
		ssh -p $(DEMO_PORT) $(DEMO_HOST) "cat > $(DEMO_PATH)/$(FILE)"; \
	scp -P $(DEMO_PORT) $(CSS_FILE) $(DEMO_HOST):$(DEMO_PATH)/$(CSS_FILE); \
	scp -P $(DEMO_PORT) -r cli $(DEMO_HOST):$(DEMO_HOME)/.phpman/; \
	scp -P $(DEMO_PORT) -r tools $(DEMO_HOST):$(DEMO_HOME)/.phpman/; \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) "chmod 644 $(DEMO_PATH)/$(FILE) $(DEMO_PATH)/$(CSS_FILE) && chmod +x \$$HOME/.phpman/cli/*.php \$$HOME/.phpman/tools/*.php"; \
	echo ""; \
	echo "=== Deployed to production ==="; \
	echo "$(DEMO_URL)"; \
	echo "Rollback: make rollback"; \
	echo ""; \
	$(MAKE) logcheck

release: test _release-code

release-reindex: test _release-code
	@echo "=== Rebuilding production search index ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cd $(DEMO_HOME)/.phpman && php cli/build-index.php --cron"
	@echo "=== Production index rebuild complete ==="

# ─── Standalone search index rebuild (no code push) ───

reindex:
	@echo "=== Rebuilding production search index (no code push) ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cd $(DEMO_HOME)/.phpman && php cli/build-index.php --cron"
	@echo "=== Done ==="

reindex-staging:
	@echo "=== Rebuilding staging search index (no code push) ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"cd $(STAGING_HOME)/.phpman_test && php cli/build-index.php --cron"
	@echo "=== Done ==="

# ─── Rollback ───

rollback:
	@LATEST_BACKUP=$$(ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"ls -1t \"\$$HOME/.phpman/backups/$(FILE).\"*.bak 2>/dev/null | head -1"); \
	if [ -z "$$LATEST_BACKUP" ]; then \
		echo "ERROR: No backup found in ~/.phpman/backups"; \
		exit 1; \
	fi; \
	echo "=== Restoring: $$LATEST_BACKUP ==="; \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cp $$LATEST_BACKUP $(DEMO_PATH)/$(FILE)"; \
	echo "=== Rolled back to: $$(basename $$LATEST_BACKUP) ==="; \
	echo "Verify: $(DEMO_URL)"

verify:
	@echo "=== Staging ==="
	@curl -sk -o /dev/null -w "HTTP: %{http_code}  %{url_effective}\n" $(TEST_URL)
	@echo ""
	@echo "=== Production ==="
	@curl -sk -o /dev/null -w "HTTP: %{http_code}  %{url_effective}\n" $(DEMO_URL)

# Check server logs for errors after a production release.
# Requires DEMO_ERROR_LOG and DEMO_ACCESS_LOG to be set in .deploy.mk.
logcheck:
	@echo "=== Post-deploy log check ==="
	@echo "--- phpMan error log ---"
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"test -f \"\$$HOME/.phpman/logs/phpman_error.log\" && tail -10 \"\$$HOME/.phpman/logs/phpman_error.log\" || echo '(no phpman_error.log yet)'"
	@echo ""
	@echo "--- Server error log (last 10 lines) ---"
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"test -f '$(DEMO_ERROR_LOG)' && echo '$(DEMO_ERROR_LOG):' && tail -10 '$(DEMO_ERROR_LOG)' || echo '(error log not configured or not found)'"
	@echo ""
	@echo "--- Access log (recent 5xx errors) ---"
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"test -f '$(DEMO_ACCESS_LOG)' && echo '$(DEMO_ACCESS_LOG):' && (tail -100 '$(DEMO_ACCESS_LOG)' | grep -E '\" (5[0-9][0-9]) ' || echo '(no 5xx in recent requests)') || echo '(access log not configured or not found)'"
	@echo ""
	@echo "=== Log check complete ==="

# ─── Version tagging ───

tag:
	@if [ -z "$(VERSION)" ]; then \
		echo "Usage: make tag VERSION=4.1.0"; \
		echo "Current: $(GIT_VERSION)"; \
		exit 1; \
	fi
	git tag -a "v$(VERSION)" -m "v$(VERSION)"
	git push origin "v$(VERSION)"
	@echo "Tagged and pushed v$(VERSION)"

# ─── Cache management ───

cache-flush:
	@echo "=== Flushing production cache ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"rm -f \"\$$HOME/.phpman/db/phpman_cache.db\"*"
	@echo "Done. Cache will rebuild on next request."

cache-flush-staging:
	@echo "=== Flushing staging cache ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"rm -f \"\$$HOME/.phpman_test/db/phpman_cache.db\"*"
	@echo "Done. Cache will rebuild on next request."

cache-stats:
	@echo "=== Production cache stats ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"ls -lh \"\$$HOME/.phpman/db/phpman_cache.db\" 2>/dev/null || echo '(cache DB not yet created)'"

package: test
	gzip -k -f $(FILE)

upload-release: package
	gh release create $(TAG) $(FILE).gz README.md --title "$(TAG)" --notes "See README.md for details"

clean:
	rm -f $(FILE).bak $(FILE).gz
