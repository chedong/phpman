<?php
declare(strict_types=1);

define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../phpMan.php';

echo "=== Running phpMan Markdown Formatter Integration Tests ===\n";

function assert_contains_str(string $haystack, string $needle, string $message): void {
    if (strpos($haystack, $needle) === false) {
        echo "FAIL: $message (Could not find '$needle' in Markdown output)\n";
        echo "Markdown Output:\n$haystack\n";
        exit(1);
    }
    echo "PASS: $message\n";
}

// 1. Test Level 1 heading conversion to ##
$lines = [
    "NAME"
];
$md = formatManPerlDocToMarkdown($lines);
assert_contains_str($md, "## NAME", "Level 1 heading formatted correctly to ##");

// 2. Test Level 2 heading conversion to ###
$lines = [
    "  Methods you should implement"
];
$md = formatManPerlDocToMarkdown($lines);
assert_contains_str($md, "### Methods you should implement", "Level 2 heading formatted correctly to ###");

// 3. Test bold overstrike formatting to **
$lines = [
    "g" . chr(8) . "g" . "c" . chr(8) . "c"
];
$md = formatManPerlDocToMarkdown($lines);
assert_contains_str($md, "**gc**", "Bold overstrike formatted correctly to **");

// 4. Test SGR escape sequences for Bold to **
$lines = [
    chr(27) . "[1mgcc" . chr(27) . "[0m"
];
$md = formatManPerlDocToMarkdown($lines);
assert_contains_str($md, "**gcc**", "SGR bold formatted correctly to **");

// 5. Test email and url autolinks
$lines = [
    "Contact chedong@chedong.com at https://www.chedong.com"
];
$md = formatManPerlDocToMarkdown($lines);
assert_contains_str($md, "<chedong@chedong.com>", "Email link wrapped as markdown autolink");
assert_contains_str($md, "<https://www.chedong.com>", "URL wrapped as markdown autolink");

// 6. Test command references and Perl modules
$lines = [
    "Use gcc(1) and File::Path module"
];
$md = formatManPerlDocToMarkdown($lines);
assert_contains_str($md, "[gcc(1)]", "Command reference gcc(1) wrapped in brackets");
assert_contains_str($md, "[File::Path]", "Perl module File::Path wrapped in brackets");

echo "\nAll phpMan Markdown Formatter Integration Tests PASSED!\n";
