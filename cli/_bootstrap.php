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

// Load phpMan core functions directly from src/ — no web dispatcher needed.
// Prefer the checked-out project source for maintainer CLI runs; fall back to
// PHPMAN_HOME/src for installed deployments.
define('PHPMAN_NO_CLI_DISPATCH', true);
$project_bootstrap = dirname(__DIR__) . '/src/bootstrap.php';
$installed_bootstrap = rtrim(PHPMAN_HOME, '/') . '/src/bootstrap.php';
if (file_exists($project_bootstrap)) {
    require_once $project_bootstrap;
} elseif (file_exists($installed_bootstrap)) {
    require_once $installed_bootstrap;
} else {
    fwrite(STDERR, "phpMan bootstrap not found. Checked:\n");
    fwrite(STDERR, "  - {$project_bootstrap}\n");
    fwrite(STDERR, "  - {$installed_bootstrap}\n");
    exit(1);
}
