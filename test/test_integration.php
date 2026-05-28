<?php
declare(strict_types=1);

// Define test mode to bypass main execution in phpMan.php
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../phpMan.php';

echo "=== Running phpMan General Integration Tests ===\n";

// Helper assertions
function assert_equals($actual, $expected, string $message): void {
    if ($actual !== $expected) {
        echo "FAIL: $message (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")\n";
        exit(1);
    }
    echo "PASS: $message\n";
}

// 1. Test Input Normalization
assert_equals(normalizeMode("man"), "man", "Mode 'man' normalizes correctly");
assert_equals(normalizeMode("invalid"), "man", "Invalid mode falls back to 'man'");
assert_equals(normalizeMode("perldoc"), "perldoc", "Mode 'perldoc' normalizes correctly");

assert_equals(normalizeSection("1"), "1", "Section '1' normalizes correctly");
assert_equals(normalizeSection("3pm"), "3pm", "Section '3pm' normalizes correctly");
assert_equals(normalizeSection("invalid section;"), "", "Invalid section characters are stripped");

assert_equals(normalizeParameter("gcc"), "gcc", "Parameter 'gcc' normalizes correctly");
assert_equals(normalizeParameter("File::Path"), "File::Path", "Parameter with double colon normalizes correctly");
assert_equals(normalizeParameter("tar\0bad"), "tarbad", "Null bytes in parameter are stripped");

// 2. Test Safe Host Header Validation
$_SERVER['HTTP_HOST'] = "www.chedong.com";
assert_equals(getSafeHost(), "www.chedong.com", "Safe host matches valid HTTP_HOST");

$_SERVER['HTTP_HOST'] = "malicious.domain.com;inject";
$_SERVER['SERVER_NAME'] = "localhost";
assert_equals(getSafeHost(), "localhost", "Host header injection falls back to SERVER_NAME");

echo "\nAll phpMan General Integration Tests PASSED!\n";
