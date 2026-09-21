<?php
/**
 * Integration tests: content cap for json/mcp payloads
 *
 * Very large pages (info py is ~14MB of section text) would otherwise be held
 * in memory in full just to be serialised, which exceeds PHP's memory_limit.
 * Text past the budget is dropped, the payload says so, and the semantic fields
 * are still derived from the full line stream — they must survive the cap.
 */
define('PHPMAN_TEST_MODE', true);
// Force truncation with a budget this sample page cannot fit in.
define('PHPMAN_JSON_MAX_CONTENT_BYTES', 300);
define('PHPMAN_JSON_MAX_SECTION_BYTES', 200);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: JSON content cap ===\n";

$lines = [
    "LS(1)",
    "",
    "N" . "\x08" . "NA" . "\x08" . "AM" . "\x08" . "ME",
    "       ls - list directory contents",
    "",
    "D" . "\x08" . "DE" . "\x08" . "ES" . "\x08" . "SC" . "\x08" . "CR" . "\x08" . "RI" . "\x08" . "IP" . "\x08" . "PT" . "\x08" . "TI" . "\x08" . "IO" . "\x08" . "ON",
    "       List information about the FILEs (the current directory by default).",
    str_repeat("       filler line for the description section.\n", 0) . "       Sort entries alphabetically if none of -cftuvSUX nor --sort is specified.",
    "",
    "O" . "\x08" . "OP" . "\x08" . "PT" . "\x08" . "TI" . "\x08" . "IO" . "\x08" . "ON" . "\x08" . "NS",
    "       -a, --all",
    "              do not ignore entries starting with .",
    "       -l     use a long listing format",
    "",
    "E" . "\x08" . "EX" . "\x08" . "XA" . "\x08" . "AM" . "\x08" . "MP" . "\x08" . "PL" . "\x08" . "LE" . "\x08" . "ES",
    "       ls -la",
    "       ls -R /tmp",
    "",
    "S" . "\x08" . "SE" . "\x08" . "EE" . "\x08" . "E A" . "\x08" . "AL" . "\x08" . "LS" . "\x08" . "SO" . "\x08" . "O",
    "       dir(1), vdir(1)",
    "",
];
// Give the DESCRIPTION section plenty of text so the budget runs out inside it.
array_splice($lines, 8, 0, explode("\n", str_repeat("       padding line to consume the budget.\n", 40)));

$copy = $lines;
$json = formatToJSON($copy, "ls", "1", "man");
$data = json_decode($json, true);

echo "\n--- payload stays valid JSON ---\n";
assert_equals(null, json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg(), "capped payload parses");
assert_equals(true, is_array($data), "decodes to an array");

echo "\n--- truncation is declared ---\n";
assert_equals(true, $data["content_truncated"] ?? null, "content_truncated flag set");
assert_equals(300, $data["content_budget_bytes"] ?? null, "budget reported");
assert_equals(true, ($data["content_retained_bytes"] ?? 0) > 0, "retained bytes reported");
assert_equals(true, ($data["content_retained_bytes"] ?? 0) <= 300, "retained bytes within budget");

$truncated = null;
foreach ($data["sections"] as $sec) {
    if (!empty($sec["truncated"])) {
        $truncated = $sec;
        break;
    }
}
assert_equals(true, $truncated !== null, "at least one section marked truncated");
assert_equals(true, ($truncated["content_bytes"] ?? 0) > strlen($truncated["content"] ?? ""), "content_bytes exceeds what was kept");
assert_equals(true, ($truncated["content_lines"] ?? 0) > 0, "content_lines reported");

echo "\n--- outline and semantic fields survive the cap ---\n";
assert_equals(true, isset($data["sections"]["NAME"]), "NAME section still present");
assert_equals(true, isset($data["sections"]["OPTIONS"]), "OPTIONS section still present");
assert_equals(true, isset($data["sections"]["EXAMPLES"]), "EXAMPLES section still present");
assert_equals("ls - list directory contents", $data["summary"] ?? null, "summary derived despite the cap");
assert_equals(true, count($data["flags"] ?? []) >= 1, "flags derived despite the cap");
assert_equals("-a", $data["flags"][0]["flag"] ?? null, "flag parsed despite the cap");
assert_equals(true, count($data["examples"] ?? []) > 0, "examples derived despite the cap");
assert_equals(true, count($data["see_also"] ?? []) > 0, "see_also derived despite the cap");

echo "\n--- the MCP envelope carries the cap too ---\n";
// formatMcpStructured() picks fields explicitly, so the cap has to be surfaced
// there as well — a consumer reading only structuredContent sees no sections
// metadata otherwise.
$copy2 = $lines;
$envelope = json_decode(formatMcpEnvelope(buildJsonData($copy2, "ls", "1", "man")), true);
assert_equals(true, is_array($envelope), "mcp envelope is valid JSON");
assert_equals(true, $envelope["structuredContent"]["content_truncated"] ?? null, "envelope declares truncation");
assert_equals(300, $envelope["structuredContent"]["content_budget_bytes"] ?? null, "envelope reports the budget");
assert_equals(true, str_contains($envelope["content"][0]["text"], "Section text is capped"), "markdown says the text is capped");
assert_equals("ls - list directory contents", $envelope["structuredContent"]["summary"] ?? null, "envelope summary intact");

exit(test_summary());
