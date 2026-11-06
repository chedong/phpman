<?php
/**
 * Unit tests: overstrike cleaning patterns
 * Requirement: SKILL.md §Overstrike pitfalls
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: Overstrike Cleaning (UTF-8 safety) ===\n\n";

$bs = chr(8);

// Bold overstrike: l^Hls^Hs → <b>ls</b> (merged bold block)
echo "T1: Bold overstrike\n";
$lines = ["l{$bs}ls{$bs}s"];
$result = formatManPerlDoc($lines, "man");
assert_contains("<b>ls</b>", $result, "bold overstrike l^Hls^Hs → <b>ls</b>");

// Single char bold: a^Ha → <b>a</b>
echo "\nT2: Single char bold\n";
$lines = ["a{$bs}a"];
$result = formatManPerlDoc($lines, "man");
assert_contains("<b>a</b>", $result, "single bold a^Ha → <b>a</b>");

// Underline overstrike: _^Ht → <u>t</u>
echo "\nT3: Underline overstrike\n";
$lines = ["_{$bs}t"];
$result = formatManPerlDoc($lines, "man");
assert_contains("<u>t</u>", $result, "underline _^Ht → <u>t</u>");

// UTF-8 multibyte NOT split: U+2010 hyphen (‐) = \xe2\x80\x90
echo "\nT4: UTF-8 safety — multibyte chars not split\n";
$hyphen = "\xe2\x80\x90";
$lines = ["com_{$bs}{$hyphen}press"];
$result = formatManPerlDoc($lines, "man");
assert_not_contains("<u>\xe2</u>", $result, "UTF-8 hyphen NOT split");

// Valid UTF-8 output
echo "\nT5: Output is valid UTF-8\n";
$lines = ["Normal text with UTF-8: • bullet — em dash"];
$result = formatManPerlDoc($lines, "man");
$enc = mb_detect_encoding($result, "UTF-8", true);
assert_equals(true, $enc !== false, "valid UTF-8 encoding");

// No residual backspace characters
echo "\nT6: Backspace cleanup\n";
$lines = ["a{$bs}ab{$bs}bc{$bs}c"];
$result = formatManPerlDoc($lines, "man");
assert_not_contains($bs, $result, "no residual backspace chars");

// SGR bold: ESC[1m...ESC[0m
echo "\nT7: SGR bold\n";
$esc = chr(27);
$lines = ["{$esc}[1mbold{$esc}[0m"];
$result = formatManPerlDoc($lines, "man");
assert_contains("<b>bold</b>", $result, "SGR bold → <b>bold</b>");

// SGR underline: ESC[4m...ESC[0m
echo "\nT8: SGR underline\n";
$lines = ["{$esc}[4munderlined{$esc}[0m"];
$result = formatManPerlDoc($lines, "man");
assert_contains("<u>underlined</u>", $result, "SGR underline → <u>underlined</u>");

// T9: real man-tar.txt fragment — underline opens exactly where bold closes.
// Two non-greedy regexes produced interleaved tags (<b>-f <u></b>ARCHIVE</u>,
// invalid HTML); the state machine must emit well-nested tags instead.
echo "\nT9: SGR bold/underline handoff (real tar fragment)\n";
$lines = ["{$esc}[1mtar -A {$esc}[22m[{$esc}[4mOPTIONS{$esc}[24m] {$esc}[1m-f {$esc}[4m{$esc}[22mARCHIVE{$esc}[24m {$esc}[4mARCHIVE{$esc}[24m..."];
$result = trim(formatManPerlDoc($lines, "man"));
assert_equals(
    "<b>tar -A </b>[<u>OPTIONS</u>] <b>-f </b><u>ARCHIVE</u> <u>ARCHIVE</u>...",
    $result,
    "真实 tar 片段输出合法嵌套"
);
assert_not_contains("<u></b>", $result, "无交错：<u> 内不得直接闭合 </b>");
assert_not_contains("<b></u>", $result, "无交错：<b> 内不得直接闭合 </u>");

// T10: bold→underline handoff — the state machine itself must produce
// well-nested output (<b>io<u>_</u>cancel</b>), opening <b> before <u> and
// closing in reverse. The full pipeline keeps the existing <u>_</u>→_ cleanup.
echo "\nT10: SGR state machine — bold/underline handoff\n";
$sgrSplit = "{$esc}[1mio{$esc}[4m_{$esc}[24mcancel{$esc}[0m";
assert_equals("<b>io<u>_</u>cancel</b>", sgrToTags($sgrSplit), "状态机直接输出合法嵌套");
assert_equals("<b>io_cancel</b>\n", formatManPerlDoc([$sgrSplit], "man"), "管线保留既有 <u>_</u> 清理");

echo "\nT11: SGR state machine — only underline\n";
assert_equals("<u>OPTIONS</u>", sgrToTags("{$esc}[4mOPTIONS{$esc}[24m"), "只有下划线");

echo "\nT12: SGR state machine — only bold\n";
assert_equals("<b>bold</b>", sgrToTags("{$esc}[1mbold{$esc}[0m"), "只有加粗");

echo "\nT13: SGR ESC[0m closes all mid-line\n";
assert_equals("<b>abc</b>def", sgrToTags("{$esc}[1mabc{$esc}[0mdef"), "ESC[0m 全关");
assert_equals("<b>abc</b>def\n", formatManPerlDoc(["{$esc}[1mabc{$esc}[0mdef"], "man"), "管线中 ESC[0m 全关");

echo "\nT14: SGR state machine — close still-open tags at end of line\n";
assert_equals("<b>tail <u>under</u></b>", sgrToTags("{$esc}[1mtail {$esc}[4munder"), "行尾补齐闭合（逆序）");

echo "\nT15: SGR color codes are dropped\n";
assert_equals("color", sgrToTags("{$esc}[34mcolor{$esc}[0m"), "颜色码丢弃");
assert_equals("{$esc}[3;5Hx", sgrToTags("{$esc}[3;5Hx"), "非 m 结尾的转义序列原样保留");

// --- markdown path: cleanTerminalOutput() markers \x01..\x04 → ** / _ ---
// Same SGR handoff class that corrupted the HTML path; the markdown regexes
// produced "**-f _**ARCHIVE_"-style interleaved markers on man-tar.txt line 11.

// T16: real tar fragment — must be well-nested markers, "**" closing before
// "_" opens (old output: "**-f _**ARCHIVE_").
echo "\nT16: cleanTerminalOutput — SGR bold/underline handoff (markdown)\n";
$lines = ["{$esc}[1m-f {$esc}[4m{$esc}[22mARCHIVE{$esc}[24m"];
cleanTerminalOutput($lines);
assert_equals("**-f **_ARCHIVE_", $lines[0], "真实交接片段输出合法嵌套标记");
$lines = ["{$esc}[1mtar -A {$esc}[22m[{$esc}[4mOPTIONS{$esc}[24m] {$esc}[1m-f {$esc}[4m{$esc}[22mARCHIVE{$esc}[24m {$esc}[4mARCHIVE{$esc}[24m..."];
cleanTerminalOutput($lines);
assert_equals("**tar -A **[_OPTIONS_] **-f **_ARCHIVE_ _ARCHIVE_...", $lines[0], "man-tar 第 11 行全文");

// T17: bold→underline handoff mid-token (pins the pre-existing output of the
// old regexes for this input — no regression; the literal _ between the
// markers makes three underscores, same as the old pairing regexes).
echo "\nT17: cleanTerminalOutput — bold/underline handoff mid-token\n";
$lines = ["{$esc}[1mio{$esc}[4m_{$esc}[24mcancel{$esc}[0m"];
cleanTerminalOutput($lines);
assert_equals("**io___cancel**", $lines[0], "io-cancel 标记输出与旧实现一致");

// T18: unclosed SGR at end of line — close still-open markers in reverse.
echo "\nT18: cleanTerminalOutput — close still-open markers at end of line\n";
$lines = ["{$esc}[1mtail {$esc}[4munder"];
cleanTerminalOutput($lines);
assert_equals("**tail _under_**", $lines[0], "行尾补齐闭合（逆序）");

exit(test_summary());
