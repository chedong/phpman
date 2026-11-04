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
require_once __DIR__ . '/../../src/source_man.php';   // manNoMatchNotice (#253)

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

// man's miss path had no notice at all until #253 — an empty apropos result
// rendered as a 41-byte bare title. The deploy verifier (deploy/verify-release.sh)
// now asserts this text is present after a release.
echo "\n--- man wrapper (#253) ---\n";
$manMd = manNoMatchNotice('zzz_phpman_verify', '1', 'markdown');
assert_contains('No man page matches "zzz_phpman_verify(1)"', $manMd,
    "the man notice names the page with its section");
assert_equals(true, strpos($manMd, '> ') === 0, "…and renders as a blockquote");
$manNoSection = manNoMatchNotice('zzz_phpman_verify', '', 'markdown');
assert_contains('"zzz_phpman_verify"', $manNoSection,
    "with no section it does not invent an empty ()");
$manJson = json_decode(manNoMatchNotice('x', '', 'json'), true);
assert_equals('man', $manJson['mode'] ?? null, "json envelope carries mode=man");
assert_equals([], $manJson['results'] ?? null, "…and an empty results array");

exit(test_summary());
