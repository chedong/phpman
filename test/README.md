# phpMan Test Architecture

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
# Unit tests (no network, instant)
php test/unit/test_detect.php
php test/unit/test_normalize.php
php test/unit/test_parseFlag.php
php test/unit/test_overstrike.php

# Integration tests (no network, loads phpMan.php)
php test/integration/test_formatter_html.php
php test/integration/test_formatter_markdown.php
php test/integration/test_formatter_json.php
php test/integration/test_formatter_mcp.php
php test/integration/test_formatter_tldr.php

# E2E tests (requires network, hits production)
php test/e2e/test_user_scenarios.php
php test/e2e/test_agent_scenarios.php
php test/e2e/test_spider_scenarios.php
php test/e2e/test_security.php

# Run all (unit + integration, no network)
php test/run_all.php

# Full deploy validation (network required)
bash phpman-regression.sh
```

## Test Framework

All tests use a minimal built-in assertion framework (no PHPUnit dependency):
- `assert_equals($expected, $actual, $message)` — strict `===` comparison
- `assert_contains($needle, $haystack, $message)` — substring check
- `assert_match($pattern, $string, $message)` — regex match
- Exit code 0 = all pass, 1 = any failure
- Output: `✅ PASS` / `❌ FAIL` per assertion, summary at end
