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

.PHONY: test test-unit staging staging-reindex release release-reindex reindex reindex-staging rollback verify logcheck log-rotate package upload-release clean cache-flush cache-flush-staging cache-stats _deploy-code _release-code _release-tag require-staging require-production release-precheck

# 会被实际上传到服务器的路径。既是「工作树是否脏」的判定范围，也是
# deploy/release-precheck.sh 的入参 —— 只在这一个地方定义。
DEPLOY_PATHS = src phpMan.php cli deploy phpman.config.php.example phpman.css phpman.js

# staging 部署的通常是**尚未打 tag** 的提交（tag 现在只在 release 那一刻才打），
# 所以它不能声称自己是某个发布版本 —— 报上一个 release 的版本号会让 staging 显得
# 比生产还新，方向正好反了。sha 是诚实的；工作树脏时补 -dirty，因为那个 sha
# 描述不了实际传上去的字节。
#
# 两条 $(shell) 都是纯本地命令（git rev-parse / git status，无网络），放 parse-time
# 可以接受 —— 会拖慢每一次 make 的是网络调用，不是这两条。
STAGING_SHA     := $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
STAGING_DIRTY   := $(shell git status --porcelain -uall -- $(DEPLOY_PATHS) 2>/dev/null | head -1)
STAGING_VERSION := staging-$(STAGING_SHA)$(if $(STAGING_DIRTY),-dirty)

# Deploy timestamp, resolved once at parse time so every line of a recipe sees the
# same value — recipe lines each get their own shell, so a `date` inside one would
# hand consecutive lines different stamps and split one deploy across two release
# directories. Plain local command, no network.
RELEASE_STAMP := $(shell date +%Y%m%d-%H%M%S)

# Release directory name for the atomic symlink release (see deploy/atomic-release.sh).
#
# The timestamp is not decoration: the id must be unique per deploy. Keyed on the
# version alone, a second deploy of the same commit would resolve to the release
# that is currently live and rsync into it, updating the running code in place —
# which is the mixed-version window this whole mechanism exists to remove. It also
# makes `ls -1dt` ordering (what rollback walks) match deploy order.
STAGING_RELEASE_ID := $(STAGING_VERSION)-$(RELEASE_STAMP)

# Production's version is not known until the release recipe runs (it comes from
# origin's tags, and computing it at parse time would put a network call in every
# `make`). `_release-code` writes it to .release-version first; this expands at
# recipe time, when that file exists.
RELEASE_ID = $$(cat .release-version)-$(RELEASE_STAMP)

# Resolve remote $HOME so config gets the literal path (mod_fcgid may not set HOME)
STAGING_HOME := $(shell ssh -p $(TEST_PORT) $(TEST_HOST) 'echo $$HOME' 2>/dev/null || echo "")
DEMO_HOME    := $(shell ssh -p $(DEMO_PORT) $(DEMO_HOST) 'echo $$HOME' 2>/dev/null || echo "")

# Guards for a remote $HOME that could not be resolved (SSH down, too many
# connections, DNS). These are prerequisites of the targets that actually use
# those paths, not parse-time $(error)s: as $(error)s a single blip to *either*
# host failed every target in this file, including the ones that never touch it
# — a flaky SSH to staging aborted `make logcheck` and `make log-rotate`, which
# only talk to production. The values above are still resolved once per run
# (recursive `=` here would re-run ssh on every reference in a recipe).
require-staging:
	@test -n "$(STAGING_HOME)" || { echo "Failed to resolve STAGING_HOME — SSH to $(TEST_HOST):$(TEST_PORT) failed. Check your connection and try again." >&2; exit 1; }
require-production:
	@test -n "$(DEMO_HOME)" || { echo "Failed to resolve DEMO_HOME — SSH to $(DEMO_HOST):$(DEMO_PORT) failed. Check your connection and try again." >&2; exit 1; }

# Targets that use the resolved paths carry the matching guard. Targets that only
# need the host/port (logcheck, log-rotate, verify) deliberately do not — they
# must keep working while the other host is unreachable.
staging staging-reindex reindex-staging cache-flush-staging _deploy-code: require-staging
release release-reindex reindex cache-flush cache-stats rollback package _release-code: require-production

test:
	php -l $(FILE)
	php -l phpman.config.php.example
	@for f in src/*.php; do php -l "$$f" >/dev/null || exit 1; done
	@for f in cli/*.php; do php -l "$$f" >/dev/null || exit 1; done
	@for f in deploy/*.sh; do sh -n "$$f" || exit 1; done
	@echo "All PHP files pass syntax check"

# Behavioural tests (test/run_all.php). Deliberately separate from `test`:
# `test` is the fast syntax gate you run constantly, this one shells out and
# takes seconds. It is a prerequisite of release/release-reindex — the guards
# this release pipeline now depends on (deploy/next-version.sh,
# deploy/release-precheck.sh) are only exercised here, so a regression in them
# must not be able to ship.
test-unit:
	php test/run_all.php

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
	    -e "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(STAGING_VERSION)');/" \
	    -e "s/define('PHPMAN_VERSION', '[^']*');/define('PHPMAN_VERSION', '$(STAGING_VERSION)');/" $(FILE) > $(FILE).deploy
	@# Atomic release: both artifacts land in releases/$(RELEASE_ID)/ and are
	@# then activated by a single rename, so phpMan.php and src/ can never be
	@# observed out of step. This replaces the old "src/ first, then phpMan.php"
	@# order, which was correct only for additive changes — a change that REMOVES
	@# a symbol needs the opposite order, so no fixed order is safe in general.
	@# See deploy/atomic-release.sh and CHANGELOG 2026-10-02.
	@ssh -p $(TEST_PORT) $(TEST_HOST) "mkdir -p $(STAGING_HOME)/.phpman_test/releases/$(STAGING_RELEASE_ID)/src"
	@rsync -avz -e "ssh -p $(TEST_PORT)" src/ $(TEST_HOST):$(STAGING_HOME)/.phpman_test/releases/$(STAGING_RELEASE_ID)/src/
	@scp -P $(TEST_PORT) $(FILE).deploy $(TEST_HOST):$(STAGING_HOME)/.phpman_test/releases/$(STAGING_RELEASE_ID)/$(FILE)
	@rm -f $(FILE).deploy
	@rsync -avz -e "ssh -p $(TEST_PORT)" deploy/ $(TEST_HOST):$(STAGING_HOME)/.phpman_test/deploy/
	@ssh -p $(TEST_PORT) $(TEST_HOST) "sh $(STAGING_HOME)/.phpman_test/deploy/atomic-release.sh '$(STAGING_HOME)/.phpman_test' '$(TEST_PATH)' '$(FILE)' '$(STAGING_RELEASE_ID)'"
	@rsync -avz -e "ssh -p $(TEST_PORT)" $(CSS_FILE) $(JS_FILE) $(TEST_HOST):$(TEST_PATH)/
	@rsync -avz -e "ssh -p $(TEST_PORT)" cli/ $(TEST_HOST):$(STAGING_HOME)/.phpman_test/cli/
	@rsync -avz -e "ssh -p $(TEST_PORT)" phpman.config.php.example $(TEST_HOST):$(STAGING_HOME)/.phpman_test/
	@ssh -p $(TEST_PORT) $(TEST_HOST) "chmod 644 $(TEST_PATH)/$(FILE) $(TEST_PATH)/$(CSS_FILE) $(TEST_PATH)/$(JS_FILE) && chmod +x \$$HOME/.phpman_test/cli/*.php"
	@echo "--- Detecting available documentation tools ---"
	@# Write to a temp file and rename: rewriting in place truncates first, and a
	# request served in that window would read an empty tools_config.php (#240).
	@ssh -p $(TEST_PORT) $(TEST_HOST) "php $(STAGING_HOME)/.phpman_test/cli/detect-tools.php > $(STAGING_HOME)/.phpman_test/tools_config.php.tmp && mv $(STAGING_HOME)/.phpman_test/tools_config.php.tmp $(STAGING_HOME)/.phpman_test/tools_config.php && cat $(STAGING_HOME)/.phpman_test/tools_config.php"
	@echo ""
	@echo "=== Deployed to staging ($(STAGING_VERSION)) ==="
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
	@# 守卫先跑，任何 rsync 之前。三条守卫的用途见 deploy/release-precheck.sh。
	@# 它在成功时把 origin 的 tag 快照打到 stdout —— 版本号必须由**同一份**快照算出来。
	@# 若让下面那步自己再 ls-remote 一次，那次失败会得到空输入，被 next-version.sh
	@# 当成「远端还没有 tag」算出 0.0.1：一个静默的版本倒退。
	@deploy/release-precheck.sh $(DEPLOY_PATHS) > .release-remote-tags
	@# 每次发布都重写 .release-version：绝不复用上一次的残留值，否则中断过的发布会把
	@# tag 打到错误的版本号上。先写 .tmp 再改名，避免中断时留下半截文件。
	@deploy/next-version.sh $(VERSION) < .release-remote-tags > .release-version.tmp \
	    && printf 'v%s\n' "$$(cat .release-version.tmp)" > .release-version \
	    && rm -f .release-version.tmp .release-remote-tags
	@echo "=== Deploying $$(cat .release-version) ($(RELEASE_ID)) ==="
	@# Patch placeholders in phpMan.php (never touches local FILE)
	@RELVER=$$(cat .release-version); \
	  sed -e "s|define('PHPMAN_HOME',[^;]*;|define('PHPMAN_HOME', '$(DEMO_HOME)/.phpman');|" \
	    -e "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$$RELVER');/" \
	    -e "s/define('PHPMAN_VERSION', '[^']*');/define('PHPMAN_VERSION', '$${RELVER#v}');/" $(FILE) > $(FILE).deploy
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
	@# Write to a temp file and rename: rewriting in place truncates first, and a
	# request served in that window would read an empty tools_config.php (#240).
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) "php $(DEMO_HOME)/.phpman/cli/detect-tools.php > $(DEMO_HOME)/.phpman/tools_config.php.tmp && mv $(DEMO_HOME)/.phpman/tools_config.php.tmp $(DEMO_HOME)/.phpman/tools_config.php && cat $(DEMO_HOME)/.phpman/tools_config.php"
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

# 部署**全部**成功之后才打 tag 并 push。
#
# 刻意不放进 _release-code：它是 release 与 release-reindex 共享的前置，而
# release-reindex 的 index 重建与 sitemap 生成跑在它之后。tag 若在 _release-code
# 里推走，reindex 失败时就会留下一个「代码已上线、索引没重建」的 tag，而重跑会被
# 守卫 2 挡住（HEAD 已被 tag 过），只剩手工 make reindex 一条路。
#
# 本地 tag 已存在时跳过创建：那是上一次发布创建了 tag 但 push 失败留下的。push 失败
# 时**删掉本地 tag** 再退出 —— 守卫 2 只查远端，删掉本地 tag 后重跑时四条守卫全过，
# 会重新算出同一个版本号并补推，天然自愈。
_release-tag:
	@RELVER=$$(cat .release-version); \
	  if git rev-parse -q --verify "refs/tags/$$RELVER" >/dev/null; then \
	    echo "本地已有 $$RELVER（上一次 push 失败留下的），确认它指向 HEAD"; \
	    [ "$$(git rev-parse "$$RELVER^{commit}")" = "$$(git rev-parse HEAD)" ] \
	      || { echo "错误：本地 tag $$RELVER 不指向 HEAD，手工确认后再发布。" >&2; exit 1; }; \
	  else \
	    git tag -a "$$RELVER" -m "$$RELVER"; \
	  fi; \
	  if git push origin master "refs/tags/$$RELVER"; then \
	    echo "=== 已打 tag 并推送：$$RELVER ==="; \
	    rm -f .release-version; \
	  else \
	    git tag -d "$$RELVER" >/dev/null; \
	    echo "错误：代码已上线，但 $$RELVER 与 master 未推送到 origin。" >&2; \
	    echo "      已删掉本地 tag；修好之后重跑 make release 即可补上。" >&2; \
	    exit 1; \
	  fi

release: test test-unit _release-code
	@$(MAKE) _release-tag

release-reindex: test test-unit _release-code
	@echo "=== Rebuilding production search index ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cd $(DEMO_HOME)/.phpman && php cli/build-index.php --cron"
	@echo "=== Generating production sitemap (html for Google, markdown/json for AI bots) ==="
	ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"cd $(DEMO_HOME)/.phpman && php cli/build-sitemap.php --output $(DEMO_PATH)/sitemap-phpman.xml.gz --base-url $(DEMO_URL) --sitemap-url https://www.chedong.com/sitemap-phpman.xml.gz --formats html --max-urls 50000 \
		 && php cli/build-sitemap.php --output $(DEMO_PATH)/sitemap-phpman-ai.xml.gz --base-url $(DEMO_URL) --sitemap-url https://www.chedong.com/sitemap-phpman-ai.xml.gz --formats markdown,json --max-urls 50000 --llms-output $(DEMO_PATH)/llms.txt"
	@echo "=== Production index + sitemap complete ==="
	@$(MAKE) _release-tag

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
# The check itself lives in cli/logcheck.sh and is piped to the server over
# stdin, so the script in this checkout is always the one that runs — nothing
# needs deploying first. Requires DEMO_ERROR_LOG and DEMO_ACCESS_LOG in
# .deploy.mk; LOGCHECK_DAYS / LOGCHECK_RECENT_LINES tune the window.
logcheck:
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"DEMO_ERROR_LOG='$(DEMO_ERROR_LOG)' DEMO_ACCESS_LOG='$(DEMO_ACCESS_LOG)' bash -s" < cli/logcheck.sh

# Rotate the production phpMan error log, keeping the newest LOG_ROTATE_KEEP
# (default 5) rotations. Piped over stdin like logcheck, so nothing has to be
# deployed first. Preview with: make log-rotate LOG_ROTATE_DRY_RUN=1
# The same script is deployed under cli/, which is what a cron entry would call.
log-rotate:
	@ssh -p $(DEMO_PORT) $(DEMO_HOST) \
		"PHPMAN_LOG='$(PHPMAN_LOG)' LOG_ROTATE_KEEP='$(LOG_ROTATE_KEEP)' LOG_ROTATE_DRY_RUN='$(LOG_ROTATE_DRY_RUN)' bash -s" < cli/log-rotate.sh

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
