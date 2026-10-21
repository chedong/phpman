<?php
/**
 * Unit tests: cli/logcheck.sh — the 5xx family split (#249)
 * Requirement: issue #249 (路线 1), and the window-scope bug found on 2026-10-21.
 *
 * 为什么需要它：access log 里的一行 5xx 说不出自己是哪种 5xx。「客户端放弃大响应」
 * （EPIPE）和「进程被杀」从 access log 看完全一样。缺的那一半在 server error log
 * 里 —— 一行 `Broken pipe` 带着客户端 IP 和分钟。这个测试用构造的日志钉住三件事：
 *
 *   1. (MMDD HH:MM, IP) 配对真的按 IP 匹配，不只是按时间
 *   2. `(70007)` APR_TIMEUP 不是 EPIPE，不能混进那一族
 *   3. **窗口范围必须打进输出** —— 2026-10-20 那次，脚本在有 38 个 503 的日志上报了
 *      "none"，因为 503 全在 `LOGCHECK_RECENT_LINES` 窗口之外。计数能谎报自己的范围
 *      比没有计数更糟，所以这条是回归测试的重点。
 *
 * 手法：全部用构造的日志跑真实脚本，不 mock —— 被改的正是那个 awk，测它的行为才有意义。
 */
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: logcheck.sh 5xx family split ===\n\n";

$tmp = sys_get_temp_dir() . '/phpman_logcheck_' . getmypid();
@mkdir($tmp, 0700, true);
$accessLog = $tmp . '/access.log';
$errorLog  = $tmp . '/error.log';
$emptyLog  = $tmp . '/error-empty.log';
$noSuchLog = $tmp . '/does-not-exist.log';

// 7 行 access log，5 个是 5xx，刻意让两族交错、并且有一行「同分钟不同 IP」。
file_put_contents($accessLog, implode("\n", [
    '1.1.1.1 - - [20/Oct/2026:10:00:01 -0700] "GET /phpMan.php/man/ls/markdown HTTP/1.1" 200 1234 "-" "UA"',
    '2.2.2.2 - - [20/Oct/2026:10:01:02 -0700] "GET /phpMan.php/man/ls/markdown HTTP/1.1" 500 4096 "-" "SemrushBot"',
    '3.3.3.3 - - [20/Oct/2026:10:02:03 -0700] "GET /phpMan.php/man/ls/markdown HTTP/1.1" 500 0 "-" "ExaSearchBot"',
    '4.4.4.4 - - [20/Oct/2026:10:03:04 -0700] "GET /phpMan.php/man/ls/markdown HTTP/1.1" 500 8192 "-" "OtherBot"',
    '5.5.5.5 - - [20/Oct/2026:10:04:05 -0700] "GET /phpMan.php/man/ls HTTP/1.1" 503 - "-" "Bot"',
    '6.6.6.6 - - [20/Oct/2026:10:05:06 -0700] "GET /phpMan.php/man/ls HTTP/1.1" 404 100 "-" "Bot"',
    '7.7.7.7 - - [20/Oct/2026:10:06:07 -0700] "GET /phpMan.php/man/ls HTTP/1.1" 500 512 "-" "Bot"',
]) . "\n");

// error log：10:01 与 10:06 各一条 Broken pipe；10:03 那条属于 9.9.9.9（≠ 4.4.4.4，
// 用来证明匹配是按 IP 而不只是按分钟）；10:05 那条是 70007，不是 EPIPE。
file_put_contents($errorLog, implode("\n", [
    '[Tue Oct 20 10:01:02 2026] [chedong.com] [warn] [client 2.2.2.2:55555] fcgid_bridge.c(409): (32)Broken pipe: mod_fcgid: ap_pass_brigade failed',
    '[Tue Oct 20 10:03:04 2026] [chedong.com] [warn] [client 9.9.9.9:11111] fcgid_bridge.c(409): (32)Broken pipe: mod_fcgid: ap_pass_brigade failed',
    '[Tue Oct 20 10:05:05 2026] [chedong.com] [warn] [client 5.5.5.5:22222] fcgid_bridge.c(409): (70007)The timeout specified has expired',
    '[Tue Oct 20 10:06:07 2026] [chedong.com] [warn] [client 7.7.7.7:33333] fcgid_bridge.c(409): (32)Broken pipe: mod_fcgid: ap_pass_brigade failed',
]) . "\n");

touch($emptyLog);

/** 跑真实脚本，返回合并后的输出。 */
function runLogcheck(string $access, string $error, string $recent = '2000'): string {
    $script = realpath(__DIR__ . '/../../cli/logcheck.sh');
    $env = 'DEMO_ACCESS_LOG=' . escapeshellarg($access) . ' '
         . 'DEMO_ERROR_LOG=' . escapeshellarg($error) . ' '
         . 'PHPMAN_LOG=' . escapeshellarg($access . '.no-phpman-log') . ' '
         . 'PHPMAN_MF_LOG=' . escapeshellarg($access . '.no-mf-log') . ' '
         . 'LOGCHECK_RECENT_LINES=' . escapeshellarg($recent) . ' ';
    $out = [];
    $exit = 0;
    exec($env . 'bash ' . escapeshellarg($script) . ' 2>&1', $out, $exit);
    return implode("\n", $out);
}

/** 折叠连续空格，让断言不受 printf 对齐宽度影响。 */
function squash(string $s): string {
    return preg_replace('/ +/', ' ', $s);
}

echo "--- 两族分开计数 ---\n";

$out = squash(runLogcheck($accessLog, $errorLog));

assert_contains("5xx in window: 5", $out, "窗口内 5xx 共 5 个（500×4 + 503×1，404 不计）");
assert_contains("+ body, Broken pipe in server log (EPIPE family): 2", $out,
    "EPIPE 族 2 个：2.2.2.2@10:01 与 7.7.7.7@10:06");
assert_contains("+ body, no Broken pipe match: 1", $out,
    "带 body 但无 Broken pipe 关联 1 个：4.4.4.4@10:03（同分钟但 IP 不同 ⇒ 不能按时间匹配）");
assert_contains("zero-byte body: 2", $out,
    "0 字节 2 个：3.3.3.3 的 500/0 与 5.5.5.5 的 503/-");
// 3 条 Broken pipe（2.2.2.2、9.9.9.9、7.7.7.7），但只有 2 条配上了 5xx ——
// 9.9.9.9 那条没有对应的 5xx 行。收 3 配 2 正是「按 IP 配对」而不是
// 「数一数有几条 Broken pipe」的证据。
assert_contains("pairing source: 3 Broken pipe line(s), Oct 20 10:01", $out,
    "写明配对来源收了多少条 Broken pipe、覆盖到哪一段（否则 EPIPE=0 无从解读）");

echo "\n--- (70007) 不是 EPIPE ---\n";

// 5.5.5.5@10:05 在 error log 里有一条 (70007) 记录。若把 70007 也收进关联集合，
// 这个 503 会被算进 EPIPE 族（变成 3），而它其实是 0 字节族。
assert_not_contains("(EPIPE family): 3", $out,
    "APR_TIMEUP(70007) 没有被当成 EPIPE 收进关联集合");

echo "\n--- 窗口范围必须写进输出 ---\n";

// 这正是 2026-10-20 那个「有 38 个 503 却报 none」的根因所在：计数不写范围，
// 读的人就无从知道它只看了窗口内的一小段。
assert_contains("window: last 7 lines", $out, "窗口行写明扫了多少行");
assert_contains("20/Oct/2026:10:00:01", $out, "窗口行写明起始时间");
assert_contains("20/Oct/2026:10:06:07", $out, "窗口行写明结束时间");

$out = squash(runLogcheck($accessLog, $errorLog, '2'));

assert_contains("window: last 2 lines", $out, "LOGCHECK_RECENT_LINES=2 时窗口行如实收窄");
assert_contains("5xx in window: 1", $out, "窗口只剩 2 行 ⇒ 只数到 1 个 5xx（而不是报 none）");
assert_not_contains("none", $out, "窗口收窄后不谎报 none");

echo "\n--- 空 error log 不能打乱 access log 的解析 ---\n";

// 为什么单列一条：awk 若用两个文件参数 + NR==FNR 区分，第一个文件为空时
// NR==FNR 会对第二个文件也成立，access log 会被当成 error log 读。这里读的是
// BEGIN + getline，所以空文件必须无害。
$out = squash(runLogcheck($accessLog, $emptyLog));

assert_contains("5xx in window: 5", $out, "error log 为空时 5xx 计数不变");
assert_contains("(EPIPE family): 0", $out, "没有 Broken pipe ⇒ EPIPE 族为 0");
assert_contains("+ body, no Broken pipe match: 3", $out, "3 个带 body 的 5xx 全部归入「无关联」");
assert_contains("zero-byte body: 2", $out, "0 字节族不受影响");
// 两个日志各按各的节奏轮转。error log 已经轮转出窗口时，EPIPE=0 说的是
// 「证据过期了」而不是「没有客户端放弃」—— 这两种意思必须能被读出来。
assert_contains("pairing source: no Broken pipe lines", $out,
    "error log 里没有 Broken pipe 时，明说 EPIPE=0 不能作为结论");
assert_contains("EPIPE=0 proves nothing here", $out, "并写清为什么不能当结论");

echo "\n--- error log 不存在 ---\n";

$out = squash(runLogcheck($accessLog, $noSuchLog));
assert_contains("5xx in window: 5", $out, "error log 路径不存在时不崩，计数照旧");
assert_contains("(EPIPE family): 0", $out, "读不到 error log ⇒ 关联集合为空");

echo "\n--- 永不非 0 退出（只读检查） ---\n";

$script = realpath(__DIR__ . '/../../cli/logcheck.sh');
$out = [];
$exit = 0;
exec('DEMO_ACCESS_LOG=' . escapeshellarg($noSuchLog) . ' DEMO_ERROR_LOG=' . escapeshellarg($noSuchLog)
    . ' PHPMAN_LOG=' . escapeshellarg($noSuchLog) . ' bash ' . escapeshellarg($script) . ' 2>&1', $out, $exit);
assert_equals(0, $exit, "日志全部不存在时也 exit 0 —— 发布不该被只读检查判失败");

foreach ([$accessLog, $errorLog, $emptyLog] as $f) { @unlink($f); }
@rmdir($tmp);

exit(test_summary());
