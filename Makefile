# phpMan CI/CD tasks
# Usage:
#   make test
#   make deploy
#   make release
#   make deploy-verify

-include .deploy.mk

# --- Staging / test deployment ---
# (can be a different server, user, path, port from demo)
TEST_USER ?= your-user
TEST_HOST ?= example.com
TEST_PORT ?= 22
TEST_PATH ?= /path/to/webroot/test
TEST_URL  ?= https://example.com/test/phpMan.php

# --- Demo / production deployment ---
DEMO_USER ?= your-user
DEMO_HOST ?= example.com
DEMO_PORT ?= 22
DEMO_PATH ?= /path/to/webroot
DEMO_URL  ?= https://example.com/phpMan.php

FILE ?= phpMan.php
FRS_TARGET ?= your-user@frs.sourceforge.net:/home/frs/project/phpunixman/

.PHONY: test deploy release deploy-verify package upload-release clean

test:
	php -l $(FILE)

deploy: test
	scp -P $(TEST_PORT) $(FILE) $(TEST_USER)@$(TEST_HOST):$(TEST_PATH)/
	@echo ""
	@echo "=== Deployed to staging ==="
	@echo "$(TEST_URL)"
	@echo ""

release: test
	scp -P $(DEMO_PORT) $(FILE) $(DEMO_USER)@$(DEMO_HOST):$(DEMO_PATH)/
	@echo ""
	@echo "=== Deployed to production ==="
	@echo "$(DEMO_URL)"
	@echo ""

deploy-verify:
	@echo "=== Staging ==="
	@curl -sk -o /dev/null -w "HTTP: %{http_code}  %{url_effective}\n" $(TEST_URL)
	@echo ""
	@echo "=== Production ==="
	@curl -sk -o /dev/null -w "HTTP: %{http_code}  %{url_effective}\n" $(DEMO_URL)

package: test
	gzip -k -f $(FILE)

upload-release: package
	scp $(FILE).gz README.md $(FRS_TARGET)

clean:
	rm -f $(FILE).bak $(FILE).gz
