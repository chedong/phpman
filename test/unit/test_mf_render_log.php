<?php
/**
 * Unit tests: machine-format render telemetry (#244)
 *
 * A 5xx on /markdown, /json or /mcp used to leave nothing in the logs, so
 * "the client abandoned a slow response" and "mod_fcgid killed a slow process"
 * look identical from them — and they want opposite fixes. One line per slow
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
// #251: telemetry has its own file. Point it at a temp path before phpMan.php
// loads — the constant is fixed at config load, so ini_set('error_log') no
// longer reaches it.
$mfLog = sys_get_temp_dir() . '/phpman_test_mf_' . getmypid() . '.log';
@unlink($mfLog);
define('PHPMAN_MF_LOG_FILE', $mfLog);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: machine-format render log (#244) ===\n\n";

$T = 1000.0;   // request start, in seconds since epoch

echo "--- the threshold gates the line ---\n";
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, 500, now: $T + 0.400),
    "400ms under a 500ms threshold stays silent (that is a cache hit)");
assert_not_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, 500, now: $T + 0.600),
    "600ms over a 500ms threshold logs (that is a cold render)");
assert_not_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, 500, now: $T + 0.500),
    "exactly at the threshold logs (>=, not >)");
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, 0, now: $T + 9.0),
    "threshold 0 disables the line entirely");
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'body', $T, -1, now: $T + 9.0),
    "a negative threshold is treated as disabled, not as always-on");

echo "\n--- the size trigger (#249) ---\n";

// 这一族才是 #249 要看的：500 丢正文的那批请求是**大**，不是**慢**。
// 实测生产上被截断的正文 77-81 KB、完整 157-646 KB，而抽样的 6 个 500
// **全是热缓存命中、渲染 ~150ms** —— 永远跨不过 500ms 的耗时阈值。
$big = str_repeat('x', 80000);   // 80 KB，正是被截断的那一档
assert_not_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', $big, $T, 500, 65536, now: $T + 0.150),
    "150ms 的大响应（80KB）在 64KB 阈值下**要**记一行 —— 这正是耗时阈值漏掉的那批");
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'small body', $T, 500, 65536, now: $T + 0.150),
    "又小又快的不记（否则等于把 15.7k/天 全量打开）");
assert_not_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', 'small body', $T, 500, 65536, now: $T + 2.0),
    "又小又慢的仍按耗时记（两个条件是 OR，不是 AND）");
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', $big, $T, 0, 0, now: $T + 0.150),
    "两个阈值都为 0 时什么都不记");
assert_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', $big, $T, 500, 0, now: $T + 0.150),
    "size 阈值 0 = 关掉尺寸触发，退回只看耗时（#249 之前的行为）");

$bigLine = phpManMachineRenderLine('man', 'ls', '1', 'markdown', $big, $T, 500, 65536, now: $T + 0.150);
assert_contains('bytes=80000', $bigLine, "行里的 bytes= 是未压缩的正文长度 —— 拿它和 access log 的 %b 比对");
assert_contains('elapsed=150ms', $bigLine, "耗时照记，好区分「大而快」与「大而慢」");
assert_not_equals(null, phpManMachineRenderLine('man', 'ls', '1', 'markdown', str_repeat('x', 65536), $T, 500, 65536, now: $T + 0.1),
    "正好等于 size 阈值也记（>=，与耗时阈值一致）");

echo "\n--- the line carries the fields the diagnosis needs ---\n";
$line = phpManMachineRenderLine('man', 'zshall', '1', 'markdown', str_repeat('x', 1666546), $T, 500, now: $T + 4.164);
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
$a = phpManMachineRenderLine('man', 'ls', '1', 'json', 'b', $T, 500, now: $T + 1.0);
assert_contains('elapsed=1000ms', $a, "1.0s start-to-now becomes elapsed=1000ms");

echo "\n--- a URL-derived name cannot forge a log line ---\n";
$nasty = phpManMachineRenderLine('man', "evil\n[13/Oct/2026:00:00:00] PHP Fatal error: fake", '1', 'markdown', 'b', $T, 500, now: $T + 1.0);
assert_not_contains("\n", $nasty, "a newline in the page name does not survive into the log");
assert_not_contains("\r", $nasty, "nor a carriage return");
assert_contains('evil', $nasty, "the name is still identifiable, just flattened");
assert_not_contains("\t", phpManMachineRenderLine('man', "a\tb", '1', 'markdown', 'b', $T, 500, now: $T + 1.0),
    "tabs are stripped too");
assert_not_contains("\x1b", phpManMachineRenderLine('man', "a\x1bb", '1', 'markdown', 'b', $T, 500, now: $T + 1.0),
    "so are terminal escapes");

echo "\n--- an absurd name is truncated, not logged whole ---\n";
$long = phpManMachineRenderLine('man', str_repeat('A', 5000), '1', 'markdown', 'b', $T, 500, now: $T + 1.0);
assert_equals(true, strlen($long) < 500, "a 5000-char name does not produce a 5000-char log line");

echo "\n--- the wrapper writes to the telemetry log, and stays quiet under the threshold ---\n";
$log = $mfLog;

$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 1.0;
phpManLogMachineRender('man', 'perldiag', '1', 'markdown', 'body');
$written = file_get_contents($log);
assert_contains('mf mode=man name=perldiag sec=1 fmt=markdown', $written, "a slow render reaches the telemetry log");
assert_contains('peak=', $written, "with the memory figure attached");
assert_contains('] phpMan: ', $written, "framed the way error_log() frames its own lines");

file_put_contents($log, '');
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
phpManLogMachineRender('man', 'ls', '1', 'markdown', 'body');
assert_equals('', file_get_contents($log), "a fast render writes nothing");

unset($_SERVER['REQUEST_TIME_FLOAT']);
file_put_contents($log, '');
phpManLogMachineRender('man', 'ls', '1', 'markdown', 'body');
assert_equals('', file_get_contents($log), "no REQUEST_TIME_FLOAT (CLI) writes nothing rather than guessing");

// #251: the whole point of the split — a telemetry line must NOT land in the
// error log, or `make logcheck` reads it as an error again.
$errLog = tempnam(sys_get_temp_dir(), 'phpman_errlog_');
$prev = ini_get('error_log');
ini_set('error_log', $errLog);
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 1.0;
phpManLogMachineRender('man', 'perldiag', '1', 'markdown', 'body');
assert_equals('', file_get_contents($errLog), "a telemetry line does not reach error_log() (#251)");
assert_contains('mf mode=man name=perldiag', file_get_contents($log), "…it went to the telemetry file instead");
ini_set('error_log', $prev === false ? '' : $prev);
@unlink($errLog);

@unlink($log);

exit(test_summary());
