# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Project overview

phpMan is a PHP web app (`phpMan.php` ~795 lines + 21 source files in `src/`) that wraps Unix `man`, `perldoc`, `info`, `pydoc3`, `ri`, and `apropos` commands into HTML, Markdown, and JSON responses. It also runs as an MCP Server for AI agent integration. CLI tools (search index rebuild, sitemap generation, cache stats/flush) are in `cli/`.

## Build / test / deploy

```bash
# Syntax check
make test                           # php -l phpMan.php

# Unit + integration tests (no network, instant)
php test/run_all.php

# E2E tests (need network)
php test/e2e/test_user_scenarios.php
php test/e2e/test_agent_scenarios.php
php test/e2e/test_spider_scenarios.php
php test/e2e/test_security.php

# Full deploy validation (network required)
bash test/phpman-regression.sh                        # against production
bash test/phpman-regression.sh --local http://localhost:8080/phpMan.php   # pre-deploy

# Deploy (atomic since v5.0: releases/<tag>/ + `current` symlink swap)
make staging                         # staging: syntax check + atomic release
make release                         # production: syntax check + atomic release + logcheck
make release-reindex                 # production: code + rebuild search index + sitemaps
make staging-reindex                 # staging: code + rebuild search index + sitemaps
make reindex                         # production: rebuild search index + sitemaps only
make reindex-staging                 # staging: rebuild search index + sitemaps only
make rollback                        # flip `current` back a release (STEP=n)
make verify                          # curl health check on both
make logcheck                        # post-deploy error summary (per-day / per-type)

# Cache (sharded per mode since v4.11)
make cache-stats                     # rows / bytes / stale per shard
make cache-flush                     # delete production page-cache shards
make cache-flush-staging             # delete staging page-cache shards
```

The test framework is minimal (no PHPUnit): `assert_equals`, `assert_contains`, `assert_match`. Tests load `phpMan.php` with `define('PHPMAN_TEST_MODE', true)` to skip runtime execution and only define functions.

## CLI tools

CLI functionality has been split into standalone scripts under `cli/`:

```bash
# Search index
php cli/build-index.php              # Rebuild FTS5 search index
php cli/build-index.php --cron       # Rebuild with UTC timestamp

# Sitemap
php cli/build-sitemap.php --output sitemap-phpman.xml.gz
```

All CLI scripts resolve `PHPMAN_HOME`, then require `src/bootstrap.php` directly
(no longer load `phpMan.php`). `src/config.php` sets defaults and loads
`~/.phpman/phpman.config.php` for user overrides. The old
`php phpMan.php --build-index` / `--build-index-cron` / `--enhance` flags are removed.

## Architecture

**URL routing** — PATH_INFO-based: `phpMan.php/MODE/COMMAND/SECTION/FORMAT`. The main dispatch `switch ($mode)` routes to `getManPage`, `getPerldocPage`, `getInfoPage`, `getSearchPage`, or the index variants. Before dispatch, `normalizeMode/Parameter/Section` clean input. The `.well-known/mcp.json` and `mcp` mode are handled before the switch.

**Format negotiation** (4-tier priority): GET param → PATH_INFO segment → Accept header → default HTML. Supported: `html`, `markdown`, `json`. (`mcp` is an internal rendering core that `handleMcp()` calls — not a URL-selectable format; see `PHPMAN_OUTPUT_FORMATS`.) The `formatForOutput()` function converts the JSON intermediate representation to the requested format.

**Content pipeline** — Each get*Page function shells out to the system command, captures raw lines, and passes them through `formatManPerlDoc()` which converts overstrike sequences (man) and ANSI escapes (perldoc) to HTML. For JSON/Markdown/MCP output, the HTML result is parsed again through `formatToJSON()` or `formatManPerlDocToMarkdown()`.

**Heading detection** — `detectHeadingType()` handles 4 patterns: ALL_CAPS L1, indented title-case L2, bold option flags L2, and `=head2`-style L2. Order matters: L2 patterns must be checked before L1 to avoid misclassifying subheadings.

**MCP server** — `handleMcp()` implements JSON-RPC 2.0 over Streamable HTTP POST at `/mcp`. Two tools: `cli_help` and `cli_search`. MCP responses wrap JSON in `{content: [{type: "text", text: ...}], structuredContent: {...}}`.

**TLDR** — TLDR cheatsheets are embedded inline in man page detail pages. `fetchOfficialTldr()` fetches from tldr-pages GitHub raw (primary) or cheat.sh (fallback), caches in SQLite `tldr_cache` table under the unified cache TTL (`PHPMAN_CACHE_TTL_FOUND`, default 7 months; set via `PHPMAN_CACHE_TTL_MONTHS`). No LLM/API key needed. The old `/tldr` route is removed.

**Cache invalidation — `RENDERER_VERSION`** — The page cache stores *finished* output under a 210-day TTL (`PHPMAN_CACHE_TTL_FOUND`), so **a renderer fix does not reach a page that is already cached**. That is not hypothetical: the OSC-8 and JSON-markdown fixes were invisible on production until 1,703 rows were found and deleted by hand. `RENDERER_VERSION` (`src/config.php`) is written on every `PageCache::set()` and is part of `PageCache::get()`'s `WHERE` clause, so a row from a different renderer reads as a miss and re-renders on its next request, overwriting itself in place.

**Bump `RENDERER_VERSION` whenever a change alters rendered output** — the HTML/markdown/JSON renderers, `formatForOutput()`, `cleanTerminalOutput()`, OSC-8 link handling. Nothing needs purging and there is no re-render herd; existing rows default to the old version and drain gradually through real traffic. `make cache-stats` shows a `stale` count per shard.

Do **not** reintroduce a schema-version stamp alongside it. `CACHE_SCHEMA_VERSION` and both migration ladders were removed in v5.0 (`91cb020`): each cache DB now creates its schema with `CREATE ... IF NOT EXISTS` on every open, so an additive change needs no migration, and the stamps that used to record the shape (`meta.schema_version`, `PRAGMA user_version`) existed only so a rolled-back deploy could re-stamp and re-migrate — that rollback provision is gone. Do not auto-derive `RENDERER_VERSION` from a deploy stamp or source hash either; that would invalidate on every deploy, including ones that cannot change a byte of output.

History not to repeat: schema v5→v6 dropped `cache.generator_version`, which stored `GIT_DESCRIBE` on every `set()` — nothing ever read it, so it invalidated nothing. **A version is only worth storing if `get()` filters on it.**

Surveying the cache needs care too: in `json` rows a terminal escape is stored as the six-character sequence `\u001b`, not the raw byte, so a raw-byte search reports zero — decode before searching.

**Emoji cache rows (inert)** — `emoji_html` / `emoji_md` cache rows predate v4.10, which removed the LLM enhancement layer (`enhanceManPage()`, `callLLM()`, `cleanEmojiHtml()`, `cli/batch-enhance.php`). v5.0 (`5bf0025`) then removed the **read** side too — the default-view fallback, the `/markdown` preference, the `CACHE_FORMAT_EMOJI_*` constants and the never-expire TTL rule — and the v3→v4 migration whose preserve list was the last thing naming those formats was deleted in the same release. So these rows are now written by nothing, read by nothing, and named by nothing. Do not add code that reads them. Historical design lives in git history v4.0–v4.9 and `docs/01-PRODUCT.md` §2.12.

**UX: code blocks + copy button** — External JS `phpman.js` (loaded in `showFooter()`) wraps all `#content-wrap pre` blocks in `<div class="code-block">` with a `📋 Copy` button positioned top-right. Clicking copies the `<code>` (or `<pre>`) textContent to clipboard with `✓ Copied!` feedback. CSS: Tokyo Night `#1f2335` background, `italic` font, rounded border, button hidden until hover (.code-block:hover .copy-btn).

## Git workflow — CRITICAL: worktree rebase rule

### 分支约定（2026-09）

- **default 是 `master`**：push 一律 `git push origin master`，master 是唯一主干。
- **不要 push 其他分支名**（如 `git push origin main`）：远程没有该分支时会意外创建一个非主干分支（myblog 的教训：default=main 却 push master，养出了旁支）。若日后决定调整 default 分支，再更新本节。
- 无 feature branch、无 PR，提交直接进 master。

**phpMan uses `master` as the single source of truth. No feature branches, no PRs. Every commit goes directly to master.**

### Commit 署名格式

`Co-Authored-By` 行用固定格式，三个值**动态读取**（不要写死 `Claude <noreply@anthropic.com>`）：

```
Co-Authored-By: Claude code <版本> with <模型名> <noreply@<域名>>
```

| 部分 | 来源 |
|---|---|
| 版本 | `claude --version` |
| 模型名 | **本会话实际跑的模型**，从会话 transcript 读（见下）—— 不是 `ANTHROPIC_MODEL` |
| 域名 | `ANTHROPIC_BASE_URL` 的 host（如 `https://taotoken.net/api` → `taotoken.net`） |

**模型名不要取 `ANTHROPIC_MODEL`**：那是*配置*里的模型，未必是会话实际被路由到的那个。2026-10-02 就出现过 `ANTHROPIC_MODEL=glm-5.3-flash` 而会话实际跑 `deepseek-flash`。从 transcript 读实际值：

```bash
grep -o '"model":"[^"]*"' ~/.claude/projects/<project-slug>/$CLAUDE_CODE_SESSION_ID.jsonl | sort -u
```

示例：`Co-Authored-By: Claude code 2.1.285 with deepseek-flash <noreply@taotoken.net>`

### Rule: rebase before commit in any worktree

Worktrees are snapshots frozen at creation time. If you commit from a worktree without rebasing first, you will **silently overwrite** commits pushed to master since the worktree was created. This has happened multiple times (CSS fixes, font sizes, format link positions all lost to overwrites).

**Before committing from ANY worktree, always:**

```bash
# 1. Fetch latest from GitHub
git fetch origin master

# 2. Rebase current work onto latest master
git rebase origin/master

# 3. If conflicts: resolve, then
#    git add <resolved-files>
#    git rebase --continue

# 4. Only now commit
git add <files>
git commit -m "..."
git push origin master
```

**Pre-commit checklist (Codex agents MUST follow):**

1. `git fetch origin master` — get latest remote state
2. `git log --oneline HEAD..origin/master` — are there commits on remote I don't have?
3. If yes → `git rebase origin/master` BEFORE any local commit
4. If no (fast-forward possible) → safe to commit
5. Never use `-f` (force push) on master

**If you created a worktree for a task and the task is done:**
- Delete the worktree (`ExitWorktree` with `action: "remove"`)
- Or keep it but rebase before next use

**When in doubt about which branch/worktree you're in:**
```bash
git status          # shows branch and tracking
git worktree list   # shows all worktrees
git log --oneline -3  # shows recent commits
```

## Key design rules

- **Single-file deployment by design** — one PHP file (`phpMan.php`) in webroot, 21 source files in `src/` outside webroot. No Composer, no autoload. Code structure preserves a single web-accessible entry point.
- **XHTML 1.0 Transitional** — no HTML5 tags (`<nav>`, `<section>`), no `og:` meta tags. Use `<div id="...">` and `<p>` instead. Underline uses `<span class="u">` (CSS-driven, avoids `<u>` deprecation warnings in validators).
- **Footer IP + UA display is intentional** — it's for spider/bot tracking in `showFooter()`. Do not remove it. See `docs/01-PRODUCT.md` for the full rationale.
- **`?debug=1`** only shows sensitive details when `isLocalRequest()` returns true (REMOTE_ADDR is 127.0.0.1, ::1, or empty).
- **Config architecture (v4.5)** — single config file at `~/.phpman/phpman.config.php` (NEVER in webroot). `PHPMAN_HOME` is baked into `phpMan.php` at deploy time (via `sed`, same as `GIT_DESCRIBE`). `src/config.php` loads defaults then requires the user config. API keys (`MCP_API_KEY`) are outside webroot.
- **Cap word style** for new code: functionNames, variableNames, arrayKeys. Existing code uses mixed styles — match the surrounding convention.
- **Output format purity** — each format must produce self-consistent output with no cross-format contamination. Markdown output MUST NOT contain HTML tags (`<ul>`, `<li>`, `<a>`) — use pure Markdown (`- ` list items, `[text](url)` links). JSON MUST be valid parseable JSON. HTML MUST be XHTML 1.0 Transitional compliant.
- **`h()` and `serverValue()`** are the canonical helpers for HTML escaping and reading `$_SERVER`. Use them instead of direct access.
