<?php
/**
 * Unit tests: pydoc scan policy (#282)
 *
 * The page fallback used to run `pydoc3 -k <name>` after the primary
 * `pydoc3 <name>` lookup had already failed — 8–20s to answer a "did you mean"
 * hint. Measured over a day of crawler traffic: 126 distinct non-dotted names,
 * every one costing 8–20s and returning nothing.
 *
 * The policy lives in one pure function so both the decision and the call site
 * can be pinned: dotted paths never scan, the page fallback never scans, and
 * explicit searches keep the scan.
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: pydoc scan policy (#282) ===\n\n";

// ──────────────────────────────────────────────
// The decision function
// ──────────────────────────────────────────────
echo "--- pydocShouldShellScan() ---\n";
assert_equals(false, pydocShouldShellScan('sos.report.plugins.kafka', true),
    "含点号的完整路径不扫描（#247）");
assert_equals(true, pydocShouldShellScan('datab', true),
    "搜索页里的裸关键词仍然扫描（这正是扫描擅长的）");
assert_equals(false, pydocShouldShellScan('datab', false),
    "页面兜底一律不扫描（#282）");
assert_equals(false, pydocShouldShellScan('AsyncIterator', false),
    "类名更不扫描 —— 实测每个 8–20s 且零结果");
assert_equals(false, pydocShouldShellScan('bzxmlrpc', false),
    "全小写的猜测名同样不扫描（它们也会吃满 20s）");
assert_equals(true, pydocShouldShellScan('Collection', true),
    "搜索页里的非点号名字仍然扫描 —— 由调用方决定，不按名字形状一刀切");

// ──────────────────────────────────────────────
// The call site must actually pass the flag
// ──────────────────────────────────────────────
echo "\n--- 页面兜底调用点 ---\n";
$phpMan = (string)file_get_contents(__DIR__ . '/../../phpMan.php');
assert_contains('getPydocSearchPage($parameter, $format, $timedOut, false)', $phpMan,
    "phpMan.php 的 pydoc 404 兜底显式传了 false —— 没有这个参数，策略函数再多也没人用");

// ──────────────────────────────────────────────
// Behaviour: no shell, no wait
// ──────────────────────────────────────────────
echo "\n--- 不扫描时立刻返回 ---\n";
$t0 = microtime(true);
$timedOut = null;
$out = getPydocSearchPage('DefinitelyNotAModuleZzz123', 'html', $timedOut, false);
$elapsed = microtime(true) - $t0;
assert_equals('', $out, "未知名字没有索引命中 → 空结果");
assert_equals(false, $timedOut, "没扫描就谈不上超时");
assert_equals(true, $elapsed < 2.0, "耗时 < 2s（实测 " . round($elapsed, 3) . "s）；扫描路径需要 8–20s");

exit(test_summary());
