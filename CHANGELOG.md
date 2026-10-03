# Changelog

All notable changes to phpMan are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- **`cli/cache.php`** — `stats` and `flush` for the sharded page cache, wrapped by `make cache-stats` / `cache-flush` / `cache-flush-staging`. It exists because the command it replaces was silently wrong: `rm -f ~/.phpman/db/phpman_cache.db*` matches the central file and its `-wal`/`-shm` but **no shard at all** — the names diverge at `_` vs `.` — so since v4.11 sharded the page cache it reported success while leaving every shard in place. `stats` opens each shard through a new `$create = false` mode on `pageCacheDb()`, so counting cannot materialise the six empty databases it was supposed to be reporting on; `flush` deletes the shard files and then clears only the central `cache` table, because the FTS search index and the TLDR cache live in that file too. (#228)
- **`PHPMAN_ADSENSE_ID` in `phpman.config.php.example`** — the constant was real since v4.9.26 but missing from the example config, so there was nothing to uncomment.

### Changed
- **`baseUrl()` takes its scheme from the configured base URL** — it honoured the configured *host* but always re-derived the scheme from the request, so an install whose `PHPMAN_BASE_URL` says `https://` behind a TLS-terminating proxy (Nginx, Cloudflare, ELB) emitted `http://` canonicals and JSON-LD. The scheme now comes from the configured URL when there is one, then `$_SERVER['HTTPS']`, then `X-Forwarded-Proto`. `getSafeHost()` is deliberately unchanged — it returns `host[:port]` only, and its callers pair it with their own scheme. (#233)
- **Docs synced to the code (emoji cache rows, `mcp` as a format)** — `CLAUDE.md`/`AGENTS.md` listed `mcp` among the URL-selectable formats; it is an internal rendering core `handleMcp()` calls, and `PHPMAN_OUTPUT_FORMATS` has only `html`/`markdown`/`json`. The emoji paragraphs now say what the rows are: **inert**. v4.10 (`7740029`) removed the writer, v5.0 (`5bf0025`) removed the reader (the default-view fallback, the `/markdown` preference, the `CACHE_FORMAT_EMOJI_*` constants, the never-expire TTL rule), and the one surviving reference is the v3→v4 migration's preserve list — kept on purpose, because rewriting a shipped migration would change what an old database migrating forward today would delete. Also fixed `05-PLAN.md` §v4.4, which claimed every test file had replaced `require 'phpMan.php'` with `require PHPMAN_HOME . '/src/bootstrap.php'`; the tests never did, and doing so fatals (see Fixed). (#237 #221)
- **Deploys are atomic** — `phpMan.php` and `src/` now upload into `~/.phpman/releases/<tag>/` and are activated by swapping one `current` symlink (`deploy/atomic-release.sh`), so no request can observe one half from a new version and the other from an old one. The rule this replaces — "upload `src/` before `phpMan.php`" — was only ever correct in one direction: it protects an *additive* change (a new `src/` function the new `phpMan.php` calls, which produced 2 transient 500s on 2026-07-13) and is exactly wrong for a *removal* (a constant deleted from `src/` while the old `phpMan.php` still reads it). The removal case is not hypothetical: it put production into ~20 seconds of `Undefined constant "CACHE_FORMAT_EMOJI_HTML"` fatals on 2026-10-02 (19:51:17–19:51:37 PDT) while the two uploads were in flight. No fixed order fixes both, because the two directions want opposite orders — a rename does. `~/.phpman/src` becomes a symlink to `current/src` so the CLI follows the same release the web does, `~/.phpman/phpMan.php` a symlink to `current/phpMan.php` so `test/run_all.php` still runs from the install home (its `test/unit/*.php` do `require __DIR__ . '/../../phpMan.php'`, which the release layout otherwise resolves into `releases/<id>/`), and `make rollback` flips `current` back a release instead of copying a `.bak` over the entry script (which would now write *through* the symlink and corrupt the release in place). Old releases (5) are the rollback targets, replacing the `.bak` backups.
- **`cleanTerminalOutput()` rewrites its buffer in place** — `formatManPerlDocToMarkdown()` took `array $lines` by value and immediately rebound it to the function's return, so a 430k-line page (`info py`) had two full copies of the buffer alive at once. Both it and `cleanTerminalOutput()` now take `array &$lines` and clean in place, and the two call sites (`format_json.php:12`, `format_markdown.php:4`) drop the assignment. The in-place loop has to be a `for` over the indexes: writing `$lines[$i]` inside a by-value `foreach` makes PHP separate (copy) the array — measured at the same 70MB peak as the old form, i.e. no saving at all — and a by-reference `foreach` avoids the copy but re-boxes every element as a reference (48MB). A non-list input is normalised with `array_values()` first so the loop can index by position; no caller hits that, since both pass `exec()` output, which is always a packed list. Peak on `info py` markdown: **76.0MB → 60.0MB**, and the clean phase alone goes from +34MB to +0MB — the second array is gone entirely. Output is unchanged: markdown for 8 pages (`man ls`/`GCC`/`createuser`, `info flex`/`coreutils`/`py`, `perldoc Image::ExifTool::TagNames`/`LWP::UserAgent`) is byte-identical in md5 and length, and json/mcp for the same pages is identical once the `generated` timestamp is stripped.
- **Docs synced to the code** — `PHPMAN_ADSENSE_ID` documented in `01-PRODUCT.md` (loader-only, needs Auto Ads, don't set on staging); the stale LLM-era config recipe removed from `05-PLAN.md`, which also had `phpman.config.php` living in the webroot — it lives in `~/.phpman/`, outside the webroot, which is where the secrets are.
- **`.codex/` gitignored** — agent local state no longer shows up in `git status`.
- **`test_user_scenarios.php` resynced with the code** — four assertions still described behaviour that deliberate changes had already replaced, so the suite reported 4 phantom failures (U07/U08/U09/U11). U07 asserted `/tldr/ls` returns 200, a route `e70318a` removed; it now asserts the route stays 404 *and* that the TLDR block reaches the user inside `/man/ls/1`. U09 and U11 looked for CSS and JS inline in the HTML, which `b908fcb` externalised to `phpman.css`/`phpman.js` — U09 now fetches the stylesheet and checks the breakpoint there, U11 checks the `ext-nav` body class. U08's expected 404 body was the pre-`10939fd` wording.

### Removed
- **`cache.generator_version`** — the column and the code that wrote it. It stored the deployed `GIT_DESCRIBE` on every `set()`, but nothing ever read it back: `PageCache::get()` selects only `id, content, status, ttl, updated_at`, and no query in the codebase filtered on it. A column nothing consults cannot invalidate anything, so it never did the "which version produced this entry" tracking its comment claimed — it was dead weight on the hot write path. Schema version 5 → 6; shards drop the column on their first connection after deploy.
- **`mcp` is no longer a public output format** — `/{mode}/{param}/mcp`, `/search/{query}/mcp` and `?format=mcp` fall back to HTML now; use `POST /mcp` for the MCP envelope. It was never MCP: parameters came from the URL path, GET was allowed, and it bypassed the JSON-RPC endpoint's API-key check, 64KB body cap and POST-only guard, while being served with `Expires: +7d`. The format string itself stays — `handleMcp()` renders through `getManPage(..., "mcp")` — it is simply no longer reachable from a URL. **Breaking** for callers that used the suffix. (Requests for the retired suffix now 301 to the command's real section — see below.)
- **`/status`, and the rest of the LLM-era read side** — v5.0 removed the batch-enhance subsystem but left everything that read its output. `GET /status` was a JSON dashboard for a batch job that no longer exists: it watched `/tmp/bm.pid` and four siblings (`bp`/`bi`/`bpy`/`br`) that nothing creates, counted errors by shelling out to `grep -c 'mode='` against `ini_get('error_log')` — which is empty under the web SAPI, so that branch never ran — and reported `last_emoji_min_ago` from `emoji_html`/`emoji_md` rows nothing writes, inside a `catch (\Throwable $ignored) {}` that swallowed whatever went wrong. It was undocumented, called by nothing, and its `isLocalRequest()` guard skipped the API-key check for any request from a local address. The route is gone and `status` is no longer an allowed mode in `phpMan.php` or `normalizeMode()`, so `/status` now answers the ordinary 404 for an unknown command rather than JSON (verified on staging: byte-identical to the 404 for any other unknown name, modulo the echoed name). The read side went with it: the ETag's `emoji_html` lookup (one of two DB queries on every HTML request, for a format that is never written — #213), the `emoji_md` preference in the markdown path, the enhanced-HTML branch that served `emoji_html` in place of the rendered page together with the TLDR-skip lookup above it, the `CACHE_FORMAT_EMOJI_*` constants, and the never-expire TTL rule for those two formats. Every one of those branches was already unreachable, so rendered output is unchanged; the ETag changes value once (it no longer carries the constant `raw` suffix), costing each client a single revalidation. One reference is kept deliberately — the v3→v4 migration's `format NOT IN ('json','search','emoji_md','emoji_html')` preserve list — because rewriting a shipped migration would change what an old database migrating forward today would delete. (#212 #213 #217 #218 #219)

### Fixed
- **Markdown had no output cap, so an oversized page was a mechanism for a 500 (#227)** — json and mcp have payload limits (1MB / 512KB) but markdown did not. The body was concatenated in full and then `gzcompress`ed into the cache, so a large page doubled an unbounded string until `memory_limit` was exhausted — and an OOM kill writes no PHP fatal to the error log, so the request left no trace. A synthetic 52.7MB page died with `Allowed memory size of 134217728 bytes exhausted in format_markdown.php`; the largest real page (`info py`, 17.9MB of source, 18.0MB of output, 60MB peak, 8.0s) is well inside it. The cap is 24MB — above every page that exists today, so nothing is truncated now, but bounded. `mdAppendLine()` refuses a line that will not fit rather than overshooting and stopping afterwards, so the body stays strictly inside the budget; truncation appends a notice pointing at the json view. HTML is untouched: it wraps lines in `<pre>`, where cutting mid-line would leave unclosed `<b>`/`<u>`/`<a>` and break the XHTML purity `AGENTS.md` requires — that needs its own tag-boundary work.
- **`cache_fts` was indexed under the wrong rowid (#229)** — `syncFts()` took the rowid from `lastInsertRowID()`, but an UPSERT's `ON CONFLICT DO UPDATE` branch does not update it: a fresh connection returns `0`, a long-lived one returns the id of some earlier INSERT. `cache_fts` is an external-content table (`content_rowid='id'`), so the index silently pointed at the wrong row — rowid `0` makes any `MATCH` raise `fts5: missing row 0 from content table`, and a stale id pairs two rows' data. The web SAPI is CGI, so every request is a fresh connection and every one of them hit the `0` case. `set()` now does an explicit `SELECT id` (not `RETURNING`, which needs SQLite 3.35 while `install.sh` supports PHP 7.2+, whose bundled SQLite may be older). The existing overwrite test could not catch this: the INSERT immediately before it was the same row, so the stale id happened to equal the correct one. The new case writes a different row in between and reported `expected 25 / actual 26` before the fix. **The production shards' FTS indexes are misaligned today — `make reindex` (or `INSERT INTO cache_fts(cache_fts) VALUES('rebuild')` per shard) is required after deploying this.**
- **`getValidInfoFiles()` hardcoded `/usr/share/info` (#234)** — so on any non-standard prefix (MacPorts' `/opt/local/share/info`, Homebrew, a custom `INFOPATH`) the `info` index linked to pages that do not exist. It now builds a discovery union: `INFOPATH` split on `PATH_SEPARATOR`, then `/usr/local/share/info`, then `/usr/share/info`, then `dirname(info --where dir)`. A `$filtering = !empty($validFiles)` guard keeps the old behaviour when no directory is discovered at all, rather than filtering every entry away. Verified on both platforms (`info --where dir` answers `/usr/share/info/coreutils.info.gz` on the server, `/opt/local/share/info/dir` on macOS; `dirname()` is right for both), and the new `test/unit/test_info_valid_files.php` was shown to fail on the old code — which produced byte-identical output with and without `INFOPATH=/tmp/oldinfo`, never seeing the `foo`/`bar` entries placed there.
- **An unknown command through MCP paid for a TLDR fetch it could not use (#235)** — `buildJsonData()` injected the TLDR before checking whether the page had any sections, and on a TLDR cache miss `fetchOfficialTldr()` makes up to four external requests with a 5s timeout each. An agent asking `cli_help` for a mistyped command waited ~20s to decorate a page that does not exist. The fetch is now skipped when `sections` is empty: measured 0.00s with no `tldr` key and no new `tldr_cache` row, against 1.64s and a new row when the same command goes through `fetchOfficialTldr()` directly. `src/format_mcp.php` no longer triggers its own fetch either — it reads `$data["tldr"]`. The same pass fixed the TLDR read filter, which applied the 7-month `PHPMAN_CACHE_TTL_FOUND` to every source and so never expired a `not_found` row; it now uses `PHPMAN_CACHE_TTL_NOT_FOUND` (1 day) for those. Verified by ageing one row of each kind by 2 days: the `not_found` one expired, the `found` one was kept.
- **JSON `sections[].content` carried markdown link syntax (#236)** — `cleanTerminalOutput()` emits the markdown equivalent of a groff OSC 8 hyperlink, `[text](uri)`, and the JSON and MCP paths reused it, so an OSC 8 link reached consumers as literal `[text](uri)` inside a field that is otherwise plain text. The function takes a `$linkStyle` (`'markdown'` by default, `'plain'` from `buildJsonData()`); plain keeps the link text and drops the target. The scheme allowlist that discards `javascript:`/`data:` applies in both modes.
- **`install.sh` never checked for mbstring (#230)** — it is a hard dependency (HTML rendering and the search index call `mb_*` unguarded), so a host without it fatals on every render. `check_mbstring()` now runs with the other extension checks and exits with a per-platform hint (apt / dnf / yum / pacman / brew); `README.md` lists it and the Debian/Ubuntu line installs `php-mbstring`.
- **`deploy/atomic-release.sh` and `deploy/rollback.sh` assumed GNU `mv` (#232)** — they use `mv -T`, which BSD/macOS `mv` does not have, so the scripts failed somewhere in the middle with a confusing error. Both now test `mv --version` up front and exit 1 with a plain message.
- **`make staging-reindex` kept the pre-split sitemap form (#231)** — `1fdd975` (#225) split the sitemap into an html one for Google and a markdown/json one for AI bots, with `llms.txt`, and updated three of the four targets; `staging-reindex` was the one it missed, so staging never exercised what production serves. It now matches `reindex-staging`, including `--sitemap-url https://test.chedong.com/…` and `--llms-output`.
- **A foreign `Host` header reached the canonical URL and JSON-LD** — `getSafeHost()` accepted any *well-formed* `HTTP_HOST`, which is not a guard: `evil.com` is well-formed. `curl -H 'Host: evil.com' https://www.chedong.com/phpMan.php/man/ls/1` was answered with `<link rel="canonical" href="https://evil.com/phpMan.php/man/ls/1"/>`, the same host in the Schema.org `url`/`author.url`/`publisher.url`, and in every footer format link — while the function's own docblock claimed it "prevents Host header injection attacks on canonical URLs and Schema.org output". The install's configured `PHPMAN_BASE_URL` now decides the host, and the request supplies one only when there is nothing configured to compare against (then a well-formed `HTTP_HOST`, then `SERVER_NAME`, then `localhost`); the constant/env-var lookup is shared with `scriptName()` through a new `configuredBaseUrl()`. No change for legitimate traffic — the configured host is the one requests already arrive with — but a forged `Host` can no longer choose where our canonical points, and a request over a hostname that is not the configured one (a parked domain pointed at the same vhost) no longer emits self-referential canonicals. `test/unit/test_safe_host.php` pins it; the foreign-`Host` case returns `evil.com` on the old code. The e2e assertion that should have caught this (P11) was passing *vacuously*: `test.chedong.com` answers 421 Misdirected Request before PHP runs when the `Host` does not match the TLS SNI, and an error page with no body trivially contains no `evil.com`. P11 now requires HTTP 200 before the body assertions, asserts the canonical names the configured host, and reports a server-level rejection explicitly when the vhost answers 400/421.
- **Title format (#226)** — `<title>` is now `Name - description - mode(section) - [phpMan]`; the mode/section segment is dropped when there is no mode.
- **perldoc title description** — resolves again; a perldoc page's `name` lives in the `3perl`/`3pm` sections of `search_fts`, which the lookup did not cover.
- **GA4 beacon CSP** — `doubleclick` and `ga-audiences` added to `connect-src`; GA4 measurement beacons were being blocked.
- **config-check sed escaping** — the deploy-time config check mangled values containing sed metacharacters.
- **`cli_help` for a command with no page returns an empty result, not an error** — the man → pydoc → ri fallback cascade ended by returning `""`, which `handleMcpToolsCall()` could not decode and reported as `-32603 "Internal error: invalid MCP output"`. An agent could not tell a mistyped command from a broken server. The call now succeeds with an empty envelope (`summary: null`, `sections: []`) — the machine-readable "no page for this name here" signal.
- **Unknown tool and missing parameter answer `-32602`, not `-32603`** — a `tools/call` naming a tool that does not exist, or `cli_help`/`cli_search` without their required argument, fell into the generic `catch (Throwable)` and came back as `-32603 "Internal error"` with the detail discarded. These are the caller's fault, so they now raise `McpInvalidParams` and answer `-32602 "Invalid params: Unknown tool: X"` / `"Missing required parameter: command"`, naming what to fix.
- **`test/TEST_MCP.md` assertions ran against the wrong field** — they parsed `result.content[0].text` as JSON, but that field is a markdown rendering; the structured payload is the sibling `result.structuredContent`. Every assertion now reads `structuredContent`, the error codes are documented, and `test/e2e/test_agent_scenarios.php` gained `structuredContent` assertions plus A11 (nonexistent command → empty result). Both MCP suites take `PHPMAN_TEST_MCP_KEY` now that `POST /mcp` is fail-closed.
- **Deploy-time config check no longer flags `PHPMAN_BASE_URL`** — it matched `define('KEY'` with single quotes only, and both live configs write that one with double quotes, so every deploy printed a false "not in your phpman.config.php". The match is quote-agnostic now, so the warning means what it says. (A09 in `test_agent_scenarios.php` also skips its 304 assertion on a `PHPMAN_DEBUG` target, where per-request profiling timings make the ETag change by design.)
- **CLI tools run from a non-default home resolved `PHPMAN_HOME` to `~/.phpman`** — `cli/_bootstrap.php` fell back to `$HOME/.phpman` whenever the config did not define the constant, so `cd ~/.phpman_test && php cli/build-index.php` — which is what `make reindex-staging` and `make staging-reindex` run — rebuilt **production's** index and left staging's stale. `PHPMAN_HOME` is now the directory holding the `phpman.config.php` that was loaded, which is what every deployment is (`cd <home> && php cli/...`); the `$HOME/.phpman` fallback stays for when there is no config at all. The web path was never affected — `phpMan.php` gets `PHPMAN_HOME` substituted at deploy (`src/config.php:81`).
- **A deployed install could load the wrong `phpman.config.php`** — `src/config.php` looked for `dirname(__DIR__)/phpman.config.php` first, which is only correct while `src/` sits directly in the install home, and otherwise fell through to a hardcoded `$HOME/.phpman/phpman.config.php`. The atomic-release layout above puts `src/` at `PHPMAN_HOME/releases/<id>/src/`, so the first lookup misses there and *every* deployed install resolved to `$HOME/.phpman` — production's directory — wherever it actually lived. Staging therefore loaded production's config: `MCP_API_KEY` came from the wrong file, so MCP requests failed closed with `-32001 Unauthorized` and `test/e2e/test_agent_scenarios.php` died at A03 (`array_map(): Argument #2 ($array) must be of type array, null given`). The search now consults `PHPMAN_HOME/phpman.config.php` between the two, which is the install's real home under any layout. Regression test: `test/unit/test_config_resolution.php` (fails on the old lookup, resolving to the `$HOME/.phpman` file).
- **ANSI color codes no longer leak into rendered pages** — groff emits SGR color escapes for man pages that use them (util-linux, systemd), but only bold (`ESC[1m`) and underline (`ESC[4m`) were handled, so a pair like `ESC[34m…ESC[0m` survived as literal `[34m` / `[0m` in the body text of HTML, JSON and markdown alike. In HTML it was worse than cosmetic: the URL linkifier matches `[\w]+://`, so the `34m` of `ESC[34mhttps://…` was swallowed into the href — `<a href="34mhttps://github.com/util-linux/util-linux/issues">` — a broken *relative* link that resolves to `/man/<cmd>/34mhttps://…`. Crawlers followed it: 122 403s in one day's access log, every one of them a util-linux command. Color sequences are now stripped outright in `formatManPerlDoc()` and `cleanTerminalOutput()`; bold and underline are still converted as before.
- **OSC 8 hyperlinks render as real links** — groff emits `ESC ] 8 ; params ; URI ST` … `ESC ] 8 ; ; ST` for man pages that use `.UR`/`.UE` (netpbm and friends), and the pair reached the page verbatim, so `pbmtoybm(1)` showed visible garbage: `]8;;index.html#commonoptions\ Common Options]8;;\`. `formatManPerlDoc()` now turns each pair into `<a href="URI">text</a>` — kept as a link rather than stripped, because the URL linkifier matches only absolute `scheme://` URLs and these targets are usually relative (`index.html#commonoptions`), so dropping the sequence would lose the target. The URI is held to an allowlist — an absolute URL on `http`/`https`/`ftp`/`mailto`, or a scheme-less relative target — and its charset excludes quotes and every whitespace/control byte, so it can neither break out of the attribute nor smuggle a `javascript:`/`data:` scheme past the guard (browsers strip leading whitespace before resolving a URL, hence excluding it). Anything else leaves the sequence unmatched, and it is dropped with the text kept; window titles and an unclosed `8` go the same way. The generic OSC strip runs *before* `&<>` become the `\x05\x06\x07` placeholders, because it accepts BEL as a terminator and the `\x07` placeholder standing in for `>` would otherwise satisfy that test and truncate the strip mid-sequence. `cleanTerminalOutput()` emits the markdown equivalent, `[text](URI)`. **Note (applies to this and the SGR fix above):** the cache stores *rendered* HTML per mode with a 210-day TTL, so deploying a renderer change does not by itself change what is served — the affected `cache` rows have to be purged, or the pre-fix markup keeps coming back.
- **Wrong or retired section numbers 301 to the real page instead of 404ing** — `/man/mount/1` answered 404 while `/man/mount/8` answered 200, and `/man/<cmd>/mcp` — the format suffix retired above — parsed as `section='mcp'` and 404'd the same way. Between them they were about half of the ~6,700 daily 404s under `/man/`. When a command exists in exactly one section (`search_index_meta` is indexed on `(name, section, source)`, so this is a covering-index lookup), a request naming a different or unknown section now 301s to the real one, carrying a format suffix through: `/man/dmsetup/mcp` → `/man/dmsetup/8`, `/man/ls/9/markdown` → `/man/ls/1/markdown`. A command that really does live in two or more sections still 404s — which one was meant is a guess the URL does not support — and a lookup failure falls through to the old 404 rather than turning it into a 500.
- **The command input had no label** — `showForm()`'s text field was unlabelled to assistive tech while every radio beside it had one. It now carries a visually hidden `<label for="cmd-input">` (`.sr-only`, new in `phpman.css`), so the toolbar's only text field announces itself.
- **`cache_fts` is queryable again** — the table was declared `fts5(mode, name, section, title, content='cache')`, but `cache` had no `title` column. An external-content FTS5 table stores no values of its own: it reads each named column back from the content table, so `syncFts()` was indexing a title that no query could then read — `SELECT COUNT(*) FROM cache_fts` died with `no such column: T.title` and only `MATCH` worked. The column now exists on `cache` (schema v6 → v7), `set()` writes it alongside the index, and the title-extraction logic moved out of `syncFts()` into `extractTitle()` so both get the same value. Nothing in the application queries this table — search uses `search_fts` — so this was latent, not user-visible; the unit tests were the only thing exercising it.

### Security
- **MCP API key check is now fail-closed** — `handleMcp()` used to skip authentication entirely when `MCP_API_KEY` was empty (`if (MCP_API_KEY !== '')`), so a missing or emptied `phpman.config.php` silently published `POST /mcp` to the world instead of denying. An unset key now rejects every request with 401. The comparison also uses `hash_equals()` rather than `!==`, so it no longer leaks the key's length and matching prefix through timing. (The `/status` endpoint that used the same shape is gone — see Removed.) **Breaking** for a deployment that intentionally ran MCP unauthenticated: set `MCP_API_KEY` in `~/.phpman/phpman.config.php`.

## [4.11.1] — 2026-09-26

### Removed
- **Orphaned LLM scaffolding** — leftover LLM hooks in `src/search_index.php`, the dead `PHPMAN_ENHANCE_*` constants, and a no-op write to a by-value parameter. No behavior change.

### Changed
- **`pageCachePath()` is the single source of the cache-shard naming rule** — `cli/build-sitemap.php` now reads `PHPMAN_CONTENT_MODES` instead of duplicating the mode list.

### Fixed
- **Docs no longer claim the LLM layer "moved to the doc-enhance project"** — no such repository exists; it was deleted in v4.10.0. The batch-enhance CLI reference and the LLM config keys are gone.

## [4.11.0] — 2026-09-24

### Added
- **Unified cache TTL** — `PageCache` found-entries and the TLDR cache expire on the same schedule (default 7 months) instead of drifting apart.
- **JSON/MCP payload limits** — the envelope exposes them; `formatMcpStructured()` picks fields from a whitelist, so it had to be told about the new limits explicitly. Derived fields are computed in a single pass when wrapping lines.
- **Cache-busting for CSS/JS** — asset URLs carry a version query string.
- **Tailscale mesh tooling** — migrated into the chedong.com repo; skips cross-tailnet shared nodes during discovery, reports each node's exit-node usage, includes the executing node in the measurements, supports local status / config-collected nodes / no-arg help, and uses Mb/s consistently.

### Changed
- **Page cache sharded per mode** — eliminates SQLite write-lock contention between search and page rendering.
- **Markdown formatter fast path** — `baseUrl` and the closures hoisted out of the loop, regex passes guarded with `strpos()`.
- **MCP search description** improved.

### Fixed
- **Large pages no longer route through an IR string for MCP/JSON** — `info` pages were exceeding the memory limit.
- **FTS5 `and`/`not` in names** — a page named `and` or `not` was parsed as an operator (`no such column`); such terms are now escaped.
- **`?debug=1` no longer re-serializes the whole response** just to append the `_profiling` key.
- **Indexed `url` field points at a usable address** — `/man/json` is a page query, not an endpoint.
- **Stale `info` dir-menu entries** filtered by real `.info` files.
- **`showForm()` with markdown/json URLs** — the not-found fallback link was broken.

## [4.10.0] — 2026-08-08

### Removed
- **LLM enhancement** — `batch-enhance`, `callLLM()`, and `enhanceManPage()` are gone, along with the enhancement cache. phpMan no longer makes outbound LLM API calls.

### Added
- **`tailscale-mesh-bench.sh`** — iperf3 bandwidth benchmark for the Tailscale mesh, with `--help` / `-h`.

### Fixed
- **Emoji cache entries never expired** — the 7-day TTL caused mass data loss.
- **`PageCache::set()` no longer overwrites valid content** with an empty `not_found` entry.

## [4.9.26] — 2026-07-24

### Added
- **Google AdSense support** — `PHPMAN_ADSENSE_ID` emits the `adsbygoogle.js` loader from `showFooter()` and widens the CSP automatically. Loader only: ads appear only when Auto Ads is enabled for the site.
- **`log-analyze.sh`** — web access log analysis tool.
- **Runtime process status** in `--status` output.
- **Compressed sitemap** generated during reindex.

### Changed
- **`--rate-limit` is configurable**, default lowered 120→60s (v4.9.21).
- **Tool detection at deploy**, plus a `--cache-only` mode (v4.9.20).
- **Form layout** — single-row desktop layout (search + modes inline), compact mobile form, dark-mode field contrast (v4.9.15–19).
- **Code review cleanup** — dead code removal, abort fix, WAL safety, ETag logging, `X-Forwarded-Proto` (v4.9.17, v4.9.22).
- **`--status` man count** — WAL checkpoint before reading, so it no longer reports 0 (v4.9.13).

### Fixed
- **`GIT_DESCRIBE` guard in `showFooter()`**.
- **URL attack guard** — malformed paths with protocol-prefix traps are rejected.
- **403 guard** — valid Perl modules containing multiple `::` are no longer rejected.
- **PHP `default_socket_timeout`** no longer preempts `CURLOPT_TIMEOUT`.
- **Deploy ordering** — `src/` is deployed before `phpMan.php` to avoid transient 500s mid-release.
- **Man batch abort rate** — `maxConsecutiveFailures` raised 3→10.
- **FTS5 duplicate entries** — `DELETE FROM search_fts` instead of `DROP` + `CREATE`/`RENAME` (v4.9.25).
- **`rebuildSearchIndex` cache cleanup** — deletes `WHERE mode='search'` rather than `format='search'`, and no longer wipes all HTML page caches (v4.9.23–24).
- **Undefined `GIT_DESCRIBE` in `tldr.php:85`** — crashed the CLI batch.

## [4.9.0] — 2026-06-27

### Changed
- **`PHPMAN_VERSION` / `GIT_DESCRIBE` use a `__PLACEHOLDER__` pattern** — a deploy never dirties the local file, and the version comes from the global latest tag rather than the branch-local one.
- **`RE_ASCII`, `RE_ASCII_SAFE`, `GIT_DESCRIBE` defined in the CLI bootstrap** so CLI scripts can use them.
- **Schema migration simplified** — `_old` table logic removed, format tabs moved.
- **Design pass** — +2px font scale, format tabs, mobile search polish.
- **`v4.8.2`** — theme toggle moved to bottom-right, "back to top" shortened.
- **`v4.8.1`** — search heading is dynamic: `man` (FTS5) or `apropos` (fallback).
- **Dead code deduped**, key masking and a shell function simplified.

### Fixed
- **FTS5 search** — useless `LEFT JOIN` removed, `DISTINCT` added, `LIMIT` 300→500.

## [4.8] — 2026-06-26

### Changed
- **Calibrated Terminal CSS** — Geist-inspired token system; version bumped 5.0 → 4.8.

### Fixed
- **`PHPMAN_HOME` placeholder** now resolves to `~/.phpman` for local dev.

## [4.7] — 2026-06-26

### Changed
- **Deploys use `rsync` instead of `scp`**.
- **All deploy-time constants use placeholders** — a deploy never dirties the local file.
- **`--status` man page total** now read from `search_index_meta`.
- **Docs updated for v4.7** — placeholder constants, rsync, theme toggle, mobile screenshot, v4.5+ config architecture.

### Removed
- **Stale `tools/` cleanup** from the deploy targets.

## [4.6.0] — 2026-06-26

### Added
- **Hakusho (白書) light mode** — 20 CSS custom properties with `@media (prefers-color-scheme: light)` auto-switch. Zero JS. Tokyo Night dark defaults, warm paper-tone light palette. Manual toggle via `[data-theme]` selectors + `phpman.js` with localStorage persistence (`phpman-theme-v2` key).
- **Theme toggle button** — `#theme-toggle` (fixed top-left, ☀/☾ icon) in `phpman.js`, with CSS transition on `body` for smooth palette swap.

### Fixed
- **PATH_INFO guard hoist (#181)** — `strlen()` check moved before `explode()` to prevent memory DoS on oversized PATH_INFO.
- **FTS5 colon query error (#192)** — `buildFtsQuery()` now strips leading/trailing colons from search terms. Searching for `SQL:` no longer triggers `no such column: SQL`.
- **SQLite lock retries (#193)** — cache write retries increased 3→8 with exponential backoff + random jitter (~12s max wait vs ~0.9s).
- **LLM_MAX_TOKENS default (#182)** — reduced from 409600 (exceeds all provider limits) to 8192.
- **CSS token names aligned with design docs (#183)** — 19 semantic custom properties (`--bg-main`, `--text-body`, `--text-link`, `--btn-text`…) replace abbreviated names.
- **CLI config fallback (#185)** — `cli/_bootstrap.php` now searches `$HOME/.phpman/phpman.config.php` when project-root config is absent.
- **batch-enhance status config dump (#188)** — `LLM_API_KEY` added to displayed keys (masked); dead masking code removed.
- **GA4 script XHTML compliance (#187)** — `async` replaced with `type="text/javascript"` (tradeoff documented).

### Changed
- **CSS refactored** — all hardcoded hex colors replaced with semantic custom properties in `:root`, one `@media` block for Hakusho, `[data-theme]` overrides with higher specificity (0-2-0).
- **Search index rebuilt** — staging + production reindexed (13,835 entries, 0 errors).

## [3.6.1] — 2026-06-08

### Changed
- **getSearchPage() single FTS5 query covers man/pydoc/ri** — removed `AND section NOT IN ('pydoc','ri')` filter; results routed by section into `$lines` (man), `$pydocFtsLines` (pydoc), `$riFtsLines` (ri)
- Search cascade uses FTS5 results as baseline, only falls back to command-line `pydoc3 -k` / `ri` when FTS5 had no hits for that source
- Removed `searchFtsBySource()` calls from cascade — no longer needed since FTS5 query already covers all sources
- Updated `docs/SEARCH_FTS5_DESIGN.md` to v4: single-query architecture, two-level search flow

## [3.6] — 2026-06-08

### Added
- **FTS5 index now includes pydoc3 and ri entries** — `rebuildSearchIndex()` indexes `pydoc3 modules` (section='pydoc') and `ri -l` (section='ri') alongside man pages
- **`expandNameForFts()` case-insensitive matching** — appends lowercase + dot/colon expansion: `JSON::Ext::Parser` also matches `json`, `parser`, `ext parser`
- **`searchFtsBySource()`** — queries FTS5 for pydoc/ri entries by section (retained as utility function)

### Changed
- Search mode always aggregates apropos + pydoc3 + ri results (no longer only cascades when apropos is empty)
- `getSearchPage()` FTS5 availability check uses all sources (`SELECT COUNT(*) FROM search_index_meta`) not just `source='man'`

### Fixed
- `getRiSearchPage()` now filters `.xxx not found` responses from `ri` command
- Updated test expectations for expanded `expandNameForFts()` output

## [3.5] — 2026-06-06

### Added
- **Standalone rebuild-index.php** — cron-based FTS5 index maintenance with positional dir argument, `--help`, and auto-clear search cache

### Changed
- Cache directories renamed to `phpman_cache/{staging,production}`
- Documentation uses `/path/to` style instead of `/home/your-user`
- `TEST_USER`/`DEMO_USER` merged into single `HOST` as `user@host`

### Fixed
- FTS5 duplicate entries deduplicated via `search_index_meta` before INSERT
- `rebuild-index.php` no-arg now shows help; cron uses full `php` path
- Absolute path for `phpman.css` to work on nested URLs
- CSS extracted to `phpman.css` with try/catch PRAGMA WAL

## [3.4] — 2026-06-05

### Fixed
- SQLite busy timeout moved before PRAGMA exec to prevent "database is locked"
- Removed mobile alpha sidebar overrides — same 30px sidebar for all viewports

## [3.3] — 2026-06-04

### Added
- **Alphabet index sidebar** for search/index pages with >80 results, extended to pydoc/ri index pages
- Search page caching with `hits++` UPDATE removed on cache reads

### Fixed
- Alpha sidebar embedded in cached HTML to survive `cacheOrExecute`
- Mobile alpha sidebar: column layout with larger touch targets, body-consistent sizing
- `#` (symbols) moved to front of alphabet sidebar to match page order

### Changed
- Font sizes normalized to 12px and 14px only (except H1)

## [3.2] — 2026-06-03

### Added
- **Alphabet index sidebar** for search/index pages with >80 results

### Fixed
- Empty TLDR block suppressed when examples have no command text

## [3.1] — 2026-06-03

### Added
- **FTS5 full-text search engine** with profiler and search cascade optimization

### Fixed
- MCP format link hidden on 404/search fallback pages

## [3.0] — 2026-06-02

### Added
- **SQLite cache engine** — persistent disk cache with TTL, negative cache, and WAL mode
- **phpman.config.php** — external configuration file (WordPress wp-config style)
- `SECURITY.md` with vulnerability reporting policy
- Git version tag in footer and deploy pipeline
- Collapsible mobile TOC: narrow screen default collapsed, title row clickable toggle

### Changed
- Config switched to `define()` pattern
- TLDR scoped to man section 1 only, 404 log spam removed
- Standalone `/tldr` route removed — TLDR integrated across all 4 formats
- Security boundary update: rate limit/gzip/headers are server-layer duty
- No root-path files (robots.txt/sitemap/llms.txt) — phpMan may not be at root
- `favicon.png` → `favicon.ico` with correct MIME type

### Fixed
- Code review fixes (#82-#88): pydoc index list format, design doc updates
- `isLocalRequest()` deprecated — HSTS/version to Nginx, debug to env var
- H1 breadcrumb + title format, JSON-LD fix
- MCP error masking

### Added
- **pydoc3 (Python 3) documentation mode** — `/pydoc/{module}/{format}` with HTML/Markdown/JSON/MCP output
- **ri (Ruby) documentation mode** — `/ri/{Class#method}/{format}` with HTML/Markdown/JSON/MCP output
- **Search cascade** — `apropos` → `pydoc3 -k` → `ri` search in all formats (HTML, Markdown, JSON, MCP)
- **pydoc module index** — `pydoc3 modules` parsed and rendered in all 4 formats
- **ri class index** — `ri -l` listing rendered in all 4 formats
- **Auto-detection in MCP cli_help** — dotted names (`json.loads`) → pydoc, `#` suffix (`Array#map`) → ri, `::` → perldoc
- **pydoc class/function heading detection** — indented `class Name(Parent)` and `funcName(args)` as L2 subsections
- **ri RDoc heading detection** — `= Section` and `== Subsection` markers exclusive to ri mode
- **Mode-specific link patterns** — parent class links in pydoc, `::` constant links in ri
- **Not found fallback links** — Python Docs search for pydoc, Ruby-Doc search for ri
- **TOC label cleaning** — strip RDoc `=` / `==` prefixes from TOC entries

### Changed
- MCP tool description updated to mention pydoc3 and ri
- Search radio button order: pydoc and ri placed after info

### Fixed
- Man page regex `[\dnol]\w*` → `(\d\w*|n)` to avoid false matches with pydoc/ri parameter names
- "Not found locally" message not showing for pydoc/ri detail pages
- `PHPMAN_WIDTH` converted from variable to `define()` constant (shared by man + perldoc)

## [2.5] — 2026-06-02

### Fixed
- pydoc index list format: `<ul><li>` instead of `<pre><a><br/>`

## [2.4.1] — 2026-06-02

### Changed
- pydoc index: use `<ul><li>` list format instead of `<pre><a><br/>`

## [2.4] — 2026-06-02

### Added
- Cache design v3.0 with real metrics, PHP 7.2+ floor
- Git version tag in footer and deploy pipeline
- Collapsible mobile TOC

### Changed
- `isLocalRequest()` deprecated — HSTS/version to Nginx, debug to env var
- Security boundary update: rate limit/gzip/headers are server-layer duty
- No root-path files (robots.txt/sitemap/llms.txt)
- `favicon.png` → `favicon.ico`

### Fixed
- H1 breadcrumb + title format
- JSON-LD fix
- MCP error masking

## [2.3] — 2026-06-01

### Added
- **pydoc3 (Python 3) documentation mode** — `/pydoc/{module}/{format}`
- **ri (Ruby) documentation mode** — `/ri/{Class#method}/{format}`
- **Search cascade** — `apropos` → `pydoc3 -k` → `ri`
- **pydoc module index** — `pydoc3 modules` parsed
- **ri class index** — `ri -l` listing
- **Auto-detection in MCP cli_help** — dotted names → pydoc, `#` suffix → ri, `::` → perldoc
- **pydoc class/function heading detection**
- **ri RDoc heading detection**
- **Mode-specific link patterns**
- **Not found fallback links**
- **TOC label cleaning**

### Changed
- MCP tool description updated
- Search radio button order

### Fixed
- Man page regex fix
- "Not found locally" message for pydoc/ri
- `PHPMAN_WIDTH` → `define()` constant

## [2.2] — 2026-06-02

### Added
- **Official tldr-pages embedding** — TLDR cheatsheets from [tldr-pages/tldr](https://github.com/tldr-pages/tldr) GitHub raw, embedded at top of man/perldoc detail pages in all 4 output formats (HTML, JSON, Markdown, MCP)
- **cheat.sh fallback** — commands not covered by tldr-pages fallback to [cheat.sh](https://cheat.sh) plain-text API, with automatic parsing
- **TLDR source attribution** — `tldr-pages` or `cheat.sh` source label shown in HTML TLDR block header, JSON `tldr.source` field, MCP `tldr_source` field, and Markdown `*Source:*` line
- **Clickable TLDR header** — TLDR block title links to full page on tldr.inbrowser.app (official) or cheat.sh
- **Bracket-to-bold conversion** — tldr-pages `[x]` shortcut notation rendered as `<b>x</b>` in HTML TLDR examples

### Changed
- TLDR endpoint: official tldr-pages → cheat.sh → LLM → extraction, zero-config by default
- Fallback link on man detail pages: Linux Command Library → cheat.sh
- Removed "TLDR Docs" nav link (TLDR content now embedded inline)
- Repository migrated from SourceForge to GitHub ([chedong/phpman](https://github.com/chedong/phpman))
- Roadmap reorganized into v2.2 / v3.0 version milestones (PLAN.md, CACHE_DESIGN.md)
- Removed `index.html` (SF static site now deployed independently)

### Security
- `role="search"` removed from `<form>` — invalid in XHTML 1.0 Transitional

## [2.1] — 2026-05-31

### Added
- **Cross-platform width control** — `MANWIDTH` fallback for BSD/macOS, `pod2text -w N` for perldoc
- BSD/macOS fallback when `-Tutf8` is unsupported
- TLDR endpoint (`/tldr?page=...`)
- Project roadmap (`PLAN.md`) with priority matrix

### Changed
- Deploy config split into staging (`make deploy`) and production (`make release`)
- Static `.well-known/` directory removed — MCP discovery is fully dynamic

## [2.0] — 2026-05-29

### Added
- **MCP (Model Context Protocol) server mode** — `/mcp` REST endpoint + JSON-RPC POST
- **Semantic JSON output** — `command`, `summary`, `flags` (flag/long/arg), `examples`, `see_also` with URLs
- TLDR, Cheat, Translate footer links on detail pages
- SKILL.md for AI agent integration
- `.well-known/mcp.json` auto-discovery + deploy system
- Regression test script for external validation

### Changed
- Test suite restructured with 4-level architecture (209 tests)
- TOC sidebar: enforced 80-line threshold, all test cases audited
- Directory renamed `doc/` → `docs/`

### Fixed
- ParseFlagJSON: trailing comma on short flags, standalone arg placeholders
- ALL CAPS sections (SEE ALSO, etc.) incorrectly detected as L2 headings
- Man page header/footer lines filtered from section detection
- Overstrike patterns breaking UTF-8 in man pages with Unicode characters
- Footer links for non-section-1 man pages

### Performance
- **gzip + ETag** for JSON/MCP output (bash manpage 351KB → 97KB gzipped, repeat requests 304 in 2ms)

### Security
- `PHP_SELF` XSS fix — use `h(scriptName())` instead of raw `$_SERVER['PHP_SELF']`
- `getSafeHost()` prevents Host header injection on canonical URL / Schema.org / validator links

## [2.0-rc] — 2026-05-28

### Added
- MCP server mode (first implementation)
- Deployment Makefile with SourceForge FRS upload
- JSON format link added to footer
- SYNOPSIS extracted as top-level `synopsis` field in JSON output
- Mobile responsive CSS — viewport meta, touch-friendly form, `pre-wrap`, P0–P2 improvements

### Changed
- REST `/mcp` format unified with POST `/mcp` JSON-RPC output
- Unified title/h1 format: `{page} - {mode} - phpMan`
- TOC sidebar renamed from "Sections" to "TOC", width doubled (160→320px)
- README translated to English, added man/info/perldoc mode comparison

### Fixed
- Markdown heading level differentiation: `##` for L1, `###` for L2
- Man page `.SH` and `.SS` headings detected for `##`/`###` markers
- Perldoc sub-section headings correctly rendered as `###`
- Bold-formatted man `.SH` headings detected as L1 in TOC
- Man `.TP` tagged paragraphs detected as L2 for config variables
- JSON section detection unified with HTML/Markdown via `detectHeadingType()`
- TOC sidebar shown when 1 L1 section has L2 subsections
- TOC sidebar shown on short man pages; indent false positives prevented
- `formatToJSON()` failure returns `'{}'` instead of `false`
- `section=1` no longer forced when no section specified
- `-Tascii` removed so `MANWIDTH` env var controls line length
- `_^H` overstrike rendering — correctly maps to `<u>`, restored full `<b>` pattern
- SGR regex patterns handle modern man-db output (`ESC[22m`)
- `GROFF_NO_SGR=1` restored for `<b>`/`<u>` tag extraction
- XHTML: 224 duplicate `id` errors fixed
- UTF-8 encoding and W3C validation errors fixed
- Accessibility improved — labels, contrast, `lang` attribute
- Correct SourceForge thumbnail URL (750×400)
- `MANWIDTH=128` preserved in output with overflow scroll

### Security
- Server version hidden (local-only), perldoc index param bug fixed
- Cache reduced 30d → 7d, format whitelist enforced
- `stripslashes` removed, `substr_count` used for safer parsing
- Source and phpinfo entry links removed

### Removed
- Translate link removed from footer
- Internal implementation comment removed from HTML header

---

## 2026 Modern Rewrite

The modern phpMan was rebuilt from scratch starting 2026-05-22 as a single-file PHP application,
replacing the original multi-file CGI-era codebase from 2002. Initial features included:

- Two-level TOC sidebar based on indentation
- Section anchors and floating TOC
- Back-to-top CSS button (no JavaScript required)
- `MANWIDTH=128` with horizontal overflow scroll
- Overstrike (`_^H`) parsing for bold/underline HTML tags
- XHTML 1.0 Transitional output with W3C validation
- SourceForge FRS deployment workflow
- SEO: auto-detect `base_url` from `SCRIPT_NAME` + `HTTP_HOST`

---

## phpMan 2.0 (Original) — 2002-06-05

The original phpMan 2.0, released on SourceForge under the `phpunixman` project.

### Added
- GPL license
- CSS-styled HTML output (XHTML/CSS valid)
- **Man page viewer** with section navigation
- **Perldoc viewer** with module index
- **Info page viewer** (GNU info format)
- Search via `man -k` / `apropos`
- Default landing pages for man, perldoc, and info modes
- Screen-size auto-fit
- Related command/module cross-links
- Email transfer (send man page via email)
- Source code viewer

### Fixed (Jul 2002)
- Perldoc bug with space-to-`%20` translation
- Code formatted with `astyle -j`

### Security
- `escapeshellcmd()` to prevent arbitrary command execution

## phpMan 1.0 (Original) — 2002-05-28

Initial release. A basic PHP-based Unix manual page viewer hosted on SourceForge.

---

## Project Origins — 2002-01-18

Initial checkin to SourceForge CVS. A PHP script to browse Unix man pages over the web.

---

[Unreleased]: https://github.com/chedong/phpman/compare/v3.5...HEAD
[3.5]: https://github.com/chedong/phpman/releases/tag/v3.5
[3.4]: https://github.com/chedong/phpman/compare/v3.4...v3.5
[3.3]: https://github.com/chedong/phpman/compare/v3.3...v3.4
[3.2]: https://github.com/chedong/phpman/compare/v3.2...v3.3
[3.1]: https://github.com/chedong/phpman/compare/v3.1...v3.2
[3.0]: https://github.com/chedong/phpman/compare/v3.0...v3.1
[2.5]: https://github.com/chedong/phpman/compare/v2.4.1...v2.5
[2.4.1]: https://github.com/chedong/phpman/compare/v2.4...v2.4.1
[2.4]: https://github.com/chedong/phpman/compare/v2.3...v2.4
[2.3]: https://github.com/chedong/phpman/releases/tag/v2.3
[2.2]: https://github.com/chedong/phpman/compare/v2.1...v2.2
[2.1]: https://github.com/chedong/phpman/compare/v2.0...v2.1
[2.0]: https://github.com/chedong/phpman/releases/tag/v2.0
