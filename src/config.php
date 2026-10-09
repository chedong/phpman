<?php
// ─── CONFIGURATION ───────────────────────────────────────────────────────────
// TWO files, one pattern (like WordPress wp-config.php):
//
//   1. phpman.config.php     ← USER OVERRIDES (edit this, NOT this file)
//   2. src/config.php        ← SYSTEM DEFAULTS (this file — DO NOT EDIT)
//
// Load order: phpman.config.php is loaded FIRST (lines below), then
// defaults are defined with if(!defined('X')) guards. User defines always win.
//
// To change any setting: copy phpman.config.php.example → phpman.config.php,
// uncomment and edit the relevant define() line.
// ──────────────────────────────────────────────────────────────────────────────

// Load user overrides FIRST (so defaults don't overwrite them)
// Search order: 1) ../phpman.config.php (dev checkout: src/ next to the config)
//               2) PHPMAN_HOME/phpman.config.php  (deployed install)
//               3) $HOME/.phpman/phpman.config.php
//
// (2) is not redundant with (1): a deployed install does not have to keep src/
// as a direct child of PHPMAN_HOME. The atomic-release layout puts it at
// PHPMAN_HOME/releases/<id>/src/, so (1) misses there. Without (2) the search
// would jump straight to (3) — a hardcoded $HOME/.phpman — and a *staging*
// install would silently load production's config, MCP_API_KEY included. That
// is exactly what happened on 2026-10-02; it showed up as staging MCP requests
// failing closed with -32001 Unauthorized.
$_config_file = dirname(__DIR__) . '/phpman.config.php';
if (!file_exists($_config_file)
    && defined('PHPMAN_HOME')
    && PHPMAN_HOME !== ''
    && !str_starts_with(PHPMAN_HOME, '__')) {   // unpatched __PHPMAN_HOME__ placeholder
    $_config_file = PHPMAN_HOME . '/phpman.config.php';
}
if (!file_exists($_config_file)) {
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    $_config_file = $home . '/.phpman/phpman.config.php';
}
if (file_exists($_config_file)) {
    require_once $_config_file;
}

// ═══════════ SYSTEM DEFAULTS BELOW — DO NOT EDIT ═════════════════════════════
// Override any of these in phpman.config.php instead.

// Default terminal width for man/perldoc output (#49: character width, used as MANROFFOPT -rLL=NNNn).
// Override in phpman.config.php via define('PHPMAN_WIDTH', 120);
if (!defined('PHPMAN_WIDTH')) {
    define('PHPMAN_WIDTH', 100);
}

// Tuning knobs — overrideable in phpman.config.php
if (!defined('PHPMAN_TOC_THRESHOLD')) {
    define('PHPMAN_TOC_THRESHOLD', 80);       // min lines to show TOC sidebar
}
if (!defined('PHPMAN_GZIP_MIN_BYTES')) {
    define('PHPMAN_GZIP_MIN_BYTES', 1000);     // min response size for gzip compression
}
if (!defined('PHPMAN_TLDR_MAX_EXAMPLES')) {
    define('PHPMAN_TLDR_MAX_EXAMPLES', 16);     // max examples in TLDR output
}
if (!defined('PHPMAN_JSON_MAX_CONTENT_BYTES')) {
    define('PHPMAN_JSON_MAX_CONTENT_BYTES', 1048576);  // total section text kept for json/mcp output (1MB)
}
if (!defined('PHPMAN_JSON_MAX_SECTION_BYTES')) {
    define('PHPMAN_JSON_MAX_SECTION_BYTES', 524288);   // per-section share of that budget (512KB)
}
// Whole-page cap for the markdown body. Deliberately separate from (and much
// larger than) the JSON budget above: that one bounds section *text* for machine
// consumers, this one only exists so a runaway page cannot exhaust memory_limit
// while the body is concatenated and then gzcompressed for the cache. (#227)
//
// Sized from measurement, not guesswork (web SAPI is 128M):
//   info py, the largest page served — 17.9MB raw, 18.0MB out, 60MB peak, 8.0s.
//   A synthetic 52.7MB page — "Allowed memory size of 134217728 bytes exhausted"
//   in format_markdown.php, which is the 500 this cap exists to prevent.
// So the cap sits above every real page (nothing truncates today) while still
// refusing to grow without bound. Lower it and info py starts losing its tail.
if (!defined('PHPMAN_MD_MAX_BYTES')) {
    define('PHPMAN_MD_MAX_BYTES', 25165824);   // 24MB retained markdown body
}
// Unified data-cache TTL: PageCache found entries + TLDR cache expire on the
// same schedule so no cache tier silently outlives another. "Month" = 30 days
// for determinism → 7 months = 210 days = 18,144,000s.
// (Emoji-enhanced output is intentionally exempt — never expires, see cache.php.)
if (!defined('PHPMAN_CACHE_TTL_MONTHS')) {
    define('PHPMAN_CACHE_TTL_MONTHS', 7);
}
if (!defined('PHPMAN_CACHE_TTL_FOUND')) {
    define('PHPMAN_CACHE_TTL_FOUND', PHPMAN_CACHE_TTL_MONTHS * 30 * 86400);
}
if (!defined('PHPMAN_CACHE_TTL_NOT_FOUND')) {
    define('PHPMAN_CACHE_TTL_NOT_FOUND', 86400);  // 1 day — retry missing pages sooner
}
if (!defined('PHPMAN_GA_ID')) {
    define('PHPMAN_GA_ID', '');                  // Google Analytics GA4 measurement ID (empty = disabled)
}
if (!defined('PHPMAN_ADSENSE_ID')) {
    define('PHPMAN_ADSENSE_ID', '');              // Google AdSense publisher ID (empty = disabled)
}
if (!defined('PHPMAN_HOME_TITLE')) {
    define('PHPMAN_HOME_TITLE', 'phpman - Linux Command Reference, JSON API & MCP Server for AI Agents');
}
if (!defined('PHPMAN_PROJECT_NAME')) {
    define('PHPMAN_PROJECT_NAME', 'phpman');
}

// PHPMAN_HOME: base directory for all local data (cache, logs, backups).
// Default: PHPMAN_HOME env var > HOME env var > $_SERVER['HOME'] > posix_getpwuid
// staging should use ~/.phpman_test to avoid sharing DB/logs/backups with production.
//
// phpMan.php pre-defines PHPMAN_HOME = '__PHPMAN_HOME__' (replaced by sed at deploy).
// Locally, we resolve the placeholder to ~/.phpman for filesystem operations.
$_phpman_home = defined('PHPMAN_HOME') ? PHPMAN_HOME : '';
if ($_phpman_home === '' || $_phpman_home === '__PHPMAN_HOME__' || str_starts_with($_phpman_home, '__')) {
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if (!$home && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_getuid());
        $home = $pw['dir'] ?? '';
    }
    $_phpman_home = getenv('PHPMAN_HOME') ?: $home . '/.phpman';
}

// Derived paths — use resolved $_phpman_home, not raw PHPMAN_HOME constant
if (!defined('PHPMAN_CACHE_DIR')) {
    define('PHPMAN_CACHE_DIR', $_phpman_home . '/db');
}
if (!defined('PHPMAN_LOG_DIR')) {
    define('PHPMAN_LOG_DIR', $_phpman_home . '/logs');
}
if (!defined('PHPMAN_BACKUP_DIR')) {
    define('PHPMAN_BACKUP_DIR', $_phpman_home . '/backups');
}

// Fixed filenames under derived dirs (not configurable)
define('PHPMAN_CACHE_DB', PHPMAN_CACHE_DIR . '/phpman_cache.db');
define('PHPMAN_LOG_FILE', PHPMAN_LOG_DIR . '/phpman_error.log');
// There is no CACHE_SCHEMA_VERSION. Both cache DBs create their schema with
// CREATE ... IF NOT EXISTS on every open, so an additive change needs no
// migration; and since the pre-sharding DB is gone and rollback to older code is
// not supported, there is no older shape to migrate from. The version stamps
// that used to record it (meta.schema_version, PRAGMA user_version) existed so a
// rolled-back deploy could re-stamp and re-migrate — that is the rollback
// provision, and it is gone. For invalidating cached *output*, see
// RENDERER_VERSION below; that one is read back, which is the whole difference.

// RENDERER_VERSION — bump this by hand whenever a change alters the *rendered
// output* of a page (formatForOutput(), the HTML/markdown/JSON renderers, the
// OSC-8 link handling). PageCache::get() treats any row written by a different
// version as a miss, so a bump invalidates stale renders lazily: each page is
// re-rendered on its next request and the row overwritten in place. Nothing has
// to be purged by hand, and there is no re-render herd, because the whole cache
// is never dropped at once.
//
// This exists because the page cache stores finished output under a 210-day TTL
// (PHPMAN_CACHE_TTL_FOUND), so a renderer fix otherwise never reaches a page
// that was already cached — which is exactly what happened to the OSC-8 and
// JSON-markdown fixes before v4.11.3, and needed a 1,703-row manual purge.
//
// Do NOT auto-derive this from a deploy stamp or a source hash: that would
// invalidate on every deploy, including ones that cannot change a single byte of
// output.
//
// History to avoid repeating: schema v5→v6 dropped cache.generator_version,
// which held GIT_DESCRIBE on every set(). Nothing ever read it back, so it
// invalidated nothing. A version is only worth storing if get() filters on it.
//
// 2 — the footer stopped emitting the dead /{mode}/{param}/mcp link (#238).
//     Cached HTML still held it, so the deploy alone did not reach a single
//     already-cached page: the footer link stayed live on production until this
//     bump. Any change to what showFooter()/showHeader() emit needs a bump for
//     the same reason as a formatter change.
define('RENDERER_VERSION', 2);

// PHPMAN_VERSION — numeric version (e.g. "4.9.19").
// Set by Makefile in phpMan.php at deploy time; provide fallback for CLI scripts.
if (!defined('PHPMAN_VERSION')) {
    define('PHPMAN_VERSION', defined('GIT_DESCRIBE') ? ltrim(GIT_DESCRIBE, 'v') : '0.0.0');
}

// Include server-specific tool availability (generated by cli/detect-tools.php during deploy).
// Defines PHPMAN_HAS_PERLDOC, PHPMAN_HAS_PYDOC, PHPMAN_HAS_RI, PHPMAN_HAS_INFO.
// If the file is missing (pre-deploy or manual install), default to showing all tools.
$toolsConfig = PHPMAN_HOME . '/tools_config.php';
if (file_exists($toolsConfig)) {
    require $toolsConfig;
} else {
    // Fallback: assume all tools are available (graceful — UI shows all options)
    if (!defined('PHPMAN_HAS_PERLDOC')) define('PHPMAN_HAS_PERLDOC', true);
    if (!defined('PHPMAN_HAS_PYDOC'))   define('PHPMAN_HAS_PYDOC',   true);
    if (!defined('PHPMAN_HAS_RI'))      define('PHPMAN_HAS_RI',      true);
    if (!defined('PHPMAN_HAS_INFO'))    define('PHPMAN_HAS_INFO',    true);
}

// ASCII character classes for overstrike pattern matching:
// RE_ASCII — plain printable ASCII, for raw terminal output (cleanTerminalOutput)
// RE_ASCII_SAFE — printable + placeholder bytes \x05\x06\x07 for &<>, used after
//                 formatManPerlDoc() replaces &<> with placeholders
// Moved here (v4.9.9) so CLI tools can access them without
// loading phpMan.php. These are used by src/format_common.php.
define('RE_ASCII', '[ -~]');
define('RE_ASCII_SAFE', '[ -~' . "\x05\x06\x07" . ']');

// #145: Named constants for cache sentinel and format strings
define('CACHE_SENTINEL_NOT_FOUND', '###NOT_FOUND###');
define('CACHE_FORMAT_JSON',      'json');
define('CACHE_FORMAT_SEARCH',    'search');
define('CACHE_FORMAT_HTML',      'html');
define('CACHE_STATUS_FOUND',     'found');
define('CACHE_STATUS_NOT_FOUND', 'not_found');
define('PHPMAN_CONTENT_MODES', ['man', 'perldoc', 'info', 'pydoc', 'ri']);

// Output formats selectable from a URL segment or ?format=.
// `mcp` is deliberately NOT here: it is an internal rendering core that
// handleMcp() calls (getManPage(..., "mcp")), not a public output format.
// Exposing it as /{mode}/{param}/mcp served the same envelope without any of
// the JSON-RPC endpoint's guards — no API key, no 64KB body cap, no POST-only
// check — and was cacheable for 7 days. See CHANGELOG (Unreleased).
define('PHPMAN_OUTPUT_FORMATS', ['html', 'markdown', 'json']);

// Ensure log dir exists, then set error_log target
if (!is_dir(PHPMAN_LOG_DIR)) @mkdir(PHPMAN_LOG_DIR, 0755, true);
@ini_set('error_log', PHPMAN_LOG_FILE);

// MCP API key: every POST /mcp request must carry it in the X-Api-Key header.
// Fail-closed — the default is empty, and an empty key returns 401 for every
// request rather than disabling the check. Set it in ~/.phpman/phpman.config.php.
if (!defined('MCP_API_KEY')) define('MCP_API_KEY', '');

// Debug mode: phpman.config.php > env var > default false
if (!defined('PHPMAN_DEBUG')) define('PHPMAN_DEBUG', getenv('PHPMAN_DEBUG') === 'true');
// Profiler::init() called by cache.php after class definition
