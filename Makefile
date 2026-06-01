# phpMan CI/CD tasks
# Usage:
#   make test
#   make deploy
#   make release
#   make rollback
#   make deploy-verify
#
# Requires .deploy.mk — copy from .deploy.mk.example and configure

-include .deploy.mk

ifeq ($(wildcard .deploy.mk),)
$(error Missing .deploy.mk — copy from .deploy.mk.example and configure your server settings)
endif

FILE ?= phpMan.php
BACKUP_DIR ?= backups/phpman
BACKUP_KEEP ?= 5

.PHONY: test deploy release rollback deploy-verify package upload-release clean

test:
	php -l $(FILE)

deploy: test
	scp -P $(TEST_PORT) $(FILE) $(TEST_USER)@$(TEST_HOST):$(TEST_PATH)/
	@echo ""
	@echo "=== Deployed to staging ==="
	@echo "$(TEST_URL)"
	@echo ""

release: test
	@TIMESTAMP=$$(date +%Y%m%d-%H%M%S); \
	ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
		"mkdir -p \"\$$HOME/$(BACKUP_DIR)\" && cp $(DEMO_PATH)/$(FILE) \"\$$HOME/$(BACKUP_DIR)/$(FILE).$${TIMESTAMP}.bak\" 2>/dev/null || true"; \
	echo "=== Pruning old backups (keeping last $(BACKUP_KEEP)) ==="; \
	ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
		"ls -1t \"\$$HOME/$(BACKUP_DIR)/$(FILE).\"*.bak 2>/dev/null | tail -n +$$(( $(BACKUP_KEEP) + 1 )) | xargs rm -f 2>/dev/null || true"; \
	scp -P $(DEMO_PORT) $(FILE) $(DEMO_USER)@$(DEMO_HOST):$(DEMO_PATH)/; \
	echo ""; \
	echo "=== Deployed to production ==="; \
	echo "$(DEMO_URL)"; \
	echo "Rollback: make rollback"; \
	echo ""

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

package: test
	gzip -k -f $(FILE)

upload-release: package
	gh release create $(TAG) $(FILE).gz README.md --title "$(TAG)" --notes "See README.md for details"

clean:
	rm -f $(FILE).bak $(FILE).gz
