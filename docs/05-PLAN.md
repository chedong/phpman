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
- `cli/batch-enhance.php`: single-page CLI tool for shared hosts where man(1) can't fork
- `cli/batch-enhance.php`: offline batch enhancement — auto-discovers ~35K entries from search_index_meta + cache, 2-min rate limiting, resilient resume, `--cached-first` sort, idempotent per-entry cache writes (2026-06-17)
- `DELETE FROM cache` now preserves emoji_md/emoji_html during reindex (2026-06-17)
- See `docs/01-PRODUCT.md` §2.11 for full design rationale

**Phase 4: Code split** (planned)
- `src/Source/` + `src/Formatter/` + `src/Cache/` + `src/Config/`
- Single-file entry point preserved

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
└── Makefile tag           ← writes PHPMAN_VERSION into phpMan.php before git tag
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
  1. sed 's/PHPMAN_VERSION.*/4.4.0/' phpMan.php     ← write version into file
  2. git commit -m "v4.4.0: bump PHPMAN_VERSION"     ← commit (repo always current)
  3. git tag -a v4.4.0 -m "v4.4.0"                  ← annotated tag
  4. git push origin master v4.4.0                    ← push commit + tag

Maintainer: make release                            (deploy to prod)
  1. make test                                       ← syntax check
  2. sed GIT_DESCRIBE + PHPMAN_VERSION in phpMan.php ← stamp exact version
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
