# phpMan Test Architecture

## Known Thresholds & Business Logic

| Logic | Value | Implementation | Test Coverage |
|-------|-------|---------------|---------------|
| TOC sidebar threshold | `PHPMAN_TOC_THRESHOLD` (80) | `showHeader()` → `<div id="toc-sidebar">` | E2E U11 |
| TOC requires sections | >1 L1 or L1+L2 children | `buildToc()` | E2E U11 |
| Allowed modes | 9: man/perldoc/info/search/copyright/mcp/pydoc/ri/.well-known | `normalizeMode()` | Unit normalizeMode |
| Section validation | `/^[A-Za-z0-9_]+$/` | `normalizeSection()` | Unit normalizeSection |
| Mobile breakpoint | 1024px | `$MOBILE_CSS` / `@media (max-width:1024px)` | E2E U09, Regression #8 |

## Test Levels

| Level | Directory | Target | Run When |
|-------|-----------|--------|----------|
| **Unit** | `unit/` | Individual functions (pure logic, no I/O) | Every code change |
| **Integration** | `integration/` | Format pipelines (input → output) | Formatter changes |
| **E2E** | `e2e/` | Full request flows against production | Every deploy |
| **Regression** | `phpman-regression.sh` | External validators (W3C, UTF-8, etc.) | Every deploy |

## Persona-Based Test Coverage

### 👤 Human User (browser)
- Search for a command → read man page → navigate TOC → switch formats
- Tests: `e2e/test_user_scenarios.php`

### 🤖 AI Agent (MCP/JSON client)
- Call `cli_help` → parse flags → follow `see_also` → call `cli_search`
- Tests: `e2e/test_agent_scenarios.php`

### 🕷️ Web Spider/Crawler (HTTP GET)
- Discover via sitemap → follow links → index meta tags → respect robots
- Tests: `e2e/test_spider_scenarios.php`

## Test Mapping to Requirements

| Requirement | Implementation | Test File(s) |
|-------------|---------------|--------------|
| URL routing & modes | `normalizeMode/Parameter/Section` + main `switch($mode)` | `unit/test_normalize.php` |
| Man page rendering | `formatManPerlDoc()` pipeline | `integration/test_formatter_html.php` |
| Markdown output | `formatManPerlDocToMarkdown()` | `integration/test_formatter_markdown.php` |
| JSON output | `formatToJSON()` | `integration/test_formatter_json.php` |
| MCP protocol | `handleMcp()` + `formatForOutput('mcp')` | `integration/test_formatter_mcp.php`, `TEST_MCP.md` |
| TLDR generation | `fetchOfficialTldr()` + `formatTldr()` | `integration/test_formatter_tldr.php` |
| Heading detection | `detectHeadingType()` | `unit/test_detect.php` |
| Flag parsing | `extractFlagsFromSections()` | `unit/test_parseFlag.php` |
| Overstrike cleaning | `cleanTerminalOutput()` | `unit/test_overstrike.php` |
| Security | `h()` / `escapeshellarg()` / MCP auth | `e2e/test_security.php` |
| SEO/robots/spider | `showHeader()` meta + canonical + schema | `e2e/test_spider_scenarios.php` |
| Validation | W3C XHTML + CSS validators | `test/phpman-regression.sh` |
| XHTML compliance | XHTML 1.0 Transitional markup | `test/phpman-regression.sh` #1 |

## Running Tests

```bash
# Unit + Integration (no network, instant)
# 133 tests
php test/run_all.php

# E2E tests (requires network)
# ~72 tests across 4 files
php test/e2e/test_user_scenarios.php
php test/e2e/test_agent_scenarios.php
php test/e2e/test_spider_scenarios.php
php test/e2e/test_security.php

# Full deploy validation (network required, 10 checks)
bash test/phpman-regression.sh
```

## Test Framework

All tests use a minimal built-in assertion framework (no PHPUnit dependency):
- `assert_equals($expected, $actual, $message)` — strict `===` comparison
- `assert_contains($needle, $haystack, $message)` — substring check
- `assert_match($pattern, $string, $message)` — regex match
- Exit code 0 = all pass, 1 = any failure
- Output: `✅ PASS` / `❌ FAIL` per assertion, summary at end
