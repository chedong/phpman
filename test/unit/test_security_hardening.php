<?php
/**
 * Unit tests: security hardening from the 2026-11-03 audit
 *   #277 isLoopbackAddress() — an empty REMOTE_ADDR is not loopback
 *   #278 renderGroupedResults() — the URL prefix is HTML-escaped
 *   #275 the traditional URL linkifier — scheme allowlist, not "any scheme"
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: security hardening (#275 #277 #278) ===\n\n";

// ──────────────────────────────────────────────
// #277
// ──────────────────────────────────────────────
echo "--- #277 isLoopbackAddress() ---\n";
assert_equals(true, isLoopbackAddress("127.0.0.1"), "127.0.0.1 is loopback");
assert_equals(true, isLoopbackAddress("::1"), "::1 is loopback");
assert_equals(false, isLoopbackAddress(""),
    "an EMPTY address is NOT loopback — that is the #277 fix");
assert_equals(false, isLoopbackAddress("192.168.1.10"), "RFC1918 is not loopback");
assert_equals(false, isLoopbackAddress("10.0.0.1"), "RFC1918 is not loopback");
assert_equals(false, isLoopbackAddress("203.0.113.9"), "a public address is not loopback");
assert_equals(false, isLoopbackAddress("127.0.0.2"), "127.0.0.2 is outside the allowlist");
assert_equals(false, isLoopbackAddress("localhost"), "the hostname form is not an address");

// ──────────────────────────────────────────────
// #278
// ──────────────────────────────────────────────
echo "\n--- #278 renderGroupedResults() escapes the URL prefix ---\n";
// A prefix that would break out of the attribute if it were concatenated raw.
$hostile = 'https://x.example/" onmouseover="alert(1)';
$results = [];
for ($i = 0; $i < 3; $i++) {
    $results[] = ['name' => "cmd$i", 'section' => '', 'description' => '', 'sources' => ['pydoc']];
}
// Below the alpha threshold -> the flat-list branch.
$out = renderGroupedResults($results, $hostile)['html'];
assert_not_contains('" onmouseover="', $out,
    "a quote in the URL prefix does not break out of href (#278)");
assert_contains('&quot;', $out, "…it comes out as &quot; instead");
assert_contains('onmouseover=', $out,
    "…the text itself survives, just escaped — we are not silently dropping data");

// Above the threshold -> the grouped branch, which builds the same href.
$many = [];
for ($i = 0; $i < 90; $i++) {
    $many[] = ['name' => sprintf('cmd%02d', $i), 'section' => '', 'description' => '', 'sources' => ['pydoc']];
}
$outMany = renderGroupedResults($many, $hostile)['html'];
assert_not_contains('" onmouseover="', $outMany,
    "…the grouped branch is escaped too");

// A normal prefix must be left alone (no double-escaping of the slashes etc.).
$plain = renderGroupedResults($results, 'https://www.example.com/phpMan.php')['html'];
assert_contains('href="https://www.example.com/phpMan.php/pydoc/cmd0"', $plain,
    "an ordinary prefix comes through unchanged");

// ──────────────────────────────────────────────
// #275
// ──────────────────────────────────────────────
echo "\n--- #275 URL linkifier scheme allowlist ---\n";
// Lines are fed to the renderer; the linkifier rewrites any absolute URL in them.
$probe = function (string $url) {
    $html = formatManPerlDoc(["  " . $url], "man");
    return (string)$html;
};

$js = $probe('javascript://%0Aalert(1)');
assert_not_contains('href="javascript:', $js,
    "javascript:// is NOT turned into a link (#275)");

$data = $probe('data://text/html;base64,PHNjcmlwdD4=');
assert_not_contains('href="data:', $data, "data:// is NOT turned into a link");

foreach (['https://example.com/a', 'http://example.com/a', 'ftp://ftp.example.com/f'] as $safe) {
    $html = $probe($safe);
    assert_contains('href="' . $safe . '"', $html, "{$safe} is still linkified");
}

$host = $probe('https://example.com.cn/path?x=1&y=2');
assert_contains('<a href="', $host, "a URL with a query string still becomes a link");

exit(test_summary());
