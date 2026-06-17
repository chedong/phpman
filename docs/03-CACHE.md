# phpMan SQLite Database Schema Reference

> Status: v3.6.2 production
> Environment: PHP 8.x + SQLite 3.x / WAL mode / FTS5 enabled

---

## 1. Database Overview

Single `phpman_cache.db` file containing page cache + FTS5 full-text search index + TLDR cache.

| Table | Type | Rows (production) | Purpose |
|---|------|:---:|------|
| `cache` | Regular | ~38K | Page content cache (man/perldoc/pydoc/ri rendered output + emoji_md/emoji_html) |
| `cache_fts` | FTS5 virtual (content) | — | Cached page title index (linked to cache.id) |
| `search_fts` | FTS5 virtual (standalone) | 13,835 | Offline full-text search index (man+pydoc+ri) |
| `search_index_meta` | Regular | 13,835 | Index entry metadata (dedup, sort, stats) |
| `tldr_cache` | Regular | On demand | TLDR cheatsheet cache (7-day TTL) |
| `meta` | Regular | 3 | Schema version, index count, update time |

---

## 2. cache — Page Content Cache

### 2.1 Schema

```sql
CREATE TABLE IF NOT EXISTS cache (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    mode        TEXT NOT NULL,              -- 'man'|'perldoc'|'info'|'pydoc'|'ri'|'search'
    name        TEXT NOT NULL,              -- command/module name (e.g. 'ls', 'File::Basename')
    section     TEXT NOT NULL DEFAULT '',   -- '1'~'9','3pm','n','pydoc','ri' or ''
    format      TEXT NOT NULL,              -- 'html'|'markdown'|'json'|'mcp'|'emoji_md'|'emoji_html'
    content     BLOB,                       -- gzcompress() compressed rendered output
    content_len INTEGER NOT NULL DEFAULT 0, -- uncompressed byte size
    status      TEXT NOT NULL DEFAULT 'found'
                    CHECK(status IN ('found','not_found')),
    ttl         INTEGER NOT NULL DEFAULT 0, -- 604800=found(7d), 86400=not_found(1d)
    hits        INTEGER NOT NULL DEFAULT 0, -- cache hit count
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE(mode, name, section, format)
);

CREATE INDEX idx_cache_lookup ON cache(mode, name, section, format);
CREATE INDEX idx_cache_status  ON cache(status, updated_at);
CREATE INDEX idx_cache_hits    ON cache(hits DESC);
CREATE INDEX idx_cache_expiry  ON cache(updated_at) WHERE ttl > 0;
```

### 2.2 Notes

- **Cache key**: (mode, name, section, format) uniquely identifies a cached entry
- **Compression**: PHP `gzcompress()`, SQLite BLOB storage, ~70% average compression ratio
- **TTL**: found entries 604800s (7 days), not_found entries 86400s (1 day). Expired entries auto-deleted on `get()`
- **LLM enhancement formats** (`emoji_md`, `emoji_html`): Written with TTL=0 (permanent, no auto-expiry). Generated offline by `tools/batch_enhance.php` or online by `enhanceManPage()`. Preserved across `--build-index-cron` runs (reindex skips emoji formats to avoid wasting 48+ days of LLM work).
- **Auto-cleanup**: `cacheOrExecute()` has 1% probability of triggering `DELETE FROM cache WHERE expired`
- **search mode**: Not written to `cache_fts` index, no hits counting, emits `<meta name="robots" content="noindex">`

### 2.3 Typical Data Sizes

| mode | Average cache size | Example |
|------|:---:|------|
| man | ~11 KB (gz) | 45 KB HTML → 12 KB gz |
| perldoc | ~9 KB | 21 KB HTML → 6 KB gz |
| pydoc | ~13 KB | 35 KB HTML → 10 KB gz |
| ri | ~1.4 KB | Small pages |
| search | ~110 KB | Search result listing |

---

## 3. search_fts — FTS5 Offline Full-Text Search Index

### 3.1 Schema

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(
    name,           -- expanded name: "git-commit git commit git-commit"
    section,        -- '1'~'9','n','3pm','pydoc','ri' etc.
    description,    -- apropos one-line summary / 'Python 3 module' / 'Ruby class/module'
    body,           -- empty (body text not indexed to avoid bloat)
    tokenize='unicode61 tokenchars ''-:''',
    prefix='1,2,3'
);
```

### 3.2 Notes

- **Standalone content table**: `search_fts` is a standalone FTS5 table, entries added via `INSERT`
- **name column**: Stores `expandNameForFts()` expanded multi-token string for case-insensitive + token matching
- **body column**: Always empty. Body text is not indexed — forking `man` per entry on shared hosts triggers `fork: retry: Resource temporarily unavailable`
- **tokenchars**: `-` and `:` preserved as part of token characters (not split), `git-commit` and `File::Find` kept intact
- **Dedup**: `search_index_meta` checked via `INSERT OR IGNORE` → `changes()` before FTS INSERT

### 3.3 Rebuild

```bash
# CLI
php phpMan.php --build-index

# Cron (daily)
0 3 * * * /usr/bin/php /path/to/phpMan.php --build-index-cron
```

Rebuild flow: `DROP TABLE search_fts` → `CREATE` → `DELETE FROM search_index_meta` → `INSERT` man pages (apropos) → `INSERT` pydoc3 modules → `INSERT` ri classes. On DROP failure, falls back to `DELETE FROM` before CREATE.

---

## 4. search_index_meta — Index Metadata

### 4.1 Schema

```sql
CREATE TABLE IF NOT EXISTS search_index_meta (
    name         TEXT NOT NULL,              -- original name (unexpanded)
    section      TEXT NOT NULL DEFAULT '',   -- section number
    source       TEXT NOT NULL DEFAULT 'man',-- 'man'|'pydoc'|'ri'
    body_len     INTEGER NOT NULL DEFAULT 0, -- body length (currently 0)
    hits         INTEGER NOT NULL DEFAULT 0, -- hit counter
    last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE(name, section, source)
);
```

### 4.2 Notes

- **Dedup**: UNIQUE(name, section, source) ensures one entry per name+section+source combination
- **Incremental indexing**: `indexAproposLines()` checks `INSERT OR IGNORE` → `changes()` to avoid duplicate FTS5 INSERTs
- **Sort signal**: hits value used in search result ranking (higher hits → higher rank)
- **Data volume**: production ~13,835 rows (man 9,630 + pydoc 341 + ri 3,878)

---

## 5. cache_fts — Cached Page Title Index

### 5.1 Schema

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS cache_fts USING fts5(
    mode, name, section, title,
    tokenize='unicode61',
    content='cache',        -- external content table
    content_rowid='id'      -- references cache.id
);
```

### 5.2 Notes

- **External content FTS5**: Linked to `cache` table via `cache.id`
- **Purpose**: Title index of cached pages, usable for autocomplete/command lookup
- **Sync**: Written by `PageCache::set()` via `syncFts()` method
- **Note**: Actual search uses `search_fts`, not `cache_fts`

---

## 6. tldr_cache — TLDR Persistent Cache

### 6.1 Schema

```sql
CREATE TABLE IF NOT EXISTS tldr_cache (
    command    TEXT UNIQUE NOT NULL,         -- command name (lowercase)
    source     TEXT NOT NULL,                -- 'official'|'cheatsh'|'not_found'
    content    TEXT NOT NULL,                -- JSON serialized TLDR data
    fetched_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
);
```

### 6.2 Notes

- **TTL**: Checked on read via `(strftime('%s','now') - fetched_at) < 604800` (7 days)
- **Negative cache**: `source='not_found'` caches missing TLDR commands to avoid repeated GitHub requests
- **Data flow**: `fetchOfficialTldr()` → check SQLite → miss → tldr-pages/cheat.sh → write SQLite
- **Source priority**: tldr-pages (common/ → linux/ → osx/) → cheat.sh fallback

---

## 7. meta — Metadata

```sql
CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- Current entries:
-- schema_version     = '3'
-- search_index_count = '13849'
-- search_index_updated = '2026-06-08T...'
```

Schema version is used to detect upgrades (version mismatch triggers cache cleanup).

---

## 8. expandNameForFts() Expansion Rules

Names are expanded into multiple tokens during indexing for flexible matching:

| Original | Expanded (search_fts.name) |
|------|--------------------------|
| `ls` | `ls ls` |
| `git-commit` | `git-commit git commit git-commit` |
| `File::Find` | `File::Find File Find file find file::find` |
| `json.decoder` | `json.decoder json decoder json.decoder` |
| `JSON::Ext::Parser` | `JSON::Ext::Parser JSON Ext Parser json ext parser json::ext::parser` |

Rules: original + de-separated + lowercased + lowercased-de-separated.

Searching `json` → matches `JSON::Ext::Parser` (expansion contains `json`), `Psych::JSON` (expansion contains `json`), `json.decoder` (original contains `json`).

---

## 9. PRAGMA Configuration

```sql
PRAGMA journal_mode = WAL;        -- writes don't block reads
PRAGMA synchronous  = NORMAL;     -- safety/performance balance
PRAGMA foreign_keys = ON;         -- foreign key constraints
```

---

## 10. Maintenance

### 10.1 Rebuild FTS5 Index

```bash
# CLI full rebuild (run after production deploy)
php phpMan.php --build-index

# Cron daily rebuild
0 3 * * * /usr/bin/php /path/to/phpMan.php --build-index-cron
```

### 10.2 LLM Emoji Enhancement (Batch)

```bash
# Dry-run preview
php tools/batch_enhance.php --dry-run

# Full batch (md only, HTML-cached first)
nohup php tools/batch_enhance.php --cached-first --skip-errors --yes --format=md \
  > logs/batch_enhance_md.log 2>&1 &

# Single page (CLI)
php tools/enhance_page.php man ls
```

See `docs/01-PRODUCT.md` §2.11.5–2.11.6 for full design.

### 10.3 Cache Cleanup

```bash
# Full reset (delete DB file; next request auto-creates)
rm -f ~/.phpman/db/phpman_cache.db*

# Clear only search cache (forces fresh FTS5 results on next query)
sqlite3 ~/.phpman/db/phpman_cache.db "DELETE FROM cache WHERE mode='search'"

# Remove expired entries (auto-triggered, manual also works)
sqlite3 ~/.phpman/db/phpman_cache.db "DELETE FROM cache WHERE ttl > 0 AND (strftime('%s','now') - updated_at) > ttl"
```

### 10.4 Defragmentation

After extended operation, many INSERT/DELETEs may cause fragmentation. VACUUM rewrites the entire database file:

```bash
sqlite3 ~/.phpman/db/phpman_cache.db "PRAGMA journal_mode=DELETE; VACUUM;"
```

Best results when run after rebuilding the FTS5 index (DROP+CREATE clears old index, then VACUUM reclaims space).

### 10.5 Related Docs

- [SEARCH_FTS5_DESIGN.md](SEARCH_FTS5_DESIGN.md) — FTS5 full-text search design (v4/v3.6.2)
- [PYDOC_RI_DESIGN.md](PYDOC_RI_DESIGN.md) — pydoc3 / ri document format parsing design
- [DESIGN.md](DESIGN.md) — phpMan product definition and core design decisions
