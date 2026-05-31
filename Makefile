# phpMan CI/CD tasks
# Usage:
#   make test
#   make deploy
#   make release
#   make deploy-verify
#
# Requires .deploy.mk — copy from .deploy.mk.example and configure

-include .deploy.mk

ifeq ($(wildcard .deploy.mk),)
$(error Missing .deploy.mk — copy from .deploy.mk.example and configure your server settings)
endif

FILE ?= phpMan.php

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
	gh release create $(TAG) $(FILE).gz README.md --title "$(TAG)" --notes "See README.md for details"

clean:
	rm -f $(FILE).bak $(FILE).gz
