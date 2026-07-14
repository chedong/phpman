# Site Analytics Service — Standalone

> **Status:** Design (not yet implemented)
> **Date:** 2026-07-14
> **Repository:** Separate project — `site-stats` (TBD)
> **Scope:** v4.10 / standalone

## 1. Problem & Scope

### 1.1 What's wrong with the previous design

The earlier draft (v4.10) coupled GA analytics **directly into phpMan's MCP server** as a `ga_report` tool. This is wrong for two reasons:

1. **phpMan is a documentation server, not an analytics service.** Adding an analytics tool blurs the project boundary and bakes GA4-specific logic into phpMan's source tree.
2. **One project = one website = one lens.** A real operator (you) runs multiple sites — `chedong.com/blog`, `chedong.com/phpMan.php`, the MT-migration `myblog` project, plus whatever comes next. Coupling analytics to phpMan means rebuilding the same thing 5 times.

### 1.2 What this design is

A **standalone site analytics service** that:

- Reads GA4 data for **any number of properties** (one per site)
- Exposes **categorized reports** (栏目) — traffic, content, users, releases, conversions, anomalies
- Speaks **two interfaces** — MCP (for agents) and HTTP/JSON (for humans/dashboards)
- Is **phpMan-agnostic** — phpMan is one consumer among many, not the primary user
- phpMan may OPTIONALLY call this service to show "popular man pages this week" widgets, but the analytics service has no knowledge of phpMan

### 1.3 What this is NOT

- Not a phpMan module
- Not bundled with phpMan
- Not deployed via `make release` to chedong.com
- Not GA-specific long-term — the data source is pluggable (GA4 today, plausible/matomo/umami later)

## 2. Architecture

```
                       ┌──────────────────────────────┐
                       │   site-stats service         │
                       │   (standalone repo, own      │
                       │   deploy, own scaling)       │
                       │                              │
   AI Agent ───MCP────►│   mcp_server.py/.php         │
   Dashboard ──HTTP───►│   http_api.php               │
   phpMan ──HTTP/MCP──►│   ↕                          │
   myblog  ──HTTP/MCP──►│   ┌──────────────────────┐  │
   anything ──HTTP/MCP─►│   │ DataSource interface │  │
                       │   │  - GA4DataSource      │  │
                       │   │  - PlausibleDataSource│  │
                       │   │  - MatomoDataSource   │  │
                       │   └──────────────────────┘  │
                       │   ↕                          │
                       │   ┌──────────────────────┐  │
                       │   │ Report generators    │  │
                       │   │  - traffic           │  │
                       │   │  - content           │  │
                       │   │  - users             │  │
                       │   │  - releases          │  │
                       │   │  - anomalies         │  │
                       │   └──────────────────────┘  │
                       │   ↕                          │
                       │   SQLite + filesystem cache  │
                       └──────────────────────────────┘
                                       ↕
                       ┌──────────────────────────────┐
                       │  Google Analytics 4 Data API │
                       │  (analytics.readonly scope)  │
                       └──────────────────────────────┘
```

**Key design points**:

- **One process, many sites** — sites are config entries, not separate deployments
- **Pluggable data source** — GA4 is the first; can swap to self-hosted (Plausible/Matomo) without changing the service surface
- **Reports are pre-computed and cached** — agents don't pay the GA quota, they pay the local cache hit
- **No web UI in v1** — MCP + HTTP/JSON only. Visual dashboards come later (or someone else's problem)

## 3. Sites & Data Model

### 3.1 Site registry (`config/sites.yaml` or similar)

```yaml
sites:
  - id: chedong-blog
    name: "chedong.com/blog (MT)"
    ga_property_id: "123456789"   # numeric GA4 property ID
    timezone: "America/Los_Angeles"
    domain_filter: "chedong.com"  # optional, restrict to subpath

  - id: chedong-phpman
    name: "chedong.com/phpMan.php"
    ga_property_id: "987654321"
    timezone: "America/Los_Angeles"
    domain_filter: "chedong.com/phpMan.php"

  - id: myblog-markdown
    name: "myblog (MT→markdown migration staging)"
    ga_property_id: ""             # empty = not wired to GA yet
    timezone: "America/Los_Angeles"
```

### 3.2 Report categories (栏目)

Each category is a self-contained generator:

| Category | Purpose | Common metrics | Common dimensions |
|---|---|---|---|
| `traffic` | Volume over time | sessions, totalUsers, screenPageViews | date, sessionDefaultChannelGroup |
| `content` | What pages / sections perform | screenPageViews, engagementRate, avgSessionDuration | pagePath, pageTitle |
| `users` | Who visits | totalUsers, newUsers, returningUsers, userAgeBracket | country, deviceCategory, browser |
| `sources` | Where they come from | sessions, engagementRate | sessionSource, sessionMedium, sessionDefaultChannelGroup |
| `releases` | Before/after a deploy/event | sessions, totalUsers, screenPageViews | date (split by event boundary) |
| `anomalies` | Outlier days, traffic spikes | z-score on daily sessions | date, pagePath |
| `search` | Internal site search queries | searchUniques, events | searchTerm, pagePath |

Each generator is a small function that takes `(siteId, params)` and returns a structured result. Adding a new category = adding a new file.

## 4. Interfaces

### 4.1 HTTP API (v1)

Base URL: `https://stats.example.com/api/v1/` (or wherever deployed)

```
GET /sites
    → list of registered sites with their GA property IDs

GET /sites/{siteId}/reports/{category}
    ?startDate=YYYY-MM-DD|relative
    &endDate=YYYY-MM-DD|relative
    &metrics=sessions,totalUsers
    &dimensions=pagePath
    &orderBy=-sessions
    &limit=10
    → { site, category, dateRange, rows[], rowCount, cached, cacheAge }

POST /sites/{siteId}/reports/{category}/query
    Content-Type: application/json
    Body: { dateRanges, metrics, dimensions, orderBys, limit, filters }
    → same shape as GET
```

### 4.2 MCP tools

```python
@mcp.tool()
def site_list() -> list[dict]:
    """List all registered analytics sites."""

@mcp.tool()
def site_report(site_id: str, category: str, start_date: str, end_date: str,
                metrics: list[str] | None = None,
                dimensions: list[str] | None = None,
                order_by: str = "",
                limit: int = 10) -> dict:
    """Get a categorized report for one site. Categories: traffic, content,
    users, sources, releases, anomalies, search. Dates can be 'YYYY-MM-DD'
    or relative: 'today', 'yesterday', '7daysAgo', '30daysAgo', '90daysAgo'."""

@mcp.tool()
def site_compare(site_id: str, category: str,
                 period_a: tuple[str, str],
                 period_b: tuple[str, str]) -> dict:
    """Compare two periods (e.g. this week vs last week). Returns per-row
    deltas and percent change."""

@mcp.tool()
def site_release_impact(site_id: str, event_time: str, window_hours: int = 24) -> dict:
    """Compare traffic for `window_hours` before vs after `event_time` (ISO 8601).
    Used to measure deploy impact."""
```

### 4.3 Response shape (uniform across categories)

```json
{
  "site": {"id": "chedong-phpman", "name": "chedong.com/phpMan.php"},
  "category": "content",
  "dateRange": {"startDate": "30daysAgo", "endDate": "today"},
  "query": {"metrics": ["screenPageViews"], "dimensions": ["pagePath"]},
  "rows": [
    {"pagePath": "/phpMan.php/man/ls", "screenPageViews": 1342, "engagementRate": 0.78},
    ...
  ],
  "rowCount": 10,
  "totals": {"screenPageViews": 18729, "engagementRate": 0.65},
  "cached": true,
  "cacheAge": 234,
  "generatedAt": "2026-07-14T14:13:00Z"
}
```

`totals` is the aggregate over the full date range (not just top-N), so agents can answer "what's the sum of all sessions".

## 5. Caching Strategy

| Layer | TTL | Why |
|---|---|---|
| GA access token | 1h | GA OAuth token TTL |
| Report result | 10 min | Default. Chatty agents don't burn quota |
| Daily aggregate | 24h | Once a day is "done", no need to re-query |
| Monthly aggregate | 30 days | Stable for trend lines |

Cache key: `sha1(site_id | category | query_params_normalized)`.

Storage: filesystem JSON files under `~/.site-stats/cache/<layer>/<key>.json` (no new DB needed for v1).

## 6. Implementation Sketch

### 6.1 File layout

```
site-stats/
├── README.md
├── config/
│   └── sites.yaml
├── src/
│   ├── server.py              # FastAPI + uvicorn
│   ├── mcp_server.py          # MCP tool registration
│   ├── data_sources/
│   │   ├── base.py            # DataSource ABC
│   │   ├── ga4.py             # GA4 implementation
│   │   └── plausible.py       # (future)
│   ├── reports/
│   │   ├── traffic.py
│   │   ├── content.py
│   │   ├── users.py
│   │   ├── sources.py
│   │   ├── releases.py
│   │   ├── anomalies.py
│   │   └── search.py
│   ├── auth/
│   │   ├── ga_jwt.py          # ~80 lines pure JWT
│   │   └── token_cache.py
│   └── cache/
│       └── filesystem.py
├── tests/
│   ├── unit/
│   └── integration/
└── deploy/
    └── systemd unit file
```

### 6.2 Technology choice: Python, not PHP

This service is **not phpMan**. Choosing Python here is deliberate:

- `google-analytics-data` has an official Python client (no manual JWT)
- MCP SDK is more mature in Python (the Anthropic reference impl is Python)
- This service is a thin orchestration layer; PHP would be a poor fit
- Operators already have Python for the LLM tools (myblog migration); one more service in the same language reduces context switching

### 6.3 What stays out of v1

- Web UI (humans can curl the HTTP API or use a downstream dashboard)
- Alerting / anomaly detection notifications (the `anomalies` report is computed on demand)
- Cross-site comparison
- Custom dimensions/metrics per-site
- Real-time (intraday) reports — GA4 realtime API has different auth, defer

## 7. phpMan Integration (optional consumer)

phpMan **may** query this service for a "popular man pages" widget on its index page. That's a 5-line fetch:

```php
// In phpMan's index renderer (pseudo-code)
$stats = @file_get_contents(
    "https://stats.example.com/api/v1/sites/chedong-phpman/reports/content"
    . "?startDate=7daysAgo&endDate=today"
    . "&metrics=screenPageViews&dimensions=pagePath&limit=10"
    . "&filter=pagePath%3D~%2FphpMan.php%2Fman%2F"
);
if ($stats !== false) {
    $rows = json_decode($stats, true)['rows'] ?? [];
    renderPopularPagesWidget($rows);
}
```

Notes:
- The `@` suppresses the warning; if the service is down, phpMan silently skips the widget
- phpMan does NOT bundle any GA client library
- phpMan does NOT know about the service's existence beyond this one HTTP call
- If the service is removed, phpMan keeps working unchanged

## 8. Security

| Concern | Mitigation |
|---|---|
| Service account key leak | Filesystem 0600, outside any project repo, not in any CI env var |
| Privilege escalation | SA role = **Viewer**; service exposes only aggregate data |
| DoS via quota burn | 10-min cache + per-IP rate limit + per-tool rate limit in MCP middleware |
| PII / GDPR | GA4 returns aggregate data; service never exposes user_id-level data; PII columns are filtered out at the report layer |
| Key rotation | Replace JSON file, wait 1h for token TTL, old key dead |
| MCP auth | Service-to-service auth: if exposed publicly, put behind a bearer token + IP allowlist; if internal-only, bind to localhost or a private network |

## 9. Operator Setup

```bash
# 1. Clone and configure
git clone <site-stats-repo> ~/code/site-stats
cd ~/code/site-stats
cp config/sites.example.yaml config/sites.yaml
$EDITOR config/sites.yaml          # add sites

# 2. Drop service account JSON
mkdir -p ~/.site-stats
cp ~/Downloads/phpman-analytics-reader-*.json ~/.site-stats/ga-key.json
chmod 600 ~/.site-stats/ga-key.json

# 3. Install + run
python -m venv .venv
source .venv/bin/activate
pip install -e .
uvicorn site_stats.server:app --host 127.0.0.1 --port 9090

# 4. Verify
curl http://127.0.0.1:9090/api/v1/sites
curl 'http://127.0.0.1:9090/api/v1/sites/chedong-phpman/reports/traffic?startDate=7daysAgo&endDate=today'
```

## 10. Why Not Just Put It in phpMan

| Reason | Detail |
|---|---|
| Wrong abstraction | phpMan is a doc server, not an analytics service |
| Multi-site | Analytics serves blog, phpman, myblog, future sites — phpMan only knows about itself |
| Tech mismatch | GA4 SDK is Python-first; MCP tooling more mature in Python; PHP would be pain |
| Independent scaling | Analytics load doesn't track with doc load; can be redeployed without touching phpMan |
| Independent failure domain | Analytics outage should not take down doc lookups |
| Different release cadence | Analytics reports change weekly; phpMan docs change per release |
| Different operators | May want to share analytics with collaborators who don't run phpMan |

## 11. Future Extensions

- **Custom events** — wire to GA4 custom events (e.g., MT import progress, search queries, 404 spikes)
- **Anomaly alerts** — Slack/email on z-score > 3
- **Forecast** — simple ARIMA or rolling-average projection
- **Multi-property rollup** — "all chedong.com properties combined" view
- **Self-hosted backend** — drop in Plausible/Matomo when GA4 pricing or privacy concerns change

## 12. Revision History

| Date | Author | Change |
|---|---|---|
| 2026-07-14 | AtomCode | Initial standalone design (was previously scoped as phpMan-internal MCP tool, decoupled here) |
