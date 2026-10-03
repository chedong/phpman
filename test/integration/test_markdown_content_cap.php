<?php
/**
 * Integration tests: whole-page byte cap for markdown output (#227)
 *
 * markdown had no payload cap, unlike json/mcp. The body was concatenated in
 * full and then gzcompressed for the cache, so a monster page (info py, zshall)
 * doubled an unbounded string until memory_limit was gone and the request 500'd
 * — with nothing in the error log, because an OOM kill leaves no PHP fatal.
 *
 * The page must still render, just shortened, and say so.
 */
define('PHPMAN_TEST_MODE', true);
// Force truncation with a budget this sample page cannot fit in.
define('PHPMAN_MD_MAX_BYTES', 300);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: markdown content cap ===\n";

$lines = ["LS(1)", ""];
for ($i = 0; $i < 60; $i++) {
    $lines[] = "       padding line {$i} to consume the markdown budget.";
}
$lines[] = "";
$lines[] = "       tail marker that must NOT survive the cap";

$copy = $lines;
// parameter "" so the TLDR lookup is skipped — this suite needs no network.
$md = formatManPerlDocToMarkdown($copy, "", "man", "");

echo "\n--- the body is bounded ---\n";
$noticeAt = strpos($md, '*Output truncated at');
assert_equals(true, $noticeAt !== false, "truncation notice present");
$body = ($noticeAt === false) ? $md : substr($md, 0, $noticeAt);
assert_equals(true, strlen($body) <= 300, "retained body within the budget");

echo "\n--- the cap actually dropped content ---\n";
assert_equals(false, strpos($md, 'tail marker') !== false, "content past the budget was dropped");

echo "\n--- the notice points at the uncapped format ---\n";
assert_equals(true, strpos($md, '/json') !== false, "notice links the json view");
assert_equals(true, strlen($md) <= 300 + 200, "notice itself is small and bounded");

echo "\n--- a page within budget is left alone ---\n";
$short = ["LS(1)", "", "       short page body"];
$shortMd = formatManPerlDocToMarkdown($short, "", "man", "");
assert_equals(false, strpos($shortMd, '*Output truncated at') !== false, "no notice on a page that fits");
assert_equals(true, strpos($shortMd, 'short page body') !== false, "short page body intact");

exit(test_summary());
