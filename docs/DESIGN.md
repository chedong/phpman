# phpMan Product Definition & Design Document

> Read before code review: this document defines phpMan's product positioning, core design decisions, and intentional behaviors.
> Avoid misclassifying functional design choices as security flaws or technical debt.

---

## 1. Product Positioning

phpMan is a single-file PHP web application that presents Unix `man`/`perldoc`/`info`/`apropos`/`pydoc3`/`ri` command output in HTML, Markdown, JSON, and MCP formats. It also serves as an MCP Server for AI Agent consumption.

**Core value**: Make Unix documentation efficiently accessible to both humans and AI.

---

## 2. Intentional Design Decisions

### 2.1 Footer Displays Visitor IP and User-Agent

**Issue reference**: #27 (closed, not a defect)

`showFooter()` outputs visitor IP and User-Agent as an **intentional product feature**, used for:

1. **Search engine spider tracking** — identify crawlers like Googlebot, Bingbot, Baiduspider via User-Agent
2. **LLM scraper tracking** — identify AI crawlers like GPTBot, ClaudeBot, Bytespider, Applebot-Extended
3. **Traffic analysis** — IP helps distinguish real users from automated requests for ops decisions

**Design principles**:
- This information only appears in HTML page source (XSS-protected via `h()`)
- JSON/MCP/Markdown formats do not include visitor information
- This is phpMan's core observability feature as a public documentation site, not a privacy leak

**Code location**: `showFooter()` ~line 964-965

### 2.2 Single-File Architecture

phpMan is deployed as a single `phpMan.php` file by design:

- **Zero-dependency deployment**: scp one file and it runs, no Composer/autoload needed
- **Backward compatibility**: users from the SourceForge era may run on older PHP versions
- Future code splits (v3.0 roadmap) will be gradual, preserving the single-file entry point

### 2.3 XHTML 1.0 Transitional

phpMan maintains XHTML 1.0 Transitional compliance, not upgrading to HTML5:

- No `og:` meta tags (`property` attribute incompatible with XHTML)
- No HTML5 semantic tags (`<nav>`, `<section>`, etc.)
- External links keep minimal URL parameters

### 2.4 TLDR Cache Strategy

TLDR results are persistently cached in the SQLite `tldr_cache` table (7-day TTL):

- `fetchOfficialTldr()` fetches from tldr-pages GitHub Raw (cheat.sh fallback)
- Cached in `phpm_cache.db` `tldr_cache` table
- Includes negative caching: 404/not_found commands are cached to avoid repeated HTTP requests
- Old file-based `tldr_cache/` directory is deprecated and no longer used

### 2.5 Info Mode Setext Heading Detection

GNU info pages use Setext-style underline headings:

| Level | Pattern | Example |
|------|------|------|
| H1 | Next line is `*****` | `1 Introduction\n**************` |
| H2 | Next line is `=====` | `2.1 Invoking shar\n=================` |
| H3 | Next line is `-----` | `2.1.1 shar help\n----------------` |

`detectHeadingType($line, $mode, $nextLine)` accepts an optional `$nextLine` parameter. In info mode, detected headings return `skipNext: true`, and the caller skips the underline line. Other modes ignore this parameter.

**Code location**: `detectHeadingType()` ~line 420, `formatManPerlDoc()` ~line 2374

### 2.6 Tokyo Night Dark Theme

v2.3 adopted Tokyo Night color scheme, unifying visual style across all modes (man/perldoc/info/pydoc/ri):

| Element | Color | Usage |
|------|------|------|
| `#1a1b26` | Deep blue-black | Main background |
| `#c0caf5` | Light blue-gray | Body text |
| `#e0af68` | Warm gold | Bold |
| `#9ece6a` | Green | Underline |
| `#7aa2f7` | Blue | Links, buttons |
| `#24283b` | Dark blue-gray | Sidebar/TLDR background |
| `#3b4261` | Mid gray-blue | Borders |

CSS unified globally: `body`/`pre` share font family and size, `<b>`/`<u>` colors no longer differ by mode.

### 2.7 Format Links on Detail Pages Only

Markdown | JSON | MCP format links only appear on detail pages (with actual content) in the search bar row. Index pages and no-result pages do not show format links.

**Code location**: `showForm()` ~line 1252

### 2.8 H1 Breadcrumb + Title Format

Detail page H1 and `<title>` use a unified breadcrumb format:

```
phpMan > man > ls(1)
```

- `phpMan` links to homepage, intermediate elements link to mode index pages (`/man`, `/pydoc`, etc.), current page is plain text
- perldoc has no standalone index; intermediate link points to `/search/perl`
- Homepage/search mode keeps the original single title style

**Issue**: #65

### 2.9 Unified Search Result List Format

Search (apropos) and pydoc3 keyword results use unified `<ul><li>` list format, replacing `<pre>` + `<br />` line breaks:

| Module | Format | Container |
|------|------|------|
| apropos | `<li><a>` link list | `<h2>apropos</h2>` + `<ul>` |
| pydoc3 | `<li><a>mod — desc</a></li>` | `<h2>Python 3 (pydoc3)</h2>` + `<ul>` |
| ri | Full document content | `<h2>Ruby (ri)</h2>` + `<pre>` |

ri index (`/ri`) is also changed to `<ul>` list. Search/fallback pages use `<div id="man-content">` instead of `<pre>`.

### 2.10 Footer Git Version Number

`make deploy`/`make release` injects `git describe --tags --always --dirty` into the `GIT_DESCRIBE` constant via `sed` + ssh pipe. Footer displays `phpMan v2.3-5-g1cea00a`. Local dev defaults to `local`.

### 2.11 Command Name Case & Platform Differences (Linux vs BSD)

phpMan's `normalizeParameter()` routing preserves original case in command names (no `strtolower`), relying on downstream systems to handle it.

**System man command case handling varies by platform (verified empirically):**

| Platform | `man RUBY` | Reason |
|------|-----------|------|
| Linux (GNU man-db) | Found `ruby(1)` | mandb database + filesystem glob dual-path normalization |
| macOS (BSD man) | No manual entry | Direct `stat()` on file, case-sensitive |

**GNU man normalization** (confirmed via `man -d RUBY`):
1. Opens `/var/cache/man/index.db`
2. `multi key lookup (Ruby\t1)` and `multi key lookup (ruby\t1)` — queries both title-case and lowercase
3. `globbing pattern RUBY.1*` can also match `ruby.1.gz`
4. Finally finds physical file `ruby3.0.1.gz`

**BSD man**: directly searches by filename, `man RUBY` fails.

**Impact on phpMan routing:**

```
phpMan.php/man/RUBY/1
  → exec("man -Tutf8 1 'RUBY'")
    → GNU man finds ruby(1) via mandb ✅ (Linux)
    → BSD man fails ❌ (macOS, unless filesystem is case-insensitive)

  → fetchOfficialTldr("RUBY")
    → GitHub RAW: RUBY.md 404 ❌ (URL is case-sensitive)
    → cheat.sh/RUBY: Unknown topic ❌
```

**Core asymmetry**: system commands (man/perldoc/info) depend on system behavior, while external APIs (GitHub RAW / cheat.sh / LLM API) use case-sensitive URLs. This means:

- man command finding a page ≠ fetchOfficialTldr finding TLDR (before fix)
- TLDR cache keys must be normalized (`strtolower`) before use (fixed in #XX)
- Other external API calls (LLM `/tldr` endpoint) similarly need case normalization at entry layer

**Design principle**: phpMan's "trust system calls, defend external APIs" asymmetry is core to understanding the routing design. System command compatibility is guaranteed by each platform; external API calls must be explicitly normalized by phpMan.

### 2.12 TOC Display Strategy on Mobile

- **Wide screen (>1024px)**: TOC sidebar fixed to right, expanded by default, no toggle button
- **Narrow screen (≤1024px)**: TOC collapsed by default, showing only the title row (e.g. `tar(1) □`), tap title row to expand/collapse
- **Toggle button**: `□` (expand) / `✕` (collapse) icon `float:right` inline with title, entire title row is tappable
- **back-to-top**: mobile z-index above TOC sidebar, not hidden when expanded
- **Implementation**: `body.toc-open` class toggle, pure CSS, inline onclick JS with no external dependencies


### 2.13 Project Structure: Makefile vs install.sh

phpMan provides two deployment tools for two different audiences:

| | Makefile | install.sh |
|---|---|---|
| **受众** | 项目维护者（chedong） | 外部用户 |
| **入口** | `make staging` / `make release` | `curl \| bash` |
| **前提** | SSH 访问目标服务器 + `.deploy.mk` | 本地 PHP + git |
| **功能** | 远程部署、回滚、日志检查、缓存管理、健康检查 | 本地安装、更新、启动开发服务器、webroot 部署 |

**为什么两者共存而非统一**：

- `make rollback` — 从远程备份恢复，install.sh 做不到（需要 SSH）
- `make logcheck` — 读取服务器 nginx/PHP 错误日志，install.sh 做不到
- `make cache-flush/stats` — 管理远程 SQLite 缓存，install.sh 做不到
- `make verify` — 同时健康检查 staging + production，install.sh 做不到

**敏感信息隔离**：`.deploy.mk` 包含 SSH host/port/path，已 `.gitignore`。模板 `.deploy.mk.example` 可公开。

**Code location**: `Makefile`（CI/CD 入口）, `.deploy.mk.example`（服务器配置模板）, `install.sh`（用户端一键安装）

---

## 3. Security Boundary Definition

The following are **security defects** requiring fixes:

| Category | Example | Action |
|------|------|----------|
| Injection vulnerabilities | CRLF injection, command injection, XSS | Fix immediately |
| Information leakage (unintended) | MCP errors exposing internal paths, stack traces | Fix |

The following are **defense-in-depth measures** that should be handled by the server layer (Nginx/Cloudflare/CDN). PHP-layer code is pending cleanup:

| Defense | Server-layer solution | PHP-layer current state (to clean up) | Issue | Notes |
|--------|-------------|------------------------|-------|------|
| Rate limiting | Nginx `limit_req` / Cloudflare WAF | `checkRateLimit()` file-lock approach | #84 | PHP rate limiting ineffective behind proxy (`REMOTE_ADDR` is proxy IP); file locks have high contention |
| Gzip compression | Nginx `gzip on` / Cloudflare auto-compression | `ob_gzhandler` | #84 | May double-compress with server gzip; blocks PHP process |
| Security headers (HSTS) | Nginx `add_header Strict-Transport-Security ... always;` | Conditional output in `showHeader()` via `if (!isLocalRequest())` | #89 | Behind proxy, `REMOTE_ADDR` is internal IP → production doesn't send HSTS; local dev uses HTTP so no HSTS needed |

**Design principle**: phpMan is a single-file app. Rate limiting, compression, and security headers are infrastructure concerns to be handled by the deployment layer (Nginx/Apache/Cloudflare/CDN). phpMan doesn't necessarily run at the website root, so it does not generate robots.txt, sitemap.xml, llms.txt, or other root-path files — these should be configured by the site admin at the server layer.

### 3.1 `isLocalRequest()` Deprecation

`isLocalRequest()` determines request source via `$_SERVER['REMOTE_ADDR']`, which behind a reverse proxy is the proxy IP, not the client IP, making the check unreliable. This function will be removed entirely, with its 3 call sites replaced by correct alternatives:

| Call site | Current behavior | Problem | Replacement | Issue |
|--------|----------|------|----------|-------|
| line 1172: HSTS header | `if (!isLocalRequest())` → send HSTS | Behind proxy, `REMOTE_ADDR` is internal IP → production never sends HSTS | **Nginx config**: production HTTPS vhost `add_header Strict-Transport-Security ... always;`; local dev uses HTTP so no HSTS | #89 |
| line 1423: server version | `if (isLocalRequest())` → show `SERVER_SOFTWARE` | Behind proxy, all requests come from internal IP → anyone can see version info | **Nginx `server_tokens off`** + **php.ini `expose_php=Off`**; remove version display from PHP code | #89 |
| `?debug=1` debug mode | `isLocalRequest()` → allow sensitive details | Same as above, behind proxy anyone can trigger debug | **PHP env var** `PHPMAN_DEBUG=true`, explicit config instead of IP inference | #89 |

**Design principle**: Security policies (HSTS, version hiding) belong to the transport/infrastructure layer and should be handled by the web server at TLS termination, not by PHP application logic. Application-level features (debug mode) should use explicit environment variables, not runtime IP inference — `REMOTE_ADDR` is unreliable in proxy architectures.

The following are **security hardening completed in v2.3**:

| Hardening | Issue | Implementation | Status |
|--------|-------|------|------|
| Non-HTML response security headers | #63 | JSON/Markdown/MCP responses add `X-Content-Type-Options: nosniff` + `X-Frame-Options: DENY` | ✅ Retained |
| HSTS force HTTPS | #70 → #89 | `Strict-Transport-Security` header, originally conditional via `if (!isLocalRequest())` | 🔄 Pending cleanup: move to Nginx config, remove PHP-layer `isLocalRequest()` (#89) |
| IP-level rate limiting | #69 → #84 | `checkRateLimit()` based on file lock + JSON storage, default 30 req/60s | 🔄 Pending cleanup: move to Nginx `limit_req`, remove PHP-layer implementation (#84) |
| MCP error message sanitization | #71 | `sendMcpError()` returns `Method not found` without exposing internal method names | ✅ Retained |
| Shell argument defense | #62 | `$width` already `intval()` before interpolating into shell command | ✅ Retained |

The following are **product features** and should not be removed:

| Feature | Location | Reason |
|------|------|------|
| Footer IP + UA display | `showFooter()` | Spider/crawler tracking |
| `?debug=1` diagnostic info | Dev helper | Switch to `PHPMAN_DEBUG=true` env var instead of `isLocalRequest()` IP check (#89) |

---

## 4. Review Process

When reviewing code, follow this order:

1. **Read this document first** — understand product definition and intentional design decisions
2. **Consult `docs/PLAN.md`** — understand version roadmap and architecture direction
3. **Consult `docs/PYDOC_RI_DESIGN.md`** — pydoc3/ri format parsing and content pipeline design
4. **Check GitHub Issues** — understand known issues and fix priorities
5. **Then review the code** — avoid misclassifying product design as defects

---

## 5. Ticket Status Summary

| Issue | Title | Status | Fix Version |
|-------|-------|--------|-------------|
| #96 | Unescaped array in search HTML (XSS) | Fixed | v3.7 |
| #97 | MCP endpoint has no authentication | Fixed | v3.7 |
| #98 | Silent catch blocks swallow exceptions | Fixed | v3.7 |
| #99 | $pydocFtsLines/$riFtsLines undefined | Verified | v3.7 |
| #100 | CACHE_DIR writable validation | Fixed | v3.7 |
| #101 | PageCache/cacheDb unit tests | Open | — |
| #102 | Perldoc $width not escaped | Fixed | v3.7 |
| #103 | rebuildSearchIndex transaction safety | Fixed | v3.7 |
| #104 | FTS5 operator passthrough | Fixed | v3.7 |
| #105 | ETag strategy inconsistency | Fixed | v3.7 |
| #107 | $expanded undefined in TLDR | Fixed | v3.7.1 |
| #108 | SQL string interpolation | Fixed | v3.7.1 |
| #109 | tldr_cache missing TTL index | Fixed | v3.7.1 |
| #110 | INSERT OR REPLACE semantics unclear | Fixed | v3.7.1 |
| #111 | DESIGN.md ticket status table | Fixed | v3.7.1 |
| #112 | CLI --help hardcodes cache DB path | Fixed | v3.7.1 |

---

## 6. Revision History

| Date | Changes |
|------|----------|
| 2026-06-09 | v3.7.1: Fix #96 XSS (sources array h()), #107 undefined $expanded, #108 SQL prepared stmt, #109 tldr_cache TTL index, #110 INSERT OR REPLACE comments, #111 ticket status table, #112 CLI CACHE_DB constant; add Ticket Status Summary table |
| 2026-06-08 | v3.7: Security hardening — #95 SQL parameterize, #98 catch block logging, #100 CACHE_DIR validation, #102 perldoc $width escape, #104 FTS5 sanitize, #105 ETag invalidation, #103 rebuildSearchIndex logging | TLDR cache strategy: SQLite `tldr_cache` with 7-day TTL, negative caching; old file-based `tldr_cache/` deprecated |
| 2026-06-04 | `isLocalRequest()` deprecation: HSTS/version hiding moved to Nginx config, debug switched to `PHPMAN_DEBUG` env var; `ob_gzhandler` + `checkRateLimit()` marked for cleanup (#84 #89); security hardening table adds status column |
| 2026-06-03 | Security boundary update: rate limiting/compression/security headers positioned as server-layer responsibilities, PHP layer as fallback only; no root-path file generation; closed #66 #72 #76 #77 |
| 2026-06-03 | v2.3 mobile TOC collapse: narrow screen default collapsed, title row tappable to expand/collapse, fixed `$MOBILE_CSS` global declaration |
| 2026-06-03 | v2.3 unified search result list format, ri index listification, footer git version (`git describe`), removed standalone `/tldr` route |
| 2026-06-03 | v2.3 security hardening: rate limiting (#69), HSTS (#70), nosniff headers (#63), MCP error sanitization (#71), H1 breadcrumbs (#65), JSON-LD fix (#64), closed #62 |
| 2026-06-03 | v2.3: Tokyo Night dark theme, info mode Setext heading detection, CSS global unification, format links on detail pages only, footer optimization (closed #55 #60 #61 #67 #73 #74 #75) |
| 2026-06-02 | v2.3: Added pydoc3 / ri modes, see `docs/PYDOC_RI_DESIGN.md` |
| 2026-06-01 | Initial version: documented footer IP/UA display as intentional design (closed #27) |
