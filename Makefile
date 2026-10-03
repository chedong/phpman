# phpMan CI/CD tasks
# This Makefile is for the phpMan maintainer's CI/CD pipeline (staging, release,
# rollback, cache management). Requires SSH access to target servers.
#
# If you're looking to install phpMan on your own server, use install.sh instead:
#   curl -fsSL https://raw.githubusercontent.com/chedong/phpman/master/install.sh | bash
#
# Usage:
#   make test
#   make staging                  # staging: push code + CSS/JS only
#   make staging-reindex          # staging: push code + rebuild search index
#   make release                  # production: push code + CSS/JS only
#   make release-reindex          # production: push code + rebuild search index
#   make reindex                  # production: rebuild search index + sitemap (no code push)
#   make reindex-staging          # staging: rebuild search index + sitemap
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
#     - HTML/CSS/JS/UI changes
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
JS_FILE  ?= phpman.js

.PHONY: test staging staging-reindex release release-reindex reindex reindex-staging rollback verify logcheck package upload-release clean cache-flush cache-flush-staging cache-stats tag tag-minor _deploy-code _release-code

GIT_TAG     := $(shell git describe --tags --always --dirty 2>/dev/null || echo "local")
GIT_VERSION := $(shell (git describe --tags --abbrev=0 2>/dev/null || echo "v0.0.0") | sed 's/^v//')

# Release directory name for the atomic symlink release (see deploy/atomic-release.sh).
# Derived from GIT_TAG so a rollback target is human-readable, sanitized because
# the tag can carry a "-dirty" suffix and must stay a safe path component.
#
# The timestamp is not decoration: the id must be unique per deploy. Keyed on the
# tag alone, a second deploy of the same commit would resolve to the release that
# is currently live and rsync into it, updating the running code in place — which
# is the mixed-version window this whole mechanism exists to remove. It also makes
# `ls -1dt` ordering (what rollback walks) match deploy order.
RELEASE_ID  := $(shell printf '%s' '$(GIT_TAG)' | sed 's/[^A-Za-z0-9._-]/_/g')-$(shell date +%Y%m%d-%H%M%S)

# Resolve remote $HOME so config gets the literal path (mod_fcgid may not set HOME)
STAGING_HOME := $(shell ssh -p $(TEST_PORT) $(TEST_HOST) 'echo $$HOME' 2>/dev/null || echo "")
DEMO_HOME    := $(shell ssh -p $(DEMO_PORT) $(DEMO_HOST) 'echo $$HOME' 2>/dev/null || echo "")

# Guard: fail early if remote $HOME couldn't be resolved (SSH down, too many connections, etc.)
ifeq ($(STAGING_HOME),)
$(error Failed to resolve STAGING_HOME — SSH to $(TEST_HOST):$(TEST_PORT) failed. Check your connection and try again.)
endif
ifeq ($(DEMO_HOME),)
$(error Failed to resolve DEMO_HOME — SSH to $(DEMO_HOST):$(DEMO_PORT) failed. Check your connection and try again.)
endif

test:
	php -l $(FILE)
	php -l phpman.config.php.example
	@for f in src/*.php; do php -l "$$f" >/dev/null || exit 1; done
	@for f in cli/*.php; do php -l "$$f" >/dev/null || exit 1; done
	@for f in deploy/*.sh; do sh -n "$$f" || exit 1; done
	@echo "All PHP files pass syntax check"

# ─── Staging ───

# Internal: push code + CSS to staging (no index rebuild)
_deploy-code:
	@echo "=== Preparing staging server ==="
	@echo "--- Configuring staging home: ~/.phpman_test (debug ON) ---"
	@# PHPMAN_HOME config: full config from .example (create if missing, update if exists)
	@ssh -p $(TEST_PORT) $(TEST_HOST) " \
		if [ -f $(STAGING_HOME)/.phpman_test/phpman.config.php ] && [ -s $(STAGING_HOME)/.phpman_test/phpman.config.php ]; then \
			sed -i \"s|define('PHPMAN_HOME',.*|define('PHPMAN_HOME', '$(STAGING_HOME)/.phpman_test');|\" $(STAGING_HOME)/.phpman_test/phpman.config.php; \
			sed -i \"s|// define('PHPMAN_DEBUG'.*|define('PHPMAN_DEBUG', true);|\" $(STAGING_HOME)/.phpman_test/phpman.config.php; \
			sed -i \"s|define('PHPMAN_DEBUG',[[:space:]]*false.*|define('PHPMAN_DEBUG', true);|\" $(STAGING_HOME)/.phpman_test/phpman.config.php; \
			echo 'Updated phpman.config.php (preserved existing)'; \
		else \
			sed -e \"s|// define('PHPMAN_HOME'.*\\.phpman_test.*|define('PHPMAN_HOME', '$(STAGING_HOME)/.phpman_test');|\" \
			    -e \"s|// define('PHPMAN_DEBUG'.*|define('PHPMAN_DEBUG', true);|\" \
				$(STAGING_HOME)/.phpman_test/phpman.config.php.example | \
			cat > $(STAGING_HOME)/.phpman_test/phpman.config.php && chmod 644 $(STAGING_HOME)/.phpman_test/phpman.config.php && echo 'Created phpman.config.php'; \
		fi"
	@# Patch placeholders in phpMan.php and upload (never touches local FILE)
	@sed -e "s|define('PHPMAN_HOME',[^;]*;|define('PHPMAN_HOME', '$(STAGING_HOME)/.phpman_test');|" \
	    -e "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" \
	    -e "s/define('PHPMAN_VERSION', '[^']*');/define('PHPMAN_VERSION', '$(GIT_VERSION)');/" $(FILE) > $(FILE).deploy
	@# Atomic release: both artifacts land in releases/$(RELEASE_ID)/ and are
	@# then activated by a single rename, so phpMan.php and src/ can never be
	@# observed out of step. This replaces the old "src/ first, then phpMan.php"
	@# order, which was correct only for additive changes — a change that REMOVES
	@# a symbol needs the opposite order, so no fixed order is safe in general.
	@# See deploy/atomic-release.sh and CHANGELOG 2026-10-02.
	@ssh -p $(TEST_PORT) $(TEST_HOST) "mkdir -p $(STAGING_HOME)/.phpman_test/releases/$(RELEASE_ID)/src"
	@rsync -avz -e "ssh -p $(TEST_PORT)" src/ $(TEST_HOST):$(STAGING_HOME)/.phpman_test/releases/$(RELEASE_ID)/src/
	@scp -P $(TEST_PORT) $(FILE).deploy $(TEST_HOST):$(STAGING_HOME)/.phpman_test/releases/$(RELEASE_ID)/$(FILE)
	@rm -f $(FILE).deploy
	@rsync -avz -e "ssh -p $(TEST_PORT)" deploy/ $(TEST_HOST):$(STAGING_HOME)/.phpman_test/deploy/
	@ssh -p $(TEST_PORT) $(TEST_HOST) "sh $(STAGING_HOME)/.phpman_test/deploy/atomic-release.sh '$(STAGING_HOME)/.phpman_test' '$(TEST_PATH)' '$(FILE)' '$(RELEASE_ID)'"
	@rsync -avz -e "ssh -p $(TEST_PORT)" $(CSS_FILE) $(JS_FILE) $(TEST_HOST):$(TEST_PATH)/
	@rsync -avz -e "ssh -p $(TEST_PORT)" cli/ $(TEST_HOST):$(STAGING_HOME)/.phpman_test/cli/
	@rsync -avz -e "ssh -p $(TEST_PORT)" phpman.config.php.example $(TEST_HOST):$(STAGING_HOME)/.phpman_test/
	@ssh -p $(TEST_PORT) $(TEST_HOST) "chmod 644 $(TEST_PATH)/$(FILE) $(TEST_PATH)/$(CSS_FILE) $(TEST_PATH)/$(JS_FILE) && chmod +x \$$HOME/.phpman_test/cli/*.php"
	@echo "--- Detecting available documentation tools ---"
	@ssh -p $(TEST_PORT) $(TEST_HOST) "php $(STAGING_HOME)/.phpman_test/cli/detect-tools.php > $(STAGING_HOME)/.phpman_test/tools_config.php && cat $(STAGING_HOME)/.phpman_test/tools_config.php"
	@echo ""
	@echo "=== Deployed to staging ($(GIT_TAG)) ==="
	@echo "$(TEST_URL)"
	@echo ""
	@# Check for new config options not in server's phpman.config.php
	@# NOTE on the sed escaping below: the expression crosses two double-quoted
	@# shell layers (this recipe's shell, then the shell ssh spawns), and each
	@# layer halves every backslash run. So the four backslashes you see before
	@# ( and ) are what arrives at sed as \( and \) — a BRE capture group. Write
	@# six there and sed gets \\( (literal backslash + literal paren), the group
	@# never opens, and the check dies with "Unmatched ) or \)" while still
	@# printing "✓ Config up to date" — a silent false negative that hid a missing
	@# PHPMAN_ADSENSE_ID until 2026-09-27. The 4/4/4 symmetry is deliberate.
	@# The two `.` around the key match either quote character: both live configs
	@# write PHPMAN_BASE_URL with double quotes, which the earlier single-quoted
	@# pattern reported as missing on every deploy.
	@echo "--- Checking for new config options ---"; \
		missing=$$(ssh -p $(TEST_PORT) $(TEST_HOST) " \
			example=$(STAGING_HOME)/.phpman_test/phpman.config.php.example; \
			config=$(STAGING_HOME)/.phpman_test/phpman.config.php; \
			if [ ! -f \"\$$config\" ] || [ ! -f \"\$$example\" ]; then exit 0; fi; \
			while IFS= read -r key; do \
				[ -z \"\$$key\" ] && continue; \
				grep -q \"define(.\$$key.\" \"\$$config\" && continue; \
				hint=\$$(grep -B5 \"define('\$$key'\" \"\$$example\" | grep '//' | tail -1 | sed 's/^[[:space:]]*\/\/[[:space:]]*//'); \
				[ -z \"\$$hint\" ] && hint=\"\$$key\"; \
				echo \"  \$$key — \$$hint\"; \
			done < <(sed -n \"s/.*define('\\\\([A-Z_][A-Z_0-9]*\\\\)'.*/\\\\1/p\" \"\$$example\" | sort -u) \
		"); \
		if [ -n "$$missing" ]; then \
			echo "  ⚠  New config options not in your phpman.config.php:"; \
			echo "$$missing"; \
			echo "  → Compare: diff phpman.config.php phpman.config.php.example"; \
		else \
			echo "  ✓ Config up to date"; \
		fi

staging: test _deploy-code

staging-reindex: test _deploy-code
	@echo "=== Rebuilding staging search index ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"cd $(STAGING_HOME)/.phpman_test && php cli/build-index.php --cron"
	@# Split sitemaps, matching reindex-staging below. This target kept the
	@# pre-split form (uncompressed sitemap.phpman.xml, html only, no --sitemap-url,
	@# no AI sitemap, no llms.txt) — #225 updated the other three targets and
	@# missed this one, so staging never exercised what production actually serves. (#231)
	@echo "=== Generating staging sitemap (html for Google, markdown/json for AI bots) ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"cd $(STAGING_HOME)/.phpman_test && php cli/build-sitemap.php --output $(TEST_PATH)/sitemap-phpman.xml.gz --base-url $(TEST_URL) --sitemap-url https://test.chedong.com/sitemap-phpman.xml.gz --formats html --max-urls 50000 \
		 && php cli/build-sitemap.php --output $(TEST_PATH)/sitemap-phpman-ai.xml.gz --base-url $(TEST_URL) --sitemap-url https://test.chedong.com/sitemap-phpman-ai.xml.gz --formats markdown,json --max-urls 50000 --llms-output $(TEST_PATH)/llms.txt"
	@echo "=== Staging index + sitemap complete ==="

# ─── Production ───

# Internal: push code + CSS to production as an atomic release
_release-code:
	@echo "=== Deploying $(GIT_TAG) ==="
	@# Patch placeholders in phpMan.php (never touches local FILE)
	@sed -e "s|define('PHPMAN_HOME',[^;]*;|define('PHPMAN_HOME', '$(DEMO_HOME)/.phpman');|" \
	    -e "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(GIT_TAG)');/" \
	    -e "s/define('PHPMAN_VERSION', '[^']*');/define('PHPMAN_VERSION', '$(GIT_VERSION)');/" $(FILE) > $(FILE).deploy
	@# Atomic release: upload both artifacts into releases/$(RELEASE_ID)/, then
	@# activate them with a single rename, so no request can observe phpMan.php
	@# and src/ from different versions. See deploy/atomic-release.sh for the two
	@# production incidents (2026-07-13 additive, 2026-10-02 removal) that no
	@# fixed upload order could fix. Kept releases (5) ARE the rollback targets,
	@# which is why the old .bak backup/prune steps are gone.
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) "mkdir -p $(DEMO_HOME)/.phpman/releases/$(RELEASE_ID)/src"
	@rsync -avz -e "ssh -p $(DEMO_PORT)" src/ $(DEMO_HOST):$(DEMO_HOME)/.phpman/releases/$(RELEASE_ID)/src/
	@scp -P $(DEMO_PORT) $(FILE).deploy $(DEMO_HOST):$(DEMO_HOME)/.phpman/releases/$(RELEASE_ID)/$(FILE)
	@rm -f $(FILE).deploy
	@rsync -avz -e "ssh -p $(DEMO_PORT)" deploy/ $(DEMO_HOST):$(DEMO_HOME)/.phpman/deploy/
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) "sh $(DEMO_HOME)/.phpman/deploy/atomic-release.sh '$(DEMO_HOME)/.phpman' '$(DEMO_PATH)' '$(FILE)' '$(RELEASE_ID)'"
	@rsync -avz -e "ssh -p $(DEMO_PORT)" $(CSS_FILE) $(JS_FILE) $(DEMO_HOST):$(DEMO_PATH)/
	@rsync -avz -e "ssh -p $(DEMO_PORT)" cli/ $(DEMO_HOST):$(DEMO_HOME)/.phpman/cli/
	@rsync -avz -e "ssh -p $(DEMO_PORT)" phpman.config.php.example $(DEMO_HOST):$(DEMO_HOME)/.phpman/
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) "chmod 644 $(DEMO_PATH)/$(FILE) $(DEMO_PATH)/$(CSS_FILE) $(DEMO_PATH)/$(JS_FILE) && chmod +x \$$HOME/.phpman/cli/*.php"
	@echo "--- Detecting available documentation tools ---"
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) "php $(DEMO_HOME)/.phpman/cli/detect-tools.php > $(DEMO_HOME)/.phpman/tools_config.php && cat $(DEMO_HOME)/.phpman/tools_config.php"
	@echo ""
	@echo "=== Deployed to production ==="
	@echo "$(DEMO_URL)"
	@echo "Rollback: make rollback"
	@echo ""
	@# Check for new config options not in server's phpman.config.php
	@# (sed escaping and the quote-agnostic key match explained in the staging copy
	@#  of this check — same 4/4/4.)
	@echo "--- Checking for new config options ---"; \
		missing=$$(ssh -p $(DEMO_PORT) $(DEMO_HOST) " \
			example=$(DEMO_HOME)/.phpman/phpman.config.php.example; \
			config=$(DEMO_HOME)/.phpman/phpman.config.php; \
			if [ ! -f \"\$$config\" ] || [ ! -f \"\$$example\" ]; then exit 0; fi; \
			while IFS= read -r key; do \
				[ -z \"\$$key\" ] && continue; \
				grep -q \"define(.\$$key.\" \"\$$config\" && continue; \
				hint=\$$(grep -B5 \"define('\$$key'\" \"\$$example\" | grep '//' | tail -1 | sed 's/^[[:space:]]*\/\/[[:space:]]*//'); \
				[ -z \"\$$hint\" ] && hint=\"\$$key\"; \
				echo \"  \$$key — \$$hint\"; \
			done < <(sed -n \"s/.*define('\\\\([A-Z_][A-Z_0-9]*\\\\)'.*/\\\\1/p\" \"\$$example\" | sort -u) \
		"); \
		if [ -n "$$missing" ]; then \
			echo "  ⚠  New config options not in your phpman.config.php:"; \
			echo "$$missing"; \
			echo "  → Compare: diff phpman.config.php phpman.config.php.example"; \
		else \
			echo "  ✓ Config up to date"; \
		fi
	$(MAKE) logcheck

release: test _release-code

release-reindex: test _release-code
	@echo "=== Rebuilding production search index ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cd $(DEMO_HOME)/.phpman && php cli/build-index.php --cron"
	@echo "=== Generating production sitemap (html for Google, markdown/json for AI bots) ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cd $(DEMO_HOME)/.phpman && php cli/build-sitemap.php --output $(DEMO_PATH)/sitemap-phpman.xml.gz --base-url $(DEMO_URL) --sitemap-url https://www.chedong.com/sitemap-phpman.xml.gz --formats html --max-urls 50000 \
		 && php cli/build-sitemap.php --output $(DEMO_PATH)/sitemap-phpman-ai.xml.gz --base-url $(DEMO_URL) --sitemap-url https://www.chedong.com/sitemap-phpman-ai.xml.gz --formats markdown,json --max-urls 50000 --llms-output $(DEMO_PATH)/llms.txt"
	@echo "=== Production index + sitemap complete ==="

# ─── Standalone search index rebuild (no code push) ───

reindex:
	@echo "=== Rebuilding production search index (no code push) ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cd $(DEMO_HOME)/.phpman && php cli/build-index.php --cron \
		 && php cli/build-sitemap.php --output $(DEMO_PATH)/sitemap-phpman.xml.gz --base-url $(DEMO_URL) --sitemap-url https://www.chedong.com/sitemap-phpman.xml.gz --formats html --max-urls 50000 \
		 && php cli/build-sitemap.php --output $(DEMO_PATH)/sitemap-phpman-ai.xml.gz --base-url $(DEMO_URL) --sitemap-url https://www.chedong.com/sitemap-phpman-ai.xml.gz --formats markdown,json --max-urls 50000 --llms-output $(DEMO_PATH)/llms.txt"
	@echo "=== Done (index + sitemap) ==="

reindex-staging:
	@echo "=== Rebuilding staging search index (no code push) ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) \
		"cd $(STAGING_HOME)/.phpman_test && php cli/build-index.php --cron \
		 && php cli/build-sitemap.php --output $(TEST_PATH)/sitemap-phpman.xml.gz --base-url $(TEST_URL) --sitemap-url https://test.chedong.com/sitemap-phpman.xml.gz --formats html --max-urls 50000 \
		 && php cli/build-sitemap.php --output $(TEST_PATH)/sitemap-phpman-ai.xml.gz --base-url $(TEST_URL) --sitemap-url https://test.chedong.com/sitemap-phpman-ai.xml.gz --formats markdown,json --max-urls 50000 --llms-output $(TEST_PATH)/llms.txt"
	@echo "=== Done (index + sitemap) ==="

# ─── Rollback ───

# Flip production back to the previous release. Releases are the rollback
# targets now (see deploy/atomic-release.sh): the entry script and src/ are
# symlinks into releases/<id>/, so this is the same atomic rename a deploy uses.
# Copying a file over the entry script — what this target used to do — would
# write *through* the symlink and corrupt the current release instead of
# switching it. STEP=n rolls back n releases; default 1.
STEP ?= 1

rollback:
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"sh $(DEMO_HOME)/.phpman/deploy/rollback.sh '$(DEMO_HOME)/.phpman' '$(DEMO_PATH)' '$(FILE)' '$(STEP)'"
	@echo "Verify: $(DEMO_URL)"

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
		latest=$$(git tag -l 'v*' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$$' | sort -V | tail -1 | sed 's/^v//'); \
		if [ -z "$$latest" ]; then latest="0.0.0"; fi; \
		major=$$(echo $$latest | cut -d. -f1); \
		minor=$$(echo $$latest | cut -d. -f2); \
		patch=$$(echo $$latest | cut -d. -f3); \
		next="$$major.$$minor.$$((patch + 1))"; \
		echo "Latest: v$$latest  →  tagging v$$next (patch bump)"; \
		echo "  minor bump: make tag-minor"; \
		echo "  explicit:   make tag VERSION=X.Y.Z"; \
		$(MAKE) tag VERSION=$$next; \
	else \
		git tag -a "v$(VERSION)" -m "v$(VERSION)"; \
		echo "=== v$(VERSION): tagged (placeholders replaced at deploy) ==="; \
		git push origin master "v$(VERSION)"; \
		echo "Pushed master + tag v$(VERSION)"; \
	fi

tag-minor:
	@latest=$$(git tag -l 'v*' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$$' | sort -V | tail -1 | sed 's/^v//'); \
	if [ -z "$$latest" ]; then latest="0.0.0"; fi; \
	major=$$(echo $$latest | cut -d. -f1); \
	minor=$$(echo $$latest | cut -d. -f2); \
	next="$$major.$$((minor + 1)).0"; \
	echo "Latest: v$$latest  →  tagging v$$next (minor bump)"; \
	$(MAKE) tag VERSION=$$next

# ─── Cache management ───

cache-flush:
	@echo "=== Flushing production cache ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) "cd $(DEMO_HOME)/.phpman && php cli/cache.php flush"

cache-flush-staging:
	@echo "=== Flushing staging cache ==="
	ssh -p $(TEST_PORT) $(TEST_HOST) "cd $(STAGING_HOME)/.phpman_test && php cli/cache.php flush"

cache-stats:
	@echo "=== Production cache stats ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) "cd $(DEMO_HOME)/.phpman && php cli/cache.php stats"

package: test
	gzip -k -f $(FILE)

upload-release: package
	gh release create $(TAG) $(FILE).gz README.md --title "$(TAG)" --notes "See README.md for details"

clean:
	rm -f $(FILE).bak $(FILE).gz
