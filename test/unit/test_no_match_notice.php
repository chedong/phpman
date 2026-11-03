<?php
/**
 * Unit tests: noMatchNotice() — the 404 fallback body (#252)
 *
 * The notice exists because an empty fallback rendered as a zero-byte JSON body,
 * which reads as "broken" rather than "nothing matched". But the parameter comes
 * straight from the URL and may be invalid UTF-8: json_encode() returns false for
 * that, and the `: string` return type coerces false to '' — reintroducing the
 * exact zero-byte response the notice was added to remove.
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../src/util.php';

echo "=== Unit: noMatchNotice() ===\n\n";

echo "--- valid UTF-8 ---\n";
$json = noMatchNotice('pydoc', 'BaseHTTPRequestHandler', 'json', 'reason text');
$decoded = json_decode($json, true);
assert_equals(true, is_array($decoded), "json output is valid and parses");
assert_equals([], $decoded['results'], "results is an empty array, not prose");
assert_equals('pydoc', $decoded['mode'], "mode is carried");
assert_equals('BaseHTTPRequestHandler', $decoded['parameter'], "parameter is carried");
assert_equals('reason text', $decoded['note'], "note is carried");

echo "\n--- invalid UTF-8 (the zero-byte case) ---\n";
// \xFF is not a valid UTF-8 sequence. This is the regression: without
// JSON_INVALID_UTF8_SUBSTITUTE, json_encode() returns false and the response
// body is 0 bytes with Content-Type: application/json.
$bad = noMatchNotice('pydoc', "\xFF", 'json', 'reason text');
assert_equals(true, strlen($bad) > 0, "invalid UTF-8 still produces a non-empty body");
assert_equals(true, is_array(json_decode($bad, true)), "…and the body is still valid JSON");
assert_equals(true, strpos($bad, '"results":[]') !== false, "…with the machine-readable empty results");

$badMcp = noMatchNotice('pydoc', "\xFF", 'mcp', 'reason text');
assert_equals(true, strlen($badMcp) > 0, "mcp output is non-empty for invalid UTF-8");
assert_equals(true, is_array(json_decode($badMcp, true)), "…and parses as a JSON-RPC-style envelope");

echo "\n--- other formats ---\n";
assert_equals("> reason text\n", noMatchNotice('pydoc', 'x', 'markdown', 'reason text'),
    "markdown is a plain blockquote");
assert_equals("<p>reason text</p>\n", noMatchNotice('pydoc', 'x', 'html', 'reason text'),
    "html is a paragraph");

exit(test_summary());
