<?php
/**
 * Integration tests: Markdown formatting pipeline
 * Requirement: SKILL.md §Format Negotiation (formatManPerlDocToMarkdown)
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: Markdown Formatter ===\n\n";

$bs = chr(8);
$esc = chr(27);

// T1: L1 heading → ##
echo "T1: L1 heading → ##\n";
$lines = ["NAME", "       ls - list directory contents"];
$result = formatManPerlDocToMarkdown($lines);
assert_contains("## NAME", $result, "NAME → ## NAME");

// T2: L2 heading → ###
echo "\nT2: L2 heading → ###\n";
$lines = ["DESCRIPTION", "   **Packages**", "       package info"];
$result = formatManPerlDocToMarkdown($lines);
assert_contains("### Packages", $result, "L2 bold → ### Packages");

// T3: Bold overstrike → **text**
echo "\nT3: Bold → **text**\n";
$lines = ["l{$bs}ls{$bs}s"];
$result = formatManPerlDocToMarkdown($lines);
assert_contains("**ls**", $result, "bold overstrike → **ls**");

// T4: SGR bold → **text**
echo "\nT4: SGR bold → **text**\n";
$lines = ["{$esc}[1mbold{$esc}[0m"];
$result = formatManPerlDocToMarkdown($lines);
assert_contains("**bold**", $result, "SGR bold → **bold**");

// T5: URL preserved
echo "\nT5: URL preserved\n";
$lines = ["See https://example.com/docs"];
$result = formatManPerlDocToMarkdown($lines);
assert_contains("https://example.com/docs", $result, "URL preserved");

// T6: Perl module reference preserved
echo "\nT6: Perl module reference\n";
$lines = ["See File::Path for details"];
$result = formatManPerlDocToMarkdown($lines);
assert_contains("File::Path", $result, "Perl module preserved");

// T7: UTF-8 validity
echo "\nT7: UTF-8 validity\n";
$lines = ["UTF-8: • bullet — em dash"];
$result = formatManPerlDocToMarkdown($lines);
$enc = mb_detect_encoding($result, "UTF-8", true);
assert_equals(true, $enc !== false, "valid UTF-8");

// T8: No backspaces
echo "\nT8: Backspace cleanup\n";
$lines = ["a{$bs}ab{$bs}b"];
$result = formatManPerlDocToMarkdown($lines);
assert_not_contains(chr(8), $result, "no residual backspaces");

// T9: OSC 8 hyperlinks keep their target as a markdown link (#236)
// groff emits these for man pages using .UR/.UE (netpbm and friends).
echo "\nT9: OSC 8 hyperlink → markdown link\n";
$osc = "{$esc}]8;;https://example.com/x{$esc}\\text{$esc}]8;;{$esc}\\";
$lines = [$osc];
$result = formatManPerlDocToMarkdown($lines);
// The markdown pipeline wraps the target in angle brackets, so assert the
// construct and the target rather than one exact spelling.
assert_contains("[text](", $result, "markdown renders the OSC 8 link");
assert_contains("https://example.com/x", $result, "markdown keeps the OSC 8 target");
assert_not_contains(chr(27), $result, "no residual ESC");

// T10: a dangerous OSC 8 scheme is dropped in both styles (#236)
echo "\nT10: javascript: OSC 8 target is dropped\n";
$evil = "{$esc}]8;;javascript:alert(1){$esc}\\click{$esc}]8;;{$esc}\\";
$lines = [$evil];
$result = formatManPerlDocToMarkdown($lines);
assert_not_contains("javascript:", $result, "markdown drops a javascript: target");
assert_not_contains("](http", $result, "markdown emits no link for it");

exit(test_summary());
