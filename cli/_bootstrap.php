<?php
// cli/_bootstrap.php — shared CLI bootstrap: resolve PHPMAN_HOME + load phpMan.php
// Included by all CLI tools to avoid duplicating ~20 lines of setup.

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    die("CLI only\n");
}

// Load site config (may define PHPMAN_HOME, LLM_API_KEY, etc.)
$config_file = __DIR__ . '/../phpman.config.php';
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

// Load phpMan core (functions only, no web dispatch)
define('PHPMAN_NO_CLI_DISPATCH', true);
require_once PHPMAN_HOME . '/phpMan.php';
