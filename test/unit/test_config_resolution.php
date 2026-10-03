<?php
/**
 * Unit tests: phpman.config.php resolution order (src/config.php)
 *
 * Regression test for the 2026-10-02 staging incident. The atomic-release
 * layout keeps src/ at PHPMAN_HOME/releases/<id>/src/, so the "config next to
 * src/" lookup — dirname(__DIR__) — misses there. Before the fix the search
 * fell straight through to the hardcoded $HOME/.phpman/phpman.config.php, so a
 * staging install silently loaded *production's* config, MCP_API_KEY included.
 * Staging MCP then failed closed with -32001 Unauthorized and the agent e2e
 * suite died at A03 tools/list.
 *
 * These cases run the real src/config.php in a subprocess: it resolves its own
 * path from __DIR__ at require time, so it cannot be exercised in-process.
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

$realConfig = realpath(__DIR__ . '/../../src/config.php');
if ($realConfig === false) {
    fwrite(STDERR, "cannot locate src/config.php\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/phpman_cfg_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));

// Atomic-release layout: the install's config sits in PHPMAN_HOME, while src/
// is one level deeper, inside the release directory.
mkdir($tmp . '/install/releases/r1/src', 0777, true);
mkdir($tmp . '/home/.phpman', 0777, true);
copy($realConfig, $tmp . '/install/releases/r1/src/config.php');

// The install's own config — what SHOULD be found ...
file_put_contents($tmp . '/install/phpman.config.php', "<?php define('CFG_MARKER', 'phphome');\n");
// ... and the production-shaped fallback that must NOT be reached.
file_put_contents($tmp . '/home/.phpman/phpman.config.php', "<?php define('CFG_MARKER', 'homefallback');\n");

/**
 * Load a copy of config.php in a fresh process and report which user config it
 * picked up.
 */
function resolve_marker(string $src, ?string $phpHome, string $home): string {
    $pre  = $phpHome === null ? '' : 'define("PHPMAN_HOME", ' . var_export($phpHome, true) . ');';
    $code = $pre . ' require ' . var_export($src, true) . ';'
          . ' echo defined("CFG_MARKER") ? CFG_MARKER : "(none)";';
    $out  = shell_exec('HOME=' . escapeshellarg($home) . ' php -r ' . escapeshellarg($code) . ' 2>/dev/null');
    return trim((string)$out);
}

$releaseSrc = $tmp . '/install/releases/r1/src/config.php';

echo "=== Unit: phpman.config.php resolution ===\n\n";
echo "--- atomic-release layout (src/ under releases/<id>/) ---\n";

assert_equals('phphome', resolve_marker($releaseSrc, $tmp . '/install', $tmp . '/home'),
    "PHPMAN_HOME/phpman.config.php wins over the \$HOME/.phpman fallback");

mkdir($tmp . '/emptyhome', 0777, true);
assert_equals('homefallback', resolve_marker($releaseSrc, $tmp . '/emptyhome', $tmp . '/home'),
    "no config in PHPMAN_HOME -> \$HOME/.phpman fallback");

// Note: PHPMAN_HOME undefined is deliberately not covered — config.php uses the
// constant unguarded further down (the tools_config.php path), so that scenario
// has always been fatal and is not a supported configuration. The web entry
// script defines it before loading config.php; the CLI gets it from the user
// config. The placeholder case below is the reachable "not really set" shape.
assert_equals('homefallback', resolve_marker($releaseSrc, '__PHPMAN_HOME__', $tmp . '/home'),
    "unpatched __PHPMAN_HOME__ placeholder -> \$HOME/.phpman fallback");

echo "\n--- dev checkout (src/ next to the config) ---\n";

mkdir($tmp . '/dev/src', 0777, true);
copy($realConfig, $tmp . '/dev/src/config.php');
file_put_contents($tmp . '/dev/phpman.config.php', "<?php define('CFG_MARKER', 'adjacent');\n");

assert_equals('adjacent', resolve_marker($tmp . '/dev/src/config.php', $tmp . '/install', $tmp . '/home'),
    "config next to src/ still outranks PHPMAN_HOME (search order preserved)");

exec('rm -rf ' . escapeshellarg($tmp));

exit(test_summary());
