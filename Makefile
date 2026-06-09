# phpMan CI/CD tasks
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

.PHONY: test staging staging-reindex release release-reindex reindex reindex-staging rollback verify logcheck package upload-release clean cache-flush cache-flush-staging cache-stats _deploy-code _release-code

GIT_TAG := $(shell git describe --tags --always --dirty 2>/dev/null || echo "local")

test:
	php -l $(FILE)
	php -l phpman.config.php.example

# ─── Staging ───

# Internal: push code + CSS to staging (no index rebuild)
_deploy-code:
	@echo "=== Preparing staging server ==="
	@echo "--- Configuring staging home: ~/.phpman_test ---"
	sed "s|// define('PHPMAN_HOME'.*|define('PHPMAN_HOME', '\$$HOME/.phpman_test');|" \
		phpman.config.php.example | \
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"test -f $(TEST_PATH)/phpman.config.php && cat > /dev/null || cat > $(TEST_PATH)/phpman.config.php && chmod 644 $(TEST_PATH)/phpman.config.php && echo 'Created phpman.config.php'"
	sed "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" $(FILE) | \
		ssh -p $(TEST_PORT) $(TEST_HOST) "cat > $(TEST_PATH)/$(FILE)"; \
	scp -P $(TEST_PORT) $(CSS_FILE) $(TEST_HOST):$(TEST_PATH)/$(CSS_FILE); \
	ssh -p $(TEST_PORT) $(TEST_HOST) "chmod 644 $(TEST_PATH)/$(FILE) $(TEST_PATH)/$(CSS_FILE)"
	@echo ""
	@echo "=== Deployed to staging ($(GIT_TAG)) ==="
	@echo "$(TEST_URL)"
	@echo ""

staging: test _deploy-code

staging-reindex: test _deploy-code
	@echo "=== Rebuilding staging search index ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"cd $(TEST_PATH) && php $(FILE) --build-index-cron"
	@echo "=== Staging index rebuild complete ==="

# ─── Production ───

# Internal: push code + CSS to production, with backup
_release-code:
	@echo "=== Deploying $(GIT_TAG) ==="
	@echo "=== Preparing production server ==="
	@echo "--- Configuring home: ~/.phpman ---"
	sed "s|// define('PHPMAN_HOME'.*|define('PHPMAN_HOME', '\$$HOME/.phpman');|" \
		phpman.config.php.example | \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"test -f $(DEMO_PATH)/phpman.config.php && cat > /dev/null || cat > $(DEMO_PATH)/phpman.config.php && chmod 644 $(DEMO_PATH)/phpman.config.php && echo 'Created phpman.config.php'"
	@TIMESTAMP=$$(date +%Y%m%d-%H%M%S); \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"mkdir -p \"\$$HOME/.phpman/backups\" && cp $(DEMO_PATH)/$(FILE) \"\$$HOME/.phpman/backups/$(FILE).$${TIMESTAMP}.bak\" 2>/dev/null || true"; \
	echo "=== Pruning old backups (keeping last 5) ==="; \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"ls -1t \"\$$HOME/.phpman/backups/$(FILE).\"*.bak 2>/dev/null | tail -n +6 | xargs rm -f 2>/dev/null || true"; \
	sed "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" $(FILE) | \
		ssh -p $(DEMO_PORT) $(DEMO_HOST) "cat > $(DEMO_PATH)/$(FILE)"; \
	scp -P $(DEMO_PORT) $(CSS_FILE) $(DEMO_HOST):$(DEMO_PATH)/$(CSS_FILE); \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) "chmod 644 $(DEMO_PATH)/$(FILE) $(DEMO_PATH)/$(CSS_FILE)"; \
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
		"cd $(DEMO_PATH) && php $(FILE) --build-index-cron"
	@echo "=== Production index rebuild complete ==="

# ─── Standalone search index rebuild (no code push) ───

reindex:
	@echo "=== Rebuilding production search index (no code push) ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cd $(DEMO_PATH) && php $(FILE) --build-index-cron"
	@echo "=== Done ==="

reindex-staging:
	@echo "=== Rebuilding staging search index (no code push) ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"cd $(TEST_PATH) && php $(FILE) --build-index-cron"
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
