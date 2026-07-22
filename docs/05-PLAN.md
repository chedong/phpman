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
v2.1 → v2.3 → v3.6 → v3.7.12 → v4.0 → v4.1 → v4.2 → v4.3 → v4.4 → v4.5 → v4.6 → v4.7 → v4.8 (current)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
man/perldoc/info   pydoc3/ri        Config overridables   JSON canonical cache   batch PID/stop    Copy button UX   OKF Markdown    Code Split
MCP Server         structured out   Underscore link fix   LLM emoji enhancement   XSS hardening     Prompt v2 tuning  PHPMAN_BASE_URL thin dispatcher
JSON API           Search cascade   man7.org fallback     Code split (design)     --parameter mode  ENHANCE_MAX_CHARS URL hardening   src/ layout
TLDR endpoint      FTS5 3-source    Docs restructured     i18n                   minimal webroot   TOC regex fix     format purity   22 src files
                                   Structure regr test   AI translation          install.sh MCP key Makefile version sync ?build-index removal  shared bootstrap
                                                                                                               rsync deploy   Calibrated Terminal CSS
                                                                                                               theme toggle   Geist-inspired token system
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
- **Removed**: `rebuild-index.php` (superseded by `php cli/build-index.php`)

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

**Phase 3: LLM emoji enhancement** (✅ shipped 2026-06-15, **moved out 2026-07-14**)
- Dual-format architecture: 2 LLM calls per document → `emoji_html` (default view) + `emoji_md` (/markdown format)
- HTML-direct pipeline: rendered HTML → LLM → enhanced HTML with `<h2>`/`<h3>`/`<pre><code>`/`<a>` preserved
- Markdown pipeline: raw Markdown → LLM → enhanced Markdown (for /markdown format)
- `enhanceManPage()`: CLI batch mode `php phpMan.php --enhance=man:ls,tar,grep`
- `callLLM()`: OpenAI-compatible chat completions API (deepseek-v4-pro via taotoken.net)
- No hard max_tokens cap; `finish_reason: "length"` truncation detection + logging
- `renderTocSidebar()`: floating TOC built from enhanced `<h2>`/`<h3>` HTML tags
- Enhanced HTML is the default view when `emoji_html` cache exists; `?format=html` bypasses
- Config: `LLM_API_KEY`, `LLM_API_URL`, `LLM_MODEL`, `LLM_MAX_TOKENS` via `phpman.config.php`
- `cli/batch-enhance.php`: single-page CLI tool for shared hosts where man(1) can't fork
- `cli/batch-enhance.php`: offline batch enhancement — auto-discovers ~35K entries from search_index_meta + cache, 2-min rate limiting, resilient resume, `--cached-first` sort, idempotent per-entry cache writes (2026-06-17)
- `DELETE FROM cache` now preserves emoji_md/emoji_html during reindex (2026-06-17)
- Lives on as the standalone `doc-enhance` project (see `## External Projects`)
- Historical design preserved in git history (v4.0..v4.9) for reference
- See `docs/01-PRODUCT.md` §2.12 for what moved and why

**Phase 4: Code split** (planned)
- `src/Source/` + `src/Formatter/` + `src/Cache/` + `src/Config/`
- Single-file entry point preserved

### v4.9 (planned) — CSP nonce + strict-dynamic
- Replace `'unsafe-inline'` with `'nonce-{CSP_NONCE}' 'strict-dynamic'` (Google-recommended pattern)
- Per-request `random_bytes(16)` nonce shared via `define('CSP_NONCE', ...)` between `showHeader()` and `showFooter()`
- Keep domain allowlist as Safari <15.4 fallback, keep `'unsafe-inline'` during transition
- See `docs/01-PRODUCT.md` §3.2 for full strategy

### v4.8 — Calibrated Terminal CSS (2026-06-26)

- **CSS rewrites from scratch**: Geist-inspired semantic design token system — 6 surface tokens, 4 text tokens, 4 alpha overlay tokens, border strength scale
- **Typography split**: System sans-serif (`-apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif`) for UI chrome (H1 breadcrumb, form, sidebar, footer, TLDR headers); monospace (`'SF Mono', 'Cascadia Code', Menlo, Consolas, 'DejaVu Sans Mono', monospace`) for man page content
- **Alpha-layered depth**: `rgba(255,255,255,0.04–0.18)` translucent overlays replace opaque surface variants — depth through transparency without backdrop-filter
- **Focus ring**: Two-layer `box-shadow` (`2px surface-root, 4px accent-blue`) on all `:focus-visible` interactive elements. Input fields get softer 3px glow ring
- **Motion system**: `150ms` state changes, `200ms` overlays, `cubic-bezier(0.175, 0.885, 0.32, 1.1)` easing. `prefers-reduced-motion: reduce` kills all transitions
- **4px spacing unit**: 4/8/12/16/24/32/40px token scale (from Vercel Geist)
- **Border radius**: Consistent 6px default on all surface elements. No mixing of sharp and round
- **Form redesign**: Toolbar card with `card` shadow, input focus glow ring, button `scale(0.97)` active feedback, uppercase format links with letter-spacing
- **Print stylesheet**: Hides all chrome (toggle, sidebars, form, copy buttons), pure B&W
- **Box shadows**: `card` (1px blur, subtle) and `popover` (three-layer, pronounced) — Geist dark-theme shadow values
- Palette stays Tokyo Night dark / Hakusho light DNA — colors refined (deeper ink `#0b0b14`, paper white `#fafaf6`) but recognizable
- Full design system spec: `docs/02-UI-DESIGN.md`, palette documented in `docs/01-PRODUCT.md` §2.7

### v4.6 — Light/Dark Auto-Switch (completed, 2026-06-26)
- **Hakusho (白書) light mode**: warm paper-tone palette via `prefers-color-scheme: light`
- CSS custom properties (19 semantic tokens: `--bg-main`, `--text-body`, `--text-link`…) — one stylesheet, two palettes
- Manual theme toggle: `[data-theme]` CSS selectors (specificity 0-2-0) + `phpman.js` localStorage (`phpman-theme-v2`)
- **FTS5 colon query fix** (#192): strip leading/trailing colons from search terms
- **SQLite lock retry** (#193): 3→8 retries with exponential backoff + random jitter
- **PATH_INFO guard hoist** (#181): strlen check before explode() for DoS protection
- See `docs/02-UI-DESIGN.md` for full palette and design rationale

### v4.1 — Tooling & Security Hardening (current, 2026-06-17)

**batch_enhance.php lifecycle**:
- `--pid-file` + `--stop`: PID-based process management, safe kill via SIGTERM→SIGKILL
- `--status`: per-mode emoji enhancement progress with counts, percentages, recent entries
- `--rebuild` / `-r`: force re-enhance even if emoji cache exists
- `--parameter=<name1;name2>` + `--section=<s>`: single/multi-page enhancement (supersedes `cli/batch-enhance.php`)
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
- `cli/` moved out of webroot on staging/production
- install.sh `--webroot` auto-generates `MCP_API_KEY` (random 32-char hex)
- README restructured: install.sh first, MCP agent config moved lower
- Removed `cli/batch-enhance.php` — superseded by `batch_enhance.php --parameter`

**Cleanup**:
- Removed all `maxLen` input truncation — LLM models handle full pages natively
- Dead constant `PHPMAN_FLAG_DESC_MAX_LEN` removed
- `formatMarkdownToHTML()` regex `#{1,4}` → `#{2,5}` (skips h1, matches h2-h6)
- Makefile auto-creates `~/.phpman/phpman.config.php` → webroot symlink on deploy
- All 10 stale worktree branches purged from GitHub
- 7 open issues closed: #128, #133–#141

### v4.2 — UX Polish & Prompt Tuning (2026-06-18)

**Code block copy button**:
- External JS `phpman.js` (loaded via `<script src="phpman.js">` in `showFooter()`) wraps all `#content-wrap pre` in `<div class="code-block">` with `📋 Copy` button top-right
- Click copies code text to clipboard, shows `✓ Copied!` feedback (1.5s)
- Tokyo Night styling: `#1f2335` background, `italic` font, `#292e42` border, `border-radius:4px`
- Button visible on hover (desktop), always visible (mobile)

**Prompt v2 optimization**:
- Removed "Add a Quick Reference table" / "Exit codes section" — only if already in original
- Forbid `<a>` links inside `<pre><code>` blocks (breaks copy functionality)
- Forbid emoji as list/bullet markers (🔹🔸▪️) — use standard `<ul><li>` with emoji in text
- Strengthened structure preservation: PRIMARY goal is keeping original heading hierarchy
- Output size control: `PHPMAN_ENHANCE_MAX_CHARS` (default 32,000) in prompt instruction
- Removed all input truncation — LLM sees full documents

**TOC fix**:
- Enhanced TOC regex changed from `#<(h2|h3)>(.+?)</\1>#i` to `#<(h2|h3)\b[^>]*>(.+?)</\1>#i`
- Now matches LLM-generated headings with `id="..."` attributes

**Makefile version sync**:
- `PHPMAN_VERSION` auto-replaced from `git describe --tags --abbrev=0` during deploy
- New `make tag VERSION=4.2.0` convenience target

**New config override**: `PHPMAN_ENHANCE_MAX_CHARS` (default 32,000)

---

### v4.3 — OKF Markdown & PHP_BASE_URL Hardening (2026-06-18 → 2026-06-20)

**v4.3.0 — Open Knowledge Format for enhanced Markdown**:
- Switched enhanced Markdown output to OKF markup conventions:
  - `@H2@` / `@H3@` section markers for unambiguous heading parsing
  - `@PRE_START@` / `@PRE_END@` code block boundaries
  - `@LINK_START@` / `@LINK_END@` link wrappers, `@BOLD@` emphasis
- Prompt updated to forbid `<pre><code>` nesting in Quick Ref sections (use `<code>` only)
- `cleanEmojiHtml()` updated to handle OKF-structured enhanced content
- Fix: deploy `src/` alongside `cli/` in Makefile staging/release targets

**v4.3.1 — Cache version tracking + nested HTML fix**:
- `CACHE_FORMAT_VERSION` stamp on every cache entry for format migration safety
- Fixed nested HTML in enhanced output (double-wrapped `<div>` / `<code>` blocks)
- Fixed broken `localhost` links in enhanced MD — converted to relative paths
- `showStatus()` merged `--stats` into `--status` with per-mode sample URLs

**v4.3.2–v4.3.7 — URL hardening chain**:
- v4.3.2: Merged `--stats` into `--status` with random enhanced sample URLs
- v4.3.3: Replaced CLI-local filesystem paths in enhanced HTML with web URLs (`scriptName()`)
- v4.3.4: Switched `PHPMAN_BASE_URL` from `getenv()` to `define()` constant (deploy-time injection)
- v4.3.5: Extended CLI link fix to also match relative `phpMan.php` paths
- v4.3.6: Made `scriptName()` use `PHPMAN_BASE_URL` globally (not `SCRIPT_NAME`), fixing all `baseUrl()` call sites
- v4.3.7: Fixed table CSS overflow + prompt tuning (`<code>` not `<pre><code>` in Quick Ref)

**Ongoing (working tree, uncommitted)**:
- Removed inline `?build-index` web handler — index rebuild is now CLI-only via `php cli/build-index.php`
- Markdown output format purity: `getSearchPage()` returns pure Markdown list items (`- `) instead of `<ul>`/`<li>` HTML wrappers
- Added `## apropos` subheading in Markdown format search results
- `--status` sample labeling: now shows `Enhanced samples (N/T pages, emoji_html → default view)`
- CLAUDE.md: documented "Output format purity" design rule

---

### v4.4 — Code Split: Phase 4 Architecture (design)

**Goal**: Split the ~5650-line `phpMan.php` monolith into focused source files
while preserving a single-file web entry point. Minimize web output: only
`phpMan.php` + `phpman.css` in the webroot.

**Principles**:
1. **Single entry point preserved** — `phpMan.php` stays in webroot, thin
   dispatcher (~80 lines): config load → bootstrap require → dispatch
2. **All logic in PHPMAN_HOME** — `~/.phpman/src/` outside webroot
3. **No Composer, no autoloader** — manual `require_once` via `bootstrap.php`
4. **Backward compatible** — same URLs, same output, same test mode
   (`define('PHPMAN_TEST_MODE', true)` before require)
5. **Each file ~150–400 lines**, single responsibility
6. **PHPMAN_NO_CLI_DISPATCH** still works — CLI tools require `bootstrap.php`
   directly

**Target file tree**:

```
repo/                               # Git repository root
├── phpMan.php                      # Thin dispatcher (753 lines) — only PHP file in webroot
├── phpman.css                      # Stylesheet
│
├── src/                            # 22 source files (5080 lines total, loaded by bootstrap.php)
│   ├── bootstrap.php               # require all src files in dependency order
│   ├── config.php                  # PHPMAN_* default constants (defined() guard)
│   ├── util.php                    # h(), serverValue(), baseUrl(), scriptName(),
│   │                               #   getSafeHost(), isLocalRequest(), requestValue(),
│   │                               #   normalizeMode(), normalizeParameter(), normalizeSection()
│   ├── log.php                     # phpManLog()
│   ├── cache.php                   # cacheDb(), PageCache class, Profiler, cacheOrExecute()
│   ├── search_index.php            # rebuildSearchIndex(), expandNameForFts(),
│   │                               #   buildFtsQuery(), indexAproposLines(), parseApropos*()
│   ├── format_common.php           # cleanTerminalOutput(), detectHeadingType() +
│   │                               #   all detectL1/L2 helpers + extractFlagsFromSections()
│   ├── format_html.php             # formatManPerlDoc(), renderTocSidebar(), addManPageToc()
│   ├── format_markdown.php         # formatManPerlDocToMarkdown(), showCopyright()
│   ├── format_json.php             # formatToJSON(), parseFlagJSON()
│   ├── format_mcp.php              # formatForOutput(), formatMcpMarkdown(),
│   │                               #   formatMcpStructured(), formatSearchResults()
│   ├── source_man.php              # getManPage(), getManIndex()
│   ├── source_perldoc.php          # getPerldocPage(), getPerldocIndex()
│   ├── source_info.php             # getInfoPage(), getInfoIndex()
│   ├── source_pydoc.php            # getPydocPage(), getPydocIndex(), getPydocSearchPage()
│   ├── source_ri.php               # getRiPage(), getRiIndex(), getRiSearchPage()
│   ├── source_search.php           # getSearchPage(), renderGroupedResults()
│   ├── enhance.php                 # enhanceManPage(), callLLM(), cleanEmojiHtml(),
│   │                               #   getMdEnhancePrompt(), getHtmlEnhancePrompt()
│   ├── tldr.php                    # fetchOfficialTldr() + all TLDR parsers/formatters
│   ├── mcp_server.php              # handleMcp(), handleWellKnown() + 8 MCP helpers
│   ├── web_header.php              # showHeader() — HTTP headers, SEO meta, CSS
│   └── web_footer.php              # showFooter(), showForm() — footer HTML, profiling display
│
├── cli/                            # CLI tools (deployed to PHPMAN_HOME alongside src/)
│   ├── _bootstrap.php              # Shared bootstrap: PHP_SAPI guard + PHPMAN_HOME resolve
│   │                               #   + phpman.config.php load + require phpMan.php
│   ├── build-index.php             # php cli/build-index.php [--cron]
│   └── batch-enhance.php           # php cli/batch-enhance.php [mode:names] [--status|...]
│                                   #   Shorthand: php cli/batch-enhance.php man:ls,tar
│
├── test/                           # Test suite (require phpMan.php → all src/ loaded)
│   ├── run_all.php                 # All 296 tests entry point
│   ├── test_helper.php             # assert_equals/contains/match/not_*()
│   ├── unit/                       # 8 unit test files
│   ├── integration/                # 5 integration test files
│   └── e2e/                        # 4 E2E test files (require network)
│
├── docs/                           # Design documentation
├── Makefile                        # CI/CD pipeline
├── .deploy.mk.example              # SSH config template (not committed)
├── install.sh                      # One-line installer
└── phpman.config.php.example       # User config template

Deployed:
  webroot:   phpMan.php  phpman.css  phpman.config.php
  PHPMAN_HOME:  src/  cli/  db/  logs/  (phpman.config.php symlink)
```

**Entry point (`phpMan.php`, 753 lines)**:

```php
<?php
// GPL header + RE_ASCII + version constants (lines 1-36)
// ...

// Load site-specific config before defaults (define() guard pattern)
$_config_file = __DIR__ . "/phpman.config.php";
if (file_exists($_config_file)) { require $_config_file; }
unset($_config_file);

// Load all source files (config defaults + functions + classes)
// Resolve src/: dev (next to phpMan.php) or deployed (PHPMAN_HOME/src/)
$srcDir = is_dir(__DIR__ . '/src') ? __DIR__ . '/src' : PHPMAN_HOME . '/src';
require $srcDir . '/config.php';
require $srcDir . '/bootstrap.php';

// Test mode: load functions only, skip dispatch
if (defined("PHPMAN_TEST_MODE")) { return; }

// CLI tools define PHPMAN_NO_CLI_DISPATCH to skip web dispatch
if (defined("PHPMAN_NO_CLI_DISPATCH")) return;

// === Web dispatch (inline procedural code) ===
// Format negotiation → PATH_INFO routing → switch($mode) → output
```

**Dependency order** (`src/bootstrap.php`, 31 lines):

```php
<?php
$srcDir = __DIR__;

require $srcDir . '/config.php';         // 0: constants (defined() guards)
require $srcDir . '/util.php';           // 1: h(), baseUrl(), scriptName(), etc.
require $srcDir . '/log.php';            // 1: phpManLog()
require $srcDir . '/cache.php';          // 1: cacheDb(), PageCache, Profiler
require $srcDir . '/search_index.php';   // 2: FTS5 indexing, apropos parsing
require $srcDir . '/format_common.php';  // 2: cleanTerminalOutput(), detectHeadingType()
require $srcDir . '/format_html.php';    // 3: formatManPerlDoc(), renderTocSidebar()
require $srcDir . '/format_markdown.php';// 3: formatManPerlDocToMarkdown(), showCopyright()
require $srcDir . '/format_json.php';    // 3: formatToJSON(), parseFlagJSON()
require $srcDir . '/format_mcp.php';     // 3: formatForOutput(), formatMcp*()
require $srcDir . '/source_man.php';     // 4: getManPage(), getManIndex()
require $srcDir . '/source_perldoc.php'; // 4: getPerldocPage()
require $srcDir . '/source_info.php';    // 4: getInfoPage()
require $srcDir . '/source_pydoc.php';   // 4: getPydocPage()
require $srcDir . '/source_ri.php';      // 4: getRiPage()
require $srcDir . '/source_search.php';  // 4: getSearchPage()
require $srcDir . '/enhance.php';        // 5: enhanceManPage(), callLLM()
require $srcDir . '/tldr.php';           // 5: fetchOfficialTldr()
require $srcDir . '/mcp_server.php';     // 6: handleMcp(), handleWellKnown()
require $srcDir . '/web_header.php';     // 7: showHeader()
require $srcDir . '/web_footer.php';     // 7: showFooter(), showForm()

$PHPMAN_TITLE = PHPMAN_HOME_TITLE;
$TOC_ITEMS = array();
```

**Shared CLI bootstrap** (`cli/_bootstrap.php`, 28 lines):

```php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(400); die("CLI only\n"); }

$config_file = __DIR__ . '/../phpman.config.php';
if (file_exists($config_file)) { require $config_file; }

if (!defined('PHPMAN_HOME') || PHPMAN_HOME === '') { /* resolve HOME */ }

define('PHPMAN_NO_CLI_DISPATCH', true);
require_once PHPMAN_HOME . '/phpMan.php';
```

**Note**: The web dispatch code (~700 lines) remains inline in `phpMan.php`.
It is NOT a separate `web_router.php` file — keeping it inline means the
entry point is self-contained for the web-accessible path, while all
shared logic (functions, classes) lives in `src/`. The dispatch code
calls functions loaded by bootstrap but is not itself required by anything.

**Key design decisions**:

- **Not a class hierarchy** — functions remain functions. Each "class" is a
  file. This keeps the code grep-friendly and avoids OOP tax in a procedural
  codebase that has function-scoped caching and shared state via constants.
- **`PageCache` stays a class** — already well-encapsulated, no change needed.
- **Tests unchanged** — `define('PHPMAN_TEST_MODE', true)` before requiring
  bootstrap.php loads all functions without running the web dispatch. Every
  test file replaces `require 'phpMan.php'` with `require PHPMAN_HOME .
  '/src/bootstrap.php'`.
- **Config overridables unchanged** — `defined()` guard pattern in `config.php`.
- **Deploy unchanged** — Makefile `sed` for PHPMAN_VERSION + `scp` phpMan.php
  + phpman.css to webroot, `scp -r cli tools src` to PHPMAN_HOME.
- **Single-file constraint met** — webroot has 1 PHP file (phpMan.php).
  All logic is outside webroot, unreachable via HTTP.

### v4.10 — Superseded by External `site-stats` Project

The original v4.10 design (GA as a phpMan MCP tool) was rejected on
2026-07-14 in favor of decoupling. Site analytics is a multi-site
concern that doesn't belong in a documentation server.

**What changed**: GA analytics is now a **standalone project** (`site-stats`),
not a phpMan feature. phpMan remains a pure doc server; site-stats serves
any website (chedong.com/blog, chedong.com/phpMan.php, myblog, future
sites) with categorized reports (栏目) over MCP + HTTP.

**phpMan-side status**: zero changes. No new config keys, no new
dependencies, no `src/` files. The original v4.10 spec (JWT, cache,
config keys, files to add) is preserved in git history and superseded
by the standalone design at `docs/06-ANALYTICS.md`.

**Why not just put it in phpMan**:
- phpMan is a doc server, not an analytics service
- Multi-site: analytics serves blog, phpman, myblog, future sites
- Tech mismatch: GA4 SDK + MCP tooling are Python-first
- Independent scaling, failure domain, release cadence

See `## External Projects` below for the full design of `site-stats`.


## External Projects (Out of Scope for phpMan)

Two capabilities were extracted from phpMan into standalone projects
on 2026-07-14. They are listed here for reference only — they are
**not** part of the phpMan codebase, do not ship in `make release`,
and have their own repositories / deployments.

### doc-enhance — LLM-based doc enhancement

- **Purpose**: Apply LLM transforms (emoji, OKF, custom prompts) to
  any markdown content (phpMan output, MT-imported posts, etc.)
- **Why external**: LLM work is dominated by task management, prompt
  versioning, and flow orchestration — concerns that don't belong
  in a doc server. Same prompts serve phpMan, myblog, and future
  projects; sharing via a standalone project is the right shape.
- **What it replaces** (formerly in phpMan):
  - `enhanceManPage()`, `callLLM()`, `cleanEmojiHtml()`
  - `formatMarkdownToHTML()`, `formatInlineMarkdown()`
  - `renderTocSidebar()` (the LLM-enhanced HTML TOC)
  - `emoji_html` / `emoji_md` SQLite cache fields
  - LLM config: `LLM_API_KEY`, `LLM_API_URL`, `LLM_MODEL`,
    `LLM_MAX_TOKENS`, `PHPMAN_ENHANCE_MAX_CHARS`
  - `cli/batch-enhance.php` (moved as-is)
  - `--enhance` flag (formerly `phpMan.php --enhance=...`)
- **Interface**: CLI + HTTP API + (optionally) MCP; reads source
  markdown via HTTP, writes enhanced output to its own cache store
- **Tech stack**: Python (LLM client maturity, MCP tooling maturity)
- **Status**: design → implementation (in progress, 2026-07)

### site-stats — Standalone site analytics

- **Purpose**: Read GA4 data for any number of websites and expose
  categorized reports (traffic, content, users, sources, releases,
  anomalies, search) over MCP + HTTP
- **Why external**: Analytics is a multi-site concern; phpMan is one
  of many sites. Embedding GA in phpMan forces rebuilding the same
  thing 5 times; a standalone service serves them all.
- **Interface**: MCP tools (`site_list`, `site_report`, `site_compare`,
  `site_release_impact`) + HTTP/JSON API
- **Tech stack**: Python (GA4 SDK, MCP SDK maturity)
- **phpMan integration**: optional 5-line HTTP fetch for a
  "popular man pages this week" widget; service is otherwise
  invisible to phpMan
- **Status**: design (`docs/06-ANALYTICS.md`), not yet implemented

### Why External, Not Optional Modules

| Concern | External project | Optional module in phpMan |
|---|---|---|
| Deployment | Independent, own release cadence | Tied to phpMan release |
| Failure domain | Outage doesn't affect doc lookups | phpMan degraded |
| Multi-consumer | Shared across blog/phpMan/myblog | Duplicated per consumer |
| Tech stack | Right tool (Python) for the job | Forced into PHP |
| Operators | Independent team / repo | Same maintainer as phpMan |

## Migration Plan: phpMan → `llm_enhance` + `site_stats`

> **Date:** 2026-07-14
> **Trigger:** `docs/01-PRODUCT.md` §2.12 — LLM features moved out
> **Outcome:** phpMan becomes pure doc server; two new standalone repos

### 0. Overview

```
phpMan (this repo, simplified)
  ├── man / perldoc / info / pydoc / ri → raw HTML / MD / JSON / MCP
  ├── SQLite cache (storage only)
  ├── FTS5 search
  ├── MCP server (cli_help, cli_search)
  └── ZERO LLM calls

          │                    │
          │ exports MD         │ analytics
          │ (filesystem/HTTP)  │ (MCP + HTTP)
          ▼                    ▼

llm_enhance (new repo, Python)        site_stats (new repo, Python)
  ├── Source: myblog MT (MySQL)        ├── GA4 Data API (multi-property)
  ├── Source: phpMan (SQLite cache)    ├── 7 report categories
  ├── Source: filesystem (generic)     ├── MCP tools
  ├── Transforms: emoji, OKF, etc.     ├── HTTP/JSON API
  ├── Sink: filesystem / HTTP / SQLite └── Standalone service
  └── Task manager: queue, resume,
      rate limit, cost tracking
```

**Key design decision** — `llm_enhance` is **source-agnostic**:
- One set of LLM transforms serves phpMan, myblog, and future projects
- Each project provides a `Source` implementation (read entries) + `Sink` (write results)
- A single `llm_enhance run --config=foo.yaml` invocation handles any project

### 1. `llm_enhance` Repository

#### 1.1 Layout

```
llm_enhance/                              (new repo, Python 3.11+)
├── README.md
├── pyproject.toml                        (PEP 621, no setup.py)
├── LICENSE
├── src/llm_enhance/
│   ├── __init__.py
│   ├── cli.py                            # `llm_enhance run`, `list-sources`, etc.
│   ├── config.py                         # YAML config loader
│   ├── source/
│   │   ├── base.py                       # Source ABC
│   │   ├── mysql_export.py               # myblog MT → MD files
│   │   ├── phpman_cache.py               # phpMan SQLite cache → MD files
│   │   ├── filesystem.py                 # generic directory of MD files
│   │   └── http_api.py                   # fetch from HTTP endpoint
│   ├── transform/
│   │   ├── base.py                       # Transform ABC
│   │   ├── emoji.py                      # emoji enrichment
│   │   ├── okf.py                        # OKF markdown format
│   │   ├── translation.py                # language translation
│   │   └── chain.py                      # compose multiple transforms
│   ├── sink/
│   │   ├── base.py                       # Sink ABC
│   │   ├── filesystem.py                 # write to disk
│   │   ├── sqlite.py                     # write to SQLite (for phpMan compat)
│   │   └── http_api.py                   # POST to another service
│   ├── llm/
│   │   ├── client.py                     # multi-provider LLM client
│   │   ├── providers/
│   │   │   ├── openai_compat.py          # OpenAI-compatible (covers most gateways)
│   │   │   ├── anthropic.py
│   │   │   └── base.py
│   │   └── tokenizer.py                  # token counting for cost estimates
│   ├── prompts/
│   │   ├── loader.py                     # versioned prompt loader
│   │   ├── emoji_v1.txt
│   │   ├── emoji_v2.txt
│   │   ├── okf_v1.txt
│   │   └── ...
│   ├── task/
│   │   ├── manager.py                    # queue, resume, retry
│   │   ├── store.py                      # SQLite-backed state
│   │   ├── rate_limit.py
│   │   └── cost_tracker.py
│   ├── log.py
│   └── version.py
├── configs/
│   ├── myblog.yaml
│   ├── phpman.yaml
│   └── examples/
│       └── minimal.yaml
├── tests/
│   ├── unit/
│   └── integration/
└── docs/
    ├── DESIGN.md                         # moved from phpMan docs
    ├── PROMPT-GUIDE.md                   # how to write/version prompts
    └── CONFIG.md                         # config schema reference
```

#### 1.2 The Source Abstraction (central design)

```python
# src/llm_enhance/source/base.py
class Entry:
    """One document to enhance."""
    id: str                          # stable identifier (entry_id, cache key, file path)
    title: str
    content: str                     # raw markdown
    metadata: dict                   # source-specific (author, date, tags, ...)

class Source(ABC):
    """Reads entries from a project. Project-agnostic."""

    @abstractmethod
    def iter_entries(self, *, since: datetime | None = None,
                     limit: int | None = None) -> Iterator[Entry]:
        """Yield entries to process."""

    @abstractmethod
    def get_entry(self, entry_id: str) -> Entry:
        """Fetch one entry by id (for retry)."""

    @abstractmethod
    def total_count(self) -> int:
        """Total entries (for progress display)."""

    def name(self) -> str:
        return self.__class__.__name__
```

#### 1.3 Concrete Sources

**`MysqlExportSource`** — for myblog MT migration

```python
# Reads MT (Movable Type) entries from MySQL, exports to markdown files
class MysqlExportSource(Source):
    def __init__(self, conn_str: str, query: str, output_dir: Path,
                 field_map: dict, batch_size: int = 100):
        # conn_str:    "mysql://user:pass@host/mt_db"
        # query:       SELECT entry_id, entry_title, ... FROM mt_entry
        # field_map:   {"title": "entry_title", "text": "entry_text", ...}
        # output_dir:  where to write .md files (e.g. ~/data/mt-markdown/raw/)
        ...

    def iter_entries(self, *, since=None, limit=None) -> Iterator[Entry]:
        # 1. Stream rows from MySQL with keyset pagination
        # 2. Convert each row to Entry:
        #    - id = "mt-{entry_id}"
        #    - title = row["entry_title"]
        #    - content = self._convert_to_markdown(row)
        #       (handles MT-specific: text + text_more concat, BBCode, ...)
        #    - metadata = {author, date, category, tags}
        # 3. Optionally write raw MD to output_dir (one-time export)
        # 4. Yield Entry
```

**`PhpmanCacheSource`** — for phpMan (replaces `cli/batch-enhance.php`)

```python
# Reads phpMan's SQLite cache table, exports to markdown files
class PhpmanCacheSource(Source):
    def __init__(self, sqlite_path: Path, modes: list[str],
                 min_cache_age_hours: int = 24,
                 output_dir: Path | None = None):
        # sqlite_path:    ~/.phpman/cache/db.sqlite
        # modes:          ["man", "perldoc", "info", "pydoc", "ri"]
        # min_cache_age:  skip entries cached < N hours ago (in-progress)
        # output_dir:     if set, write .md files here (one-time export)
        ...

    def iter_entries(self, *, since=None, limit=None) -> Iterator[Entry]:
        # 1. SELECT name, mode, section, content, updated_at
        #    FROM cache
        #    WHERE mode IN (...) AND content_format = 'markdown'
        #    AND updated_at < datetime('now', '-N hours')
        # 2. Convert each row to Entry:
        #    - id = f"{mode}:{name}:{section}"
        #    - title = name
        #    - content = row["content"]   (already markdown)
        #    - metadata = {mode, section, updated_at}
        # 3. Optionally write raw MD to output_dir
        # 4. Yield Entry
```

**`FilesystemSource`** — generic, for any directory of MD files

```python
class FilesystemSource(Source):
    def __init__(self, base_dir: Path, glob: str = "**/*.md",
                 id_from: str = "path_relative"):
        ...

    def iter_entries(self, *, since=None, limit=None) -> Iterator[Entry]:
        # Walk base_dir, parse front-matter, yield Entry
```

#### 1.4 The Transform Abstraction

```python
# src/llm_enhance/transform/base.py
class Transform(ABC):
    """One LLM-driven or rule-based transformation step."""

    @abstractmethod
    def name(self) -> str: ...         # "emoji", "okf", "translate_zh"

    @abstractmethod
    def version(self) -> str: ...      # "v2", "v1.3"

    @abstractmethod
    def apply(self, entry: Entry, llm: LLMClient) -> Entry:
        """Return a new Entry with enhanced content."""

    def cache_key(self) -> str:
        return f"{self.name()}@{self.version()}"
```

Versioning rule: bumping `version()` invalidates all cached results for this transform. Cache is tagged with `(entry_id, transform_name, transform_version)`.

#### 1.5 The Sink Abstraction

```python
# src/llm_enhance/sink/base.py
class Sink(ABC):
    @abstractmethod
    def write(self, entry: Entry, transform_results: list[Entry]) -> None:
        """Persist the enhanced entry."""

    @abstractmethod
    def is_done(self, entry_id: str, transform: Transform) -> bool:
        """Has this (entry, transform) been written? Used for resume."""
```

Built-ins:
- `FilesystemSink(base_dir, extension=".enhanced.md")` — default for both myblog and phpMan
- `SqliteSink(path, table)` — for phpMan compat (writes back to `cache` table with new column)
- `HttpApiSink(url, headers)` — POST to another service

#### 1.6 The Task Manager

```python
# src/llm_enhance/task/manager.py
class TaskManager:
    """SQLite-backed state for resumable, rate-limited, cost-tracked runs."""

    def __init__(self, db_path: Path, config: Config):
        # state:  done / pending / failed / rate_limited
        # cost:   tokens_in, tokens_out, usd_estimate (per run, per day)
        # rate:   min_interval_seconds between LLM calls
        ...

    def should_skip(self, entry_id: str, transform: Transform) -> bool:
        """Skip if (entry, transform) already done in a previous run."""

    def record_done(self, entry_id: str, transform: Transform,
                    result: Entry, cost: CostRecord) -> None: ...

    def record_failed(self, entry_id: str, transform: Transform,
                      error: Exception, retry_after: datetime) -> None: ...

    def next_entry_to_process(self) -> Entry | None: ...
```

CLI flags (subcommand of `llm_enhance`):

```bash
llm_enhance run --config=phpman.yaml
    --dry-run                 # show what would be processed, no LLM calls
    --limit=N                 # only process N entries
    --mode=man,perldoc        # filter by source mode
    --cached-first            # process recently cached entries first
    --skip-errors             # continue past failures (default: on)
    --force                   # re-process even if done
    --pid-file=/tmp/llm.pid   # write PID for lifecycle management
    --stop                    # send SIGTERM to PID file
    --status                  # show progress + cost so far
    --yes                     # skip confirmation prompt
```

#### 1.7 Config Examples

**`configs/myblog.yaml`** — MT export + emoji enhancement

```yaml
source:
  type: mysql_export
  connection: mysql://mt_user:secret@localhost/mt_production
  query: |
    SELECT entry_id, entry_title, entry_text, entry_text_more,
           entry_author, entry_created_on, entry_basename
    FROM mt_entry
    WHERE entry_status = 2          -- published only
    ORDER BY entry_id
  field_map:
    id: entry_id
    title: entry_title
    text: entry_text
    text_more: entry_text_more
  output_dir: ~/data/mt-markdown/raw/    # one-time raw export
  batch_size: 200

transforms:
  - type: emoji
    version: v2
    model: deepseek-v4-pro
    max_output_chars: 32000
  - type: okf
    version: v1
    model: deepseek-v4-pro

sink:
  type: filesystem
  base_dir: ~/data/mt-markdown/enhanced/
  layout: "{year}/{month}/{id}.enhanced.md"
  front_matter:
    include: [id, title, author, date, source]
    extra:
      llm_enhance_version: "1.0"
      transform_chain: ["emoji@v2", "okf@v1"]

rate_limit:
  min_interval_seconds: 120
  max_tokens_per_day: 500000
  max_cost_per_day_usd: 5.00

llm:
  provider: openai_compat
  base_url: https://taotoken.net/api/v1
  api_key_env: LLM_API_KEY
  timeout_seconds: 300
```

**`configs/phpman.yaml`** — SQLite cache read + emoji enhancement

```yaml
source:
  type: phpman_cache
  sqlite_path: ~/.phpman/cache/db.sqlite
  modes: [man, perldoc, info, pydoc, ri]
  min_cache_age_hours: 24          # skip entries in active cache
  output_dir: ~/.phpman/enhanced-raw/    # one-time raw export

transforms:
  - type: emoji
    version: v2
    model: deepseek-v4-pro
    max_output_chars: 32000

sink:
  type: filesystem
  base_dir: ~/.phpman/enhanced/
  layout: "{mode}/{name}.enhanced.md"

rate_limit:
  min_interval_seconds: 120
  max_cost_per_day_usd: 5.00

llm:
  provider: openai_compat
  base_url: https://taotoken.net/api/v1
  api_key_env: LLM_API_KEY
  timeout_seconds: 300
```

### 2. `site_stats` Repository

#### 2.1 Layout

```
site_stats/                              (new repo, Python 3.11+)
├── README.md
├── pyproject.toml
├── LICENSE
├── src/site_stats/
│   ├── __init__.py
│   ├── server.py                       # FastAPI app
│   ├── mcp_server.py                   # MCP tool registration
│   ├── data_sources/
│   │   ├── base.py                     # DataSource ABC
│   │   ├── ga4.py                      # GA4 implementation
│   │   └── (future: plausible, matomo)
│   ├── reports/
│   │   ├── base.py
│   │   ├── traffic.py
│   │   ├── content.py
│   │   ├── users.py
│   │   ├── sources.py
│   │   ├── releases.py
│   │   ├── anomalies.py
│   │   └── search.py
│   ├── auth/
│   │   ├── ga_jwt.py                   # pure-PHP-equivalent JWT
│   │   └── token_cache.py
│   ├── cache/
│   │   └── filesystem.py
│   └── config.py
├── config/
│   └── sites.yaml
├── tests/
└── deploy/
    └── systemd/
        └── site-stats.service
```

#### 2.2 What Moves to `site_stats` From phpMan

| Item | Source | Destination |
|---|---|---|
| GA4 design doc | `docs/06-ANALYTICS.md` | `site_stats/docs/DESIGN.md` (content unchanged) |
| GA4 config keys | (only in docs, never added to phpman.config.php) | `site_stats/config/sites.yaml` |
| Service account key handling | (was designed but not built) | `site_stats/src/auth/ga_jwt.py` |
| No existing code | — | full greenfield implementation |

phpMan itself contributes **zero code** to `site_stats`. Only the design doc moves. The service is built from scratch in Python.

#### 2.3 phpMan Optional Integration (5 lines)

phpMan may fetch a "popular man pages" widget from `site_stats`:

```php
// In phpMan's index renderer
$stats = @file_get_contents(
    "https://stats.example.com/api/v1/sites/chedong-phpman/reports/content"
    . "?startDate=7daysAgo&endDate=today"
    . "&metrics=screenPageViews&dimensions=pagePath&limit=10"
);
if ($stats !== false) {
    foreach (json_decode($stats, true)['rows'] ?? [] as $row) {
        renderPopularPage($row['pagePath'], $row['screenPageViews']);
    }
}
```

This is the **only** phpMan code that knows `site_stats` exists. If `site_stats` is removed, phpMan keeps working.

### 3. Migration Steps (concrete, in order)

#### Phase A: Create the repos (week 1)

1. `git init llm_enhance && cd llm_enhance` — set up pyproject.toml, src layout
2. `git init site_stats && cd site_stats` — set up pyproject.toml, src layout
3. Move `docs/06-ANALYTICS.md` content → `site_stats/docs/DESIGN.md` (verbatim)
4. Stub both repos: empty `Source`, `Transform`, `Sink`, `DataSource`, `Report` ABCs + tests
5. Publish both to GitHub (public or private, TBD)

#### Phase B: Port code from phpMan to `llm_enhance` (week 2)

For each phpMan function, port in this order:

| From phpMan | To `llm_enhance` | Notes |
|---|---|---|
| `enhanceManPage()` | `transform/emoji.py:EmojiTransform.apply()` | Split HTML-direct + MD-paths into two transforms |
| `callLLM()` | `llm/client.py:LLMClient.call()` | Multi-provider, retry, truncation detection |
| `cleanEmojiHtml()` | `transform/emoji.py:post_process()` | Same rules (strip DOCTYPE, allowlist tags) |
| `formatMarkdownToHTML()` | (delete) | Was only for `emoji_md` path; not needed |
| `formatInlineMarkdown()` | (delete) | Same |
| `renderTocSidebar()` | (delete) | Was LLM-output specific; not needed |
| `cli/batch-enhance.php` | `cli.py:run_command()` | Full port: queue, resume, rate limit, status |
| LLM config keys (5) | `llm_enhance/configs/*.yaml` | Per-project config files |

#### Phase C: Implement `MysqlExportSource` for myblog (week 2-3)

1. Inspect `mt_entry` schema on the myblog MySQL host
2. Write field_map for MT 4.x/5.x schema (entry_text, entry_text_more, entry_title, etc.)
3. Implement MT-text → markdown conversion (handle BBCode-ish markup if present)
4. Test: export 10 entries, verify .md output
5. Test full chain: `llm_enhance run --config=myblog.yaml --limit=10 --dry-run`

#### Phase D: Implement `PhpmanCacheSource` (week 3)

1. Inspect phpMan's `cache` table schema (mode, command, section, content, format, updated_at)
2. Filter by `format='markdown'` to use raw MD, not HTML
3. Add `min_cache_age_hours` filter to skip in-progress entries
4. Test: `llm_enhance run --config=phpman.yaml --mode=man --limit=5 --dry-run`

#### Phase E: Build `site_stats` (week 3-4, parallel with D)

1. FastAPI skeleton + `/sites` endpoint
2. GA4 JWT auth + `runReport()` client
3. Implement 7 report categories
4. MCP tool registration
5. Multi-property config (`config/sites.yaml`)
6. Filesystem cache (10-min TTL)
7. Deploy: systemd unit, behind nginx

#### Phase F: Cleanup phpMan (week 4)

1. Delete from `phpMan.php`:
   - `enhanceManPage()`, `callLLM()`, `cleanEmojiHtml()`
   - `formatMarkdownToHTML()`, `formatInlineMarkdown()`
   - `renderTocSidebar()`
2. Delete from `phpman.config.php.example`:
   - `LLM_API_KEY`, `LLM_API_URL`, `LLM_MODEL`, `LLM_MAX_TOKENS`, `PHPMAN_ENHANCE_MAX_CHARS`
3. Delete from `cli/`:
   - `cli/batch-enhance.php`
4. Schema migration (one-time): drop `emoji_html`, `emoji_md` columns from `cache` table
5. Update `docs/01-PRODUCT.md` §2.12 wording (remove the historical LLM feature list)
6. Update `docs/02-UI-DESIGN.md` to drop emoji-related theme tokens (if any)
7. Update tests:
   - Remove `test/unit/test_enhance.php`
   - Update `test/unit/test_path_guard.php` (no impact)
8. Verify: `php test/run_all.php` passes 323/323 (was 349; -26 = LLM-related tests removed)
9. Tag release: `git tag v5.0.0` (major bump: removed features)

#### Phase G: Decommission & migrate running jobs (week 5)

1. Stop any running `nohup php cli/batch-enhance.php ...` processes
2. Verify `~/.phpman/cache/emoji_*` is unused after Phase F
3. Re-enhancement after migration: optional, can re-run `llm_enhance run --config=phpman.yaml` to regenerate
4. Archive `cli/batch-enhance.php` to `archive/cli-batch-enhance-2026.php` for reference (don't ship)

### 4. Validation Checklist (post-migration)

| Check | Pass criteria |
|---|---|
| `phpMan.php` request path | 0 LLM imports, 0 LLM function calls, 0 LLM config keys |
| `phpMan.php` line count | significantly reduced (target: -300 to -500 lines) |
| `test/run_all.php` | all pass, 0 LLM-related tests |
| `phpMan.php` GitHub README | LLM section removed; points to `llm_enhance` and `site_stats` |
| `llm_enhance` dry-run on myblog | iterates 10 entries, shows 10 LLM calls planned, $cost estimate |
| `llm_enhance` dry-run on phpMan | iterates 10 entries from cache table, shows 10 calls |
| `site_stats /sites` | lists chedong-blog, chedong-phpman, myblog-markdown |
| `site_stats /reports/traffic` | returns valid GA4 data for 7daysAgo→today |
| `phpMan` index page | optional popular-pages widget renders (or silently skipped) |
| Production deploy | phpMan deploy unchanged; `llm_enhance` and `site_stats` deploy separately |

### 5. Rollback Plan

If `llm_enhance` proves unworkable in the new shape:

- All original phpMan LLM code is in git history (v4.0..v4.9) — `git revert v5.0.0..HEAD` restores it
- The `llm_enhance` repo can be deleted; `site_stats` is independent and unaffected
- phpMan's `cli/batch-enhance.php` and `enhanceManPage()` come back exactly as they were

No data loss: the original `cache.emoji_*` columns can be restored from backup if needed.

### 6. Open Questions

- [ ] **License**: AGPLv3 (matching phpMan) or MIT (more permissive for adoption)?
- [ ] **Hosting**: GitHub public, or private? (Public enables community contribution to prompts.)
- [ ] **LLM provider default**: which provider should `llm_enhance` ship with as default? (Currently myblog/phpMan use taotoken.net → deepseek-v4-pro; new users may want Anthropic.)
- [ ] **Sink for phpMan**: keep `SqliteSink` for back-compat, or force `FilesystemSink`? (Filesystem is simpler but loses the inline-cache benefit.)
- [ ] **Resume format**: SQLite (current phpMan design) or JSONL on disk? (SQLite has better resume semantics; JSONL is git-friendly.)
- [ ] **GA4 → Plausible fallback**: when do we cut over? (When GA4 cost/privacy changes make it worth it.)

### 7. Schedule (tentative)

| Week | Milestone |
|---|---|
| W1 (2026-07-14 → 07-20) | Phase A: repos created, ABCs + tests |
| W2 (07-21 → 07-27) | Phase B: port code from phpMan |
| W2-3 (07-21 → 08-03) | Phase C: MysqlExportSource for myblog |
| W3 (07-28 → 08-03) | Phase D: PhpmanCacheSource |
| W3-4 (07-28 → 08-10) | Phase E: site_stats MVP |
| W4 (08-04 → 08-10) | Phase F: phpMan cleanup, tag v5.0.0 |
| W5 (08-11 → 08-17) | Phase G: decommission + re-enhance optional |

Total: ~5 weeks from today to a clean v5.0.0 of phpMan + 2 new working repos.

### Configuration & Deployment Architecture

Three config layers, loaded in order — earlier layers define constants first,
later layers respect `defined()` guards:

```
┌── phpman.config.php      ← user-edited, lives in webroot
│   define('PHPMAN_HOME', '/home/user/.phpman');
│   define('LLM_API_KEY', 'sk-...');
│
├── src/config.php         ← defaults (not user-edited), in PHPMAN_HOME/src/
│   if (!defined('PHPMAN_WIDTH'))  define('PHPMAN_WIDTH', 100);
│   if (!defined('PHPMAN_HOME'))   define('PHPMAN_HOME', '~/.phpman');
│
├── .deploy.mk             ← maintainer SSH config (never deployed)
│   TEST_HOST = chedong@staging.example.com
│   DEMO_HOST = chedong@chedong.com
│
└── Makefile tag           ← tags only (PHPMAN_VERSION is placeholder, no source edit)
```

**Loading order on every request**:

```
phpMan.php (webroot, ~50 lines)
│
├─1. require phpman.config.php     ← user overrides (PHPMAN_HOME, LLM keys)
│
├─2. require bootstrap.php
│   └── require src/config.php     ← fills in remaining defaults (defined() guards)
│   └── require src/util.php       ← h(), baseUrl(), scriptName()
│   └── require src/log.php
│   └── require src/cache.php      ← cacheDb(): auto-creates DB + tables if missing
│   └── ... all other src/ files ...
│
├─3. if (PHPMAN_NO_CLI_DISPATCH) return;   ← CLI tools exit here
├─4. if (PHPMAN_TEST_MODE) return;         ← tests exit here
│
└─5. require web_router.php        ← dispatch switch($mode)
```

**Why two config files?** `phpman.config.php` is user-facing — one file to edit for
LLM keys, debug mode, custom paths. `src/config.php` is internal — provides defaults
for everything the user didn't override. They never conflict because of the
`defined()` guard pattern.

#### Installation Flow

```
User runs:  curl ... | bash                        (install.sh)

1. git clone → ~/.phpman/                          ← full repo (includes src/)
2. php cli/build-index.php                          ← initial FTS5 index build
3. generate_config ~/.phpman/phpman.config.php      ← config with PHPMAN_HOME
4. if --webroot /var/www/html:
     cp ~/.phpman/phpMan.php   → /var/www/html/    ← single-file dispatcher
     cp ~/.phpman/phpman.css   → /var/www/html/
     generate_config /var/www/html/phpman.config.php ← webroot config + MCP_API_KEY

Result:
  webroot:  phpMan.php  phpman.css  phpman.config.php
  ~/.phpman: src/ cli/ db/ logs/  phpman.config.php
```

#### Update Flow

```
Maintainer: make tag VERSION=4.4.0                 (local)
  1. git tag -a v4.4.0 -m "v4.4.0"                  ← annotated tag (no source edit)
  2. git push origin master v4.4.0                    ← push + tag

Maintainer: make release                            (deploy to prod)
  1. make test                                       ← syntax check
  2. sed PHPMAN_HOME + GIT_DESCRIBE + PHPMAN_VERSION ← replace placeholders
  3. scp phpMan.php + phpman.css → webroot
  4. scp -r cli/ src/ → PHPMAN_HOME
  5. make logcheck                                   ← tail error logs

Maintainer: make release-reindex                    (deploy + rebuild index)
  Same as release, then:
  ssh ... "cd ~/.phpman && php cli/build-index.php --cron"

User:      install.sh --update                      (self-update)
  1. cd ~/.phpman && git pull --ff-only
  2. php cli/build-index.php                         ← reindex after code update
```

#### Offline Initialization (first request after fresh install)

```
Browser:  GET /phpMan.php/man/ls

phpMan.php:
  1. require phpman.config.php          → PHPMAN_HOME = '/home/user/.phpman'
  2. require bootstrap.php              → loads all functions
  3. require web_router.php             → dispatch

web_router.php:
  4. normalizeMode('man')               → 'man'
  5. normalizeParameter('ls')          → 'ls'
  6. call getManPage('ls', '', 'html')

getManPage() (src/source_man.php):
  7. PageCache::get('man','ls','','html')  → null (no cache yet)
  8. cacheDb() auto-creates:
     - PHPMAN_CACHE_DIR directory
     - phpman_cache.db SQLite file
     - cache, tldr_cache, search_fts, search_index_meta, meta tables
  9. exec('man ls 2>/dev/null')        ← fork system command
  10. formatManPerlDoc($rawLines)      ← overstrike → HTML
  11. PageCache::set(...)               ← cache for next request
  12. return HTML

No manual init needed. cacheDb() lazily bootstraps everything on first use.
The only offline step: cli/build-index.php (populates FTS5 search index).
```

#### First Deploy Directory Layout

Two deployment paths. Both result in the same runtime structure:

```
# Path A: install.sh (user self-install)
git clone → ~/.phpman/                    # repo = PHPMAN_HOME
~/.phpman/src/        ← from repo
~/.phpman/cli/        ← from repo
~/.phpman/db/         ← install.sh: mkdir -p
~/.phpman/logs/       ← install.sh: mkdir -p
~/.phpman/backups/    ← install.sh: mkdir -p

  --webroot /var/www/html:
    /var/www/html/phpMan.php          ← cp from ~/.phpman
    /var/www/html/phpman.css          ← cp from ~/.phpman
    /var/www/html/phpman.config.php   ← generated from .example

# Path B: Makefile (maintainer deploy)
scp phpMan.php + phpman.css → webroot
scp -r src/ cli/ → PHPMAN_HOME
db/ logs/ backups/           ← auto-created by cacheDb() on first request
phpman.config.php            ← created by Makefile from .example on first deploy
```

**Directories and what creates them:**

| Directory | Path A (install.sh) | Path B (Makefile) |
|---|---|---|
| `src/` | git clone | `scp -r src/` |
| `cli/` | git clone | `scp -r cli/` |
| `db/` | `mkdir -p` | cacheDb() auto-create |
| `logs/` | `mkdir -p` | cacheDb() auto-create |
| `backups/` | `mkdir -p` | `make release` (first backup) |

**webroot contains only 3 files** (minimal attack surface):
```
/var/www/html/
├── phpMan.php           # Thin dispatcher — only PHP file served by HTTP
├── phpman.css           # Stylesheet
└── phpman.config.php    # User config (define() overrides)
```

#### Config Minimization

Config is in **one file**: `phpman.config.php` (in webroot, symlinked to PHPMAN_HOME).
Defaults are in `src/config.php` — user config only needs to override what differs.

**Zero config** — works for local dev:
```bash
php -S localhost:45678 phpMan.php
# → http://localhost:45678/phpMan.php
# All defaults: PHPMAN_HOME=~/.phpman, no LLM, no MCP auth
```

**Minimal production** (2 defines):
```php
define('PHPMAN_HOME', '/home/user/.phpman');
define('PHPMAN_BASE_URL', 'https://www.example.com/phpMan.php');
```

**Add emoji enhancement** (+3 defines):
```php
define('LLM_API_KEY', 'sk-xxx');
define('LLM_API_URL', 'https://api.openai.com/v1/chat/completions');
define('LLM_MODEL', 'gpt-4o-mini');
```

**Add MCP authentication** (+1 define):
```php
define('MCP_API_KEY', 'your-secret-key-here');
```

**Add Google Analytics** (+1 define):
```php
define('PHPMAN_GA_ID', 'G-XXXXXXXXXX');
```

**install.sh config generation**: copies `phpman.config.php.example` → uncomments
`PHPMAN_HOME` with detected home path. If `--webroot` flag is passed, also generates
a random 32-char `MCP_API_KEY`. All other settings stay commented — user uncomments
as needed. Single source of truth: `.example` file defines the canonical config format.

**Config overridable constants** (all use `defined()` guard in `src/config.php`):

| Constant | Default | Required? |
|---|---|---|
| `PHPMAN_HOME` | `~/.phpman` | Only for non-standard home |
| `PHPMAN_BASE_URL` | auto-detect | Production (for correct CLI links) |
| `PHPMAN_WIDTH` | 100 | No |
| `PHPMAN_TOC_THRESHOLD` | 80 | No |
| `PHPMAN_TLDR_MAX_EXAMPLES` | 16 | No |
| `PHPMAN_ENHANCE_MAX_CHARS` | 32000 | No |
| `LLM_API_KEY` | `''` | For emoji enhancement |
| `LLM_API_URL` | `''` | For emoji enhancement |
| `LLM_MODEL` | `''` | For emoji enhancement |
| `LLM_MAX_TOKENS` | 4096 | No |
| `MCP_API_KEY` | `''` | For MCP auth |
| `PHPMAN_DEBUG` | false | No |
| `PHPMAN_HOME_TITLE` | `'phpman - Linux...'` | No |
| `PHPMAN_PROJECT_NAME` | `'phpman'` | No |

**.deploy.mk role**: maintainer-only SSH config (never committed, never deployed).
Provides server addresses, paths, log locations to Makefile:

```
.deploy.mk          →    Makefile           →    Target server
────────────────────────────────────────────────────────────────
TEST_HOST           →    scp -P TEST_PORT   →    $TEST_PATH/phpMan.php
DEMO_HOST           →    scp -P DEMO_PORT   →    $DEMO_PATH/phpMan.php
DEMO_ERROR_LOG      →    ssh ... tail       →    post-release logcheck
```

No `.deploy.mk` = Makefile exits with error. Users who deploy via `install.sh`
never touch this file.

**Migration plan**:
1. Create `src/` directory structure
2. Move functions file by file, verifying tests after each move
3. Write `bootstrap.php` with require order
4. Replace `phpMan.php` body with thin dispatcher
5. Update Makefile to deploy `src/` alongside `cli/`
6. Regression: `make test` + `test/phpman-regression.sh`

**Risk mitigation**:
- Each extraction is a pure move (no refactoring during split)
- Tests gate every step
- Rollback: keep existing monolithic `phpMan.php` as `phpMan.php.mono` until
  validation complete

---
