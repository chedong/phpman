# phpMan Project Plan

## Version Numbering Convention

- Format: `v{MAJOR}.{MINOR}` → git tag `v2.1`, `v3.0`
- Patch versions: `v3.6.3` — bugfix or minor improvement, no new features
- MAJOR bump: architecture changes or backward-incompatible API changes
- MINOR bump: new features, backward compatible
- Tag rule: each release has a corresponding annotated tag

```bash
git tag -a v3.6.3 -m "v3.6.3: English docs, --help CLI, cache TTL, removed rebuild-index.php"
git push origin v3.6.3
```

---

## Version Roadmap

```
v2.1 → v2.3 → v3.6 → v3.7.12 → v4.0 → v4.1 (current)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
man/perldoc/info   pydoc3/ri        Config overridables   JSON canonical cache   batch PID/stop
MCP Server         structured out   Underscore link fix   LLM emoji enhancement   XSS hardening
JSON API           Search cascade   man7.org fallback     Code split             --parameter mode
TLDR endpoint      FTS5 3-source    Docs restructured     i18n                   minimal webroot
                                   Structure regr test   AI translation          install.sh MCP key
```

---

## Completed Versions

### v3.7.9–3.7.12 (2026-06-11 → 2026-06-14)

- **Config overridable constants**: `PHPMAN_WIDTH`, `PHPMAN_TOC_THRESHOLD`, `PHPMAN_GZIP_MIN_BYTES`, `PHPMAN_TLDR_MAX_EXAMPLES`, `PHPMAN_HOME_TITLE`, `PHPMAN_PROJECT_NAME` — all use `defined()` guard pattern, overridable via `phpman.config.php`
- **Naming cleanup**: `$GLOBALS['PHPMAN_WIDTH']` → direct constant, `$site_name` → `PHPMAN_PROJECT_NAME`
- **Branding**: `phpMan` → `phpman` in H1, site_name, footer
- **Fix**: cross-reference links for underscored man page names (e.g., `io_cancel(2)`) — SGR processing moved before linkification
- **Fix**: man page "not found" fallback changed from cheat.sh → man7.org
- **Docs restructured**: numbered index (`00–05`), `PYDOC_RI_DESIGN.md` merged into `01-PRODUCT.md`, design system tokens in `02-UI-DESIGN.md`
- **Doc fixes**: stale line numbers removed across all docs, SKILL.md broken references fixed

### v3.4–3.6.3 (2026-06-05 → 2026-06-08)

- **FTS5 full-text search**: offline index across man/pydoc/ri, single query covers all, case-insensitive
- **SQLite cache architecture**: `cacheDb()` + `PageCache` + `search_fts` + `search_index_meta`
- **Configuration**: `phpman.config.php` with CACHE_DIR / LLM / DEBUG support
- **pydoc3 / ri modes**: HTML/Markdown/JSON/MCP four-format output
- **Search cascade**: FTS5 first → command-line fallback (apropos / pydoc3 -k / ri)
- **Deploy system**: `.deploy.mk` + Makefile (staging/production/rollback/cache-flush)
- **TLDR SQLite cache**: 7-day TTL with negative caching (fixes #79, #80)
- **FTS5 dedup guard**: meta check before INSERT prevents duplicate index rows
- **Cache TTL**: found entries 7 days, auto-cleanup (1% chance per request)
- **`--help` CLI**: `php phpMan.php --help` with usage and sources
- **Removed**: `rebuild-index.php` (superseded by `php phpMan.php --build-index`)

### v2.3 (released)

- pydoc3 / ri modes
- Search cascade (apropos → pydoc3 → ri)
- ri RDoc heading detection (`=` / `==`)
- Auto document source detection (`::` → perldoc, `.` → pydoc, `#` → ri)

### v2.2 (released)

- TLDR inline: official tldr-pages (GitHub Raw) → cheat.sh fallback → rule extraction
- Full-format embedding (HTML/JSON/Markdown/MCP)
- Zero config, no LLM key needed

### v2.1 (released)

- man / perldoc / info modes
- MCP Server (Streamable HTTP)
- Markdown / JSON / MCP output
- Cross-platform width control

---

## Planned

### v4.0 — Architecture Upgrade (✅ released 2026-06-15)

**Phase 1: Structure regression test** (✅)
- `test/structure_regression.php`: validates JSON section structure fingerprints
- Tests 5 man + 5 perldoc + 5 pydoc pages for structural invariants:
  - NAME section must exist
  - All sections have name + content
  - Subsections have non-empty names
  - Flags have flag fields
  - Mode matches expected
- Outputs structure fingerprints for regression baselines

**Phase 2: JSON canonical cache** (✅)
- Refactor cache to store only JSON format
- Derive HTML/Markdown/MCP from JSON (forward generation, no reverse parsing)
- New functions: `formatJSONToHTML()`, `formatJSONToMarkdown()`
- Cache key: `mode/command/section` → single JSON entry
- `formatForOutput()` already does JSON→MCP (reuse)

**Phase 3: LLM emoji enhancement** (✅)
- Dual-format architecture: 2 LLM calls per document → `emoji_html` (default view) + `emoji_md` (/markdown format)
- HTML-direct pipeline: rendered HTML → LLM → enhanced HTML with `<h2>`/`<h3>`/`<pre><code>`/`<a>` preserved
- Markdown pipeline: raw Markdown → LLM → enhanced Markdown (for /markdown format)
- `enhanceManPage()`: CLI batch mode `php phpMan.php --enhance=man:ls,tar,grep`
- `callLLM()`: OpenAI-compatible chat completions API (deepseek-v4-pro via taotoken.net)
- No hard max_tokens cap; `finish_reason: "length"` truncation detection + logging
- `renderTocSidebar()`: floating TOC built from enhanced `<h2>`/`<h3>` HTML tags
- Enhanced HTML is the default view when `emoji_html` cache exists; `?format=html` bypasses
- Config: `LLM_API_KEY`, `LLM_API_URL`, `LLM_MODEL`, `LLM_MAX_TOKENS` via `phpman.config.php`
- `tools/enhance_page.php`: single-page CLI tool for shared hosts where man(1) can't fork
- `tools/batch_enhance.php`: offline batch enhancement — auto-discovers ~35K entries from search_index_meta + cache, 2-min rate limiting, resilient resume, `--cached-first` sort, idempotent per-entry cache writes (2026-06-17)
- `DELETE FROM cache` now preserves emoji_md/emoji_html during reindex (2026-06-17)
- See `docs/01-PRODUCT.md` §2.11 for full design rationale

**Phase 4: Code split** (planned)
- `src/Source/` + `src/Formatter/` + `src/Cache/` + `src/Config/`
- Single-file entry point preserved

### v4.1 — Tooling & Security Hardening (current, 2026-06-17)

**batch_enhance.php lifecycle**:
- `--pid-file` + `--stop`: PID-based process management, safe kill via SIGTERM→SIGKILL
- `--status`: per-mode emoji enhancement progress with counts, percentages, recent entries
- `--rebuild` / `-r`: force re-enhance even if emoji cache exists
- `--parameter=<name1;name2>` + `--section=<s>`: single/multi-page enhancement (supersedes `tools/enhance_page.php`)
- `--parameter` + `--mode`: target specific pages across any documentation source
- No-arg invocation defaults to `--help`
- **Fully offline** (2026-06-18): `require_once`'s phpMan.php, calls `getManPage()`/`getPerldocPage()`/etc. directly, uses shared `PageCache` and `callLLM()` — zero HTTP dependency
- **Non-existent page skip**: 404 / `No manual entry` pages are written to cache as `not_found` and skipped permanently
- **Section-aware fetch**: `httpGetWithStatus()` → direct function calls with correct `$section` parameter (fixes #137 variant in batch_enhance)

**Security hardening**:
- `formatInlineMarkdown()` XSS fix: `h()`-escape all output, restore safe tags (`<a>`, `<code>`, `<b>`, `<i>`)
- `cleanEmojiHtml()` / `cleanLlmOutput()`: `strip_tags()` with safe allowlist as XSS defense-in-depth
- LLM prompts: explicit XSS prevention rule — escape bare `<` `>` outside allowed HTML tags
- v3→v4 migration: `NOT IN` list includes `emoji_md`, `emoji_html` to preserve LLM caches

**Design & docs**:
- Minimal webroot principle: only `phpMan.php` + `phpman.css` + config in public path (`01-PRODUCT.md` §2.3)
- `tools/` moved out of webroot on staging/production
- install.sh `--webroot` auto-generates `MCP_API_KEY` (random 32-char hex)
- README restructured: install.sh first, MCP agent config moved lower
- Removed `tools/enhance_page.php` — superseded by `batch_enhance.php --parameter`

**Cleanup**:
- Removed all `maxLen` input truncation — LLM models handle full pages natively
- Dead constant `PHPMAN_FLAG_DESC_MAX_LEN` removed
- `formatMarkdownToHTML()` regex `#{1,4}` → `#{2,5}` (skips h1, matches h2-h6)
- Makefile auto-creates `~/.phpman/phpman.config.php` → webroot symlink on deploy
- All 10 stale worktree branches purged from GitHub
- 7 open issues closed: #128, #133–#141

---
