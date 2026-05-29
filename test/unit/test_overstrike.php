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

exit(test_summary());
