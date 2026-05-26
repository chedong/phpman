# phpMan Test Cases & Security Audit

Generated: 2026-05-24  
Based on: full git history (2002–2026), SourceForge issues, source code review

---

## 1. Security Audit

### 1.1 Command Injection — CRITICAL AREA

All `exec()` calls use `escapeshellarg()` for user input. This is the primary defense.

| Function | Command | Escaped? | Risk |
|----------|---------|----------|------|
| `getManPage()` | `man -Tascii [section] [parameter]` | Yes (both) | Low |
| `getPerldocPage()` | `perldoc [parameter]` | Yes | Low |
| `getPerldocPage()` | `perldoc -f [parameter]` | Yes | Low |
| `getPerldocPage()` | `perldoc -q [parameter]` | Yes | Low |
| `getInfoPage()` | `info [parameter]` | Yes | Low |
| `getSearchPage()` | `apropos -s [section] .` | Yes | Low |
| `getSearchPage()` | `apropos [parameter]` | Yes | Low |
| `getInfoIndex()` | `info` (no args) | N/A | None |

**Finding:** All shell commands are properly escaped. ✅

### 1.2 XSS (Cross-Site Scripting)

| Location | Issue | Severity | Status |
|----------|-------|----------|--------|
| Line 380: `$_SERVER['PHP_SELF']` in href | Not escaped with `h()` | **HIGH** | ❌ OPEN |
| Line 285: `phpinfo()` mode | Exposes full server config | **HIGH** | ❌ OPEN |
| Line 579: `REMOTE_ADDR` in footer | Escaped with `h()` | Low | ✅ |
| Line 580: `HTTP_USER_AGENT` in footer | Escaped with `h()` | Low | ✅ |
| TOC sidebar links | Escaped with `h()` | Low | ✅ |
| Search result links | Need to verify | Medium | ⚠️ Review |

**PHP_SELF XSS (HIGH):**  
`$_SERVER['PHP_SELF']` can contain user-injected path info (e.g., `/phpMan.php/"><script>alert(1)</script>/`).  
Fix: Replace `$_SERVER['PHP_SELF']` with `h($_SERVER['SCRIPT_NAME'])` or `h(scriptName())`.

**phpinfo() Exposure (HIGH):**  
The `phpinfo` mode is accessible via `/phpMan.php/phpinfo` — it reveals PHP version, loaded extensions, server paths, environment variables.  
Fix: Disable in production, or gate behind an admin check/IP whitelist.

### 1.3 Input Validation

| Input | Normalization | Gaps |
|-------|---------------|------|
| `$mode` | `normalizeMode()` — whitelist of 7 modes | ✅ Solid |
| `$parameter` | `normalizeParameter()` — strips `/`, `\0`, control chars | ⚠️ Allows `..`, `~`, backticks |
| `$section` | `normalizeSection()` — regex `/^[A-Za-z0-9_]+$/` | ✅ Solid |
| `$format` | Checked against `"markdown"` / `"html"` | ✅ Solid |

**Parameter normalization gap:**  
`normalizeParameter()` replaces `/` and null bytes but does NOT filter:
- Shell metacharacters: `|`, `&`, `$`, `` ` ``, `;`, `>`, `<`  
  (However, `escapeshellarg()` in the exec calls provides a second layer of defense)
- Path traversal: `..`, `~`  
  (Low risk since `man`/`perldoc`/`info` don't open files by path)

### 1.4 Information Disclosure

| Item | Detail | Severity |
|------|--------|----------|
| `phpinfo()` mode | Full PHP/server config exposed | HIGH |
| Footer: `REMOTE_ADDR` | Visitor IP shown in HTML | Medium |
| Footer: `HTTP_USER_AGENT` | User agent shown in HTML | Low |
| Footer: `SERVER_SOFTWARE` | Server software version | Low |
| Version comment in HTML head | `phpMan v2026-05-22c` | Low |

### 1.5 SSRF / Open Redirect

| Item | Detail | Severity |
|------|--------|----------|
| `base_url` from `HTTP_HOST` | Attacker can spoof Host header → canonical URL points to attacker domain | **MEDIUM** |
| Validator links use `$currentUrl` | Derived from `HTTP_HOST` + `REQUEST_URI` | Medium |

**Host header injection:**  
An attacker sending `Host: evil.com` would cause:
- `<link rel="canonical" href="https://evil.com/phpMan.php/man/ls/1"/>`  
- Schema.org `url` pointing to `evil.com`  
Fix: Validate `HTTP_HOST` against an allowlist, or make `base_url` configurable.

### 1.6 CSRF

No CSRF protection exists. Low risk since phpMan is read-only (no state-changing operations).

### 1.7 Content Security

| Item | Detail | Severity |
|------|--------|----------|
| Man page content rendered in `<pre>` | Raw command output converted to HTML | Medium |
| Overstrike/SGR parsing | Regex-based conversion of terminal formatting | Low |
| URL auto-linking in content | Regex `https?://` → `<$0>` could match malicious URLs | Low |

---

## 2. Functional Test Cases

### 2.1 PATH_INFO Routing

| ID | Test | Input | Expected |
|----|------|-------|----------|
| R01 | Basic man page | `/phpMan.php/man/ls/1` | ls(1) man page content |
| R02 | Man page without section | `/phpMan.php/man/ls` | ls(1) default section |
| R03 | Perldoc mode | `/phpMan.php/perldoc/strict` | strict.pm documentation |
| R04 | Info mode | `/phpMan.php/info/coreutils` | coreutils info page |
| R05 | Search mode | `/phpMan.php/search/printf` | apropos results for printf |
| R06 | Copyright mode | `/phpMan.php/copyright` | GPL license text |
| R07 | Phpinfo mode | `/phpMan.php/phpinfo` | PHP info page |
| R08 | Markdown format | `/phpMan.php/man/ls/1/markdown` | Markdown-formatted output |
| R09 | Empty PATH_INFO | `/phpMan.php` | Man page index |
| R10 | ORIG_PATH_INFO fallback | Set `ORIG_PATH_INFO=/man/ls` | Same as `/man/ls` |

### 2.2 Query Parameter Routing

| ID | Test | Input | Expected |
|----|------|-------|----------|
| Q01 | GET parameters | `?mode=man&parameter=ls&section=1` | ls(1) man page |
| Q02 | Partial parameters | `?parameter=gcc` | gcc default page |
| Q03 | Format override | `?format=markdown` | Markdown output |
| Q04 | Mixed PATH_INFO + GET | `/man/ls?format=markdown` | Markdown ls page |

### 2.3 Input Normalization

| ID | Test | Input | Expected |
|----|------|-------|----------|
| N01 | Mode whitelist | `mode=../../../etc/passwd` | Falls back to "man" |
| N02 | Section validation | `section=;rm -rf /` | Empty string (rejected) |
| N03 | Section valid | `section=3pm` | "3pm" accepted |
| N04 | Parameter null byte | `parameter=ls\x00cat` | Null byte stripped |
| N05 | Parameter control chars | `parameter=ls\x07\x1b` | Control chars stripped |
| N06 | Parameter slash | `parameter=ls/grep` | Slash replaced with space |
| N07 | Uppercase retry | `parameter=LS` | Try lowercase "ls" after "LS" fails |
| N08 | Dot/underscore in name | `parameter=File::Spec` | Perl module lookup |

### 2.4 Content Rendering

| ID | Test | Input | Expected |
|----|------|-------|----------|
| C01 | Overstrike bold | `ls^Hs^H ` text | `<b>ls</b>` in HTML |
| C02 | Overstrike underline | `_^Hl^Hs^H ` text | `<u>ls</u>` in HTML |
| C03 | SGR escape sequences | `\e[1mBOLD\e[22m` | `<b>BOLD</b>` |
| C04 | SGR underline | `\e[4mUL\e[24m` | `<u>UL</u>` |
| C05 | Mixed SGR | `\e[1;4mBOTH\e[22;24m` | `<b><u>BOTH</u></b>` |
| C06 | Email obfuscation | `user@host.com` | `userAThost.com` |
| C07 | URL auto-linking | `http://example.com` | `<http://example.com>` |
| C08 | Related command links | `ls(1)` in content | Clickable link to ls(1) |
| C09 | Perl module links | `File::Spec(3pm)` | Link to perldoc mode |

### 2.5 TOC Sidebar

| ID | Test | Input | Expected |
|----|------|-------|----------|
| T01 | Section anchors | Man page with NAME, DESCRIPTION | `id="section-name"`, `id="section-description"` |
| T02 | Two-level TOC | Man page with indented sub-items | Level 1 + Level 2 entries |
| T03 | TOC visibility | Page with <2 sections | No sidebar |
| T04 | TOC visibility | Page with ≥2 sections | Sidebar shown |
| T05 | Duplicate section names | Two "FILES" sections | Unique IDs: `section-files`, `section-files-2` |
| T06 | Tab indentation | Level 2 items with tab indent | Detected as Level 2 |
| T07 | Special char items | Items starting with `-`, `--` | Skipped (not Level 2) |

### 2.6 SEO & Meta

| ID | Test | Input | Expected |
|----|------|-------|----------|
| S01 | Canonical URL | `/phpMan.php/man/ls/1` | `<link rel="canonical" href="https://host/phpMan.php/man/ls/1"/>` |
| S02 | Base URL auto-detect | Various Host headers | Uses current host + SCRIPT_NAME |
| S03 | Meta description (man) | ls(1) page | "Online man page for ls(1): ..." |
| S04 | Meta description (perldoc) | strict page | "Online perldoc for strict: ..." |
| S05 | Robots noindex | Search fallback page | `content="noindex, follow"` |
| S06 | Robots index | Real man page | `content="index, follow"` |
| S07 | Schema.org JSON-LD | ls(1) page | Valid TechArticle schema |
| S08 | Citation meta | Any page | `citation_title`, `citation_author` present |

### 2.7 Markdown API

| ID | Test | Input | Expected |
|----|------|-------|----------|
| M01 | Markdown via path | `/phpMan.php/man/ls/1/markdown` | `text/markdown` content type |
| M02 | Markdown via Accept | `Accept: text/markdown` | Markdown output |
| M03 | Markdown via query | `?format=markdown` | Markdown output |
| M04 | Markdown structure | Any man page | Has `#`, `##`, code blocks |

### 2.8 Edge Cases & Error Handling

| ID | Test | Input | Expected |
|----|------|-------|----------|
| E01 | Non-existent command | `mode=man&parameter=xyzzy123` | Fallback to search |
| E02 | Empty parameter | `mode=man&parameter=` | Index page |
| E03 | Very long parameter | 1000-char string | Truncated/normalized, no crash |
| E04 | Unicode parameter | `parameter=中文` | Normalized, no crash |
| E05 | Man page not found | `parameter=nonexistent` | Search fallback with "noindex" |
| E06 | Perl module fallback | `parameter=strict` with `::` | Tries perldoc after man fails |

---

## 3. Security Test Cases (Penetration)

| ID | Attack Vector | Payload | Expected Defense |
|----|---------------|---------|------------------|
| P01 | Command injection via parameter | `; cat /etc/passwd` | `escapeshellarg()` neutralizes |
| P02 | Command injection via section | `1; cat /etc/passwd` | `normalizeSection()` rejects |
| P03 | Command injection backticks | `` `cat /etc/passwd` `` | `escapeshellarg()` neutralizes |
| P04 | Command injection pipe | `\| cat /etc/passwd` | `escapeshellarg()` neutralizes |
| P05 | Path traversal | `../../etc/passwd` | `normalizeParameter()` replaces `/` |
| P06 | Null byte injection | `ls%00cat` | `normalizeParameter()` strips `\0` |
| P07 | XSS via PHP_SELF | `/phpMan.php/"><script>alert(1)</script>` | **VULNERABLE** — needs fix |
| P08 | XSS via parameter | `<script>alert(1)</script>` | `h()` escapes in output |
| P09 | Host header injection | `Host: evil.com` | canonical URL → evil.com (**VULNERABLE**) |
| P10 | SSRF via validator link | Spoofed Host + REQUEST_URI | Validator link to evil.com |
| P11 | phpinfo disclosure | `/phpMan.php/phpinfo` | Full config exposed (**VULNERABLE**) |
| P12 | CRLF injection | `parameter=ls%0d%0aSet-Cookie:evil=1` | `normalizeParameter()` strips control chars |
| P13 | Double encoding | `parameter=ls%2530` | Should not bypass normalization |

---

## 4. Priority Fixes

| # | Issue | Severity | Fix |
|---|-------|----------|-----|
| 1 | `$_SERVER['PHP_SELF']` XSS | HIGH | Replace with `h(scriptName())` on line 380 |
| 2 | `phpinfo()` publicly accessible | HIGH | Gate behind IP whitelist or remove |
| 3 | `HTTP_HOST` spoofing → canonical/Schema.org | MEDIUM | Validate against allowlist or make configurable |
| 4 | `REMOTE_ADDR` in footer | MEDIUM | Remove or make opt-in |
| 5 | SourceForge patch #1 still open | LOW | "section search bug fix" — review and close |

---

## 5. Historical Issue Tracker

- **SourceForge Bugs:** 0 open (all closed or none filed)
- **SourceForge Patches:** 1 open — "section search bug fix" (filed 2006-06-02, still open)
- **Key historical security commits:**
  - `64f9527` (2003-03-12): Fixed Security vulnerability by `escapeshellarg()`
  - `7401609` (2002-06-04): Use `escapeshellcmd` avoid executing arbitrary commands
  - `ff58cf1` (2005-12-02): Added XSS attack fix
  - `9d852bc` (2004-01-15): Replace `/` avoid Security exposure on Linux
  - `62495f8` (2002-07-11): Remove `PHP_SELF` and modified title
  - `a65b10f` (2005-01-16): Removed agent and IP info in header
  - `e79e065` (2007-08-21): Trans `ORIG_PATH_INFO` to `PATH_INFO`
