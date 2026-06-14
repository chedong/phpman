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
v2.1 → v2.3 → v3.6 → v3.7.12 → v4.0 (in progress)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
man/perldoc/info   pydoc3/ri        Config overridables   JSON canonical cache
MCP Server         structured out   Underscore link fix   LLM emoji enhancement
JSON API           Search cascade   man7.org fallback     Code split
TLDR endpoint      FTS5 3-source    Docs restructured     i18n
                                   Structure regr test   AI translation
```

---

## Completed Versions

### v3.7.9–3.7.12 (2026-06-11 → 2026-06-14)

- **Config overridable constants**: `PHPMAN_WIDTH`, `PHPMAN_TOC_THRESHOLD`, `PHPMAN_GZIP_MIN_BYTES`, `PHPMAN_TLDR_MAX_EXAMPLES`, `PHPMAN_HOME_TITLE`, `PHPMAN_PROJECT_NAME` — all use `defined()` guard pattern, overridable via `phpman.config.php`
- **Naming cleanup**: `$GLOBALS['PHPMAN_WIDTH']` → direct constant, `$site_name` → `PHPMAN_PROJECT_NAME`
- **Branding**: `phpMan` → `phpman` in H1, site_name, footer
- **Fix**: cross-reference links for underscored man page names (e.g., `io_cancel(2)`) — SGR processing moved before linkification
- **Fix**: man page "not found" fallback changed from cheat.sh → man7.org
- **Docs restructured**: numbered index (`00–05`), `PYDOC_RI_DESIGN.md` merged into `01-PRODUCT.md`, design system tokens in `02-UI-DESIGN.md`
- **Doc fixes**: stale line numbers removed across all docs, SKILL.md broken references fixed

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

### v4.0 — Architecture Upgrade (in progress)

**Phase 1: Structure regression test** (✅ 2026-06-15)
- `test/structure_regression.php`: validates JSON section structure fingerprints
- Tests 5 man + 5 perldoc + 5 pydoc pages for structural invariants:
  - NAME section must exist
  - All sections have name + content
  - Subsections have non-empty names
  - Flags have flag fields
  - Mode matches expected
- Outputs structure fingerprints for regression baselines

**Phase 2: JSON canonical cache** (planned)
- Refactor cache to store only JSON format
- Derive HTML/Markdown/MCP from JSON (forward generation, no reverse parsing)
- New functions: `formatJSONToHTML()`, `formatJSONToMarkdown()`
- Cache key: `mode/command/section` → single JSON entry
- `formatForOutput()` already does JSON→MCP (reuse)

**Phase 3: LLM enhancement** (planned)
- Emoji-enriched section headings via Claude API
- Chinese summaries for man page descriptions
- `LLM_API_KEY` / `LLM_API_URL` / `LLM_MODEL` config via `phpman.config.php`
- Offline mode: LLM disabled when API key is empty
- Enhancement is additive (base JSON still works without LLM)

**Phase 4: Code split** (planned)
- `src/Source/` + `src/Formatter/` + `src/Cache/` + `src/Config/`
- Single-file entry point preserved
