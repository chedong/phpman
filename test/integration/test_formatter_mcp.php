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

// Markdown content checks
$markdown = $mcpData["content"][0]["text"];
assert_contains("# ls", $markdown, "markdown has title");
assert_contains("Summary", $markdown, "markdown has summary label");
assert_contains("Flags", $markdown, "markdown has flags section");
assert_contains("--all", $markdown, "markdown includes --all flag");
assert_contains("Section Outline", $markdown, "markdown has section outline");

// StructuredContent checks
$sc = $mcpData["structuredContent"];
assert_equals("ls", $sc["command"], "structuredContent.command = ls");
assert_equals(true, count($sc["flags"]) >= 2, "structuredContent has flags");
assert_equals(true, isset($sc["section_outline"]), "structuredContent has section_outline");

// JSON pass-through mode
$jsonOutput = formatForOutput($jsonStr, "json");
assert_equals($jsonStr, $jsonOutput, "json format = pass-through");

exit(test_summary());
