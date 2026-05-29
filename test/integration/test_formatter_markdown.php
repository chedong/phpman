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

exit(test_summary());
