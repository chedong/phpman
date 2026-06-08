# Changelog

All notable changes to phpMan are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- `--help` CLI help for phpMan.php (`php phpMan.php --help`)

### Removed
- `rebuild-index.php` — superseded by `php phpMan.php --build-index` (which has dedup guard, lowercase expansion, DROP+DELETE fallback)

### Fixed
- FTS5 rebuild DROP TABLE fallback: if DROP fails, falls back to DELETE FROM

### Changed
- Cache TTL: found entries from permanent to 7 days; expired entries auto-cleaned (1% chance per request)
- `docs/CACHE_DESIGN.md` rewritten with actual v3.6.2 schema

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
- `PHP_MAN_WIDTH` converted from variable to `define()` constant (shared by man + perldoc)

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
- `PHP_MAN_WIDTH` → `define()` constant

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
