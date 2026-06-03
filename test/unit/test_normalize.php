<?php
/**
 * Unit tests: normalizeMode(), normalizeSection(), normalizeParameter()
 * Requirement: SKILL.md §URL Routing, phpMan.php lines 301-331
 *
 * Design notes:
 * - normalizeParameter strips path traversal (/) and control chars only.
 *   Shell injection is prevented by escapeshellarg() at exec time (defense in depth).
 * - normalizeSection uses strict alphanumeric regex: /^[A-Za-z0-9_]+$/
 * - normalizeMode has 8 allowed modes: man, perldoc, info, search, copyright, mcp, pydoc, ri
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: Input Normalization ===\n\n";

echo "--- normalizeMode() [phpMan.php:301-314] ---\n";
// 7 allowed modes
assert_equals("man", normalizeMode("man"), "man → man");
assert_equals("man", normalizeMode("MAN"), "MAN → man (case insensitive)");
assert_equals("man", normalizeMode("Man"), "Man → man (case insensitive)");
assert_equals("perldoc", normalizeMode("perldoc"), "perldoc → perldoc");
assert_equals("perldoc", normalizeMode("PERLDOC"), "PERLDOC → perldoc");
assert_equals("info", normalizeMode("info"), "info → info");
assert_equals("search", normalizeMode("search"), "search → search");
assert_equals("copyright", normalizeMode("copyright"), "copyright → copyright");
assert_equals("mcp", normalizeMode("mcp"), "mcp → mcp");
assert_equals("pydoc", normalizeMode("pydoc"), "pydoc → pydoc");
assert_equals("ri", normalizeMode("ri"), "ri → ri");
// Default fallback
assert_equals("man", normalizeMode(""), "empty → man (default)");
assert_equals("man", normalizeMode("invalid"), "invalid → man (default)");
assert_equals("man", normalizeMode("../../etc"), "path traversal → man");
assert_equals("man", normalizeMode("MAN_PAGE"), "MAN_PAGE → man (underscore)");
assert_equals("man", normalizeMode("  man  "), "whitespace trimmed → man");

echo "\n--- normalizeSection() [phpMan.php:324-331] ---\n";
// Valid sections
assert_equals("1", normalizeSection("1"), "1 → 1");
assert_equals("3", normalizeSection("3"), "3 → 3");
assert_equals("3pm", normalizeSection("3pm"), "3pm → 3pm");
assert_equals("n", normalizeSection("n"), "letter section n");
assert_equals("1a", normalizeSection("1a"), "alphanumeric section 1a");
assert_equals("8", normalizeSection("8"), "8 → 8");
// Invalid sections (rejected by /^[A-Za-z0-9_]+$/)
assert_equals("", normalizeSection(""), "empty → empty");
assert_equals("", normalizeSection(".."), "path traversal rejected");
assert_equals("", normalizeSection("../"), "slash rejected");
assert_equals("", normalizeSection("1;rm"), "semicolon rejected");
assert_equals("", normalizeSection("1 space"), "space rejected");
assert_equals("", normalizeSection("1.5"), "dot rejected");
assert_equals("", normalizeSection("<script>"), "HTML tag rejected");
assert_equals("", normalizeSection("-1"), "hyphen rejected");

echo "\n--- normalizeParameter() [phpMan.php:316-322] ---\n";
// Valid parameters
assert_equals("ls", normalizeParameter("ls"), "simple command");
assert_equals("git", normalizeParameter("git"), "simple command git");
assert_equals("File::Path", normalizeParameter("File::Path"), "perl module with ::");
assert_equals("std::string", normalizeParameter("std::string"), "C++ namespace");
assert_equals("", normalizeParameter(""), "empty parameter");
assert_equals("ls", normalizeParameter("  ls  "), "whitespace trimmed");

// Path traversal: / replaced with space
$result = normalizeParameter("../../etc/passwd");
assert_not_contains("/", $result, "slashes replaced with space");
// Note: ".." remains but / is removed, preventing actual path traversal

// Null byte stripped
$result = normalizeParameter("ls\x00extra");
assert_not_contains("\x00", $result, "null byte stripped");

// Control chars stripped (\x00-\x1F, \x7F)
$result = normalizeParameter("ls\x01\x02test");
assert_not_contains("\x01", $result, "control char 0x01 stripped");
$result = normalizeParameter("ls\x7Ftest");
assert_not_contains("\x7F", $result, "DEL char 0x7F stripped");

exit(test_summary());
