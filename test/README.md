# phpMan Test Architecture

## Known Thresholds & Business Logic

| Logic | Value | Code Location | Test Coverage |
|-------|-------|---------------|---------------|
| TOC sidebar line threshold | >80 raw lines | phpMan.php:660,667 | E2E U11 |
| TOC requires sections | >1 L1 or L1+L2 children | phpMan.php:680-682 | E2E U11 |
| Allowed modes | 7: man/perldoc/info/search/copyright/mcp/tldr | phpMan.php:303-311 | Unit normalizeMode |
| Section validation | `/^[A-Za-z0-9_]+$/` | phpMan.php:326 | Unit normalizeSection |
| Mobile breakpoint | 1024px | phpMan.php:844 | E2E U09, Regression #8 |
| Color contrast (bold) | #8B5E00 on #EEEEEE (4.7:1) | phpMan.php:826 | Regression #9 |
| Color contrast (underline) | #006600 on #EEEEEE (6.2:1) | phpMan.php:827 | Regression #9 |
| Flag description max length | 80 chars for TOC | phpMan.php:169 | Unit detectHeadingType |
| Heading text max length | 80 chars | phpMan.php:197 | Unit detectHeadingType |

## Test Levels

| Level | Directory | Target | Run When |
|-------|-----------|--------|----------|
| **Unit** | `unit/` | Individual functions (pure logic, no I/O) | Every code change |
| **Integration** | `integration/` | Format pipelines (input → output) | Formatter changes |
| **E2E** | `e2e/` | Full request flows against production | Every deploy |
| **Regression** | `../phpman-regression.sh` | External validators (W3C, UTF-8, etc.) | Every deploy |

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

| Requirement | Design Doc | Test File(s) |
|-------------|-----------|--------------|
| URL routing & modes | SKILL.md §URL Routing | `unit/test_normalize.php` |
| Man page rendering | SKILL.md §Architecture | `integration/test_formatter_html.php` |
| Markdown output | SKILL.md §Format Negotiation | `integration/test_formatter_markdown.php` |
| JSON output | SKILL.md §JSON Output Format | `integration/test_formatter_json.php` |
| MCP protocol | SKILL.md §MCP Protocol | `integration/test_formatter_mcp.php`, `TEST_MCP.md` |
| TLDR generation | SKILL.md §formatTldr | `integration/test_formatter_tldr.php` |
| Heading detection | SKILL.md §detectHeadingType | `unit/test_detect.php` |
| Flag parsing | SKILL.md §parseFlagJSON | `unit/test_parseFlag.php` |
| Overstrike cleaning | SKILL.md §Overstrike pitfalls | `unit/test_overstrike.php` |
| Security | TEST_CASES.md §Security | `e2e/test_security.php` |
| SEO/GEO | SKILL.md §SEO & GEO | `e2e/test_spider_scenarios.php` |
| Accessibility | SKILL.md §PageSpeed | `phpman-regression.sh` |
| XHTML compliance | SKILL.md §XHTML 1.0 | `phpman-regression.sh` #1 |

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
bash phpman-regression.sh
```

## Test Framework

All tests use a minimal built-in assertion framework (no PHPUnit dependency):
- `assert_equals($expected, $actual, $message)` — strict `===` comparison
- `assert_contains($needle, $haystack, $message)` — substring check
- `assert_match($pattern, $string, $message)` — regex match
- Exit code 0 = all pass, 1 = any failure
- Output: `✅ PASS` / `❌ FAIL` per assertion, summary at end
