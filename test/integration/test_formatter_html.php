<?php
/**
 * Integration tests: HTML formatting pipeline
 * Requirement: SKILL.md §Architecture (formatManPerlDoc)
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: HTML Formatter ===\n\n";

$bs = chr(8);
$esc = chr(27);

// T1: Bold overstrike → <b> (merged block)
echo "T1: Bold overstrike\n";
$lines = ["l{$bs}ls{$bs}s"];
$result = formatManPerlDoc($lines, "man");
assert_contains("<b>ls</b>", $result, "l^Hls^Hs → <b>ls</b>");

// T2: SGR bold → <b>
echo "\nT2: SGR bold\n";
$lines = ["{$esc}[1mbold text{$esc}[0m"];
$result = formatManPerlDoc($lines, "man");
assert_contains("<b>bold text</b>", $result, "SGR bold → <b>bold text</b>");

// T3: SGR underline → <u>
echo "\nT3: SGR underline\n";
$lines = ["{$esc}[4munderlined{$esc}[0m"];
$result = formatManPerlDoc($lines, "man");
assert_contains("<u>underlined</u>", $result, "SGR underline → <u>underlined</u>");

// T4: L1 heading anchor
echo "\nT4: L1 heading anchor\n";
$lines = ["NAME", "       ls - list directory contents"];
$result = formatManPerlDoc($lines, "man");
assert_contains("id=", $result, "heading has id attribute");

// T5: HTML special chars escaped
echo "\nT5: HTML entity escaping\n";
$lines = ["Use <file> & \"quotes\""];
$result = formatManPerlDoc($lines, "man");
assert_contains("&amp;", $result, "& → &amp;");

// T6: Email obfuscation
echo "\nT6: Email obfuscation\n";
$lines = ["Contact user@example.com for help"];
$result = formatManPerlDoc($lines, "man");
assert_contains("AT", $result, "email @ obfuscated to AT");

// T7: URL auto-linking
echo "\nT7: URL auto-linking\n";
$lines = ["See https://example.com/docs for details"];
$result = formatManPerlDoc($lines, "man");
assert_contains("href=", $result, "URL auto-linked");

// T8: UTF-8 safety
echo "\nT8: UTF-8 validity\n";
$lines = ["Normal text: • bullet — em dash"];
$result = formatManPerlDoc($lines, "man");
$enc = mb_detect_encoding($result, "UTF-8", true);
assert_equals(true, $enc !== false, "valid UTF-8 encoding");

// T9: No residual backspaces
echo "\nT9: Backspace cleanup\n";
$lines = ["a{$bs}ab{$bs}b", "test"];
$result = formatManPerlDoc($lines, "man");
assert_not_contains($bs, $result, "no residual backspace chars");

exit(test_summary());
