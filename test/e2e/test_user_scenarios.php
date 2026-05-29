<?php
/**
 * E2E tests: Human user scenarios (browser)
 * Persona: 👤 User opens phpMan in browser, searches commands, reads pages
 *
 * Use cases:
 *   U01: Man page detail (ls)
 *   U02: Section routing (tar)
 *   U03: Markdown format
 *   U04: Perldoc
 *   U05: Search (apropos)
 *   U06: Section listing
 *   U07: TLDR
 *   U08: Invalid command graceful fallback
 *   U09: Mobile responsive CSS
 *   U10: Form accessibility labels
 *   U11: TOC sidebar threshold (>80 lines → visible, ≤80 → hidden)
 *   U12: Copyright page
 */
require_once __DIR__ . '/../test_helper.php';

$BASE = "https://www.chedong.com/phpMan.php";

function fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    return ["code" => $httpCode, "headers" => $headers, "body" => $body];
}

echo "=== E2E: Human User Scenarios ===\n\n";

// U01: Man page detail
echo "U01: GET /man/ls/1\n";
$r = fetch("{$BASE}/man/ls/1");
assert_equals(200, $r["code"], "HTTP 200");
assert_contains("ls", strtolower($r["body"]), "mentions ls");
assert_contains("list directory", strtolower($r["body"]), "has description");

// U02: Section routing
echo "\nU02: GET /man/tar/1\n";
$r = fetch("{$BASE}/man/tar/1");
assert_equals(200, $r["code"], "HTTP 200");
assert_contains("tar", strtolower($r["body"]), "mentions tar");

// U03: Markdown format
echo "\nU03: GET /man/ls/1/markdown\n";
$r = fetch("{$BASE}/man/ls/1/markdown");
assert_equals(200, $r["code"], "HTTP 200");
assert_contains("text/markdown", $r["headers"], "Content-Type markdown");
assert_contains("# ls", $r["body"], "markdown heading");

// U04: Perldoc
echo "\nU04: GET /perldoc/File::Path\n";
$r = fetch("{$BASE}/perldoc/File::Path");
assert_equals(200, $r["code"], "HTTP 200 (even if empty)");

// U05: Search (apropos)
echo "\nU05: GET /search/file\n";
$r = fetch("{$BASE}/search/file");
assert_equals(200, $r["code"], "HTTP 200");

// U06: Section listing
echo "\nU06: GET /search/1\n";
$r = fetch("{$BASE}/search/1");
assert_equals(200, $r["code"], "HTTP 200");

// U07: TLDR
echo "\nU07: GET /tldr/ls\n";
$r = fetch("{$BASE}/tldr/ls");
assert_equals(200, $r["code"], "HTTP 200");
assert_contains("# ls", $r["body"], "TLDR title");

// U08: Invalid command graceful
echo "\nU08: GET /man/xyznotexist123\n";
$r = fetch("{$BASE}/man/xyznotexist123");
assert_equals(200, $r["code"], "HTTP 200 (graceful fallback)");

// U09: Mobile CSS
echo "\nU09: Mobile responsive CSS\n";
$r = fetch("{$BASE}/man/ls/1");
assert_contains("max-width:1024px", $r["body"], "mobile breakpoint 1024px");
assert_contains("!important", $r["body"], "TOC !important override");

// U10: Form has labels
echo "\nU10: Form accessibility labels\n";
assert_contains("<label", $r["body"], "has <label> elements");
assert_contains("for=\"cmd-input\"", $r["body"], "label for text input");

// U11: TOC sidebar threshold [phpMan.php:660,667,680-698]
// Short commands (≤80 lines raw) should NOT show TOC sidebar
echo "\nU11: TOC sidebar 80-line threshold\n";
$short = fetch("{$BASE}/man/true/1");
assert_not_contains("className", $short["body"], "true (short) has no ext-nav JS");
assert_not_contains("toc-sidebar\">\n<div", $short["body"], "true has no TOC sidebar div");

// Long commands (>80 lines raw) SHOULD show TOC sidebar
$long = fetch("{$BASE}/man/ls/1");
assert_contains("className", $long["body"], "ls (long) has ext-nav JS");

// U12: Copyright page
echo "\nU12: GET /copyright\n";
$r = fetch("{$BASE}/copyright");
assert_equals(200, $r["code"], "HTTP 200");
assert_contains("GNU", $r["body"], "mentions GNU license");

exit(test_summary());
