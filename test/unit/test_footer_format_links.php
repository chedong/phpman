<?php
declare(strict_types=1);
/**
 * Unit tests: footer format links (showFooter)
 *
 * The footer used to append a third "MCP" link pointing at
 * /{mode}/{param}/mcp. That segment is not a format any more — it is not in
 * PHPMAN_OUTPUT_FORMATS, so man pages 301 back to their HTML page and
 * perldoc/info render the segment as a bogus section name ("perldoc(mcp)").
 * MCP is a machine endpoint, advertised by the `Link: </mcp>; rel="mcp-server"`
 * header and by /.well-known/mcp.json, not as a per-page format.
 */
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: footer format links ===\n";

function footerFor(string $mode, string $parameter, string $section, string $markdownUrl, string $jsonUrl): string {
    ob_start();
    showFooter("", false, $mode, $parameter, $section, $markdownUrl, $jsonUrl);
    return (string)ob_get_clean();
}

echo "\n--- detail page ---\n";
$html = footerFor("man", "ls", "1",
    "https://example.test/phpMan.php/man/ls/1/markdown",
    "https://example.test/phpMan.php/man/ls/1/json");

assert_contains('class="fmt-link"', $html, "detail page has format links");
assert_contains(">Markdown</a>", $html, "Markdown link present");
assert_contains(">JSON</a>", $html, "JSON link present");
assert_not_contains("/mcp", $html, "no dead /mcp format link");
assert_not_contains(">MCP</a>", $html, "no MCP entry in the format list");
assert_contains("https://example.test/phpMan.php/man/ls/1/markdown", $html, "markdown href is used verbatim");
assert_contains("https://example.test/phpMan.php/man/ls/1/json", $html, "json href is used verbatim");

echo "\n--- no content URLs ---\n";
$html = footerFor("man", "ls", "1", "", "");
assert_not_contains('class="fmt-link"', $html, "no format links when there is no content to link to");

echo "\n--- index page (no parameter) ---\n";
$html = footerFor("man", "", "",
    "https://example.test/phpMan.php?mode=man&format=markdown",
    "https://example.test/phpMan.php?mode=man&format=json");
assert_not_contains('class="fmt-link"', $html, "no format links on index pages");

exit(test_summary());
