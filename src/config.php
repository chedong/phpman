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
// Search order: 1) ../phpman.config.php (webroot-relative), 2) $HOME/.phpman/
$_config_file = dirname(__DIR__) . '/phpman.config.php';
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
if (!defined('PHPMAN_ENHANCE_MAX_CHARS')) {
    define('PHPMAN_ENHANCE_MAX_CHARS', 32000);  // max chars for LLM enhance output
}
if (!defined('PHPMAN_GA_ID')) {
    define('PHPMAN_GA_ID', '');                  // Google Analytics GA4 measurement ID (empty = disabled)
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
if (!defined('PHPMAN_HOME')) {
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if (!$home && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_getuid());
        $home = $pw['dir'] ?? '';
    }
    define('PHPMAN_HOME', getenv('PHPMAN_HOME') ?: $home . '/.phpman');
}

// Derived paths — can be overridden individually in phpman.config.php
if (!defined('PHPMAN_CACHE_DIR')) {
    define('PHPMAN_CACHE_DIR', PHPMAN_HOME . '/db');
}
if (!defined('PHPMAN_LOG_DIR')) {
    define('PHPMAN_LOG_DIR', PHPMAN_HOME . '/logs');
}
if (!defined('PHPMAN_BACKUP_DIR')) {
    define('PHPMAN_BACKUP_DIR', PHPMAN_HOME . '/backups');
}

// Fixed filenames under derived dirs (not configurable)
define('PHPMAN_CACHE_DB', PHPMAN_CACHE_DIR . '/phpman_cache.db');
define('PHPMAN_LOG_FILE', PHPMAN_LOG_DIR . '/phpman_error.log');
define('CACHE_SCHEMA_VERSION', '5');

// #145: Named constants for cache sentinel and format strings
define('CACHE_SENTINEL_NOT_FOUND', '###NOT_FOUND###');
define('CACHE_FORMAT_JSON',      'json');
define('CACHE_FORMAT_SEARCH',    'search');
define('CACHE_FORMAT_HTML',      'html');
define('CACHE_FORMAT_EMOJI_MD',  'emoji_md');
define('CACHE_FORMAT_EMOJI_HTML', 'emoji_html');
define('CACHE_STATUS_FOUND',     'found');
define('CACHE_STATUS_NOT_FOUND', 'not_found');
define('PHPMAN_CONTENT_MODES', ['man', 'perldoc', 'info', 'pydoc', 'ri']);

// Ensure log dir exists, then set error_log target
if (!is_dir(PHPMAN_LOG_DIR)) @mkdir(PHPMAN_LOG_DIR, 0755, true);
@ini_set('error_log', PHPMAN_LOG_FILE);

// MCP API key: if defined in config, all MCP requests require this key in X-API-Key header
if (!defined('MCP_API_KEY')) define('MCP_API_KEY', '');

// Debug mode: phpman.config.php > env var > default false
if (!defined('PHPMAN_DEBUG')) define('PHPMAN_DEBUG', getenv('PHPMAN_DEBUG') === 'true');
// Profiler::init() called by cache.php after class definition

// LLM config (v3.0 reserved, not yet used)
if (!defined('LLM_API_KEY'))    define('LLM_API_KEY', '');
if (!defined('LLM_API_URL'))    define('LLM_API_URL', '');
if (!defined('LLM_MODEL'))      define('LLM_MODEL', '');
if (!defined('LLM_MAX_TOKENS')) define('LLM_MAX_TOKENS', 409600);

