# Changelog

All notable changes to phpMan are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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

[2.2]: https://github.com/chedong/phpman/compare/v2.1...v2.2
[2.1]: https://github.com/chedong/phpman/compare/v2.0...v2.1
[2.0]: https://github.com/chedong/phpman/releases/tag/v2.0
