# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

phpMan is a single-file PHP web app (~4800 lines, `phpMan.php`) that wraps Unix `man`, `perldoc`, `info`, `pydoc3`, `ri`, and `apropos` commands into HTML, Markdown, JSON, and MCP responses. It also runs as an MCP Server for AI agent integration.

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

# Deploy
make staging                         # scp to staging (code + CSS only)
make release                         # scp to production (backs up first, code only)
make release-reindex                 # production: code + rebuild search index
make staging-reindex                 # staging: code + rebuild search index
make reindex                         # production: rebuild search index only
make reindex-staging                 # staging: rebuild search index only
make rollback                        # restore production backup
make verify                          # curl health check on both
make logcheck                        # tail server logs after release
```

The test framework is minimal (no PHPUnit): `assert_equals`, `assert_contains`, `assert_match`. Tests load `phpMan.php` with `define('PHPMAN_TEST_MODE', true)` to skip runtime execution and only define functions.

## Architecture

**URL routing** — PATH_INFO-based: `phpMan.php/MODE/COMMAND/SECTION/FORMAT`. The main dispatch `switch ($mode)` routes to `getManPage`, `getPerldocPage`, `getInfoPage`, `getSearchPage`, or the index variants. Before dispatch, `normalizeMode/Parameter/Section` clean input. The `.well-known/mcp.json` and `mcp` mode are handled before the switch.

**Format negotiation** (4-tier priority): GET param → PATH_INFO segment → Accept header → default HTML. Supported: `html`, `markdown`, `json`, `mcp`. The `formatForOutput()` function converts the JSON intermediate representation to the requested format.

**Content pipeline** — Each get*Page function shells out to the system command, captures raw lines, and passes them through `formatManPerlDoc()` which converts overstrike sequences (man) and ANSI escapes (perldoc) to HTML. For JSON/Markdown/MCP output, the HTML result is parsed again through `formatToJSON()` or `formatManPerlDocToMarkdown()`.

**Heading detection** — `detectHeadingType()` handles 4 patterns: ALL_CAPS L1, indented title-case L2, bold option flags L2, and `=head2`-style L2. Order matters: L2 patterns must be checked before L1 to avoid misclassifying subheadings.

**MCP server** — `handleMcp()` implements JSON-RPC 2.0 over Streamable HTTP POST at `/mcp`. Two tools: `cli_help` and `cli_search`. MCP responses wrap JSON in `{content: [{type: "text", text: ...}], structuredContent: {...}}`.

**TLDR** — TLDR cheatsheets are embedded inline in man page detail pages. `fetchOfficialTldr()` fetches from tldr-pages GitHub raw (primary) or cheat.sh (fallback), caches in SQLite `tldr_cache` table with 7-day TTL. No LLM/API key needed. The old `/tldr` route is removed.

**LLM Enhancement (v4.0)** — Optional LLM-powered emoji enhancement layer. Dual-format: `enhanceManPage()` generates both `emoji_html` (HTML-direct, for default view) and `emoji_md` (Markdown, for /markdown format) via 2 LLM calls. `callLLM()` handles the API call with 300s timeout, no hard max_tokens cap, and `finish_reason: "length"` truncation detection. HTML-direct pipeline sends rendered HTML to LLM with `<pre><code>`/`<b>`/`<u>`/`<a>` preserved; TOC built from `<h2>`/`<h3>` tags. Enhanced HTML is the default view when `emoji_html` cache exists; `?format=html` bypasses. On shared hosts where `man` can't fork under load, use `tools/enhance_page.php` to fetch via HTTP and write cache directly. See `docs/01-PRODUCT.md` §2.12 for full design.

## Key design rules

- **Single-file deployment by design** — no Composer, no autoload. Code splits (v3.0 roadmap) must preserve a single-file entry point.
- **XHTML 1.0 Transitional** — no HTML5 tags (`<nav>`, `<section>`), no `og:` meta tags. Use `<div id="...">` and `<p>` instead.
- **Footer IP + UA display is intentional** — it's for spider/bot tracking in `showFooter()`. Do not remove it. See `docs/01-PRODUCT.md` for the full rationale.
- **`?debug=1`** only shows sensitive details when `isLocalRequest()` returns true (REMOTE_ADDR is 127.0.0.1, ::1, or empty).
- **Config overridables** — `PHPMAN_WIDTH`, `PHPMAN_TOC_THRESHOLD`, `PHPMAN_GZIP_MIN_BYTES`, `PHPMAN_TLDR_MAX_EXAMPLES`, `PHPMAN_HOME_TITLE`, `PHPMAN_PROJECT_NAME` use `defined()` guard pattern, overridable via `phpman.config.php`.
- **Cap word style** for new code: functionNames, variableNames, arrayKeys. Existing code uses mixed styles — match the surrounding convention.
- **`h()` and `serverValue()`** are the canonical helpers for HTML escaping and reading `$_SERVER`. Use them instead of direct access.
