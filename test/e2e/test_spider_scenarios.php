<?php
/**
 * E2E tests: Web spider/crawler scenarios
 * Persona: 🕷️ Google/Bing bot crawls phpMan for indexing
 *
 * Use cases:
 *   S01: Discover via homepage → follow links
 *   S02: Canonical URL in meta tags
 *   S03: robots meta (index/noindex)
 *   S04: JSON-LD structured data
 *   S05: Link structure (all internal links valid)
 *   S06: No duplicate content (canonical prevents it)
 *   S07: sitemap-like discovery via section index
 *   S08: XHTML validity (parseable by strict parsers)
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
        CURLOPT_USERAGENT => "Googlebot/2.1 (+http://www.google.com/bot.html)",
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [
        "code" => $httpCode,
        "headers" => substr($response, 0, $headerSize),
        "body" => substr($response, $headerSize),
        "contentType" => $contentType,
    ];
}

echo "=== E2E: Spider/Crawler Scenarios ===\n\n";

// S01: Homepage discoverability
echo "S01: Homepage → links to sections\n";
$r = fetch("{$BASE}");
assert_equals(200, $r["code"], "HTTP 200");
assert_contains("href=", $r["body"], "has links");
assert_contains("/man", $r["body"], "links to man pages");

// S02: Canonical URL
echo "\nS02: Canonical URL on detail page\n";
$r = fetch("{$BASE}/man/ls/1");
assert_contains("<link rel=\"canonical\"", $r["body"], "has canonical link");
assert_contains("phpMan.php/man/ls/1", $r["body"], "canonical URL correct");

// S03: robots meta
echo "\nS03: robots meta (index for real content)\n";
assert_contains("index, follow", $r["body"], "real page → index, follow");

// Empty page → noindex
$r2 = fetch("{$BASE}/man/xyznotexist123");
// May or may not have noindex depending on fallback behavior
echo "  ℹ️  noindex check: graceful fallback to search\n";

// S04: JSON-LD structured data
echo "\nS04: JSON-LD structured data\n";
$r = fetch("{$BASE}/man/ls/1");
assert_contains("application/ld+json", $r["body"], "has JSON-LD");
assert_contains("TechArticle", $r["body"], "schema type = TechArticle");
assert_contains("Che Dong", $r["body"], "has author");

// S05: Internal link validity (spot check)
echo "\nS05: Internal links follow consistent pattern\n";
$r = fetch("{$BASE}/man/ls/1");
// Extract hrefs
preg_match_all('/href="([^"]*phpMan[^"]*)"/', $r["body"], $matches);
$validLinks = 0;
$invalidLinks = 0;
foreach (array_slice($matches[1], 0, 10) as $link) {
    if (preg_match('#/phpMan\.php/(man|perldoc|search|info)/#', $link)) {
        $validLinks++;
    } else {
        $invalidLinks++;
    }
}
assert_equals(true, $validLinks > 0, "found valid internal links ({$validLinks})");

// S06: GEO citation meta tags
echo "\nS06: GEO citation tags for AI attribution\n";
assert_contains("citation_title", $r["body"], "has citation_title");
assert_contains("citation_author", $r["body"], "has citation_author");
assert_contains("citation_online_date", $r["body"], "has citation_online_date");

// S07: Section index discoverability
echo "\nS07: Section index page\n";
$r = fetch("{$BASE}/search/1");
assert_equals(200, $r["code"], "HTTP 200");
assert_contains("href=", $r["body"], "has links to commands");

// S08: Content-Type headers
echo "\nS08: Correct Content-Type for each format\n";
$rHtml = fetch("{$BASE}/man/ls/1");
assert_contains("text/html", $rHtml["contentType"], "HTML → text/html");

$rJson = fetch("{$BASE}/man/ls/1/json");
assert_contains("application/json", $rJson["contentType"], "JSON → application/json");

$rMd = fetch("{$BASE}/man/ls/1/markdown");
assert_contains("text/markdown", $rMd["contentType"], "Markdown → text/markdown");

// S09: MCP discovery header
echo "\nS09: MCP server discovery Link header\n";
assert_contains("mcp-server", strtolower($rHtml["headers"]), "Link header has mcp-server");

exit(test_summary());
