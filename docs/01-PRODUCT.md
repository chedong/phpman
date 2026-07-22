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
- This information only appears in HTML page source (XSS-protected via `h`)
- JSON/MCP/Markdown formats do not include visitor information
- This is phpMan's core observability feature as a public documentation site, not a privacy leak

**Code location**: `showFooter()`

**Google Analytics (v4.4.4+)**: Optional GA4 pageview tracking via `PHPMAN_GA_ID` config:

- Set `define('PHPMAN_GA_ID', 'G-XXXXXXXXXX')` in `phpman.config.php` to enable
- `showFooter()` conditionally outputs `<script async="async" src="https://www.googletagmanager.com/gtag/js?id=...">` + inline `gtag('config', ...)` 
- Inline JS is CDATA-wrapped for XHTML validity
- Leave empty (default) = no GA output, zero overhead
- Architecture: server-side injection keeps measurement ID configurable per deployment, not hardcoded in static JS

### 2.2 Single-File Architecture

phpMan is deployed as a single `phpMan.php` file by design:

- **Zero-dependency deployment**: scp one file and it runs, no Composer/autoload needed
- **Backward compatibility**: users from the SourceForge era may run on older PHP versions
- Future code splits (v3.0 roadmap) will be gradual, preserving the single-file entry point

### 2.3 Minimal Webroot (Public File Surface)

phpMan's webroot contains **only 3 files**: `phpMan.php`, `phpman.css`, `phpman.js`. No config files, no source code.

`PHPMAN_HOME` is baked directly into `phpMan.php` at deploy time (replaced from `__PHPMAN_HOME__` placeholder via `sed`, same mechanism as `GIT_DESCRIBE` and `PHPMAN_VERSION` in §2.11). All user-editable configuration lives in a single file at `~/.phpman/phpman.config.php` — securely outside webroot (see §2.16).

**What must NOT be in the webroot**:

| File/Dir | Why not |
|----------|---------|
| `cli/` | CLI-only utilities (build-index, enhance, batch-enhance) — have `PHP_SAPI !== 'cli'` guards but shouldn't be HTTP-accessible at all |
| `test/` | Test files — may leak internal paths, test data, or expose attack surfaces |
| `docs/` | Design documents — internal architecture info, not for public consumption |
| `.deploy.mk` | Deployment credentials — SSH host/port/path, already gitignored |
| `.git/` | Git metadata — source history, commit messages, working tree |

**Principle**: Any file in the webroot is one misconfiguration away from being publicly readable. CLI tools, tests, and internal documentation belong in the install directory (`~/.phpman/`) or the git clone, never in the webroot. Deployment scripts (Makefile, install.sh) must only copy the allowlist of public files (`phpMan.php`, `phpman.css`, `phpman.js`).

**Code location**: `Makefile` (`_deploy-code` rsync lines), `install.sh` (`do_deploy_webroot()` cp lines)

**Deploy performance**: `make release` uses `rsync -avz` for `cli/`, `src/`, CSS, and JS — only changed files are transferred. `phpMan.php` uses `scp` (single file, patched from temp).

### 2.4 XHTML 1.0 Transitional

phpMan maintains XHTML 1.0 Transitional compliance, not upgrading to HTML5:

- No `og:` meta tags (`property` attribute incompatible with XHTML)
- No HTML5 semantic tags (`<nav>`, `<section>`, etc.)
- External links keep minimal URL parameters

### 2.5 TLDR Cache Strategy

TLDR results are persistently cached in the SQLite `tldr_cache` table (7-day TTL):

- `fetchOfficialTldr()` fetches from tldr-pages GitHub Raw (cheat.sh fallback)
- Cached in `phpm_cache.db` `tldr_cache` table
- Includes negative caching: 404/not_found commands are cached to avoid repeated HTTP requests
- Old file-based `tldr_cache/` directory is deprecated and no longer used

### 2.6 Info Mode Setext Heading Detection

GNU info pages use Setext-style underline headings:

| Level | Pattern | Example |
|------|------|------|
| H1 | Next line is `*****` | `1 Introduction\n**************` |
| H2 | Next line is `=====` | `2.1 Invoking shar\n=================` |
| H3 | Next line is `-----` | `2.1.1 shar help\n----------------` |

`detectHeadingType($line, $mode, $nextLine)` accepts an optional `$nextLine` parameter. In info mode, detected headings return `skipNext: true`, and the caller skips the underline line. Other modes ignore this parameter.

**Code location**: `detectHeadingType()`, `formatManPerlDoc()`

### 2.7 Calibrated Terminal Theme (v4.8)

v4.8 redesign: "Calibrated Terminal" — a [Vercel Geist](https://vercel.com/design)-inspired token system applied to terminal-native colors. The CSS was rewritten from the ground up with semantic design tokens, alpha-layered depth, system sans-serif UI chrome, and a proper motion/focus system.

**Key changes from v4.7:**

| Aspect | v4.7 | v4.8 |
|--------|------|------|
| Tokens | 28 flat opaque hex values | 6-surface + 4-text + 4-alpha semantic scales |
| Typography | `monospace 14px` everywhere | Sans-serif (UI chrome) + monospace (content) |
| Depth | Flat solid backgrounds | Alpha overlays (`rgba(…)`) + box-shadows |
| Focus | Browser default | Two-layer focus ring on all interactive elements |
| Motion | `0.25s` on background/color only | `150ms`/`200ms` with `cubic-bezier` easing; `prefers-reduced-motion` support |
| Spacing | Ad-hoc px values | 4px-unit scale (8/12/16/24/32/40) |
| Border radius | Mixed 2–6px | Consistent 6px default |
| Print | None | Print stylesheet (hides chrome, B&W) |
| Form | Bare fieldset | Toolbar card with shadow, input glow ring, button scale feedback |

**Palette** (dark, native): Cool ink surfaces (`#0b0b14` root, `#131320` card, `#181828` elevated) with warm amber-gold accents for bold/emphasis (`#e0af68`) and cool blue for links (`#6d9ef0`). Light mode ("Hakusho", 白書) follows the same token structure with warm paper tones.

**Semantic color intent** (Geist-inspired 10-step scale structure):
- Surfaces ascend: `root` → `card` → `elevated` → `field`
- Text ascends: `muted` (disabled) → `secondary` (metadata) → `primary` (body)
- Borders ascend: `default` → `hover` → `active`
- Alpha overlays: `100` (4%) → `200` (7%) → `300` (12%) → `400` (18%)

**The sans/mono split** is v4.8's signature risk: UI chrome (H1 breadcrumb, form, TOC sidebar, footer, TLDR headers) uses system sans-serif for scannability; man page content stays monospace. This visual separation between reading environment and terminal artifact creates hierarchy that monospace-only design couldn't achieve.

Full design system spec: `docs/02-UI-DESIGN.md`.

### 2.8 Format Links on Detail Pages Only

Markdown · JSON · MCP format links only appear on detail pages (with actual content) in the footer
row, alongside the git version and author credit. Index pages and no-result pages do not show format
links.

**Code location**: `showFooter()` in `src/web_footer.php`

### 2.9 H1 Breadcrumb + Title Format

Detail page H1 and `<title>` use a unified breadcrumb format:

```
phpMan > man > ls(1)
```

- `phpMan` links to homepage, intermediate elements link to mode index pages (`/man`, `/pydoc`, etc.), current page is plain text
- perldoc has no standalone index; intermediate link points to `/search/perl`
- Homepage/search mode keeps the original single title style

**Issue**: #65

### 2.10 Unified Search Result List Format

Search (apropos) and pydoc3 keyword results use unified `<ul><li>` list format, replacing `<pre>` + `<br />` line breaks:

| Module | Format | Container |
|------|------|------|
| apropos | `<li><a>` link list | `<h2>apropos</h2>` + `<ul>` |
| pydoc3 | `<li><a>mod — desc</a></li>` | `<h2>Python 3 (pydoc3)</h2>` + `<ul>` |
| ri | Full document content | `<h2>Ruby (ri)</h2>` + `<pre>` |

ri index (`/ri`) is also changed to `<ul>` list. Search/fallback pages use `<div id="man-content">` instead of `<pre>`.

### 2.11 Deploy-Time Constants

`make release` / `make staging` / `install.sh` inject three constants into `phpMan.php` via `sed` before uploading:

| Constant | Placeholder | Injected value |
|----------|------------|----------------|
| `PHPMAN_HOME` | `__PHPMAN_HOME__` | `$HOME/.phpman` (resolved via SSH or local env) |
| `PHPMAN_VERSION` | `__PHPMAN_VERSION__` | `git describe --tags --abbrev=0` |
| `GIT_DESCRIBE` | `__GIT_DESCRIBE__` | `git describe --tags --always --dirty` |

All three use placeholders in the repo — never committed with real values. `make release` / `make staging` sed-replaces them into a temp file (`phpMan.php.deploy`), uploads it, then deletes the temp. The local `phpMan.php` is never touched, keeping `git status` clean.

Footer displays `phpMan v4.7-3-g1cea00a`. Local dev shows placeholder values: `PHPMAN_HOME = __PHPMAN_HOME__`, `GIT_DESCRIBE = __GIT_DESCRIBE__`.

### 2.12 LLM Enhancement — Moved to External Project

**As of 2026-07-14, LLM-based enhancement is no longer a phpMan feature.**

What moved out of phpMan:
- `enhanceManPage()`, `callLLM()`, `cleanEmojiHtml()` — LLM orchestration
- `formatMarkdownToHTML()`, `formatInlineMarkdown()` — emoji_md → HTML rendering
- `renderTocSidebar()` — TOC built from LLM-enhanced HTML structure
- `emoji_html` / `emoji_md` SQLite cache fields
- LLM config keys: `LLM_API_KEY`, `LLM_API_URL`, `LLM_MODEL`, `LLM_MAX_TOKENS`, `PHPMAN_ENHANCE_MAX_CHARS`
- `cli/batch-enhance.php` — moved to the standalone `doc-enhance` project
- The `--enhance` flag (formerly `phpMan.php --enhance=...`)

**Why**: the 2026-07 myblog MT→markdown migration made it clear that LLM work is dominated by **task management, prompt versioning, and flow orchestration** — concerns that don't belong in a documentation server. Pushing the same prompts through phpMan, the myblog migration, and future projects is the right shape; embedding it in phpMan was not.

**What this means for phpMan**:
- phpMan's HTML output is the **raw** terminal/man HTML (via `formatManPerlDoc()`), not LLM-enhanced
- phpMan's Markdown output is the **raw** markdown (via `formatManPerlDocToMarkdown()`)
- The `Markdown → HTML` conversion path that existed only for `emoji_md` rendering is removed; users wanting HTML read the HTML endpoint
- **0 LLM calls** in phpMan's request path
- Cache remains for raw output (just storage, no enhancement)

**What this means for consumers**:
- The `doc-enhance` project (external) reads phpMan's raw markdown via HTTP, applies LLM transforms, and serves the enhanced output from its own cache
- phpMan is unaware of `doc-enhance`'s existence
- The enhanced `html` and `markdown` formats are no longer available from phpMan; the raw versions are the default and the only options

Historical design of the LLM features (phpMan v4.0–v4.9) is preserved in git history for reference.

#### 2.12.1 batch-enhance.php CLI Reference (transitional)

While extraction is ongoing, `cli/batch-enhance.php` remains available for offline batch LLM emoji enhancement. It `require_once`s phpMan.php and calls `getManPage()`/`getPerldocPage()`/etc. directly — zero HTTP dependency, no web server needed.

**Quick enhance (shorthand):**

```bash
php cli/batch-enhance.php man:ls                     # Single page
php cli/batch-enhance.php man:ls,tar,grep            # Multiple pages
php cli/batch-enhance.php man:ls,tar,grep --rebuild  # Force redo
php cli/batch-enhance.php perldoc:File::Basename     # Perl module
```

**Full batch mode:**

```bash
# Dry-run preview
php cli/batch-enhance.php --dry-run

# All modes, HTML-cached first, both formats
nohup php cli/batch-enhance.php --cached-first --yes \
  >> logs/batch-enhance.log 2>&1 &

# Single mode only
nohup php cli/batch-enhance.php --mode=man --yes \
  --pid-file=/tmp/bm.pid >> logs/batch-enhance.log 2>&1 &

# HTML format only (skip emoji_md)
php cli/batch-enhance.php --format=html --yes
```

**Process management:**

```bash
# Show per-mode progress with counts, percentages, sample URLs
php cli/batch-enhance.php --status

# Show status then stop running batch
php cli/batch-enhance.php --status --stop

# Stop + restart (requires --mode)
php cli/batch-enhance.php --restart --mode=man --yes
```

**All options:**

| Option | Description |
|--------|-------------|
| `--status` | Show enhancement progress + sample URLs per mode |
| `--stop` | Stop a running batch (reads PID from `--pid-file`) |
| `--restart` | Stop + restart batch (requires `--mode`) |
| `--rebuild, -r` | Force re-enhance even if emoji cache exists |
| `--mode=<m>` | Filter: `man`, `perldoc`, `info`, `pydoc`, `ri` (comma-separated) |
| `--parameter=<p>` | Specific pages (semicolon-separated, needs `--mode`) |
| `--section=<s>` | Manual section (e.g. `1`, `3pm`) for `--parameter` targets |
| `--dry-run` | Show what would be done, no LLM calls |
| `--yes, -y` | Skip confirmation prompt (for cron/SSH) |
| `--limit=<n>` | Max entries to process (default: unlimited) |
| `--format=<f>` | `html`, `md`, or `both` (default: `both`) |
| `--resume-from=<n>` | Skip first N entries |
| `--cached-first` | Sort: entries with HTML cache first |
| `--cache-only` | Generate HTML+MD cache only, skip LLM enhancement |
| `--rate-limit=<s>` | Seconds between LLM calls (default: 60) |
| `--pid-file=<path>` | Write PID to file (auto: `PHPMAN_HOME/logs/batch_enhance.pid`) |
| `--help` | Show help text |

Key features: idempotent resume (every entry written to SQLite immediately), 2-min rate limiting, `--cached-first` sort, non-existent page skip (NOT_FOUND cache), max 10 consecutive failures before abort.

See also `docs/05-PLAN.md` §batch_enhance lifecycle for design details.

### 2.13 Command Name Case & Platform Differences (Linux vs BSD)

phpMan's `normalizeParameter()` routing preserves original case in command names (no `strtolower`), relying on downstream systems to handle it.

**System man command case handling varies by platform (verified empirically):**

| Platform | `man RUBY` | Reason |
|------|-----------|------|
| Linux (GNU man-db) | Found `ruby(1)` | mandb database + filesystem glob dual-path normalization |
| macOS (BSD man) | No manual entry | Direct `stat` on file, case-sensitive |

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

### 2.14 TOC Display Strategy on Mobile

- **Wide screen (>1024px)**: TOC sidebar fixed to right, expanded by default, no toggle button
- **Narrow screen (≤1024px)**: TOC collapsed by default, showing only the title row (e.g. `tar(1) □`), tap title row to expand/collapse
- **Toggle button**: `□` (expand) / `✕` (collapse) icon `float:right` inline with title, entire title row is tappable
- **back-to-top**: mobile z-index above TOC sidebar, not hidden when expanded
- **Implementation**: `body.toc-open` class toggle, pure CSS, inline onclick JS with no external dependencies


### 2.15 Project Structure: Makefile vs install.sh

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

### 2.16 Configuration Architecture (v4.5)

phpMan uses a **single config file** outside webroot: `~/.phpman/phpman.config.php`.

**Loading chain** (both web and CLI paths converge at `src/config.php`):

```
Web:  phpMan.php → PHPMAN_HOME (baked-in) → src/config.php (defaults + load config)
CLI:  _bootstrap.php → resolve PHPMAN_HOME → src/bootstrap.php → src/config.php
```

`src/config.php` sets defaults via the `defined()` guard pattern (like WordPress `wp-config.php`), then loads `~/.phpman/phpman.config.php` to allow overrides:

```php
if (!defined('PHPMAN_GA_ID'))  define('PHPMAN_GA_ID', '');     // default
if (!defined('LLM_API_KEY'))   define('LLM_API_KEY', '');     // default
// ... then: require PHPMAN_HOME . '/phpman.config.php';      // overrides
```

**What goes where**:

| File | Location | Contents |
|------|----------|----------|
| `phpMan.php` | webroot | `PHPMAN_HOME`, `PHPMAN_VERSION`, `GIT_DESCRIBE` — injected at deploy time |
| `phpman.config.php` | `~/.phpman/` | All user settings: `PHPMAN_BASE_URL`, `PHPMAN_GA_ID`, `LLM_API_KEY`, `MCP_API_KEY`, `PHPMAN_DEBUG`, `LLM_FALLBACKS` |
| `src/config.php` | `~/.phpman/src/` | Defaults for all constants, `define()` guard pattern |
| `phpman.config.php.example` | `~/.phpman/` (git) | Template, copied by `install.sh generate_config()` |

**Security**: API keys (`LLM_API_KEY`, `MCP_API_KEY`) are never in webroot. If PHP parsing fails, only the baked-in constants (`PHPMAN_HOME`, version strings) are exposed — no secrets.

**install.sh flow**:
1. `generate_config()` — copies `.example` → `~/.phpman/phpman.config.php`, generates `MCP_API_KEY`
2. `sed` replaces `__PHPMAN_HOME__` → `$HOME/.phpman` in both `$INSTALL_DIR/phpMan.php` (dev server) and webroot copy (Apache/Nginx)
3. `do_deploy_webroot()` — copies `phpMan.php` + CSS + JS to webroot + patches `__PHPMAN_HOME__`

**make release flow**:
1. SSH resolves `$HOME` → `DEMO_HOME`
2. `sed` replaces `__PHPMAN_HOME__`, `GIT_DESCRIBE`, `PHPMAN_VERSION` in local `phpMan.php`
3. `scp` uploads patched `phpMan.php` + CSS + JS + `src/` + `cli/` + `.example`

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
| Rate limiting | Nginx `limit_req` / Cloudflare WAF | `checkRateLimit` file-lock approach | #84 | PHP rate limiting ineffective behind proxy (`REMOTE_ADDR` is proxy IP); file locks have high contention |
| Gzip compression | Nginx `gzip on` / Cloudflare auto-compression | `ob_gzhandler` | #84 | May double-compress with server gzip; blocks PHP process |
| Security headers (HSTS) | Nginx `add_header Strict-Transport-Security ... always;` | Conditional output in `showHeader` via `if (!isLocalRequest)` | #89 | Behind proxy, `REMOTE_ADDR` is internal IP → production doesn't send HSTS; local dev uses HTTP so no HSTS needed |

**Design principle**: phpMan is a single-file app. Rate limiting, compression, and security headers are infrastructure concerns to be handled by the deployment layer (Nginx/Apache/Cloudflare/CDN). phpMan doesn't necessarily run at the website root, so it does not generate robots.txt, sitemap.xml, llms.txt, or other root-path files — these should be configured by the site admin at the server layer.

### 3.1 `isLocalRequest` Deprecation

`isLocalRequest` determines request source via `$_SERVER['REMOTE_ADDR']`, which behind a reverse proxy is the proxy IP, not the client IP, making the check unreliable. This function will be removed entirely, with its 3 call sites replaced by correct alternatives:

| Call site | Current behavior | Problem | Replacement | Issue |
|--------|----------|------|----------|-------|
| line 1172: HSTS header | `if (!isLocalRequest)` → send HSTS | Behind proxy, `REMOTE_ADDR` is internal IP → production never sends HSTS | **Nginx config**: production HTTPS vhost `add_header Strict-Transport-Security ... always;`; local dev uses HTTP so no HSTS | #89 |
| line 1423: server version | `if (isLocalRequest)` → show `SERVER_SOFTWARE` | Behind proxy, all requests come from internal IP → anyone can see version info | **Nginx `server_tokens off`** + **php.ini `expose_php=Off`**; remove version display from PHP code | #89 |
| `?debug=1` debug mode | `isLocalRequest` → allow sensitive details | Same as above, behind proxy anyone can trigger debug | **PHP env var** `PHPMAN_DEBUG=true`, explicit config instead of IP inference | #89 |

**Design principle**: Security policies (HSTS, version hiding) belong to the transport/infrastructure layer and should be handled by the web server at TLS termination, not by PHP application logic. Application-level features (debug mode) should use explicit environment variables, not runtime IP inference — `REMOTE_ADDR` is unreliable in proxy architectures.

The following are **security hardening completed in v2.3**:

| Hardening | Issue | Implementation | Status |
|--------|-------|------|------|
| Non-HTML response security headers | #63 | JSON/Markdown/MCP responses add `X-Content-Type-Options: nosniff` + `X-Frame-Options: DENY` | ✅ Retained |
| HSTS force HTTPS | #70 → #89 | `Strict-Transport-Security` header, originally conditional via `if (!isLocalRequest)` | 🔄 Pending cleanup: move to Nginx config, remove PHP-layer `isLocalRequest` (#89) |
| IP-level rate limiting | #69 → #84 | `checkRateLimit` based on file lock + JSON storage, default 30 req/60s | 🔄 Pending cleanup: move to Nginx `limit_req`, remove PHP-layer implementation (#84) |
| MCP error message sanitization | #71 | `sendMcpError` returns `Method not found` without exposing internal method names | ✅ Retained |
| Shell argument defense | #62 | `$width` already `intval` before interpolating into shell command | ✅ Retained |
| CSP nonce + strict-dynamic | #158 | `script-src 'self' 'nonce-{random}' 'strict-dynamic'` + per-request `CSP_NONCE` on all `<script>` tags | 🔄 Planned (v4.9): removes `'unsafe-inline'`, transitional with domain fallback for Safari <15.4 |

### 3.2 CSP Strategy — nonce + strict-dynamic

**Current state** (`src/web_header.php`):
```
script-src 'self' 'unsafe-inline' [GA domains] [AdSense domains]
```

`'unsafe-inline'` is required because GA/AdSense inline config scripts cannot be externally hosted. This weakens CSP's XSS protection.

**Target state** (Google-recommended pattern):
```
script-src 'self' 'nonce-{CSP_NONCE}' 'strict-dynamic' [GA domains] [AdSense domains]
```

| Component | Role |
|---|---|
| `'nonce-{random}'` | Per-request cryptographically random token (`random_bytes(16)` base64-encoded) |
| `'strict-dynamic'` | Trust propagation: scripts loaded by a nonced script inherit trust automatically |
| Domain fallback | Safari <15.4 doesn't support `'strict-dynamic'` — domain allowlist kept for backward compat |

**Transition plan** (from [chedong.com PR #49](https://github.com/chedong/chedong.com/pull/49)):

1. Add nonce + strict-dynamic, KEEP `'unsafe-inline'` (transitional — nonce adds no security yet)
2. Monitor CSP reports to verify all scripts carry nonce
3. Remove `'unsafe-inline'`, `'unsafe-hashes'`, `sha256-*` hashes

**Implementation note**: Nonce must be shared between `showHeader()` (CSP header) and `showFooter()` (`<script nonce="...">` tags). In PHP, `define('CSP_NONCE', ...)` at request start makes it available to both.

The following are **product features** and should not be removed:

| Feature | Location | Reason |
|------|------|------|
| Footer IP + UA display | `showFooter()` | Spider/crawler tracking |
| `?debug=1` diagnostic info | Dev helper | Switch to `PHPMAN_DEBUG=true` env var instead of `isLocalRequest` IP check (#89) |

---

## 4. Review Process

When reviewing code, follow this order:

1. **Read this document first** — understand product definition and intentional design decisions
2. **Consult `05-PLAN.md`** — understand version roadmap and architecture direction
3. **Consult `04-SEARCH.md`** — FTS5 search design; `03-CACHE.md` — cache/database; `02-UI-DESIGN.md` — design system
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
| 2026-06-30 | v4.9.5: Format links (Markdown/JSON/MCP) moved from form → footer with `fmt-link` class; mobile form horizontal wrap layout; CSS footer format link styles |
| 2026-06-30 | v4.9.6: Compact mobile form — reduced radio option height ~40% (12px labels, 4px gap, scale 1.1, min-height 36px) |
| 2026-07-01 | v4.9.7: Per-endpoint LLM `max_tokens` + `timeout` — primary 120s, fallbacks 60s; config example lowered LLM_MAX_TOKENS 4096→128000; fixed fallback-2 `max_tokens参数非法` error |
| 2026-07-01 | v4.9.8: batch-enhance resilience — missing markdown/HTML SKIPs instead of aborting entire batch; only 3 consecutive LLM failures trigger abort |
| 2026-07-01 | v4.9.9: Moved `RE_ASCII`/`RE_ASCII_SAFE` constants from phpMan.php → src/config.php for CLI tool access; fixed batch-enhance crash on `man/DBI` |
| 2026-07-01 | v4.9.11: fix `callLLM()` non-retryable return value — `false` vs `''` disambiguation; v4.9.12: emoji_html LLM empty → SKIP not abort; v4.9.13: `--status` WAL checkpoint; v4.9.14: fix man emoji count `:mode` never bound |
| 2026-07-01 | v4.9.10: Restored phpman.css (Geist token system) + web_footer.php (format links in footer) overwritten by worktree merge a198949; added worktree rebase rule to CLAUDE.md |
| 2026-06-09 | v3.7.1: Fix #96 XSS (sources array h), #107 undefined $expanded, #108 SQL prepared stmt, #109 tldr_cache TTL index, #110 INSERT OR REPLACE comments, #111 ticket status table, #112 CLI CACHE_DB constant; add Ticket Status Summary table |
| 2026-06-08 | v3.7: Security hardening — #95 SQL parameterize, #98 catch block logging, #100 CACHE_DIR validation, #102 perldoc $width escape, #104 FTS5 sanitize, #105 ETag invalidation, #103 rebuildSearchIndex logging | TLDR cache strategy: SQLite `tldr_cache` with 7-day TTL, negative caching; old file-based `tldr_cache/` deprecated |
| 2026-06-04 | `isLocalRequest` deprecation: HSTS/version hiding moved to Nginx config, debug switched to `PHPMAN_DEBUG` env var; `ob_gzhandler` + `checkRateLimit` marked for cleanup (#84 #89); security hardening table adds status column |
| 2026-06-03 | Security boundary update: rate limiting/compression/security headers positioned as server-layer responsibilities, PHP layer as fallback only; no root-path file generation; closed #66 #72 #76 #77 |
| 2026-06-03 | v2.3 mobile TOC collapse: narrow screen default collapsed, title row tappable to expand/collapse, fixed `$MOBILE_CSS` global declaration |
| 2026-06-03 | v2.3 unified search result list format, ri index listification, footer git version (`git describe`), removed standalone `/tldr` route |
| 2026-06-03 | v2.3 security hardening: rate limiting (#69), HSTS (#70), nosniff headers (#63), MCP error sanitization (#71), H1 breadcrumbs (#65), JSON-LD fix (#64), closed #62 |
| 2026-06-03 | v2.3: Tokyo Night dark theme, info mode Setext heading detection, CSS global unification, format links on detail pages only, footer optimization (closed #55 #60 #61 #67 #73 #74 #75) |
| 2026-06-02 | v2.3: Added pydoc3 / ri modes (now merged into this document) |
| 2026-06-01 | Initial version: documented footer IP/UA display as intentional design (closed #27) |

---

# pydoc3 / ri Document Format Parsing Design

> v2.3+: HTML/Markdown/JSON/MCP output for pydoc3 (Python 3) and ri (Ruby) CLI documentation,
> sharing the unified content pipeline with existing man/perldoc/info modes.

---

## 1. Architecture Overview

```
pydoc3 <module>  ─┐
                   ├──→ formatManPerlDoc($lines, "pydoc")    → HTML
                   ├──→ formatManPerlDocToMarkdown($lines)   → Markdown
                   ├──→ formatToJSON($lines, "", "pydoc")    → JSON
                   └──→ formatForOutput(json, "mcp")         → MCP

ri <Class#method> ─┐
                   ├──→ formatManPerlDoc($lines, "ri")       → HTML
                   ├──→ formatManPerlDocToMarkdown($lines)   → Markdown
                   ├──→ formatToJSON($lines, "", "ri")       → JSON
                   └──→ formatForOutput(json, "mcp")         → MCP
```

pydoc/ri reuse the existing `formatManPerlDoc()` / `formatToJSON` / `formatManPerlDocToMarkdown` pipeline, differentiated by `$mode` parameter. Code location: phpMan.php (search for relevant function).

---

## 2. URL Routing

| Route | Function | Handler |
|------|------|----------|
| `GET /pydoc/{module}/{format}` | Python module docs | `getPydocPage` |
| `GET /pydoc/{format}` | Python module index | `getPydocIndex` |
| `GET /ri/{Class#method}/{format}` | Ruby class/method docs | `getRiPage` |
| `GET /ri/{format}` | Ruby class index | `getRiIndex` |

### Search Cascade

Since v3.6, search always aggregates results from all three sources (no longer only cascaded when apropos is empty):

```
getSearchPage                  → FTS5/apropos (man pages)
  + getPydocSearchPage         → pydoc3 -k or FTS5 pydoc index
  + getRiSearchPage            → ri command or FTS5 ri index
```

Search priority: FTS5 offline index > command-line search > FTS5 per-source search.

See [04-SEARCH.md](04-SEARCH.md).

### MCP Auto-Detection

`cli_help` selects document source by naming convention (search for relevant function):

| Input feature | Document source | Example |
|----------|--------|------|
| Contains `::` | `getPerldocPage` | `Digest::MD5` |
| Contains `#` | `getRiPage` | `Array#map` |
| Contains `.` (no `::`) | `getPydocPage` | `json.loads`, `os.path` |
| Other | `getManPage` → pydoc fallback → ri fallback | `ls` |

---

## 3. pydoc3 Format Parsing

### 3.1 Raw Output Format

`pydoc3 <module>` outputs plain text (no overstrike/ANSI), typical structure:

```
Help on package json:

NAME
    json

DESCRIPTION
    JSON (JavaScript Object Notation) ...

CLASSES
    builtins.OSError(builtins.Exception)
        JSONDecodeError

    class JSONDecoder(builtins.object)
     |  Simple JSON decoder
     ...

    class JSONEncoder(builtins.object)
     ...

FUNCTIONS
    dump(obj, fp, ...)
        Serialize obj as JSON ...

    dumps(obj, ...)
        Serialize obj to JSON string ...
```

### 3.2 Section Heading Detection

pydoc output uses **ALL CAPS lines** as L1 section headings (`NAME`, `DESCRIPTION`, `CLASSES`, `FUNCTIONS`, `DATA`, etc.), matched by the existing `detectL1Heading` function via ALL CAPS regex (search for relevant function).

pydoc mode does **not** use the ri-specific RDoc marker detection (search for relevant function), instead using the standard man/perldoc L1/L2 detection flow.

### 3.3 Class/Function Definition Detection (L2 Sub-sections)

pydoc has two dedicated L2 patterns, handled in `detectL2IndentedPatterns()`:

**Class definition** — `detectL2IndentedPatterns()`:
```
    class Name(ParentClass)
```
- 4-space indent + `class` + class name + `(`
- Parentheses may contain HTML `<a>` links (parent classes are linked); stripped first with `preg_replace`
- Extracted as: `['level' => 2, 'text' => 'class Name']`

**Function/method definition**:
```
    funcName(args)
```
- 4-space indent + lowercase-starting identifier + `(`
- Excludes Python keywords
- Extracted as: `['level' => 2, 'text' => 'funcName']`

### 3.4 HTML Link Handling (mode="pydoc")

In `formatManPerlDoc()`, pydoc mode uses a specific link pattern (search for relevant function):

```
pattern: /class (\w+)\((\w+(?:\.\w+)*)\)/
replace: class $1(<a href="/pydoc/$2">$2</a>)
```

Parent class references become clickable links: `class JSONDecodeError(ValueError)` → `class JSONDecodeError(<a href="/pydoc/ValueError">ValueError</a>)`.

### 3.5 Module Index Format

`getPydocIndex` runs `pydoc3 modules`, which outputs multi-column text:

```
Please wait ... (calculating module list)

BaseHTTPServer      email               json                ...
Bastion             encodings           keyword             ...
...
Enter any module name ...
```

Parsing strategy (search for relevant function):
1. Skip header before blank line
2. Stop at `Enter any module name`
3. Split multi-column layout on 2+ spaces via `preg_split('/\s{2,}/', ...)`
4. Deduplicate, sort
5. Output in requested format (HTML/Markdown/JSON/MCP)

### 3.6 Search Format

`getPydocSearchPage` runs `pydoc3 -k <keyword>`, output format:

```
module_name - Description of the module
another_module - Another description
```

Parsing (search for relevant function): regex `^(\S+)\s*-\s*(.+)` splits module name and description. Entries without descriptions are listed as plain module names. Results include `name`, `description`, `link` fields.

---

## 4. ri (Ruby RDoc) Format Parsing

### 4.1 Raw Output Format

`ri <Class#method>` outputs **overstrike format** (char^Hchar, same as man), typical structure:

```
= A\bAr\brr\bra\bay\by \b <\b< \b O\bOb\bbj\bje\bec\bct\bt

------------------------------------------------------------------------
= I\bIn\bnc\bcl\blu\bud\bde\bes\bs:\b:
Enumerable (from ruby core)

(from ruby core)
------------------------------------------------------------------------
Array indexing starts at 0...

= C\bCl\bla\bas\bss \b m\bme\bet\bth\bho\bod\bds\bs:\b:

  new
  try_convert

= I\bIn\bns\bst\bta\ban\bnc\bce\be \b m\bme\bet\bth\bho\bod\bds\bs:\b:

  %
  *
  []
  map
  ...
```

### 4.2 RDoc Marker Heading Detection (ri-Specific)

ri mode uses a **completely independent** heading detection logic, bypassing standard L1/L2 detection. In `detectHeadingType()`, when `$mode === "ri"`, RDoc marker detection is returned directly (search for relevant function):

| Marker | Meaning | Detection Result |
|------|------|----------|
| `= Heading` | L1 section | `['level' => 1, 'text' => 'Heading']` |
| `== Subheading` | L2 sub-section | `['level' => 2, 'text' => 'Subheading']` |

Processing flow:
1. Strip HTML tags and markdown formatting markers (`**`, `_`)
2. Regex `/^= (.+)/` matches L1, `/^== (.+)/` matches L2
3. Filter out lines that are just `=` or `==` (separator line false positives)

**Design decision**: ri completely bypasses standard detection because:
- ri overstrike cleanup produces `= **Section** **Name**` format
- Standard `detectL2BoldSubheading` may misclassify it as L2
- RDoc markers `= / ==` are simple and reliable; no need to reuse complex logic

### 4.3 TOC Label Stripping

ri's RDoc `=` and `==` prefixes are stripped in TOC. In `buildToc`:

```php
// L1 TOC: strip "= Heading" → "Heading" 
$label = preg_replace('/^=\s*/', '', $label);

// L2 TOC: strip "== Heading" → "Heading" 
$label = preg_replace('/^==\s*/', '', $label);
```

### 4.4 HTML Link Handling (mode="ri")

In `formatManPerlDoc()`, ri mode uses two link patterns (search for relevant function):

**Parent class links** (shared with pydoc):
```
pattern: /class (\w+)\((\w+(?:\.\w+)*)\)/
replace: class $1(<a href="/ri/$2">$2</a>)
```

**Ruby constant/module references** (`::` notation):
```
pattern: /((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/
replace: $3<a href="/ri/$4">$4</a>$6
```

Effect:
- `SomeModule::SubClass` → `<a href="/ri/SomeModule::SubClass">SomeModule::SubClass</a>`
- Only replaces in whitespace/comma/HTML-tag-delimited contexts, avoiding false matches

### 4.5 Index Format

`getRiIndex` runs `ri -l`, outputting one class/module name per line (plain text), no special parsing.

### 4.6 Search Strategy

ri has **no** native `ri -k` (keyword search), so `getRiSearchPage` directly runs `ri <query>`. ri has built-in fuzzy matching: if no exact match, it tries partial matching.

First-line filter rules (no-result detection):
- `Nothing known about` — standard no-result response
- `.xxx not found` — ri special response for lowercase names (e.g., `ri json` returns `.json not found`)

When `ri` command yields no results, the search cascade falls back to FTS5 ri index. See [04-SEARCH.md](04-SEARCH.md).

---

## 5. Shared Content Pipeline

pydoc and ri content processing pipeline is **fully shared** with man/perldoc/info:

| Processing Stage | pydoc Specifics | ri Specifics |
|----------|-------------|-----------|
| `cleanTerminalOutput` | No overstrike, passes through | Has overstrike, same as man |
| `detectHeadingType()` | Standard L1/L2 + pydoc class/func patterns | Dedicated RDoc marker mode (`=`/`==`) |
| `formatManPerlDoc()` | pydoc parent class link pattern | ri parent class + `::` constant links |
| `formatToJSON` | Standard JSON structuring | Standard JSON structuring |
| `formatForOutput` | Standard MCP wrapping | Standard MCP wrapping |
| `buildToc` | `=`/`==` prefix stripping | `=`/`==` prefix stripping |
| `fetchOfficialTldr()` | No TLDR (not triggered for pydoc/ri) | No TLDR |

### cleanTerminalOutput

Location: phpMan.php (search for relevant function). Converts overstrike and ANSI sequences in raw terminal output to markdown-style markers:

- `X^HX` → `**X**` (bold)
- `_^HX` → `_X_` (underline)
- `ESC[1m...ESC[0/22m` → `**...**` (ANSI bold)
- `ESC[4m...ESC[0/24m` → `_..._` (ANSI underline)

pydoc output contains none of these sequences, so the function passes through directly.

---

## 6. "Not Found" Handling

When pydoc/ri documentation is not found on the server, external search links are shown:

| Mode | External Link | URL |
|------|------|-----|
| man | cheat.sh | `https://cheat.sh/{command}` |
| perldoc | MetaCPAN | `https://metacpan.org/pod/{module}` |
| **pydoc** | Python Docs | `https://docs.python.org/3/search.html?q={module}` |
| **ri** | Ruby-Doc | `https://ruby-doc.org/search.html?q={class}` |
| info | Google | `https://www.google.com/search?q={topic}` |

Code: phpMan.php (search for relevant function).

---

## 7. Key Design Decisions

### 7.1 Why ri Uses Independent Heading Detection

- ri's RDoc markers (`= Section`, `== Subsection`) are simple and clear; no need to reuse man's complex L1/L2 heuristics
- Overstrike cleanup produces `= **Bold** **Text**` format that standard L2 bold detection may misclassify
- Isolated logic: future RDoc format changes only affect ri mode, not polluting other modes

### 7.2 Why pydoc Reuses Standard Detection

- pydoc output is plain-text ALL CAPS sections, compatible with perldoc's `=head1` format
- Class/function definitions are handled as L2 sub-sections via extended `detectL2IndentedPatterns()`
- No need for an independent detection path, reducing maintenance cost

### 7.3 No Overstrike ≠ No cleanTerminalOutput

pydoc output has no overstrike/ANSI; `cleanTerminalOutput` is a pass-through. But the unified pipeline ensures format consistency if pydoc output format changes in the future.

---

## 8. Code Location Index

| Feature | File | Line |
|------|------|------|
| URL routing dispatch | phpMan.php | 843–866 |
| MCP auto-detection | phpMan.php | 1520–1559 |
| `getPydocPage` | phpMan.php | 1717–1727 |
| `getRiPage` | phpMan.php | 1729–1739 |
| `getPydocIndex` | phpMan.php | 1741–1808 |
| `getRiIndex` | phpMan.php | 1810–1860 |
| `getPydocSearchPage` | phpMan.php | 1862–1922 |
| `getRiSearchPage` | phpMan.php | 1924–1938 |
| ri heading detection | phpMan.php | 433–444 |
| pydoc class/func detection | phpMan.php | 363–373 |
| mode-specific link patterns | phpMan.php | 2331–2353 |
| TOC label stripping (=/==) | phpMan.php | 1652, 1663 |
| Not found external links | phpMan.php | 1272–1288 |
| `cleanTerminalOutput` | phpMan.php | 146–172 |
| `detectHeadingType()` | phpMan.php | 429–461 |
| `formatManPerlDoc()` | phpMan.php | 2285–2393 |
| `formatToJSON` | phpMan.php | 3100–3338 |
| `enhanceManPage()` | phpMan.php | 2133 |
| `callLLM()` | phpMan.php | 2072 |
| `formatMarkdownToHTML()` | phpMan.php | 2229 |
| `formatInlineMarkdown()` | phpMan.php | 2328 |
| `renderTocSidebar()` | phpMan.php | 2363 |
| `showFooter()` enhanced link | phpMan.php | 3387 |
| `cli/batch-enhance.php` | cli/batch-enhance.php | — |
| `cli/batch-enhance.php` | cli/batch-enhance.php | — |
