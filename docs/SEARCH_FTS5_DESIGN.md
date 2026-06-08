# phpMan FTS5 Full-Text Search Design

> **Status:** v4 — FTS5 offline index priority + command-line cascade fallback + single-query 3-source aggregation
> **Date:** 2026-06-08 (v3.6.3)
> **Environment:** PHP 7.2+ / SQLite 3.53.1 · `PRAGMA compile_options` includes `ENABLE_FTS5`
> **Related:** [CACHE_DESIGN.md](CACHE_DESIGN.md) — page content cache architecture

---

## 1. Search Architecture Overview

### 1.1 Two-Level Search Strategy: FTS5 First, Command-Line Fallback

```
User search request
    ↓
┌──────────────────────────────────────────────────────┐
│ Level 1: FTS5 Offline Index (fast, cached)            │
│                                                        │
│  getSearchPage() single FTS5 query covers 3 sources:   │
│    search_fts MATCH 'json*'                            │
│      → section='3pm'  → $lines (man/perldoc)           │
│      → section='pydoc' → $pydocFtsLines                │
│      → section='ri'    → $riFtsLines                   │
│                                                        │
│  Only indexes title (name) + description (summary)     │
│  Body text NOT indexed — avoid index bloat              │
└───────────────┬──────────────────────────────────────┘
                │ Source has no results?
                ├── man empty ↓
                │   exec("apropos ...") + indexAproposLines()
                ├── pydoc empty ↓
                │   getPydocSearchPage() → pydoc3 -k
                └── ri empty ↓
                    getRiSearchPage() → ri $parameter
```

### 1.2 Single FTS5 Query, 3-Source Aggregation

Since v3.6.1, `getSearchPage()` FTS5 query **no longer excludes** pydoc/ri sections. One query retrieves all three sources, routed by section:

```
getSearchPage($parameter)
    ↓ Single FTS5 query
    $lines         ← section IN ('1','2','3','4','5','6','7','8','9','n','3pm','3perl'...)
    $pydocFtsLines ← section = 'pydoc'
    $riFtsLines    ← section = 'ri'
    ↓ Fallback only when source has no FTS5 hits
    man empty   → apropos + indexAproposLines()
    pydoc empty → getPydocSearchPage() (pydoc3 -k)
    ri empty    → getRiSearchPage() (ri command)
```

### 1.3 Search Result Caching

The entire search process is wrapped in `cacheOrExecute('search', ...)`. Results are written to the `cache` table:

| Field | Value |
|------|-----|
| `mode` | `'search'` |
| `format` | Requested output format |
| `content` | gzip(aggregated complete result) |
| `ttl` | `3600` (1 hour) |

Repeated searches for the same keyword hit the cache directly — no FTS5 or command-line call.

---

## 2. FTS5 Offline Index Build

### 2.1 Index Sources

FTS5 index is built offline via `--build-index` from three system commands:

| Source | Command | Data | section value | source value |
|------|------|------|-----------|-----------|
| man pages | `apropos -s N .` | name + one-line summary | `1`-`9`, `n` | `man` |
| Python 3 modules | `pydoc3 modules` | module name | `pydoc` | `pydoc` |
| Ruby classes/modules | `ri -l` | class name | `ri` | `ri` |

### 2.2 Index Content: Title + Description Only

```sql
CREATE VIRTUAL TABLE search_fts USING fts5(
    name,               -- expanded name: "JSON::Ext::Parser JSON Ext Parser json ext parser json::ext::parser"
    section,            -- "1", "3pm", "pydoc", "ri"
    description,        -- one-line summary: "Python 3 module", "Ruby class/module"
    body,               -- empty (body text NOT indexed)
    tokenize='unicode61 tokenchars ''-:''',
    prefix='1,2,3'
);
```

**Why only title+description, not body text:**

- `apropos -s N .` traverses 9,000+ entries in ~3 seconds. Indexing body text requires forking `man` per entry (~0.15s/page × 13,000 ≈ 30 minutes)
- Shared hosting environments trigger `fork: retry: Resource temporarily unavailable` when forking many `man` processes
- Title + description is sufficient for the vast majority of search scenarios

### 2.3 Build Steps

```
1. DROP + CREATE search_fts (much faster than DELETE FROM)
2. DELETE FROM search_index_meta

3. Traverse man sections 1-9, n:
   exec("apropos -s N .") → parse "name (section) — description"
   INSERT INTO search_fts (expandNameForFts(name), section, description, '')
   INSERT INTO search_index_meta (name, section, 'man')

4. pydoc3 modules:
   exec("pydoc3 modules") → split multi-column layout on 2+ spaces
   INSERT INTO search_fts (expandNameForFts(module), 'pydoc', 'Python 3 module', '')
   INSERT INTO search_index_meta (module, 'pydoc', 'pydoc')

5. ri classes/modules:
   exec("ri -l") → one class name per line
   INSERT INTO search_fts (expandNameForFts(class), 'ri', 'Ruby class/module', '')
   INSERT INTO search_index_meta (class, 'ri', 'ri')
```

All inserts are guarded by `search_index_meta` dedup check: `INSERT OR IGNORE INTO meta` → `changes() === 1` → only then INSERT INTO search_fts.

### 2.4 Trigger Methods

```
CLI:  php phpMan.php --build-index
Cron: 0 3 * * * /usr/bin/php /path/to/phpMan.php --build-index-cron
URL:  GET /phpMan.php?build-index (local requests only)
```

### 2.5 Data Volume

| Source | Entries (production) | Index time |
|------|---------------------|---------|
| man pages | 9,630 | ~5s |
| pydoc3 modules | 341 | ~1s |
| ri classes | 3,878 | ~2s |
| **Total** | **13,849** | **~10s** |

---

## 3. Name Column Tokenization Strategy

### 3.1 expandNameForFts() — Index-Time Expansion

To enable FTS5's `unicode61` tokenizer to match both exact names and component words, the `name` column stores an expanded multi-token string:

```
Original name          → Stored in search_fts.name
─────────────────────────────────────────────────────────────────────
git-commit            → git-commit git commit git-commit
File::Find            → File::Find File Find file find file::find
JSON::Ext::Parser     → JSON::Ext::Parser JSON Ext Parser json ext parser json::ext::parser
json.decoder          → json.decoder json decoder json.decoder
ls                    → ls ls
```

Expansion rules:
1. **Original name**: always preserved
2. **Hyphen expansion**: `git-commit` → append `git commit`
3. **Double-colon expansion**: `File::Find` → append `File Find` + `file find` (lowercase) + `file::find` (lowercase original)
4. **Dot expansion**: `json.decoder` → append `json decoder`
5. **Lowercase copy**: always append `mb_strtolower(name)` for case-insensitive matching

### 3.2 FTS5 Tokenizer Configuration

```sql
tokenchars '-:'
```

Makes `-` and `:` part of token characters rather than separators:

| Original | Default unicode61 | With `tokenchars='-:'` |
|------|---------------|---------------------|
| `git-commit` | `git` `commit` | `git-commit` |
| `File::Find` | `File` `Find` | `File::Find` |
| `--verbose` | `verbose` | `--verbose` |

### 3.3 buildFtsQuery() — Preserve Special Characters at Search Time

When users search for `git-commit` or `File::Find`, `buildFtsQuery()` preserves `-`, `:`, `.`, `_`:

```php
// Default: prefix-match each term
$parts[] = '"' . $t . '"*';
// "json" → '"json"*' — matches json, JSON::Ext, json.decoder etc.
// "git-commit" → '"git-commit"*' — exact match on git-commit
// "Parser" → '"Parser"*' — matches JSON::Ext::Parser expansion
```

### 3.4 Case Insensitivity

FTS5 `unicode61` tokenizer lowercases tokens on storage by default. Combined with `expandNameForFts()` lowercase copies:

- Search `json` → matches `json`, `JSON::PP` (expansion contains `json`), `Psych::JSON` (expansion contains `json`)
- Search `JSON` → FTS5 auto-lowercases to `json` → same as above
- Search `Parser` → FTS5 auto-lowercases to `parser` → matches `JSON::Ext::Parser` (expansion contains `parser`)

---

## 4. Search Execution Flow (Detailed)

### 4.1 getSearchPage() — Single FTS5 Query Covers 3 Sources

```
getSearchPage($parameter, $section, $format)
    ↓
1. Build FTS5 query: buildFtsQuery($parameter)
    ↓
2. FTS5 available? (search_index_meta has data)
    ├── YES → SELECT FROM search_fts WHERE MATCH :q
    │         ORDER BY rank LIMIT 300    ← includes pydoc/ri sections
    │         Route by section:
    │           section='pydoc' → $pydocFtsLines
    │           section='ri'    → $riFtsLines
    │           other           → $lines (man pages)
    └── NO  → empty, go to fallback
    ↓
3. $lines empty? → exec("apropos ...") + indexAproposLines()
    ↓
4. Output formatting:
    json/mcp → $lines→results, $pydocFtsLines→pydoc_results, $riFtsLines→ri_results
    html     → <h2>apropos</h2> + $lines
              + <h2>Python 3</h2> + $pydocFtsLines (or getPydocSearchPage)
              + <h2>Ruby (ri)</h2> + $riFtsLines (or getRiSearchPage)
```

### 4.2 Search Cascade — pydoc3 and ri Command-Line Fallback

The FTS5 query already routes all 3 sources inside `getSearchPage()`. Cascade only fires when FTS5 has no hits for a source:

```
search case (wrapped in cacheOrExecute):
    ↓
getSearchPage()       → one FTS5 query
    → $lines (man), $pydocFtsLines, $riFtsLines
    ↓
$pydocFtsLines non-empty? → use FTS5 pydoc results
    └── empty → getPydocSearchPage() → pydoc3 -k
    ↓
$riFtsLines non-empty?    → use FTS5 ri results
    └── empty → getRiSearchPage() → ri command
```

### 4.3 getRiSearchPage() — "not found" Filtering

ri command fuzzy match results are sometimes not actual search results and need filtering:

```php
// These first-line patterns indicate no results; return empty
if (preg_match('/^Nothing known about/i', $first_line)) return "";
if (preg_match('/^\.json not found/i', $first_line)) return ""; // ri special response for lowercase names
```

### 4.4 getSearchPage() JSON Output — pydoc/ri Merged from FTS5

After FTS5 query routes by section, JSON output merges from `$pydocFtsLines` / `$riFtsLines`:

```json
{
  "results": [...],          // man/perldoc entries
  "pydoc_results": [...],   // Python 3 module entries
  "ri_results": [...]       // Ruby class/module entries
}
```

The search cascade aggregates inside the `cacheOrExecute` closure, ensuring cached results also include all three sources.

### 4.5 Section-Only Listing `(1)` `(3pm)` `(n)` — Must Use apropos

When the search parameter is section-only format (e.g., `(1)`, `(3pm)`, `(n)`), `getSearchPage()` detects `$sectionOnly = true` and **skips FTS5, using `apropos -s <section> .` for full enumeration**.

**Why apropos, not FTS5:**

1. **FTS5 coverage gap**: `apropos -s 1 .` returns 2,872 unique names; FTS5 section=`1` has only 2,659. The missing 213 are sub-section entries (1p/1ssl/1mh) that FTS5 stores under their respective sub-sections (e.g., section=`1p`)
2. **No pydoc/ri mixing**: Section listings request all commands in a specific man section; pydoc modules (section=`pydoc`) and ri classes (section=`ri`) must not appear
3. **FTS5 is designed for full-text search**: enumerating by section is not FTS5's design goal; FTS5 rank ordering is meaningless for full listings

**Performance**: apropos depends on the whatis/mandb database. First request may take 15-30s. Results are cached via `cacheOrExecute` to SQLite (~500KB); subsequent requests ~4s. The bottleneck is apropos database quality, not the phpMan cache layer.

---

## 5. Data Model

### 5.1 search_fts — FTS5 Full-Text Index Table

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(
    name,               -- expanded name
    section,            -- "1"-"9","n","pydoc","ri"
    description,        -- one-line summary
    body,               -- empty (body text NOT indexed)
    tokenize='unicode61 tokenchars ''-:''',
    prefix='1,2,3'
);
```

### 5.2 search_index_meta — Index Metadata

```sql
CREATE TABLE IF NOT EXISTS search_index_meta (
    name        TEXT NOT NULL,
    section     TEXT NOT NULL DEFAULT '',
    source      TEXT NOT NULL DEFAULT 'man',  -- man|pydoc|ri
    body_len    INTEGER NOT NULL DEFAULT 0,
    hits        INTEGER NOT NULL DEFAULT 0,
    last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE(name, section, source)
);
```

### 5.3 FTS5 Availability Detection

`getSearchPage()` checks total `search_index_meta` rows (any source), using FTS5 only when data exists:

```php
$totalIndexed = $db->querySingle("SELECT COUNT(*) FROM search_index_meta");
if ($totalIndexed > 0) { /* use FTS5 */ }
```

---

## 6. Search Ranking

### 6.1 5-Level Ranking

| Level | Factor | Weight | Description |
|------|------|------|------|
| **L1** | Exact name match | Highest | `name == query` |
| **L2** | Name prefix match | High | `name LIKE 'query%'` |
| **L3** | BM25 relevance | Medium | FTS5 built-in rank |
| **L4** | Section priority | Medium-low | 1 > 8 > 3 > 5 > 7 > 4 > 6 > 9 |
| **L5** | Hit count | Low | More views → higher rank |

---

## 7. Search Result Caching

### 7.1 Cache Strategy

| Scenario | Strategy |
|------|------|
| Normal search | `cacheOrExecute('search', ...)` cached 1h |
| FTS5 hit | Cache aggregated result (with pydoc_results / ri_results) |
| FTS5 miss → apropos | Cache apropos result; apropos results backfill FTS5 |
| Index rebuild | `DELETE FROM cache WHERE mode='search'` |
| Schema upgrade | Full clear |

### 7.2 Search Pages Not in FTS5 Index

```
mode='search' pages:
  ✅ Cached in cache table
  ❌ Not written to search_fts index
  ❌ Not triggering hits counting
  ❌ Output <meta name="robots" content="noindex, follow">
```

### 7.3 Incremental Indexing

Apropos fallback results are backfilled into FTS5 via `indexAproposLines()`:

```php
// In getSearchPage(), after apropos fallback
if (!empty($lines) && !$sectionOnly) {
    indexAproposLines($lines); // backfill FTS5 — next search hits FTS5 directly
}
```

---

## 8. Fallback Mechanism Overview

```
Search request
    ↓
cache hit? → YES → return cached result directly
    ↓ NO
FTS5 single query search_fts (man + pydoc + ri)
    → $lines (man), $pydocFtsLines, $riFtsLines
    ↓
$lines empty? → exec("apropos ...") + indexAproposLines()
$pydocFtsLines empty? → getPydocSearchPage() (pydoc3 -k)
$riFtsLines empty?    → getRiSearchPage() (ri command)
    ↓
Aggregate 3-source results → cache → return
```

---

## 9. Relationship with Other Search Sources

### 9.1 FTS5 3-Source Index Coverage

```
search_fts index:
  ├── man pages    — name + section + description  (via apropos -s N .)
  ├── pydoc3       — name + 'pydoc' + description  (via pydoc3 modules)
  └── ri           — name + 'ri' + description     (via ri -l)
```

### 9.2 Search Priority

| Priority | Data source | Trigger condition |
|--------|--------|---------|
| 1 | FTS5 offline index (3-source single query) | Index has data |
| 2 | apropos command | FTS5 has no man data or no hits |
| 3 | pydoc3 -k command | FTS5 has no pydoc data |
| 4 | ri command | FTS5 has no ri data |

### 9.3 Why Single FTS5 Query Beats Per-Source Query

- **Performance**: one SQL query vs three SQL queries + PHP serialization/deserialization
- **Simplicity**: routing done inside `getSearchPage()`; cascade layer doesn't need `searchFtsBySource()`
- **Consistency**: FTS5 rank ordering unified across sources, no relevance loss from split queries

---

## 10. Index Rebuild

### 10.1 Rebuild Commands

```bash
# CLI full rebuild
php phpMan.php --build-index

# Cron mode (with timestamp)
php phpMan.php --build-index-cron

# Show help
php phpMan.php --help
```

Cron example (daily at 3 AM):
```
0 3 * * * /usr/bin/php /path/to/phpMan.php --build-index-cron
```

The rebuild uses `CACHE_DIR` configuration to locate the database file; no extra arguments needed.

---

## 11. Files and Deploy

### 11.1 Deploy Steps

```
1. Update phpMan.php
2. scp to server
3. Run php phpMan.php --build-index (rebuild FTS5 index with pydoc/ri)
4. Verify: curl /phpMan.php/search/json/json | python3 -m json.tool
5. Check ri_results / pydoc_results
```

### 11.2 Related Files

| File | Changes |
|------|------|
| `phpMan.php` | `getSearchPage()` single FTS5 query covering 3 sources; `rebuildSearchIndex()` with man+pydoc+ri + meta-guard dedup; `expandNameForFts()` case+dot+colon expansion; `getRiSearchPage()` `.xxx not found` filtering; `--help` CLI |
| `docs/SEARCH_FTS5_DESIGN.md` | This document |
| `test/unit/test_search_fts.php` | Updated expandNameForFts expectations |
