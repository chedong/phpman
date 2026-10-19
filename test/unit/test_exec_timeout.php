<?php
/**
 * Unit tests: src/exec.php — 渲染子进程的时间上界
 * Requirement: docs/… 与 issue #248 / #247
 *
 * 为什么需要它：所有文档渲染都要 fork 外部命令（man / perldoc / pydoc3 / ri / info），
 * 原来全是裸 exec()、没有上界。实测生产上 `/man/DCB-DCBX/markdown` 跑 36.4 秒而响应体
 * 只有 34 字节 —— 在 mod_fcgid 的固定池下，几个并发就能让池饱和（#248）。
 *
 * 测试手法：phpManTimeoutPrefix() 用 static 缓存探测结果，所以**每个场景都要在全新的
 * PHP 进程里跑**（php -r），否则第一个场景的缓存会污染后面的。
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: Render subprocess timeout ===\n\n";

/** 全新进程里加载 src/exec.php 并跑 phpManExec，返回 ['ok'=>bool,'rc'=>int,'lines'=>array] */
function execInFreshProcess(string $timeoutExpr, string $cmd): array {
    $code = 'define("PHPMAN_CMD_TIMEOUT", ' . $timeoutExpr . ');'
          . 'require ' . var_export(__DIR__ . '/../../src/exec.php', true) . ';'
          . '$l=[];$rc=0;$ok=phpManExec(' . var_export($cmd, true) . ',$l,$rc);'
          . 'echo json_encode(["ok"=>$ok,"rc"=>$rc,"lines"=>$l]);';
    $out = [];
    $exit = 0;
    exec('php -r ' . escapeshellarg($code) . ' 2>&1', $out, $exit);
    $decoded = json_decode(implode("\n", $out), true);
    return is_array($decoded) ? $decoded : ['ok' => null, 'rc' => $exit, 'lines' => $out];
}

/** 全新进程里跑 phpManExecString */
function execStringInFreshProcess(string $timeoutExpr, string $cmd): string {
    $code = 'define("PHPMAN_CMD_TIMEOUT", ' . $timeoutExpr . ');'
          . 'require ' . var_export(__DIR__ . '/../../src/exec.php', true) . ';'
          . 'echo phpManExecString(' . var_export($cmd, true) . ');';
    $out = [];
    $exit = 0;
    exec('php -r ' . escapeshellarg($code) . ' 2>&1', $out, $exit);
    return implode("\n", $out);
}

// 这台机器上有没有 timeout(1)？没有（macOS 默认）时，切断类断言无从谈起 ——
// 但要**显式跳过并说明**，不能让它静默通过。
$hasTimeout = is_executable('/usr/bin/timeout') || is_executable('/bin/timeout');

echo "--- 正常路径 ---\n";

$r = execInFreshProcess('15', 'echo hello');
assert_equals(true, $r['ok'], "快命令成功返回 true");
assert_equals(0, $r['rc'], "快命令退出码 0");
assert_equals(['hello'], $r['lines'], "输出被收进 \$lines");

$r = execInFreshProcess('15', 'printf "a\nb\n"');
assert_equals(['a', 'b'], $r['lines'], "多行输出按行收进 \$lines");

$r = execInFreshProcess('15', 'exit 3');
assert_equals(false, $r['ok'], "非 0 退出返回 false");
assert_equals(3, $r['rc'], "退出码原样传回");

echo "\n--- 切断慢命令 ---\n";

if (!$hasTimeout) {
    echo "  ⏭️  本机没有 /usr/bin/timeout（macOS 默认如此），跳过切断类断言\n";
    echo "     这些断言在生产（Linux）上才有意义 —— 但也不能因此就不写：\n";
    echo "     它们用绝对路径探测，所以同一份测试在服务器上会真的执行。\n";
} else {
    $t0 = microtime(true);
    $r = execInFreshProcess('1', 'sleep 30; echo never');
    $elapsed = microtime(true) - $t0;

    assert_equals(false, $r['ok'], "超时返回 false（调用方按失败处理，与原来 \$rc !== 0 一致）");
    assert_equals(124, $r['rc'], "超时的退出码是 124（timeout(1) 的约定）");
    assert_equals([], $r['lines'], "被切断的命令不产出半截输出");
    assert_equals(true, $elapsed < 10, "1 秒的上界真的生效（实测耗时 " . round($elapsed, 1) . "s，而非 30s）");
}

echo "\n--- PHPMAN_CMD_TIMEOUT=0 表示不限制 ---\n";

$t0 = microtime(true);
$r = execInFreshProcess('0', 'sleep 2; echo done');
$elapsed = microtime(true) - $t0;
assert_equals(true, $r['ok'], "0 = 不限制，命令能跑完");
assert_equals(['done'], $r['lines'], "0 = 不限制时输出完整");
assert_equals(true, $elapsed >= 1.5, "确实等满了 2 秒（说明没被切断）");

echo "\n--- phpManExecString ---\n";

assert_equals('abc', execStringInFreshProcess('15', 'echo abc'), "输出被 trim");
assert_equals('', execStringInFreshProcess('15', 'exit 1'), "失败时返回空串");

if ($hasTimeout) {
    assert_equals('', execStringInFreshProcess('1', 'sleep 30; echo never'), "超时同样返回空串");
}

echo "\n--- timeout(1) 探测 ---\n";

$probe = 'require ' . var_export(__DIR__ . '/../../src/exec.php', true) . ';'
       . 'define("PHPMAN_CMD_TIMEOUT", 7);'
       // 包进括号：exec() 会吃掉每行末尾的空白，而这个前缀的尾随空格是有意义的
       // （它要直接前置到命令前面）。不包的话测的是被 trim 过的值，永远对不上。
       . 'echo "[" . phpManTimeoutPrefix() . "]";';
$out = [];
$exit = 0;
exec('php -r ' . escapeshellarg($probe) . ' 2>&1', $out, $exit);
$prefix = implode("\n", $out);

if ($hasTimeout) {
    assert_equals('[/usr/bin/timeout 7 ]', $prefix, "有 timeout(1) 时前缀是「路径 + 秒数 + 空格」");
} else {
    assert_equals('[]', $prefix, "没有 timeout(1) 时前缀为空 —— 退回裸 exec，本地开发不受影响");
}

echo "\n--- 降级路径的守卫 ---\n";

// pydocSearchLinesFromFts() 在没有 FTS 层时必须返回空数组而不是崩掉
// （它是 404 兜底页的一部分，崩掉会把一个「没找到」变成 500）。
$probe = 'require ' . var_export(__DIR__ . '/../../src/source_pydoc.php', true) . ';'
       . 'echo json_encode(pydocSearchLinesFromFts("json"));';
$out = [];
$exit = 0;
exec('php -r ' . escapeshellarg($probe) . ' 2>&1', $out, $exit);
assert_equals('[]', implode("\n", $out), "searchFtsBySource 不存在时返回空数组，不崩");

exit(test_summary());
