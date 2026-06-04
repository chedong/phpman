# phpMan CI/CD tasks
# Usage:
#   make test
#   make deploy
#   make release
#   make rollback
#   make deploy-verify
#   make release-logcheck
#
# Requires .deploy.mk — copy from .deploy.mk.example and configure

-include .deploy.mk

ifeq ($(wildcard .deploy.mk),)
$(error Missing .deploy.mk — copy from .deploy.mk.example and configure your server settings)
endif

FILE ?= phpMan.php
BACKUP_DIR ?= backups/phpman
BACKUP_KEEP ?= 5

.PHONY: test deploy release rollback deploy-verify release-logcheck package upload-release clean

GIT_TAG := $(shell git describe --tags --always --dirty 2>/dev/null || echo "local")

test:
	php -l $(FILE)

deploy: test
	sed "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" $(FILE) | \
	ssh -p $(TEST_PORT) $(TEST_USER)@$(TEST_HOST) "cat > $(TEST_PATH)/$(FILE)"; \
	ssh -p $(TEST_PORT) $(TEST_USER)@$(TEST_HOST) "chmod 644 $(TEST_PATH)/$(FILE)"
	@echo ""
	@echo "=== Deployed to staging ($(GIT_TAG)) ==="
	@echo "$(TEST_URL)"
	@echo ""

release: test
	@echo "=== Deploying $(GIT_TAG) ==="
	@TIMESTAMP=$$(date +%Y%m%d-%H%M%S); \
	ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
		"mkdir -p \"\$$HOME/$(BACKUP_DIR)\" && cp $(DEMO_PATH)/$(FILE) \"\$$HOME/$(BACKUP_DIR)/$(FILE).$${TIMESTAMP}.bak\" 2>/dev/null || true"; \
	echo "=== Pruning old backups (keeping last $(BACKUP_KEEP)) ==="; \
	ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
		"ls -1t \"\$$HOME/$(BACKUP_DIR)/$(FILE).\"*.bak 2>/dev/null | tail -n +$$(( $(BACKUP_KEEP) + 1 )) | xargs rm -f 2>/dev/null || true"; \
	sed "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" $(FILE) | \
	ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) "cat > $(DEMO_PATH)/$(FILE)"; \
	ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) "chmod 644 $(DEMO_PATH)/$(FILE)"; \
	echo ""; \
	echo "=== Deployed to production ==="; \
	echo "$(DEMO_URL)"; \
	echo "Rollback: make rollback"; \
	echo ""; \
	$(MAKE) release-logcheck

rollback:
	@LATEST_BACKUP=$$(ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
		"ls -1t \"\$$HOME/$(BACKUP_DIR)/$(FILE).\"*.bak 2>/dev/null | head -1"); \
	if [ -z "$$LATEST_BACKUP" ]; then \
		echo "ERROR: No backup found in ~/\$(BACKUP_DIR)"; \
		exit 1; \
	fi; \
	echo "=== Restoring: $$LATEST_BACKUP ==="; \
	ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
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
	@ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
		"test -f '$(DEMO_ERROR_LOG)' && echo '$(DEMO_ERROR_LOG):' && tail -10 '$(DEMO_ERROR_LOG)' || echo '(error log not configured or not found)'"
	@echo ""
	@echo "--- Access log (recent 5xx errors) ---"
	@ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
		"test -f '$(DEMO_ACCESS_LOG)' && echo '$(DEMO_ACCESS_LOG):' && (tail -100 '$(DEMO_ACCESS_LOG)' | grep -E '\" (5[0-9][0-9]) ' || echo '(no 5xx in recent requests)') || echo '(access log not configured or not found)'"
	@echo ""
	@echo "=== Log check complete ==="

package: test
	gzip -k -f $(FILE)

upload-release: package
	gh release create $(TAG) $(FILE).gz README.md --title "$(TAG)" --notes "See README.md for details"

clean:
	rm -f $(FILE).bak $(FILE).gz
