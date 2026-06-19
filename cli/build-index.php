#!/usr/bin/env php
<?php
/**
 * cli/build-index.php — Rebuild the FTS5 search index.
 *
 * Replaces: php phpMan.php --build-index
 *           php phpMan.php --build-index-cron
 *
 * Usage:
 *   php cli/build-index.php          Rebuild index
 *   php cli/build-index.php --cron   Rebuild with UTC timestamp (for cron)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    die("CLI only\n");
}

// Resolve PHPMAN_HOME the same way phpMan.php does
$config_file = __DIR__ . '/../phpman.config.php';
if (file_exists($config_file)) {
    require $config_file;
}

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

$cron = in_array('--cron', $argv ?? []);
$result = rebuildSearchIndex();

if ($cron) {
    echo '[' . gmdate('Y-m-d H:i:s') . "]\n";
}
echo $result;
