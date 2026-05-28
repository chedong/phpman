<?php
declare(strict_types=1);

define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../phpMan.php';

echo "=== Running phpMan HTML Formatter Integration Tests ===\n";

function assert_contains_str(string $haystack, string $needle, string $message): void {
    if (strpos($haystack, $needle) === false) {
        echo "FAIL: $message (Could not find '$needle' in HTML output)\n";
        echo "HTML Output:\n$haystack\n";
        exit(1);
    }
    echo "PASS: $message\n";
}

// 1. Test bold overstrike sequence (g^Hg and c^Hc)
$lines = [
    "g" . chr(8) . "g" . "c" . chr(8) . "c"
];
$html = formatManPerlDoc($lines, "man");
assert_contains_str($html, "<b>gc</b>", "Bold overstrike formatted and merged correctly to <b>gc</b>");

// 2. Test SGR escape sequences for Bold
$lines = [
    chr(27) . "[1mgcc" . chr(27) . "[0m"
];
$html = formatManPerlDoc($lines, "man");
assert_contains_str($html, "<b>gcc</b>", "SGR bold formatted correctly to <b>");

// 3. Test Level 1 heading detection and anchoring in HTML
$lines = [
    "NAME"
];
$html = formatManPerlDoc($lines, "man");
assert_contains_str($html, '<a id="section-name"></a><b>NAME</b>', "Level 1 Heading anchored and bolded");

// 4. Test Level 2 perldoc heading detection and anchoring in HTML
$lines = [
    "  Methods you should implement"
];
$html = formatManPerlDoc($lines, "perldoc");
assert_contains_str($html, '<a id="sub-methods-you-should-implement"></a>  <u>Methods you should implement</u>', "Level 2 Heading anchored and underlined");

// 5. Test Table of Contents extraction
$lines = [
    "NAME",
    "  Methods you should implement",
    "DESCRIPTION"
];
$html = formatManPerlDoc($lines, "perldoc");
list($anchoredHtml, $tocItems) = addManPageToc($html);

if (count($tocItems) !== 2) {
    echo "FAIL: Expected 2 main TOC items, got " . count($tocItems) . "\n";
    exit(1);
}
echo "PASS: Table of Contents has correct count of items\n";

if ($tocItems[0]['id'] !== 'section-name' || $tocItems[0]['label'] !== 'NAME') {
    echo "FAIL: TOC Level 1 item 0 incorrect\n";
    exit(1);
}
echo "PASS: TOC Level 1 item 0 properties correct\n";

if (count($tocItems[0]['children']) !== 1 || $tocItems[0]['children'][0]['id'] !== 'sub-methods-you-should-implement') {
    echo "FAIL: TOC Level 2 nested child item incorrect\n";
    exit(1);
}
echo "PASS: TOC Level 2 child item nested correctly under Level 1 parent\n";

echo "\nAll phpMan HTML Formatter Integration Tests PASSED!\n";
