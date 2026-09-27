# Changelog

All notable changes to phpMan are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- **`PHPMAN_ADSENSE_ID` in `phpman.config.php.example`** — the constant was real since v4.9.26 but missing from the example config, so there was nothing to uncomment.

### Changed
- **Docs synced to the code** — `PHPMAN_ADSENSE_ID` documented in `01-PRODUCT.md` (loader-only, needs Auto Ads, don't set on staging); the stale LLM-era config recipe removed from `05-PLAN.md`, which also had `phpman.config.php` living in the webroot — it lives in `~/.phpman/`, outside the webroot, which is where the secrets are.
- **`.codex/` gitignored** — agent local state no longer shows up in `git status`.

### Removed
- **`mcp` is no longer a public output format** — `/{mode}/{param}/mcp`, `/search/{query}/mcp` and `?format=mcp` fall back to HTML now; use `POST /mcp` for the MCP envelope. It was never MCP: parameters came from the URL path, GET was allowed, and it bypassed the JSON-RPC endpoint's API-key check, 64KB body cap and POST-only guard, while being served with `Expires: +7d`. The format string itself stays — `handleMcp()` renders through `getManPage(..., "mcp")` — it is simply no longer reachable from a URL. **Breaking** for callers that used the suffix.

### Fixed
- **Title format (#226)** — `<title>` is now `Name - description - mode(section) - [phpMan]`; the mode/section segment is dropped when there is no mode.
- **perldoc title description** — resolves again; a perldoc page's `name` lives in the `3perl`/`3pm` sections of `search_fts`, which the lookup did not cover.
- **GA4 beacon CSP** — `doubleclick` and `ga-audiences` added to `connect-src`; GA4 measurement beacons were being blocked.
- **config-check sed escaping** — the deploy-time config check mangled values containing sed metacharacters.
- **`cli_help` for a command with no page returns an empty result, not an error** — the man → pydoc → ri fallback cascade ended by returning `""`, which `handleMcpToolsCall()` could not decode and reported as `-32603 "Internal error: invalid MCP output"`. An agent could not tell a mistyped command from a broken server. The call now succeeds with an empty envelope (`summary: null`, `sections: []`) — the machine-readable "no page for this name here" signal.
- **Unknown tool and missing parameter answer `-32602`, not `-32603`** — a `tools/call` naming a tool that does not exist, or `cli_help`/`cli_search` without their required argument, fell into the generic `catch (Throwable)` and came back as `-32603 "Internal error"` with the detail discarded. These are the caller's fault, so they now raise `McpInvalidParams` and answer `-32602 "Invalid params: Unknown tool: X"` / `"Missing required parameter: command"`, naming what to fix.
- **`test/TEST_MCP.md` assertions ran against the wrong field** — they parsed `result.content[0].text` as JSON, but that field is a markdown rendering; the structured payload is the sibling `result.structuredContent`. Every assertion now reads `structuredContent`, the error codes are documented, and `test/e2e/test_agent_scenarios.php` gained `structuredContent` assertions plus A11 (nonexistent command → empty result). Both MCP suites take `PHPMAN_TEST_MCP_KEY` now that `POST /mcp` is fail-closed.
- **Deploy-time config check no longer flags `PHPMAN_BASE_URL`** — it matched `define('KEY'` with single quotes only, and both live configs write that one with double quotes, so every deploy printed a false "not in your phpman.config.php". The match is quote-agnostic now, so the warning means what it says. (A09 in `test_agent_scenarios.php` also skips its 304 assertion on a `PHPMAN_DEBUG` target, where per-request profiling timings make the ETag change by design.)
- **CLI tools run from a non-default home resolved `PHPMAN_HOME` to `~/.phpman`** — `cli/_bootstrap.php` fell back to `$HOME/.phpman` whenever the config did not define the constant, so `cd ~/.phpman_test && php cli/build-index.php` — which is what `make reindex-staging` and `make staging-reindex` run — rebuilt **production's** index and left staging's stale. `PHPMAN_HOME` is now the directory holding the `phpman.config.php` that was loaded, which is what every deployment is (`cd <home> && php cli/...`); the `$HOME/.phpman` fallback stays for when there is no config at all. The web path was never affected — `phpMan.php` gets `PHPMAN_HOME` substituted at deploy (`src/config.php:81`).

### Security
- **MCP API key check is now fail-closed** — `handleMcp()` used to skip authentication entirely when `MCP_API_KEY` was empty (`if (MCP_API_KEY !== '')`), so a missing or emptied `phpman.config.php` silently published `POST /mcp` to the world instead of denying. An unset key now rejects every request with 401 — the shape `phpMan.php`'s `status` endpoint already used. Both comparisons (`handleMcp()` and `status`) also use `hash_equals()` rather than `!==`, so they no longer leak the key's length and matching prefix through timing. **Breaking** for a deployment that intentionally ran MCP unauthenticated: set `MCP_API_KEY` in `~/.phpman/phpman.config.php`.

## [4.11.1] — 2026-09-26

### Removed
- **Orphaned LLM scaffolding** — leftover LLM hooks in `src/search_index.php`, the dead `PHPMAN_ENHANCE_*` constants, and a no-op write to a by-value parameter. No behavior change.

### Changed
- **`pageCachePath()` is the single source of the cache-shard naming rule** — `cli/build-sitemap.php` now reads `PHPMAN_CONTENT_MODES` instead of duplicating the mode list.

### Fixed
- **Docs no longer claim the LLM layer "moved to the doc-enhance project"** — no such repository exists; it was deleted in v4.10.0. The batch-enhance CLI reference and the LLM config keys are gone.

## [4.11.0] — 2026-09-24

### Added
- **Unified cache TTL** — `PageCache` found-entries and the TLDR cache expire on the same schedule (default 7 months) instead of drifting apart.
- **JSON/MCP payload limits** — the envelope exposes them; `formatMcpStructured()` picks fields from a whitelist, so it had to be told about the new limits explicitly. Derived fields are computed in a single pass when wrapping lines.
- **Cache-busting for CSS/JS** — asset URLs carry a version query string.
- **Tailscale mesh tooling** — migrated into the chedong.com repo; skips cross-tailnet shared nodes during discovery, reports each node's exit-node usage, includes the executing node in the measurements, supports local status / config-collected nodes / no-arg help, and uses Mb/s consistently.

### Changed
- **Page cache sharded per mode** — eliminates SQLite write-lock contention between search and page rendering.
- **Markdown formatter fast path** — `baseUrl` and the closures hoisted out of the loop, regex passes guarded with `strpos()`.
- **MCP search description** improved.

### Fixed
- **Large pages no longer route through an IR string for MCP/JSON** — `info` pages were exceeding the memory limit.
- **FTS5 `and`/`not` in names** — a page named `and` or `not` was parsed as an operator (`no such column`); such terms are now escaped.
- **`?debug=1` no longer re-serializes the whole response** just to append the `_profiling` key.
- **Indexed `url` field points at a usable address** — `/man/json` is a page query, not an endpoint.
- **Stale `info` dir-menu entries** filtered by real `.info` files.
- **`showForm()` with markdown/json URLs** — the not-found fallback link was broken.

## [4.10.0] — 2026-08-08

### Removed
- **LLM enhancement** — `batch-enhance`, `callLLM()`, and `enhanceManPage()` are gone, along with the enhancement cache. phpMan no longer makes outbound LLM API calls.

### Added
- **`tailscale-mesh-bench.sh`** — iperf3 bandwidth benchmark for the Tailscale mesh, with `--help` / `-h`.

### Fixed
- **Emoji cache entries never expired** — the 7-day TTL caused mass data loss.
- **`PageCache::set()` no longer overwrites valid content** with an empty `not_found` entry.

## [4.9.26] — 2026-07-24

### Added
- **Google AdSense support** — `PHPMAN_ADSENSE_ID` emits the `adsbygoogle.js` loader from `showFooter()` and widens the CSP automatically. Loader only: ads appear only when Auto Ads is enabled for the site.
- **`log-analyze.sh`** — web access log analysis tool.
- **Runtime process status** in `--status` output.
- **Compressed sitemap** generated during reindex.

### Changed
- **`--rate-limit` is configurable**, default lowered 120→60s (v4.9.21).
- **Tool detection at deploy**, plus a `--cache-only` mode (v4.9.20).
- **Form layout** — single-row desktop layout (search + modes inline), compact mobile form, dark-mode field contrast (v4.9.15–19).
- **Code review cleanup** — dead code removal, abort fix, WAL safety, ETag logging, `X-Forwarded-Proto` (v4.9.17, v4.9.22).
- **`--status` man count** — WAL checkpoint before reading, so it no longer reports 0 (v4.9.13).

### Fixed
- **`GIT_DESCRIBE` guard in `showFooter()`**.
- **URL attack guard** — malformed paths with protocol-prefix traps are rejected.
- **403 guard** — valid Perl modules containing multiple `::` are no longer rejected.
- **PHP `default_socket_timeout`** no longer preempts `CURLOPT_TIMEOUT`.
- **Deploy ordering** — `src/` is deployed before `phpMan.php` to avoid transient 500s mid-release.
- **Man batch abort rate** — `maxConsecutiveFailures` raised 3→10.
- **FTS5 duplicate entries** — `DELETE FROM search_fts` instead of `DROP` + `CREATE`/`RENAME` (v4.9.25).
- **`rebuildSearchIndex` cache cleanup** — deletes `WHERE mode='search'` rather than `format='search'`, and no longer wipes all HTML page caches (v4.9.23–24).
- **Undefined `GIT_DESCRIBE` in `tldr.php:85`** — crashed the CLI batch.

## [4.9.0] — 2026-06-27

### Changed
- **`PHPMAN_VERSION` / `GIT_DESCRIBE` use a `__PLACEHOLDER__` pattern** — a deploy never dirties the local file, and the version comes from the global latest tag rather than the branch-local one.
- **`RE_ASCII`, `RE_ASCII_SAFE`, `GIT_DESCRIBE` defined in the CLI bootstrap** so CLI scripts can use them.
- **Schema migration simplified** — `_old` table logic removed, format tabs moved.
- **Design pass** — +2px font scale, format tabs, mobile search polish.
- **`v4.8.2`** — theme toggle moved to bottom-right, "back to top" shortened.
- **`v4.8.1`** — search heading is dynamic: `man` (FTS5) or `apropos` (fallback).
- **Dead code deduped**, key masking and a shell function simplified.

### Fixed
- **FTS5 search** — useless `LEFT JOIN` removed, `DISTINCT` added, `LIMIT` 300→500.

## [4.8] — 2026-06-26

### Changed
- **Calibrated Terminal CSS** — Geist-inspired token system; version bumped 5.0 → 4.8.

### Fixed
- **`PHPMAN_HOME` placeholder** now resolves to `~/.phpman` for local dev.

## [4.7] — 2026-06-26

### Changed
- **Deploys use `rsync` instead of `scp`**.
- **All deploy-time constants use placeholders** — a deploy never dirties the local file.
- **`--status` man page total** now read from `search_index_meta`.
- **Docs updated for v4.7** — placeholder constants, rsync, theme toggle, mobile screenshot, v4.5+ config architecture.

### Removed
- **Stale `tools/` cleanup** from the deploy targets.

## [4.6.0] — 2026-06-26

### Added
- **Hakusho (白書) light mode** — 20 CSS custom properties with `@media (prefers-color-scheme: light)` auto-switch. Zero JS. Tokyo Night dark defaults, warm paper-tone light palette. Manual toggle via `[data-theme]` selectors + `phpman.js` with localStorage persistence (`phpman-theme-v2` key).
- **Theme toggle button** — `#theme-toggle` (fixed top-left, ☀/☾ icon) in `phpman.js`, with CSS transition on `body` for smooth palette swap.

### Fixed
- **PATH_INFO guard hoist (#181)** — `strlen()` check moved before `explode()` to prevent memory DoS on oversized PATH_INFO.
- **FTS5 colon query error (#192)** — `buildFtsQuery()` now strips leading/trailing colons from search terms. Searching for `SQL:` no longer triggers `no such column: SQL`.
- **SQLite lock retries (#193)** — cache write retries increased 3→8 with exponential backoff + random jitter (~12s max wait vs ~0.9s).
- **LLM_MAX_TOKENS default (#182)** — reduced from 409600 (exceeds all provider limits) to 8192.
- **CSS token names aligned with design docs (#183)** — 19 semantic custom properties (`--bg-main`, `--text-body`, `--text-link`, `--btn-text`…) replace abbreviated names.
- **CLI config fallback (#185)** — `cli/_bootstrap.php` now searches `$HOME/.phpman/phpman.config.php` when project-root config is absent.
- **batch-enhance status config dump (#188)** — `LLM_API_KEY` added to displayed keys (masked); dead masking code removed.
- **GA4 script XHTML compliance (#187)** — `async` replaced with `type="text/javascript"` (tradeoff documented).

### Changed
- **CSS refactored** — all hardcoded hex colors replaced with semantic custom properties in `:root`, one `@media` block for Hakusho, `[data-theme]` overrides with higher specificity (0-2-0).
- **Search index rebuilt** — staging + production reindexed (13,835 entries, 0 errors).

## [3.6.1] — 2026-06-08

### Changed
- **getSearchPage() single FTS5 query covers man/pydoc/ri** — removed `AND section NOT IN ('pydoc','ri')` filter; results routed by section into `$lines` (man), `$pydocFtsLines` (pydoc), `$riFtsLines` (ri)
- Search cascade uses FTS5 results as baseline, only falls back to command-line `pydoc3 -k` / `ri` when FTS5 had no hits for that source
- Removed `searchFtsBySource()` calls from cascade — no longer needed since FTS5 query already covers all sources
- Updated `docs/SEARCH_FTS5_DESIGN.md` to v4: single-query architecture, two-level search flow

## [3.6] — 2026-06-08

### Added
- **FTS5 index now includes pydoc3 and ri entries** — `rebuildSearchIndex()` indexes `pydoc3 modules` (section='pydoc') and `ri -l` (section='ri') alongside man pages
- **`expandNameForFts()` case-insensitive matching** — appends lowercase + dot/colon expansion: `JSON::Ext::Parser` also matches `json`, `parser`, `ext parser`
- **`searchFtsBySource()`** — queries FTS5 for pydoc/ri entries by section (retained as utility function)

### Changed
- Search mode always aggregates apropos + pydoc3 + ri results (no longer only cascades when apropos is empty)
- `getSearchPage()` FTS5 availability check uses all sources (`SELECT COUNT(*) FROM search_index_meta`) not just `source='man'`

### Fixed
- `getRiSearchPage()` now filters `.xxx not found` responses from `ri` command
- Updated test expectations for expanded `expandNameForFts()` output

## [3.5] — 2026-06-06

### Added
- **Standalone rebuild-index.php** — cron-based FTS5 index maintenance with positional dir argument, `--help`, and auto-clear search cache

### Changed
- Cache directories renamed to `phpman_cache/{staging,production}`
- Documentation uses `/path/to` style instead of `/home/your-user`
- `TEST_USER`/`DEMO_USER` merged into single `HOST` as `user@host`

### Fixed
- FTS5 duplicate entries deduplicated via `search_index_meta` before INSERT
- `rebuild-index.php` no-arg now shows help; cron uses full `php` path
- Absolute path for `phpman.css` to work on nested URLs
- CSS extracted to `phpman.css` with try/catch PRAGMA WAL

## [3.4] — 2026-06-05

### Fixed
- SQLite busy timeout moved before PRAGMA exec to prevent "database is locked"
- Removed mobile alpha sidebar overrides — same 30px sidebar for all viewports

## [3.3] — 2026-06-04

### Added
- **Alphabet index sidebar** for search/index pages with >80 results, extended to pydoc/ri index pages
- Search page caching with `hits++` UPDATE removed on cache reads

### Fixed
- Alpha sidebar embedded in cached HTML to survive `cacheOrExecute`
- Mobile alpha sidebar: column layout with larger touch targets, body-consistent sizing
- `#` (symbols) moved to front of alphabet sidebar to match page order

### Changed
- Font sizes normalized to 12px and 14px only (except H1)

## [3.2] — 2026-06-03

### Added
- **Alphabet index sidebar** for search/index pages with >80 results

### Fixed
- Empty TLDR block suppressed when examples have no command text

## [3.1] — 2026-06-03

### Added
- **FTS5 full-text search engine** with profiler and search cascade optimization

### Fixed
- MCP format link hidden on 404/search fallback pages

## [3.0] — 2026-06-02

### Added
- **SQLite cache engine** — persistent disk cache with TTL, negative cache, and WAL mode
- **phpman.config.php** — external configuration file (WordPress wp-config style)
- `SECURITY.md` with vulnerability reporting policy
- Git version tag in footer and deploy pipeline
- Collapsible mobile TOC: narrow screen default collapsed, title row clickable toggle

### Changed
- Config switched to `define()` pattern
- TLDR scoped to man section 1 only, 404 log spam removed
- Standalone `/tldr` route removed — TLDR integrated across all 4 formats
- Security boundary update: rate limit/gzip/headers are server-layer duty
- No root-path files (robots.txt/sitemap/llms.txt) — phpMan may not be at root
- `favicon.png` → `favicon.ico` with correct MIME type

### Fixed
- Code review fixes (#82-#88): pydoc index list format, design doc updates
- `isLocalRequest()` deprecated — HSTS/version to Nginx, debug to env var
- H1 breadcrumb + title format, JSON-LD fix
- MCP error masking

### Added
- **pydoc3 (Python 3) documentation mode** — `/pydoc/{module}/{format}` with HTML/Markdown/JSON/MCP output
- **ri (Ruby) documentation mode** — `/ri/{Class#method}/{format}` with HTML/Markdown/JSON/MCP output
- **Search cascade** — `apropos` → `pydoc3 -k` → `ri` search in all formats (HTML, Markdown, JSON, MCP)
- **pydoc module index** — `pydoc3 modules` parsed and rendered in all 4 formats
- **ri class index** — `ri -l` listing rendered in all 4 formats
- **Auto-detection in MCP cli_help** — dotted names (`json.loads`) → pydoc, `#` suffix (`Array#map`) → ri, `::` → perldoc
- **pydoc class/function heading detection** — indented `class Name(Parent)` and `funcName(args)` as L2 subsections
- **ri RDoc heading detection** — `= Section` and `== Subsection` markers exclusive to ri mode
- **Mode-specific link patterns** — parent class links in pydoc, `::` constant links in ri
- **Not found fallback links** — Python Docs search for pydoc, Ruby-Doc search for ri
- **TOC label cleaning** — strip RDoc `=` / `==` prefixes from TOC entries

### Changed
- MCP tool description updated to mention pydoc3 and ri
- Search radio button order: pydoc and ri placed after info

### Fixed
- Man page regex `[\dnol]\w*` → `(\d\w*|n)` to avoid false matches with pydoc/ri parameter names
- "Not found locally" message not showing for pydoc/ri detail pages
- `PHPMAN_WIDTH` converted from variable to `define()` constant (shared by man + perldoc)

## [2.5] — 2026-06-02

### Fixed
- pydoc index list format: `<ul><li>` instead of `<pre><a><br/>`

## [2.4.1] — 2026-06-02

### Changed
- pydoc index: use `<ul><li>` list format instead of `<pre><a><br/>`

## [2.4] — 2026-06-02

### Added
- Cache design v3.0 with real metrics, PHP 7.2+ floor
- Git version tag in footer and deploy pipeline
- Collapsible mobile TOC

### Changed
- `isLocalRequest()` deprecated — HSTS/version to Nginx, debug to env var
- Security boundary update: rate limit/gzip/headers are server-layer duty
- No root-path files (robots.txt/sitemap/llms.txt)
- `favicon.png` → `favicon.ico`

### Fixed
- H1 breadcrumb + title format
- JSON-LD fix
- MCP error masking

## [2.3] — 2026-06-01

### Added
- **pydoc3 (Python 3) documentation mode** — `/pydoc/{module}/{format}`
- **ri (Ruby) documentation mode** — `/ri/{Class#method}/{format}`
- **Search cascade** — `apropos` → `pydoc3 -k` → `ri`
- **pydoc module index** — `pydoc3 modules` parsed
- **ri class index** — `ri -l` listing
- **Auto-detection in MCP cli_help** — dotted names → pydoc, `#` suffix → ri, `::` → perldoc
- **pydoc class/function heading detection**
- **ri RDoc heading detection**
- **Mode-specific link patterns**
- **Not found fallback links**
- **TOC label cleaning**

### Changed
- MCP tool description updated
- Search radio button order

### Fixed
- Man page regex fix
- "Not found locally" message for pydoc/ri
- `PHPMAN_WIDTH` → `define()` constant

## [2.2] — 2026-06-02

### Added
- **Official tldr-pages embedding** — TLDR cheatsheets from [tldr-pages/tldr](https://github.com/tldr-pages/tldr) GitHub raw, embedded at top of man/perldoc detail pages in all 4 output formats (HTML, JSON, Markdown, MCP)
- **cheat.sh fallback** — commands not covered by tldr-pages fallback to [cheat.sh](https://cheat.sh) plain-text API, with automatic parsing
- **TLDR source attribution** — `tldr-pages` or `cheat.sh` source label shown in HTML TLDR block header, JSON `tldr.source` field, MCP `tldr_source` field, and Markdown `*Source:*` line
- **Clickable TLDR header** — TLDR block title links to full page on tldr.inbrowser.app (official) or cheat.sh
- **Bracket-to-bold conversion** — tldr-pages `[x]` shortcut notation rendered as `<b>x</b>` in HTML TLDR examples

### Changed
- TLDR endpoint: official tldr-pages → cheat.sh → LLM → extraction, zero-config by default
- Fallback link on man detail pages: Linux Command Library → cheat.sh
- Removed "TLDR Docs" nav link (TLDR content now embedded inline)
- Repository migrated from SourceForge to GitHub ([chedong/phpman](https://github.com/chedong/phpman))
- Roadmap reorganized into v2.2 / v3.0 version milestones (PLAN.md, CACHE_DESIGN.md)
- Removed `index.html` (SF static site now deployed independently)

### Security
- `role="search"` removed from `<form>` — invalid in XHTML 1.0 Transitional

## [2.1] — 2026-05-31

### Added
- **Cross-platform width control** — `MANWIDTH` fallback for BSD/macOS, `pod2text -w N` for perldoc
- BSD/macOS fallback when `-Tutf8` is unsupported
- TLDR endpoint (`/tldr?page=...`)
- Project roadmap (`PLAN.md`) with priority matrix

### Changed
- Deploy config split into staging (`make deploy`) and production (`make release`)
- Static `.well-known/` directory removed — MCP discovery is fully dynamic

## [2.0] — 2026-05-29

### Added
- **MCP (Model Context Protocol) server mode** — `/mcp` REST endpoint + JSON-RPC POST
- **Semantic JSON output** — `command`, `summary`, `flags` (flag/long/arg), `examples`, `see_also` with URLs
- TLDR, Cheat, Translate footer links on detail pages
- SKILL.md for AI agent integration
- `.well-known/mcp.json` auto-discovery + deploy system
- Regression test script for external validation

### Changed
- Test suite restructured with 4-level architecture (209 tests)
- TOC sidebar: enforced 80-line threshold, all test cases audited
- Directory renamed `doc/` → `docs/`

### Fixed
- ParseFlagJSON: trailing comma on short flags, standalone arg placeholders
- ALL CAPS sections (SEE ALSO, etc.) incorrectly detected as L2 headings
- Man page header/footer lines filtered from section detection
- Overstrike patterns breaking UTF-8 in man pages with Unicode characters
- Footer links for non-section-1 man pages

### Performance
- **gzip + ETag** for JSON/MCP output (bash manpage 351KB → 97KB gzipped, repeat requests 304 in 2ms)

### Security
- `PHP_SELF` XSS fix — use `h(scriptName())` instead of raw `$_SERVER['PHP_SELF']`
- `getSafeHost()` prevents Host header injection on canonical URL / Schema.org / validator links

## [2.0-rc] — 2026-05-28

### Added
- MCP server mode (first implementation)
- Deployment Makefile with SourceForge FRS upload
- JSON format link added to footer
- SYNOPSIS extracted as top-level `synopsis` field in JSON output
- Mobile responsive CSS — viewport meta, touch-friendly form, `pre-wrap`, P0–P2 improvements

### Changed
- REST `/mcp` format unified with POST `/mcp` JSON-RPC output
- Unified title/h1 format: `{page} - {mode} - phpMan`
- TOC sidebar renamed from "Sections" to "TOC", width doubled (160→320px)
- README translated to English, added man/info/perldoc mode comparison

### Fixed
- Markdown heading level differentiation: `##` for L1, `###` for L2
- Man page `.SH` and `.SS` headings detected for `##`/`###` markers
- Perldoc sub-section headings correctly rendered as `###`
- Bold-formatted man `.SH` headings detected as L1 in TOC
- Man `.TP` tagged paragraphs detected as L2 for config variables
- JSON section detection unified with HTML/Markdown via `detectHeadingType()`
- TOC sidebar shown when 1 L1 section has L2 subsections
- TOC sidebar shown on short man pages; indent false positives prevented
- `formatToJSON()` failure returns `'{}'` instead of `false`
- `section=1` no longer forced when no section specified
- `-Tascii` removed so `MANWIDTH` env var controls line length
- `_^H` overstrike rendering — correctly maps to `<u>`, restored full `<b>` pattern
- SGR regex patterns handle modern man-db output (`ESC[22m`)
- `GROFF_NO_SGR=1` restored for `<b>`/`<u>` tag extraction
- XHTML: 224 duplicate `id` errors fixed
- UTF-8 encoding and W3C validation errors fixed
- Accessibility improved — labels, contrast, `lang` attribute
- Correct SourceForge thumbnail URL (750×400)
- `MANWIDTH=128` preserved in output with overflow scroll

### Security
- Server version hidden (local-only), perldoc index param bug fixed
- Cache reduced 30d → 7d, format whitelist enforced
- `stripslashes` removed, `substr_count` used for safer parsing
- Source and phpinfo entry links removed

### Removed
- Translate link removed from footer
- Internal implementation comment removed from HTML header

---

## 2026 Modern Rewrite

The modern phpMan was rebuilt from scratch starting 2026-05-22 as a single-file PHP application,
replacing the original multi-file CGI-era codebase from 2002. Initial features included:

- Two-level TOC sidebar based on indentation
- Section anchors and floating TOC
- Back-to-top CSS button (no JavaScript required)
- `MANWIDTH=128` with horizontal overflow scroll
- Overstrike (`_^H`) parsing for bold/underline HTML tags
- XHTML 1.0 Transitional output with W3C validation
- SourceForge FRS deployment workflow
- SEO: auto-detect `base_url` from `SCRIPT_NAME` + `HTTP_HOST`

---

## phpMan 2.0 (Original) — 2002-06-05

The original phpMan 2.0, released on SourceForge under the `phpunixman` project.

### Added
- GPL license
- CSS-styled HTML output (XHTML/CSS valid)
- **Man page viewer** with section navigation
- **Perldoc viewer** with module index
- **Info page viewer** (GNU info format)
- Search via `man -k` / `apropos`
- Default landing pages for man, perldoc, and info modes
- Screen-size auto-fit
- Related command/module cross-links
- Email transfer (send man page via email)
- Source code viewer

### Fixed (Jul 2002)
- Perldoc bug with space-to-`%20` translation
- Code formatted with `astyle -j`

### Security
- `escapeshellcmd()` to prevent arbitrary command execution

## phpMan 1.0 (Original) — 2002-05-28

Initial release. A basic PHP-based Unix manual page viewer hosted on SourceForge.

---

## Project Origins — 2002-01-18

Initial checkin to SourceForge CVS. A PHP script to browse Unix man pages over the web.

---

[Unreleased]: https://github.com/chedong/phpman/compare/v3.5...HEAD
[3.5]: https://github.com/chedong/phpman/releases/tag/v3.5
[3.4]: https://github.com/chedong/phpman/compare/v3.4...v3.5
[3.3]: https://github.com/chedong/phpman/compare/v3.3...v3.4
[3.2]: https://github.com/chedong/phpman/compare/v3.2...v3.3
[3.1]: https://github.com/chedong/phpman/compare/v3.1...v3.2
[3.0]: https://github.com/chedong/phpman/compare/v3.0...v3.1
[2.5]: https://github.com/chedong/phpman/compare/v2.4.1...v2.5
[2.4.1]: https://github.com/chedong/phpman/compare/v2.4...v2.4.1
[2.4]: https://github.com/chedong/phpman/compare/v2.3...v2.4
[2.3]: https://github.com/chedong/phpman/releases/tag/v2.3
[2.2]: https://github.com/chedong/phpman/compare/v2.1...v2.2
[2.1]: https://github.com/chedong/phpman/compare/v2.0...v2.1
[2.0]: https://github.com/chedong/phpman/releases/tag/v2.0
