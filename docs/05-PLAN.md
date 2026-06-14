# phpMan Project Plan

## Version Numbering Convention

- Format: `v{MAJOR}.{MINOR}` → git tag `v2.1`, `v3.0`
- Patch versions: `v3.6.3` — bugfix or minor improvement, no new features
- MAJOR bump: architecture changes or backward-incompatible API changes
- MINOR bump: new features, backward compatible
- Tag rule: each release has a corresponding annotated tag

```bash
git tag -a v3.6.3 -m "v3.6.3: English docs, --help CLI, cache TTL, removed rebuild-index.php"
git push origin v3.6.3
```

---

## Version Roadmap

```
v2.1 (released)   →   v2.2 (released)   →   v2.3 (released)   →   v3.4–3.6 (released)     →   v4.0
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
man/perldoc/info       TLDR inline         pydoc3/ri modes      FTS5 search + cache       Code split
MCP Server             tldr-pages           structured output    Single-query 3-source     Search enhancement
Markdown output        Zero config          Search cascade       Case-insensitive match    Security hardening
JSON API               Keep single file     Auto source detect   pydoc/ri FTS5 index       i18n
TLDR endpoint          No LLM key needed                         SQLite cache architecture  LLM generation
Cross-platform width                                              phpman.config            Offline data

v3.6.3 = current production (FTS5 3-source search, SQLite TLDR cache) | v4.0 = architecture upgrade + AI
```

---

## Completed Versions

### v3.4–3.6.3 (2026-06-05 → 2026-06-08)

- **FTS5 full-text search**: offline index across man/pydoc/ri, single query covers all, case-insensitive
- **SQLite cache architecture**: `cacheDb()` + `PageCache` + `search_fts` + `search_index_meta`
- **Configuration**: `phpman.config.php` with CACHE_DIR / LLM / DEBUG support
- **pydoc3 / ri modes**: HTML/Markdown/JSON/MCP four-format output
- **Search cascade**: FTS5 first → command-line fallback (apropos / pydoc3 -k / ri)
- **Deploy system**: `.deploy.mk` + Makefile (staging/production/rollback/cache-flush)
- **TLDR SQLite cache**: 7-day TTL with negative caching (fixes #79, #80)
- **FTS5 dedup guard**: meta check before INSERT prevents duplicate index rows
- **Cache TTL**: found entries 7 days, auto-cleanup (1% chance per request)
- **`--help` CLI**: `php phpMan.php --help` with usage and sources
- **Removed**: `rebuild-index.php` (superseded by `php phpMan.php --build-index`)

### v2.3 (released)

- pydoc3 / ri modes
- Search cascade (apropos → pydoc3 → ri)
- ri RDoc heading detection (`=` / `==`)
- Auto document source detection (`::` → perldoc, `.` → pydoc, `#` → ri)

### v2.2 (released)

- TLDR inline: official tldr-pages (GitHub Raw) → cheat.sh fallback → rule extraction
- Full-format embedding (HTML/JSON/Markdown/MCP)
- Zero config, no LLM key needed

### v2.1 (released)

- man / perldoc / info modes
- MCP Server (Streamable HTTP)
- Markdown / JSON / MCP output
- Cross-platform width control

---

## Planned

### v4.0 — Architecture Upgrade

- **Code split**: `src/Source/` + `src/Formatter/` + `src/Cache/` + `src/Config/`, keeping single-file entry point
- **LLM generation**: AI translation, multilingual summaries
- **Offline data**: tldr-pages local clone + cron update
- **i18n**: LANG-based locale + AI fallback translation
- **Search enhancement**: alphabetical sidebar, autocomplete
