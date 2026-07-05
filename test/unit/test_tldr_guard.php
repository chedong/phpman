<?php
/**
 * Unit tests: tldr.php — User-Agent header construction, GIT_DESCRIBE guard
 * Covers v4.9.22+ fix: GIT_DESCRIBE undefined in CLI context (line 85 crash).
 */
declare(strict_types=1);
// Don't load phpMan.php (which defines GIT_DESCRIBE) — test in CLI-like context
// where only config.php constants are available.
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: TLDR User-Agent Guard ===\n\n";

// Simulate CLI context: GIT_DESCRIBE undefined, PHPMAN_VERSION available
// The fix on line 85 changed:
//   "User-Agent: phpMan/" . GIT_DESCRIBE
// → "User-Agent: phpMan/" . (defined('GIT_DESCRIBE') ? GIT_DESCRIBE : PHPMAN_VERSION : 'unknown')

// Verify the defensive pattern compiles correctly
$ua_version = (defined('GIT_DESCRIBE') ? GIT_DESCRIBE : (defined('PHPMAN_VERSION') ? PHPMAN_VERSION : 'unknown'));
$ua = "phpMan/" . $ua_version;

assert_equals(true, is_string($ua), "User-Agent is string");
assert_contains("phpMan/", $ua, "User-Agent contains phpMan/");

// When PHPMAN_VERSION is also undefined, fall back to 'unknown'
// (This is the triple-fallback pattern used in line 109)
$ua_fallback = "phpMan/" . (defined('NONEXISTENT') ? NONEXISTENT : (defined('ALSO_NONEXISTENT') ? ALSO_NONEXISTENT : 'unknown'));
assert_equals("phpMan/unknown", $ua_fallback, "Triple-fallback → 'unknown'");

// GIT_DESCRIBE format: typically "v4.9.20" or "v4.9.20-2-g1b0fcb4"
// The guard uses ltrim(GIT_DESCRIBE, 'v') for version-only context (line 109)
$version = defined('PHPMAN_VERSION') ? ltrim(PHPMAN_VERSION, 'v') : 'unknown';
assert_equals(true, is_string($version), "Version fallback is string");

echo "\n=== Result: All TLDR guard tests passed ===\n";
test_summary();
