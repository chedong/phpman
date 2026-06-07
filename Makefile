# phpMan CI/CD tasks
# Usage:
#   make test
#   make deploy
#   make release
#   make rollback
#   make deploy-verify
#   make release-logcheck
#   make cache-flush
#   make cache-flush-staging
#   make cache-stats
#
# Requires .deploy.mk — copy from .deploy.mk.example and configure

-include .deploy.mk

ifeq ($(wildcard .deploy.mk),)
$(error Missing .deploy.mk — copy from .deploy.mk.example and configure your server settings)
endif

FILE ?= phpMan.php
CSS_FILE ?= phpman.css
BACKUP_DIR ?= backups/phpman
BACKUP_KEEP ?= 5

.PHONY: test deploy release rollback deploy-verify release-logcheck package upload-release clean cache-flush cache-flush-staging cache-stats

GIT_TAG := $(shell git describe --tags --always --dirty 2>/dev/null || echo "local")

test:
	php -l $(FILE)
	php -l phpman.config.php.example

deploy: test
	@echo "=== Preparing staging server ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"mkdir -p $(TEST_CACHE_DIR) && chmod 755 $(TEST_CACHE_DIR)"
	sed "s|// define('CACHE_DIR'.*|define('CACHE_DIR', '$(TEST_CACHE_DIR)');|" \
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

release: test
	@echo "=== Deploying $(GIT_TAG) ==="
	@echo "=== Preparing production server ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"mkdir -p $(DEMO_CACHE_DIR) && chmod 755 $(DEMO_CACHE_DIR)"
	sed "s|// define('CACHE_DIR'.*|define('CACHE_DIR', '$(DEMO_CACHE_DIR)');|" \
		phpman.config.php.example | \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"test -f $(DEMO_PATH)/phpman.config.php && cat > /dev/null || cat > $(DEMO_PATH)/phpman.config.php && chmod 644 $(DEMO_PATH)/phpman.config.php && echo 'Created phpman.config.php'"
	@TIMESTAMP=$$(date +%Y%m%d-%H%M%S); \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"mkdir -p \"\$$HOME/$(BACKUP_DIR)\" && cp $(DEMO_PATH)/$(FILE) \"\$$HOME/$(BACKUP_DIR)/$(FILE).$${TIMESTAMP}.bak\" 2>/dev/null || true"; \
	echo "=== Pruning old backups (keeping last $(BACKUP_KEEP)) ==="; \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"ls -1t \"\$$HOME/$(BACKUP_DIR)/$(FILE).\"*.bak 2>/dev/null | tail -n +$$(( $(BACKUP_KEEP) + 1 )) | xargs rm -f 2>/dev/null || true"; \
	sed "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" $(FILE) | \
		ssh -p $(DEMO_PORT) $(DEMO_HOST) "cat > $(DEMO_PATH)/$(FILE)"; \
	scp -P $(DEMO_PORT) $(CSS_FILE) $(DEMO_HOST):$(DEMO_PATH)/$(CSS_FILE); \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) "chmod 644 $(DEMO_PATH)/$(FILE) $(DEMO_PATH)/$(CSS_FILE)"; \
	echo ""; \
	echo "=== Deployed to production ==="; \
	echo "$(DEMO_URL)"; \
	echo "Rollback: make rollback"; \
	echo ""; \
	$(MAKE) release-logcheck

rollback:
	@LATEST_BACKUP=$$(ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"ls -1t \"\$$HOME/$(BACKUP_DIR)/$(FILE).\"*.bak 2>/dev/null | head -1"); \
	if [ -z "$$LATEST_BACKUP" ]; then \
		echo "ERROR: No backup found in ~/\$(BACKUP_DIR)"; \
		exit 1; \
	fi; \
	echo "=== Restoring: $$LATEST_BACKUP ==="; \
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cp $$LATEST_BACKUP $(DEMO_PATH)/$(FILE)"; \
	echo "=== Rolled back to: $$(basename $$LATEST_BACKUP) ==="; \
	echo "Verify: $(DEMO_URL)"

deploy-verify:
	@echo "=== Staging ==="
	@curl -sk -o /dev/null -w "HTTP: %{http_code}  %{url_effective}\n" $(TEST_URL)
	@echo ""
	@echo "=== Production ==="
	@curl -sk -o /dev/null -w "HTTP: %{http_code}  %{url_effective}\n" $(DEMO_URL)

# Check server logs for errors after a production release.
# Requires DEMO_ERROR_LOG and DEMO_ACCESS_LOG to be set in .deploy.mk.
release-logcheck:
	@echo "=== Post-deploy log check ==="
	@echo "--- Error log (last 10 lines) ---"
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"test -f '$(DEMO_ERROR_LOG)' && echo '$(DEMO_ERROR_LOG):' && tail -10 '$(DEMO_ERROR_LOG)' || echo '(error log not configured or not found)'"
	@echo ""
	@echo "--- Access log (recent 5xx errors) ---"
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"test -f '$(DEMO_ACCESS_LOG)' && echo '$(DEMO_ACCESS_LOG):' && (tail -100 '$(DEMO_ACCESS_LOG)' | grep -E '\" (5[0-9][0-9]) ' || echo '(no 5xx in recent requests)') || echo '(access log not configured or not found)'"
	@echo ""
	@echo "=== Log check complete ==="

cache-flush:
	@echo "=== Flushing production cache ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"rm -f $(DEMO_CACHE_DIR)/phpm_cache.db*"
	@echo "Done. Cache will rebuild on next request."

cache-flush-staging:
	@echo "=== Flushing staging cache ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"rm -f $(TEST_CACHE_DIR)/phpm_cache.db*"
	@echo "Done. Cache will rebuild on next request."

cache-stats:
	@echo "=== Production cache stats ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"ls -lh $(DEMO_CACHE_DIR)/phpm_cache.db 2>/dev/null || echo '(cache DB not yet created)'"

package: test
	gzip -k -f $(FILE)

upload-release: package
	gh release create $(TAG) $(FILE).gz README.md --title "$(TAG)" --notes "See README.md for details"

clean:
	rm -f $(FILE).bak $(FILE).gz
