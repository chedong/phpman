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

echo "\n--- 调用点不初始化 \$rc（#248 回归） ---\n";

// 真实的 12 个调用点（source_perldoc / source_man / source_pydoc / source_ri）
// 都不初始化 $return_code。旧代码是裸 exec()，它第三个参数无类型、可选、可空，
// 所以不初始化一直是合法的。phpManExec() 最初把 $rc 收成必填的 int，于是这 12 处
// 全部抛 TypeError，生产上表现为「每次页面缓存未命中都 500，命中则正常」。
// 这个用例就是钉住那次收窄 —— 上面 execInFreshProcess() 自己写了 `$rc=0`，
// 所以整个套件在 bug 期间是绿的。
// 注意：下面 $rc 是**故意**不初始化的，不要「顺手」补上。
$probe = 'define("PHPMAN_CMD_TIMEOUT", 15);'
       . 'require ' . var_export(__DIR__ . '/../../src/exec.php', true) . ';'
       . '$l=[];'                                   // $lines 初始化，$rc 不初始化
       . '$ok=phpManExec("echo hi", $l, $rc);'
       . 'echo json_encode(["ok"=>$ok,"rc"=>$rc,"lines"=>$l]);';
$out = [];
$exit = 0;
exec('php -r ' . escapeshellarg($probe) . ' 2>&1', $out, $exit);
$decoded = json_decode(implode("\n", $out), true);

assert_equals(true, is_array($decoded),
    "未初始化的 \$rc 不再抛 TypeError（抛了的话这里拿到的是 fatal 文本，json_decode 得 null）");
if (is_array($decoded)) {
    assert_equals(true, $decoded['ok'], "未初始化 \$rc 时命令照常执行");
    assert_equals(0, $decoded['rc'], "函数内部把 \$rc 赋成 int，调用点拿到的是 int 而不是 null");
    assert_equals(['hi'], $decoded['lines'], "输出照常收进 \$lines");
}

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

echo "\n--- #247：哪些名字值得付那次 10–14s 的扫描 ---\n";

// 只对「裸关键词」扫描。dotted 是全模块路径，pydoc3 -k 无论匹配与否都要
// 10–14s，而线上 86.9% 的 pydoc 404 都是这一类，其中 1336 个返回空、72 个有
// 结果 —— 扫描几乎全是白付。
$probe = 'require ' . var_export(__DIR__ . '/../../src/source_pydoc.php', true) . ';'
       . '$cases = ["database", "DataBase", "datab", "sos.report.plugins.kafka",'
       . ' "django.db.backends.mysql.introspection", "  datab  ", "a.b"];'
       . '$r = [];'
       . 'foreach ($cases as $c) { $r[$c] = pydocSearchWorthScanning($c); }'
       . 'echo json_encode($r);';
$out = [];
$exit = 0;
exec('php -r ' . escapeshellarg($probe) . ' 2>&1', $out, $exit);
$got = json_decode(implode("\n", $out), true) ?: [];

assert_equals(true,  $got['database'] ?? null,   "裸关键词 database 值得扫描");
assert_equals(true,  $got['DataBase'] ?? null,   "大小写不影响判断（pydoc3 -k 本身不区分大小写）");
assert_equals(true,  $got['datab'] ?? null,      "前缀名 datab 值得扫描");
assert_equals(true,  $got['  datab  '] ?? null,  "首尾空白被忽略");
assert_equals(false, $got['sos.report.plugins.kafka'] ?? null,
    "dotted 全路径不扫描（线上 86.9% 的 pydoc 404 是这一类）");
assert_equals(false, $got['django.db.backends.mysql.introspection'] ?? null, "dotted 全路径不扫描");
assert_equals(false, $got['a.b'] ?? null, "最短的 dotted 也不扫描");

echo "\n--- #252：兜底无匹配时要说人话，不能只剩标题 ---\n";

// 空兜底以前渲染成一个光秃秃的标题（markdown 45 字节）或**完全空**的响应体
// （json 0 字节），看起来像坏了而不是「没找到」。
$probe = 'require ' . var_export(__DIR__ . '/../../src/util.php', true) . ';'   // h()
       . 'require ' . var_export(__DIR__ . '/../../src/source_pydoc.php', true) . ';'
       . '$r = [];'
       . 'foreach (["markdown","html","json","mcp"] as $f) { $r[$f] = pydocNoMatchNotice("JSONDecoder", $f); }'
       . 'echo json_encode($r);';
$out = [];
$exit = 0;
exec('php -r ' . escapeshellarg($probe) . ' 2>&1', $out, $exit);
$notice = json_decode(implode("\n", $out), true) ?: [];

assert_equals(true, strpos($notice['markdown'] ?? '', '> ') === 0,
    "markdown 是一个引用块，不是空串");
assert_equals(true, strpos($notice['html'] ?? '', '<p>') === 0,
    "html 是一个段落");
assert_equals(false, strpos($notice['markdown'] ?? '', 'JSONDecoder') === false,
    "正文里带上被搜的名字，读的人才知道搜的是什么");

$json = json_decode($notice['json'] ?? '', true);
assert_equals(true, is_array($json), "json 仍然是合法 JSON，不是散文");
assert_equals([], $json['results'] ?? null, "…且 results 是空数组（机器可读的「没匹配」）");
assert_equals('pydoc', $json['mode'] ?? null, "…信封字段没丢");
assert_equals('JSONDecoder', $json['parameter'] ?? null, "…parameter 也在");
assert_equals(false, empty($json['note'] ?? ''), "…并附上说明");

$mcp = json_decode($notice['mcp'] ?? '', true);
assert_equals(true, isset($mcp['content'][0]['text']), "mcp 保持它自己的信封");
assert_equals([], $mcp['structuredContent']['results'] ?? null, "…structuredContent 里 results 仍是空数组");

// 说明必须点出「为什么类名搜不到」——那正是 #252 里被误判的那件事。
assert_equals(false, strpos($notice['markdown'] ?? '', 'summaries') === false,
    "说明里点出匹配的是模块名与摘要，类名/函数名搜不到");

// ri 的那半：共用 noMatchNotice()，但 mode 字段必须是 ri。
$probe = 'require ' . var_export(__DIR__ . '/../../src/util.php', true) . ';'
       . 'require ' . var_export(__DIR__ . '/../../src/source_ri.php', true) . ';'
       . '$r = [];'
       . 'foreach (["markdown","html","json"] as $f) { $r[$f] = riNoMatchNotice("NoSuchClass", $f); }'
       . 'echo json_encode($r);';
$out = [];
$exit = 0;
exec('php -r ' . escapeshellarg($probe) . ' 2>&1', $out, $exit);
$riNotice = json_decode(implode("\n", $out), true) ?: [];

assert_equals(true, strpos($riNotice['markdown'] ?? '', '> ') === 0, "ri 的 markdown 也是引用块，不是空串");
assert_equals(true, strpos($riNotice['html'] ?? '', '<p>') === 0, "ri 的 html 也是段落");
$riJson = json_decode($riNotice['json'] ?? '', true);
assert_equals('ri', $riJson['mode'] ?? null, "ri 的 json 信封 mode 是 ri，不是 pydoc");
assert_equals([], $riJson['results'] ?? null, "…results 仍是空数组");
assert_equals('NoSuchClass', $riJson['parameter'] ?? null, "…parameter 也在");
assert_equals(false, empty($riJson['note'] ?? ''), "…并附上说明");

exit(test_summary());
