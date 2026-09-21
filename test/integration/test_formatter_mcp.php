<?php
/**
 * Integration tests: MCP output format
 * Requirement: SKILL.md §formatForOutput, §formatMcpMarkdown, §formatMcpStructured
 */
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: MCP Formatter ===\n";

// Build a sample JSON document
$sampleData = [
    "mode" => "man",
    "parameter" => "ls",
    "section" => "1",
    "summary" => "ls - list directory contents",
    "synopsis" => "ls [OPTION]... [FILE]...",
    "flags" => [
        ["flag" => "-a", "long" => "--all", "arg" => null, "description" => "do not ignore entries starting with ."],
        ["flag" => "-l", "long" => null, "arg" => null, "description" => "use a long listing format"],
    ],
    "examples" => ["ls -la", "ls -R /tmp"],
    "see_also" => [
        ["name" => "dir", "section" => "1", "url" => "/phpMan.php/man/dir/1/json"],
    ],
    "sections" => [
        "NAME" => ["content" => "ls - list directory contents", "subsections" => []],
        "SYNOPSIS" => ["content" => "ls [OPTION]... [FILE]...", "subsections" => []],
        "DESCRIPTION" => ["content" => "List information about files.", "subsections" => [
            ["name" => "-a, --all", "content" => "do not ignore entries starting with .", "flag" => "-a", "long" => "--all", "arg" => null],
        ]],
    ],
    "generated" => "2026-05-29T00:00:00Z",
    "url" => "/phpMan.php/man/ls/1/json",
];

$jsonStr = json_encode($sampleData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Test MCP output via formatForOutput
$mcpOutput = formatForOutput($jsonStr, "mcp");
$mcpData = json_decode($mcpOutput, true);

// MCP envelope structure
assert_equals(true, isset($mcpData["content"]), "has content field");
assert_equals("text", $mcpData["content"][0]["type"], "content type = text");
assert_equals(true, isset($mcpData["structuredContent"]), "has structuredContent");

// Markdown content checks (#88: simplified to NAME + SYNOPSIS + DESCRIPTION + TLDR + Sections)
$markdown = $mcpData["content"][0]["text"];
assert_contains("# ls", $markdown, "markdown has title");
assert_contains("NAME", $markdown, "markdown has NAME section");
assert_contains("SYNOPSIS", $markdown, "markdown has SYNOPSIS section");
assert_contains("DESCRIPTION", $markdown, "markdown has DESCRIPTION section");
assert_contains("Sections", $markdown, "markdown has sections outline");
assert_contains("structuredContent.sections", $markdown, "markdown references structuredContent");

// StructuredContent checks
$sc = $mcpData["structuredContent"];
assert_equals("ls", $sc["command"], "structuredContent.command = ls");
assert_equals(true, count($sc["flags"]) >= 2, "structuredContent has flags");
assert_equals(true, isset($sc["section_outline"]), "structuredContent has section_outline");

// JSON pass-through mode
$jsonOutput = formatForOutput($jsonStr, "json");
assert_equals($jsonStr, $jsonOutput, "json format = pass-through");

// Array-based envelope (the path formatPageOutput() uses for huge pages, so the
// IR string never has to be allocated) must match the string path byte for byte
$fromArray = formatMcpEnvelope(json_decode($jsonStr, true));
assert_equals(md5(formatForOutput($jsonStr, "mcp")), md5($fromArray),
    "formatMcpEnvelope(array) == formatForOutput(string)");

// _profiling is injected into the serialised body — must stay valid JSON
$withProfiling = appendProfilingJson($fromArray, ["_total_ms" => 1.0, "_version" => "1"]);
$profilingData = json_decode($withProfiling, true);
assert_equals(true, is_array($profilingData), "profiling-injected body is valid JSON");
assert_equals("_profiling", array_key_last($profilingData), "_profiling is the last key");
assert_equals($mcpData["structuredContent"]["command"], $profilingData["structuredContent"]["command"],
    "envelope body unchanged apart from _profiling");
assert_equals(true, str_starts_with($fromArray, rtrim(substr($withProfiling, 0, strpos($withProfiling, ",\n    \"_profiling\"")))),
    "injection appends to the existing body");

exit(test_summary());
