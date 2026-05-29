<?php
/**
 * Unit tests: normalizeMode(), normalizeSection(), normalizeParameter()
 * Requirement: SKILL.md §URL Routing
 *
 * Note: normalizeParameter strips path traversal and control chars.
 * Shell injection is prevented by escapeshellarg() at exec time,
 * NOT by normalizeParameter (defense in depth).
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: Input Normalization ===\n\n";

echo "--- normalizeMode() ---\n";
assert_equals("man", normalizeMode("man"), "man → man");
assert_equals("man", normalizeMode("MAN"), "MAN → man");
assert_equals("perldoc", normalizeMode("perldoc"), "perldoc → perldoc");
assert_equals("info", normalizeMode("info"), "info → info");
assert_equals("search", normalizeMode("search"), "search → search");
assert_equals("mcp", normalizeMode("mcp"), "mcp → mcp");
assert_equals("tldr", normalizeMode("tldr"), "tldr → tldr");
assert_equals("man", normalizeMode(""), "empty → man (default)");
assert_equals("man", normalizeMode("invalid"), "invalid → man (default)");
assert_equals("man", normalizeMode("../../etc"), "path traversal → man");

echo "\n--- normalizeSection() ---\n";
assert_equals("1", normalizeSection("1"), "1 → 1");
assert_equals("3", normalizeSection("3"), "3 → 3");
assert_equals("3pm", normalizeSection("3pm"), "3pm → 3pm");
assert_equals("n", normalizeSection("n"), "letter section n");
assert_equals("", normalizeSection(""), "empty → empty");
assert_equals("", normalizeSection(".."), "path traversal rejected");
assert_equals("", normalizeSection("../"), "path traversal with slash rejected");
assert_equals("", normalizeSection("1;rm"), "shell injection rejected");
assert_equals("", normalizeSection("1 space"), "space rejected");
assert_equals("1a", normalizeSection("1a"), "alphanumeric section 1a");

echo "\n--- normalizeParameter() ---\n";
assert_equals("ls", normalizeParameter("ls"), "simple command");
assert_equals("git", normalizeParameter("git"), "simple command git");
assert_equals("File::Path", normalizeParameter("File::Path"), "perl module with ::");
assert_equals("std::string", normalizeParameter("std::string"), "C++ namespace");
assert_equals("", normalizeParameter(""), "empty parameter");

// Path traversal: / replaced with space
$result = normalizeParameter("../../etc/passwd");
assert_not_contains("/", $result, "slashes replaced with space");
// Note: ".." remains but / is removed, preventing actual path traversal

// Null byte stripped
$result = normalizeParameter("ls\x00extra");
assert_not_contains("\x00", $result, "null byte stripped");

// Control chars stripped
$result = normalizeParameter("ls\x01\x02test");
assert_not_contains("\x01", $result, "control char 0x01 stripped");

// Whitespace trimming
assert_equals("ls", normalizeParameter("  ls  "), "whitespace trimmed");

exit(test_summary());
