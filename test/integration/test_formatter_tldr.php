<?php
/**
 * Integration tests: TLDR output format
 * Requirement: SKILL.md §formatTldr
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: TLDR Formatter ===\n\n";

$sampleData = [
    "parameter" => "ls",
    "mode" => "man",
    "section" => "1",
    "summary" => "ls - list directory contents",
    "synopsis" => "ls [OPTION]... [FILE]...",
    "flags" => [
        ["flag" => "-a", "long" => "--all", "arg" => null, "description" => "do not ignore entries starting with ."],
        ["flag" => "-l", "long" => null, "arg" => null, "description" => "use a long listing format"],
        ["flag" => "-h", "long" => "--human-readable", "arg" => null, "description" => "print sizes in human readable format"],
        ["flag" => "-t", "long" => null, "arg" => null, "description" => "sort by modification time"],
    ],
    "examples" => [],
    "sections" => [],
];

echo "T1: TLDR header\n";
$tldr = formatTldr($sampleData);
assert_contains("# ls", $tldr, "has command title");
assert_contains("> ls - list directory contents", $tldr, "has description");
assert_contains("More information", $tldr, "has more info link");

echo "\nT2: Flag examples\n";
assert_contains("--all", $tldr, "has --all flag example");
assert_contains("-l", $tldr, "has -l flag example");

echo "\nT3: Help example\n";
assert_contains("help", strtolower($tldr), "has help example");

echo "\nT4: Max examples\n";
$exampleCount = substr_count($tldr, "- ");
assert_equals(true, $exampleCount <= 12, "example count within limit (got {$exampleCount})");

echo "\nT5: Null input\n";
$emptyTldr = formatTldr(null);
assert_equals("", $emptyTldr, "null input → empty string");

echo "\nT6: Long descriptions filtered\n";
$longData = $sampleData;
$longData["flags"] = [
    ["flag" => "-x", "long" => null, "arg" => null, "description" => str_repeat("very long description exceeding eighty chars ", 3)],
];
$tldr2 = formatTldr($longData);
assert_not_contains("very long description", $tldr2, "long description filtered");

exit(test_summary());
