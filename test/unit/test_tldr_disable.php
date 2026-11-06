<?php
declare(strict_types=1);
/**
 * Unit tests: PHPMAN_TLDR_DISABLE — TLDR 关闭开关（#255 golden corpus）
 *
 * formatManPerlDocToMarkdown() 调 fetchOfficialTldr()（man section-1 页面），
 * 后者最多发起 4 次外网请求。golden corpus 要在 CI 里离线渲染真实工具输出，
 * 所以渲染语料时必须能显式关掉 TLDR，且关闭后绝不触网。
 *
 * 两条断言缺一不可（纯行为断言在无网环境会「vacuously」通过——函数碰巧
 * 返回 [] 并不是因为开关，而是因为请求失败）：
 *   1. 源码 tripwire：fetchOfficialTldr() 函数体里真的有 PHPMAN_TLDR_DISABLE
 *      守卫（与 test_release_precheck.php 的 tripwire 写法一致）；
 *   2. 行为：全新 PHP 子进程里定义开关后调用，立即返回 []——子进程是为了
 *      绕开常量不可撤销的问题（test_tools_config_fallback.php 的做法）。
 *
 * 默认行为不变：开关未定义/false 时 TLDR 照常工作（由既有 tldr 相关测试覆盖）。
 */
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: TLDR disable switch (#255) ===\n\n";

/**
 * 在子进程里加载 phpMan.php 并 var_export(fetchOfficialTldr(...)) 的结果。
 * $define 为 true 时先 define("PHPMAN_TLDR_DISABLE", true)。
 * PHPMAN_TEST_MODE 让 phpMan.php 只加载函数、跳过 web dispatch。
 */
function tldrSubprocess(bool $define): string {
    $code = ($define ? 'define("PHPMAN_TLDR_DISABLE", true); ' : '')
          . 'define("PHPMAN_TEST_MODE", true);'
          . ' require ' . var_export(__DIR__ . '/../../phpMan.php', true) . ';'
          . ' var_export(fetchOfficialTldr("ls", "man", "1"));';
    // 指到临时 PHPMAN_HOME，避免读到/写进真实的 ~/.phpman
    $home = sys_get_temp_dir() . '/phpman-tldr-disable-' . getmypid();
    @mkdir($home . '/db', 0755, true);
    @mkdir($home . '/logs', 0755, true);
    $cmd = 'PHPMAN_HOME=' . escapeshellarg($home) . ' php -r ' . escapeshellarg($code) . ' 2>/dev/null';
    $out = trim((string)shell_exec($cmd));
    foreach (['db', 'logs'] as $sub) {
        foreach (glob($home . '/' . $sub . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($home . '/' . $sub);
    }
    @rmdir($home);
    return $out;
}

echo "--- 源码 tripwire ---\n";

// 从 src/tldr.php 截出 fetchOfficialTldr() 的函数体（到下一个顶层 function 为止），
// 只认函数体内的守卫——别处出现同名常量不算数。
$src = (string)file_get_contents(__DIR__ . '/../../src/tldr.php');
$start = strpos($src, 'function fetchOfficialTldr(');
assert_not_equals(false, $start, "src/tldr.php 里有 fetchOfficialTldr()");
$body = $start !== false ? substr($src, $start, (int)strpos($src, "\nfunction ", $start) - $start) : '';
assert_contains('PHPMAN_TLDR_DISABLE', $body,
    "tripwire: fetchOfficialTldr() 函数体里有 PHPMAN_TLDR_DISABLE 守卫");

echo "\n--- 行为：常量关闭 ---\n";

// 关闭时立即返回空数组、不触网。var_export([]) === "array (\n)"
assert_equals('array (' . "\n" . ')', tldrSubprocess(true),
    "常量开启时 fetchOfficialTldr() 立即返回 []（不发起请求）");

echo "\n--- 行为：环境变量关闭（config.php 接线） ---\n";

// 不 define，只设环境变量 PHPMAN_TLDR_DISABLE=1：验证 src/config.php 的
// getenv 接线（define 在子进程里做的话就绕过了 config.php 的那段代码）。
$code = 'define("PHPMAN_TEST_MODE", true);'
      . ' require ' . var_export(__DIR__ . '/../../phpMan.php', true) . ';'
      . ' var_export(fetchOfficialTldr("ls", "man", "1"));';
$home = sys_get_temp_dir() . '/phpman-tldr-disable-env-' . getmypid();
@mkdir($home . '/db', 0755, true);
@mkdir($home . '/logs', 0755, true);
$cmd = 'PHPMAN_TLDR_DISABLE=1 PHPMAN_HOME=' . escapeshellarg($home)
     . ' php -r ' . escapeshellarg($code) . ' 2>/dev/null';
$out = trim((string)shell_exec($cmd));
foreach (['db', 'logs'] as $sub) {
    foreach (glob($home . '/' . $sub . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($home . '/' . $sub);
}
@rmdir($home);
assert_equals('array (' . "\n" . ')', $out,
    "env PHPMAN_TLDR_DISABLE=1（经 src/config.php）同样立即返回 []");

exit(test_summary());
