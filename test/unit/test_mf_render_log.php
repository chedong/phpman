<?php
/**
 * Unit tests: machine-format render telemetry (#244)
 *
 * A 5xx on /markdown, /json or /mcp leaves nothing in phpman_error.log today, so
 * "the client abandoned a slow response" and "mod_fcgid killed a slow process"
 * look identical from the logs — and they want opposite fixes. One line per slow
 * render separates them:
 *
 *   a 5xx with a matching line  -> the render finished; delivery failed
 *   a 5xx with no matching line -> the process died before reaching the end
 *
 * Measured on staging 2026-10-13: a cache hit renders in 30-90ms, a cold render
 * of the same page in 400-4200ms, so a 500ms threshold cleanly separates the
 * population that matters from the one that does not.
 *
 * The line is built by a pure function, so the threshold, the elapsed
 * arithmetic and the field sanitising are testable without a web request.
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
// Pinned so the wrapper test below exercises a known threshold. config.php
// guards every define with !defined(), so this wins.
define('PHPMAN_MF_LOG_MS', 500);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: machine-format render log (#244) ===\n\n";

$T = 1000.0;   // request start, in seconds since epoch

echo "--- the threshold gates the line ---\n";
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, 500, $T + 0.400),
    "400ms under a 500ms threshold stays silent (that is a cache hit)");
assert_not_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, 500, $T + 0.600),
    "600ms over a 500ms threshold logs (that is a cold render)");
assert_not_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, 500, $T + 0.500),
    "exactly at the threshold logs (>=, not >)");
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, 0, $T + 9.0),
    "threshold 0 disables the line entirely");
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, -1, $T + 9.0),
    "a negative threshold is treated as disabled, not as always-on");

echo "\n--- the line carries the fields the diagnosis needs ---\n";
$line = phpManMachineRenderLine('man', 'zshall', '1', 'markdown', str_repeat('x', 1666546), $T, 500, $T + 4.164);
assert_match('/^mf /', $line, "starts with 'mf ' so it is greppable in the log");
assert_contains('mode=man', $line, "mode");
assert_contains('name=zshall', $line, "page name");
assert_contains('sec=1', $line, "section");
assert_contains('fmt=markdown', $line, "format");
assert_contains('elapsed=4164ms', $line, "elapsed is whole milliseconds since the request started");
assert_contains('bytes=1666546', $line, "output size, the cheap proxy for render cost");
assert_match('/peak=[0-9]+\.[0-9]MB/', $line, "peak memory in MB — the leading hypothesis for the kill");

echo "\n--- elapsed is measured from the request, not from the call ---\n";
// REQUEST_TIME_FLOAT is set when PHP starts, so this is PHP's own wall time. It
// excludes the mod_fcgid queue wait, which PHP cannot see; comparing it against
// the client-observed time in the access log is what exposes that wait.
$a = phpManMachineRenderLine('man', 'ls', '1', 'json', 'b', $T, 500, $T + 1.0);
assert_contains('elapsed=1000ms', $a, "1.0s start-to-now becomes elapsed=1000ms");

echo "\n--- a URL-derived name cannot forge a log line ---\n";
$nasty = phpManMachineRenderLine('man', "evil\n[13/Oct/2026:00:00:00] PHP Fatal error: fake", '1', 'markdown', 'b', $T, 500, $T + 1.0);
assert_not_contains("\n", $nasty, "a newline in the page name does not survive into the log");
assert_not_contains("\r", $nasty, "nor a carriage return");
assert_contains('evil', $nasty, "the name is still identifiable, just flattened");
assert_not_contains("\t", phpManMachineRenderLine('man', "a\tb", '1', 'markdown', 'b', $T, 500, $T + 1.0),
    "tabs are stripped too");
assert_not_contains("\x1b", phpManMachineRenderLine('man', "a\x1bb", '1', 'markdown', 'b', $T, 500, $T + 1.0),
    "so are terminal escapes");

echo "\n--- an absurd name is truncated, not logged whole ---\n";
$long = phpManMachineRenderLine('man', str_repeat('A', 5000), '1', 'markdown', 'b', $T, 500, $T + 1.0);
assert_equals(true, strlen($long) < 500, "a 5000-char name does not produce a 5000-char log line");

echo "\n--- the wrapper writes to error_log, and stays quiet under the threshold ---\n";
$log = tempnam(sys_get_temp_dir(), 'phpman_mflog_');
$prev = ini_get('error_log');
ini_set('error_log', $log);

$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 1.0;
phpManLogMachineRender('man', 'perldiag', '1', 'markdown', 'body');
$written = file_get_contents($log);
assert_contains('mf mode=man name=perldiag sec=1 fmt=markdown', $written, "a slow render reaches the error log");
assert_contains('peak=', $written, "with the memory figure attached");

file_put_contents($log, '');
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
phpManLogMachineRender('man', 'ls', '1', 'markdown', 'body');
assert_equals('', file_get_contents($log), "a fast render writes nothing");

unset($_SERVER['REQUEST_TIME_FLOAT']);
file_put_contents($log, '');
phpManLogMachineRender('man', 'ls', '1', 'markdown', 'body');
assert_equals('', file_get_contents($log), "no REQUEST_TIME_FLOAT (CLI) writes nothing rather than guessing");

ini_set('error_log', $prev === false ? '' : $prev);
@unlink($log);

exit(test_summary());
