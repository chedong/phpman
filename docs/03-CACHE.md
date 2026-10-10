# phpMan SQLite Database Schema Reference

> Status: v3.6.2 production
> Environment: PHP 8.x + SQLite 3.x / WAL mode / FTS5 enabled

---

## 1. Database Overview

Since v4.11 the storage is **sharded by mode**. `phpman_cache.db` is the central
file, but it holds only the search index and the TLDR cache; the page cache moved
into one SQLite file per content mode:

| File | Contents |
|---|---|
| `phpman_cache.db` | `search_fts` + `search_index_meta` + `tldr_cache` + `meta` (infrastructure) |
| `phpman_cache_<mode>.db` | `cache` + `cache_fts` for that mode |

`pageCacheModes()` = `PHPMAN_CONTENT_MODES` (`man`, `perldoc`, `info`, `pydoc`, `ri`)
plus `search` — six shards. `pageCachePath($mode)` builds the filename. The split
exists so a `man` write never takes a lock that a search query needs; see
CHANGELOG v4.11 (`3d02aee`). Manage both layers with `cli/cache.php` (§10.3).

| Table | Type | Rows (production) | Purpose |
|---|------|:---:|------|
| `cache` | Regular | ~38K | Page content cache (man/perldoc/pydoc/ri rendered output). **Shard-only since v4.11** — the central file has no such table at all; the per-mode shards carry it. |
| `cache_fts` | FTS5 virtual (content) | — | Cached page title index (linked to cache.id) |
| `search_fts` | FTS5 virtual (standalone) | 13,835 | Offline full-text search index (man+pydoc+ri) |
| `search_index_meta` | Regular | 13,835 | Index entry metadata (dedup, sort, stats) |
| `tldr_cache` | Regular | On demand | TLDR cheatsheet cache (unified TTL, default 7 months) |
| `meta` | Regular | 3 | Index count, update time |

---

## 2. cache — Page Content Cache

This table exists **only in the per-mode shards** (`phpman_cache_<mode>.db`). The
central file has no `cache` table: `cacheDb()` does not create one, and it has no
migration step that touches one.

### 2.1 Schema

```sql
CREATE TABLE IF NOT EXISTS cache (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    mode        TEXT NOT NULL,              -- 'man'|'perldoc'|'info'|'pydoc'|'ri'|'search'
    name        TEXT NOT NULL,              -- command/module name (e.g. 'ls', 'File::Basename')
    section     TEXT NOT NULL DEFAULT '',   -- '1'~'9','3pm','n','pydoc','ri' or ''
    title       TEXT,                       -- first non-empty line, ≤120 chars (see cache_fts)
    format      TEXT NOT NULL,              -- 'html'|'markdown'|'json'|'search' (see note below)
    content     BLOB,                       -- gzcompress() compressed rendered output
    content_len INTEGER NOT NULL DEFAULT 0, -- uncompressed byte size
    status      TEXT NOT NULL DEFAULT 'found'
                    CHECK(status IN ('found','not_found')),
    ttl         INTEGER NOT NULL DEFAULT 0, -- seconds; 0 = never expires
    hits        INTEGER NOT NULL DEFAULT 0, -- cache hit count
    renderer_version INTEGER NOT NULL DEFAULT 0, -- RENDERER_VERSION that wrote it
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
- **`format` values**: `html`, `markdown`, `json` (the public output formats, `PHPMAN_OUTPUT_FORMATS`) and `search` (search results). `mcp` is deliberately absent — it is an internal rendering core `handleMcp()` calls, not a URL-selectable format.
- **Compression**: PHP `gzcompress()`, SQLite BLOB storage, ~70% average compression ratio
- **TTL**: found entries `PHPMAN_CACHE_TTL_FOUND` (default 7 months = 18144000s, override via `PHPMAN_CACHE_TTL_MONTHS`), not_found entries 86400s (1 day) — except `mode='search'`, whose not_found entries also use `PHPMAN_CACHE_TTL_FOUND`. Expired entries auto-deleted on `get()`
- **Emoji rows are inert**: `emoji_md` / `emoji_html` rows were written with TTL=0 by the LLM enhancement layer, deleted in v4.10.0 (`7740029`). v5.0 (`5bf0025`) removed the **read** side as well, so nothing writes or reads them now. Their last mention was the v3→v4 migration's preserve list, which has since been removed (see §10.2).
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
php cli/build-index.php

# Cron (daily)
0 3 * * * /usr/bin/php /path/to/phpman/cli/build-index.php --cron
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

- **External content FTS5**: Linked to `cache` table via `cache.id`. An external-content table stores no column values of its own — it reads them back from `cache` by name. Every column named here must therefore exist on `cache`: `title` was named here from the start but was missing from `cache` until schema v7, so any query touching that column (`COUNT(*)`, a plain `SELECT`) failed with `no such column: T.title` and only `MATCH` worked. Adding the column is what makes the table queryable; `cache.title` and the index are written together by `set()`.
- **Purpose**: Title index of cached pages, usable for autocomplete/command lookup
- **Sync**: Written by `PageCache::set()` via `syncFts()` method
- **Note**: Actual search uses `search_fts`, not `cache_fts` — nothing in the app queries this table; it is exercised by the unit tests only

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

- **TTL**: Checked on read via `(strftime('%s','now') - fetched_at) < PHPMAN_CACHE_TTL_FOUND` (unified with PageCache found entries, default 7 months)
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
-- search_index_count   = '13849'
-- search_index_updated = '2026-06-08T...'
```

There is no `schema_version` entry and no `CACHE_SCHEMA_VERSION` constant. Both cache
DBs create their schema with `CREATE ... IF NOT EXISTS` on every open, so an additive
change needs no migration; and since the pre-sharding central DB is gone and rollback to
older code is not supported, there is no older shape to migrate *from*. The version
stamps that recorded it — `meta.schema_version` here, `PRAGMA user_version` in the shards
— existed so a rolled-back deploy could re-stamp and re-migrate, which is exactly the
rollback provision that was dropped. A stamp nothing reads back is dead weight; see the
note on the retired `cache.generator_version` under `RENDERER_VERSION` in
`src/config.php`.

To invalidate cached *output* rather than the schema, use `RENDERER_VERSION` — that one
`PageCache::get()` filters on, which is the whole difference.

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
php cli/build-index.php

# Cron daily rebuild
0 3 * * * /usr/bin/php /path/to/phpman/cli/build-index.php --cron
```

### 10.2 Emoji Enhancement (Removed in v4.10)

`cli/batch-enhance.php` was deleted in v4.10.0 (commit `7740029`); v5.0 (`5bf0025`) removed the read side too, so `emoji_md` / `emoji_html` rows are now both written and read by nothing. Their last reference was the v3→v4 migration's `format NOT IN ('json','search','emoji_md','emoji_html')` preserve list in `src/cache.php`; that migration was removed with the rest of the central DB's `cache` steps (see §2 and §7), so nothing names those formats any more. See `docs/01-PRODUCT.md` §2.12.

### 10.3 Cache Cleanup

Use `cli/cache.php`. Do not hand-write the paths: since v4.11 the page cache is
sharded, and `rm -f ~/.phpman/db/phpman_cache.db*` matches the central file and its
`-wal`/`-shm` but **no shard at all** (the names diverge at `_` vs `.`). That
command was the old `make cache-flush`, so it reported success while leaving every
shard in place — the reason the CLI exists.

```bash
php cli/cache.php stats    # rows + bytes per shard, plus the central file's size
php cli/cache.php flush    # delete every shard file
```

`flush` deletes the shard files (`pageCachePath($mode)*`) and stops there. The
central file is deliberately left alone: it holds the FTS search index and the TLDR
cache, and since v4.11 it has no page-cache table to clear. Everything rebuilds on
the next request.

Both commands are also on the Makefile, which runs them over SSH:

```bash
make cache-stats
make cache-flush           # production
make cache-flush-staging   # staging
```

For narrower surgery, target one shard directly — e.g. drop only the cached
searches (they rebuild on the next query):

```bash
sqlite3 ~/.phpman/db/phpman_cache_search.db "DELETE FROM cache"
```

Expired entries are removed automatically on access (see §5); a manual sweep is
rarely needed, but per-shard it is:

```bash
for db in ~/.phpman/db/phpman_cache_*.db; do
  sqlite3 "$db" "DELETE FROM cache WHERE ttl > 0 AND (strftime('%s','now') - updated_at) > ttl"
done
```

### 10.4 Defragmentation

After extended operation, many INSERT/DELETEs may cause fragmentation. VACUUM rewrites the entire database file. Run it per file — a shard does not shrink when you vacuum the central DB, and vice versa:

```bash
for db in ~/.phpman/db/phpman_cache*.db; do
  sqlite3 "$db" "PRAGMA journal_mode=DELETE; VACUUM;"
done
```

`man` is the shard worth doing (it is the largest by far — hundreds of MB); the
central file holds the FTS index, where `INSERT INTO search_fts(search_fts)
VALUES('rebuild')` reclaims more than VACUUM does.

### 10.5 Related Docs

- [04-SEARCH.md](04-SEARCH.md) — FTS5 full-text search design (formerly `SEARCH_FTS5_DESIGN.md`)
- [01-PRODUCT.md](01-PRODUCT.md) — product definition, core design decisions, and the pydoc3 / ri
  parsing design (formerly `DESIGN.md` + `PYDOC_RI_DESIGN.md`, merged into §2)
- [00-INDEX.md](00-INDEX.md) — documentation index
