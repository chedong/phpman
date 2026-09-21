<?php
/**
 * Unit tests: appendProfilingJson()
 *
 * `?debug=1` appends a _profiling key to json/mcp responses. It is injected
 * into the serialised body rather than json_decode()+json_encode(), because
 * re-serialising a multi-MB response to add one key doubled the peak of an
 * already large request (info py) and blew the 128MB memory_limit.
 *
 * The injection must never produce invalid JSON, and must leave bodies that
 * cannot take an extra key (JSON arrays, plain text) untouched.
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: appendProfilingJson ===\n\n";

$report = ['0' => ['label' => 'init', 'elapsed_ms' => 0, 'delta_ms' => 0], '_total_ms' => 1.5, '_version' => '1'];

echo "--- pretty-printed object (the mcp/json envelope shape) ---\n";
$pretty = "{\n    \"content\": [\n        {\n            \"type\": \"text\",\n            \"text\": \"hi\"\n        }\n    ],\n    \"structuredContent\": {\n        \"mode\": \"info\"\n    }\n}";
$out = appendProfilingJson($pretty, $report);
$decoded = json_decode($out, true);
assert_equals(null, json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg(), "result is valid JSON");
assert_equals('hi', $decoded['content'][0]['text'] ?? null, "existing nested content preserved");
assert_equals('info', $decoded['structuredContent']['mode'] ?? null, "existing nested object preserved");
assert_equals(1.5, $decoded['_profiling']['_total_ms'] ?? null, "_profiling payload attached");
assert_equals('_profiling', array_key_last($decoded), "_profiling is the last key");

echo "\n--- compact object ---\n";
$out = appendProfilingJson('{"a":1}', $report);
assert_equals(['a' => 1, '_profiling' => $report], json_decode($out, true), "compact object gets the key");

echo "\n--- empty object ---\n";
$out = appendProfilingJson('{}', $report);
assert_equals(['_profiling' => $report], json_decode($out, true), "empty object becomes {_profiling}");

echo "\n--- object with trailing newline ---\n";
$out = appendProfilingJson("{\n    \"a\": 1\n}\n", $report);
assert_equals(['a' => 1, '_profiling' => $report], json_decode($out, true), "trailing whitespace tolerated");

echo "\n--- bodies that cannot take an extra key are left untouched ---\n";
assert_equals("[]", appendProfilingJson("[]", $report), "empty JSON array unchanged");
assert_equals('[{"a":1}]', appendProfilingJson('[{"a":1}]', $report), "JSON array unchanged");
assert_equals("plain text", appendProfilingJson("plain text", $report), "non-JSON unchanged");
assert_equals("", appendProfilingJson("", $report), "empty body unchanged");

echo "\n--- large body: injection cost is a copy, not a re-encode ---\n";
$big = ['sections' => []];
for ($i = 0; $i < 2000; $i++) {
    $big['sections']['Section ' . $i] = str_repeat('line of manual text. ', 40);
}
$bigJson = json_encode($big, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$out = appendProfilingJson($bigJson, $report);
$back = json_decode($out, true);
assert_equals(2000, count($back['sections'] ?? []), "all 2000 sections survive injection");
assert_equals(substr($bigJson, 0, 40), substr($out, 0, 40), "body prefix untouched");
assert_equals(1.5, $back['_profiling']['_total_ms'] ?? null, "profiling attached to large body");

exit(test_summary());
