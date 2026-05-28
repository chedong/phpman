# phpMan CI/CD tasks
# Usage:
#   make test
#   make deploy
#   make release
#   make deploy-verify

-include .deploy.mk

REMOTE_USER ?= your-user
REMOTE_HOST ?= example.com
REMOTE_BASE ?= /path/to/webroot

DEMO_TEST ?= $(REMOTE_BASE)/test
DEMO_MAIN ?= $(REMOTE_BASE)

FILE ?= phpMan.php
DEMO_URL ?= https://example.com/test/$(FILE)
MAIN_URL ?= https://example.com/$(FILE)
FRS_TARGET ?= your-user@frs.sourceforge.net:/home/frs/project/phpunixman/

.PHONY: test deploy release deploy-verify package upload-release clean

test:
	php -l $(FILE)

deploy: test
	scp $(FILE) $(REMOTE_USER)@$(REMOTE_HOST):$(DEMO_TEST)/
	@echo ""
	@echo "=== Deployed to staging ==="
	@echo "$(DEMO_URL)"
	@echo ""

release: test
	scp $(FILE) $(REMOTE_USER)@$(REMOTE_HOST):$(DEMO_MAIN)/$(FILE)
	@echo ""
	@echo "=== Deployed to production ==="
	@echo "$(MAIN_URL)"
	@echo ""

deploy-verify:
	@echo "=== Staging version ==="
	ssh $(REMOTE_USER)@$(REMOTE_HOST) "stat -c 'mtime: %y  size: %s' $(DEMO_TEST)/$(FILE)" 2>/dev/null || true
	@echo ""
	@echo "=== Production version ==="
	ssh $(REMOTE_USER)@$(REMOTE_HOST) "stat -c 'mtime: %y  size: %s' $(DEMO_MAIN)/$(FILE)" 2>/dev/null || true
	@echo ""
	@curl -sk -o /dev/null -w "Production HTTP: %{http_code}\n" $(MAIN_URL)
	@curl -sk -o /dev/null -w "Staging HTTP: %{http_code}\n" $(DEMO_URL)

package: test
	gzip -k -f $(FILE)

upload-release: package
	scp $(FILE).gz README.md $(FRS_TARGET)

clean:
	rm -f $(FILE).bak $(FILE).gz
