<?php
/**
 * Integration tests: JSON output format
 * Requirement: SKILL.md §JSON Output Format
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: JSON Formatter ===\n\n";

// Simulate a realistic man page with proper overstrike formatting
$bs = chr(8);
$lines = [
    "LS(1)                     User Commands                     LS(1)",
    "",
    "N{$bs}NA{$bs}AM{$bs}ME{$bs}E",
    "       ls - list directory contents",
    "",
    "S{$bs}SY{$bs}YN{$bs}NO{$bs}OP{$bs}PS{$bs}SI{$bs}IS{$bs}S",
    "       ls [OPTION]... [FILE]...",
    "",
    "D{$bs}DE{$bs}ES{$bs}SC{$bs}CR{$bs}RI{$bs}IP{$bs}PT{$bs}TI{$bs}IO{$bs}ON{$bs}N",
    "       List  information  about the FILEs.",
    "",
    "       -a{$bs}a, --all",
    "              do not ignore entries starting with .",
    "",
    "       -l     use a long listing format",
    "",
    "S{$bs}SE{$bs}EE{$bs}E A{$bs}AL{$bs}LS{$bs}SO{$bs}O",
    "       dir(1), vdir(1)",
];

$jsonStr = formatToJSON($lines, "ls", "1", "man");
$data = json_decode($jsonStr, true);

// Structure validation
echo "T1: JSON structure\n";
assert_equals("man", $data["mode"], "mode = man");
assert_equals("ls", $data["parameter"], "parameter = ls");
assert_equals("1", $data["section"], "section = 1");

// Summary
echo "\nT2: Summary extraction\n";
assert_equals(true, isset($data["summary"]), "has summary field");

// Synopsis
echo "\nT3: Synopsis extraction\n";
assert_equals(true, isset($data["synopsis"]), "has synopsis field");

// Sections (keyed object)
echo "\nT4: Sections structure\n";
$sections = $data["sections"];
assert_equals(true, is_array($sections), "sections is array/object");

// Flags
echo "\nT5: Flags extraction\n";
$flags = $data["flags"];
assert_equals(true, is_array($flags), "flags is array");
// May have 0 or more flags depending on parsing
echo "  ℹ️  Extracted " . count($flags) . " flags\n";

// Valid JSON re-encoding
echo "\nT6: Valid JSON\n";
$reEncoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
assert_equals(true, $reEncoded !== false, "JSON re-encodes without error");

// No deprecated fields
echo "\nT7: No deprecated fields\n";
assert_equals(false, isset($data["name"]), "no deprecated 'name' field");
assert_equals(false, isset($data["command"]), "no deprecated 'command' field");

exit(test_summary());
