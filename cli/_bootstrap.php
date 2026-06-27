<?php
// cli/_bootstrap.php — shared CLI bootstrap: resolve PHPMAN_HOME + load phpMan core.
// Included by all CLI tools to avoid duplicating ~20 lines of setup.

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    die("CLI only\n");
}

// Load site config (may define PHPMAN_HOME, PHPMAN_DEBUG, etc.)
// Search: 1) project-root, 2) $HOME/.phpman/ (matches src/config.php fallback)
$config_file = __DIR__ . '/../phpman.config.php';
if (!file_exists($config_file)) {
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    $config_file = $home . '/.phpman/phpman.config.php';
}
if (file_exists($config_file)) {
    require $config_file;
}

// Resolve PHPMAN_HOME if not already defined in config
if (!defined('PHPMAN_HOME') || PHPMAN_HOME === '') {
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if (!$home && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_getuid());
        $home = $pw['dir'] ?? '';
    }
    define('PHPMAN_HOME', getenv('PHPMAN_HOME') ?: $home . '/.phpman');
}

// Constants normally defined in phpMan.php (web dispatcher).
// When loading src/ directly via bootstrap, define them here so
// format_common.php, enhance.php etc. don't crash on undefined constants.
if (!defined('RE_ASCII'))      define('RE_ASCII', '[ -~]');
if (!defined('RE_ASCII_SAFE')) define('RE_ASCII_SAFE', '[ -~' . "\x05\x06\x07" . ']');
if (!defined('PHPMAN_VERSION')) define('PHPMAN_VERSION', '0.0.0');
if (!defined('GIT_DESCRIBE'))  define('GIT_DESCRIBE', 'cli');

// Load phpMan core functions directly from src/ — no web dispatcher needed
define('PHPMAN_NO_CLI_DISPATCH', true);
require_once PHPMAN_HOME . '/src/bootstrap.php';
