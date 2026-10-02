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
 *   U07: TLDR embedded in a man page (the standalone /tldr route is gone)
 *   U08: Invalid command graceful fallback
 *   U09: Mobile responsive CSS
 *   U10: Form accessibility labels
 *   U11: TOC sidebar threshold (>80 lines → visible, ≤80 → hidden)
 *   U12: Copyright page
 */
require_once __DIR__ . '/../test_helper.php';

$BASE = getenv("PHPMAN_TEST_URL") ?: "https://www.chedong.com/phpMan.php";

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

// U07: TLDR — embedded in the man page, no longer a standalone route
// e70318a removed /tldr in favour of a block rendered inside the page, so this
// asserts the route stays gone AND that the block actually reaches the user.
// The block depends on fetchOfficialTldr(), whose result is held in tldr_cache
// for PHPMAN_CACHE_TTL_FOUND (210 days) — stable once a page has been fetched.
echo "\nU07: GET /tldr/ls (removed) + embedded TLDR block\n";
$r = fetch("{$BASE}/tldr/ls");
assert_equals(404, $r["code"], "standalone /tldr route stays removed");
$r = fetch("{$BASE}/man/ls/1");
assert_contains("tldr-block", $r["body"], "TLDR block embedded in the man page");

// U08: Invalid command — 404 with a helpful body
// The 404 is deliberate (10939fd, v2.3 hardening): a page that does not exist
// has to say so for crawlers, while still showing the user somewhere to go.
echo "\nU08: GET /man/xyznotexist123\n";
$r = fetch("{$BASE}/man/xyznotexist123");
assert_equals(404, $r["code"], "HTTP 404 for an unknown command");
assert_contains("Not found locally", $r["body"], "still renders a helpful body");

// U09: Mobile CSS — served from phpman.css since b908fcb
echo "\nU09: Mobile responsive CSS\n";
$r = fetch("{$BASE}/man/ls/1");
assert_contains("phpman.css", $r["body"], "page links the stylesheet");
$css = fetch(str_replace("phpMan.php", "phpman.css", $BASE));
assert_contains("max-width: 1024px", $css["body"], "mobile breakpoint 1024px");
assert_contains("!important", $css["body"], "TOC !important override");

// U10: Form has labels
echo "\nU10: Form accessibility labels\n";
assert_contains("<label", $r["body"], "has <label> elements");
assert_contains("for=\"cmd-input\"", $r["body"], "label for text input");

// U11: TOC sidebar threshold [phpMan.php:660,667,680-698]
// The sidebar is marked by the body class; the JS driving it moved to
// phpman.js in b908fcb, so an inline "className" no longer appears anywhere.
// Short commands (≤80 lines raw) should NOT show TOC sidebar
echo "\nU11: TOC sidebar 80-line threshold\n";
$short = fetch("{$BASE}/man/true/1");
assert_not_contains("class=\"ext-nav\"", $short["body"], "true (short) has no ext-nav sidebar");

// Long commands (>80 lines raw) SHOULD show TOC sidebar
$long = fetch("{$BASE}/man/ls/1");
assert_contains("class=\"ext-nav\"", $long["body"], "ls (long) has ext-nav sidebar");

// U12: Copyright page
echo "\nU12: GET /copyright\n";
$r = fetch("{$BASE}/copyright");
assert_equals(200, $r["code"], "HTTP 200");
assert_contains("GNU", $r["body"], "mentions GNU license");

exit(test_summary());
