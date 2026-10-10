<?php
declare(strict_types=1);
/**
 * Unit tests: PHPMAN_HAS_* fallback in src/config.php (#240)
 *
 * The deploy rewrote ~/.phpman/tools_config.php in place
 * (`php cli/detect-tools.php > tools_config.php`), so the shell truncated it
 * before PHP wrote. A request landing in that window saw file_exists() === true
 * with nothing defined, and the fallback — which only ran when the file was
 * absent — was skipped. The result was a fatal in showForm():
 *   Undefined constant "PHPMAN_HAS_PERLDOC"  (production log, 2026-10-08)
 *
 * Each case runs config.php in a subprocess: the constants are defined on first
 * load and cannot be redefined in-process.
 */
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: tools_config.php fallback (#240) ===\n\n";

/**
 * Load src/config.php with the given tools_config.php contents and report the
 * four constants as "1" (true), "0" (false) or "u" (undefined).
 */
function toolConstants(?string $toolsConfig): string {
    $home = sys_get_temp_dir() . '/phpman-tools-' . getmypid() . '-' . substr(md5((string)$toolsConfig), 0, 8);
    @mkdir($home, 0755, true);
    if ($toolsConfig !== null) {
        file_put_contents($home . '/tools_config.php', $toolsConfig);
    }
    $code = 'define("PHPMAN_HOME", ' . var_export($home, true) . ');'
          . ' require ' . var_export(__DIR__ . '/../../src/config.php', true) . ';'
          . ' $out = "";'
          . ' foreach (["PERLDOC", "PYDOC", "RI", "INFO"] as $t) {'
          . '   $out .= defined("PHPMAN_HAS_" . $t) ? (constant("PHPMAN_HAS_" . $t) ? "1" : "0") : "u";'
          . ' }'
          . ' echo $out;';
    $result = trim((string)shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1'));

    // temp home cleanup — config.php creates db/ and logs/ while loading
    @unlink($home . '/tools_config.php');
    foreach (['db', 'logs', 'backups'] as $sub) {
        foreach (glob($home . '/' . $sub . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($home . '/' . $sub);
    }
    @rmdir($home);
    return $result;
}

echo "--- file missing (pre-deploy, manual install) ---\n";
assert_equals("1111", toolConstants(null), "no tools_config.php → assume all tools available");

echo "\n--- file exists but is empty (the truncate-then-write window) ---\n";
assert_equals("1111", toolConstants(""), "empty tools_config.php → falls back instead of leaving constants undefined");
assert_equals("1111", toolConstants("<?php\n// header written, defines not yet\n"),
    "header-only tools_config.php → falls back");

echo "\n--- partial file: defined values win, the rest fall back ---\n";
assert_equals("0111", toolConstants("<?php\ndefine('PHPMAN_HAS_PERLDOC', false);\n"),
    "only PERLDOC defined → PYDOC/RI/INFO fall back to available");
assert_equals("1000", toolConstants(
    "<?php\ndefine('PHPMAN_HAS_PERLDOC', true);\ndefine('PHPMAN_HAS_PYDOC', false);\n"
  . "define('PHPMAN_HAS_RI', false);\ndefine('PHPMAN_HAS_INFO', false);\n"),
    "complete file decides every constant");

exit(test_summary());
