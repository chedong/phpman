<?php
/**
 * Unit tests: golden-corpus normalization library (#255, Task 1)
 *   corpusReadManifest()  — tab-separated manifest, comments, empty section
 *   corpusStripFormatting() — ANSI SGR + overstrike (b\x08b style)
 *   corpusNormalizeText() — man header/footer drop, blank-line squeeze
 *   corpusMissingTokens() — multiset difference in raw order
 *   corpusTextFromHtml()  — strip tags, decode entities, collapse whitespace
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../corpus/normalize.php';

echo "=== Unit: corpus normalize (#255 Task 1) ===\n\n";

echo "--- corpusReadManifest() ---\n";
$m = corpusReadManifest(__DIR__ . '/../corpus/pages.txt');
assert_equals(6, count($m), "六页语料");
assert_equals('', $m[3]['section'], "空 section 解析为空串");
assert_equals('man', $m[0]['tool'], "工具名保留");
assert_equals('ssh_config', $m[2]['name'], "name 字段原样保留");
assert_equals('5', $m[2]['section'], "section 字段原样保留");
assert_equals('perldoc', $m[5]['tool'], "第 6 行解析正确");

echo "\n--- corpusStripFormatting() ---\n";
assert_equals("LS(1)", corpusStripFormatting("\x1b[4mLS\x1b[24m(1)"), "去 ANSI");
assert_equals("bold", corpusStripFormatting("b\x08bo\x08ol\x08ld\x08d"), "去 overstrike");
assert_equals("plain", corpusStripFormatting("plain"), "无格式文本原样返回");

echo "\n--- corpusNormalizeText() ---\n";
// man 页眉页脚：首末两行是页眉页脚时丢弃
$manPage = "LS(1)   User Commands   LS(1)\n\nNAME\n    ls - list\n\nLS(1)   User Commands   LS(1)\n";
assert_equals("NAME\nls - list", corpusNormalizeText($manPage), "页眉页脚被丢弃");

// perldoc 没有页眉：末行正文不能被当成页脚删掉
$pod = "NAME\n    DBI - database interface\n\nDESCRIPTION\n    DBI is a database access module.\n";
assert_contains("DBI is a database access module.", corpusNormalizeText($pod), "perldoc 末行保留");

// 首行像页眉但末行不是同一行 → 不丢弃（防止误删唯一的首行）
$onlyHeader = "LS(1)   User Commands   LS(1)\n\nNAME\n    ls - list\n";
assert_contains("LS(1)", corpusNormalizeText($onlyHeader), "末行不同时首行保留");

// 连续空行压成一个
$squeezed = "a\n\n\n\nb\n";
assert_equals("a\n\nb", corpusNormalizeText($squeezed), "连续空行压成一个");

echo "\n--- corpusTokens() ---\n";
assert_equals(['ls', '-l'], corpusTokens("  ls  -l\n"), "按空白切分并去空串");
assert_equals([], corpusTokens("   \n\t "), "纯空白产出空数组");

echo "\n--- corpusMissingTokens() ---\n";
$missing = corpusMissingTokens(['ls', '-l', 'ls'], ['ls', 'other']);
assert_equals(['-l', 'ls'], $missing, "缺失词按次数报告");
assert_equals([], corpusMissingTokens(['ls'], ['ls', 'extra']), "rendered 足量时无缺失");
assert_equals(['x'], corpusMissingTokens(['x', 'x'], ['x']), "raw 计数超出 rendered 报告差额");

echo "\n--- corpusTextFromHtml() ---\n";
assert_equals("a b", corpusTextFromHtml("<p>a</p><p>b</p>"), "块级标签替换为空格并折叠空白");
assert_equals("a b", corpusTextFromHtml("<li>a</li><li>b</li>"), "列表项同样按块级处理");
assert_equals("[OPTIONS]", corpusTextFromHtml("[<u>OPTIONS</u>]"), "行内标签替换为空串，不留空格");
assert_equals("http://example.com/x", corpusTextFromHtml('<a href="x">http://example.com/x</a>'), "perldoc 行内链接不被空格打断");
assert_equals("Tom & Jerry", corpusTextFromHtml("<p>Tom &amp; Jerry</p>"), "实体解码");
assert_equals("x", corpusTextFromHtml("  <b> x </b>  "), "首尾空白去除");

exit(test_summary());
