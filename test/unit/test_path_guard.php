<?php
/**
 * Unit tests: validatePathInfo()
 * Requirement: phpMan.php 403 guard (rejects URL attacks and scanner probes)
 *
 * Design notes:
 * - Returns the failing condition name or empty string if PATH_INFO is valid.
 * - Must distinguish Perl '::' (package separator, e.g. Dpkg::Control::HashCore)
 *   from URI scheme colons (sftp:, http:, gdocs:).
 * - Original bug: counted ALL colons, so any segment with > 2 colons was blocked.
 *   This wrongly rejected valid Perl modules like Dpkg::Control::HashCore (4 colons).
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: validatePathInfo() 403 guard ===\n\n";

// Empty PATH_INFO: not validated (no 403 even though empty is allowed)
assert_equals("", validatePathInfo(""), "empty PATH_INFO is allowed");

echo "--- valid PATH_INFO (no error) ---\n";
assert_equals("", validatePathInfo("/man/ls"), "simple man page");
assert_equals("", validatePathInfo("/man/tar/1"), "man with section");
assert_equals("", validatePathInfo("/man/tar/1/markdown"), "man with section+format");
assert_equals("", validatePathInfo("/man/ls(1)"), "man reference with parens");
assert_equals("", validatePathInfo("/perldoc/File::Path"), "perl module File::Path");
assert_equals("", validatePathInfo("/perldoc/Dpkg::Control::HashCore"),
    "REGRESSION: Perl module with multiple :: (4 colons) must pass");
assert_equals("", validatePathInfo("/perldoc/std::string"), "C++ namespace");
assert_equals("", validatePathInfo("/perldoc/A::B::C::D::E::F"),
    "deeply nested Perl module (10 colons) must pass");
assert_equals("", validatePathInfo("/search/keyword"), "search mode");
assert_equals("", validatePathInfo("/info/emacs"), "info mode");

echo "\n--- tooLong (PATH_INFO > 100 chars) ---\n";
$long = "/man/" . str_repeat("a", 100);
assert_equals("tooLong", validatePathInfo($long), "100+ char path rejected");
assert_equals("", validatePathInfo("/man/" . str_repeat("a", 50)),
    "50-char path allowed (within 100-char limit)");

echo "\n--- tooDeep (> 5 segments) ---\n";
assert_equals("tooDeep", validatePathInfo("/a/b/c/d/e/f"), "6 segments rejected");
assert_equals("", validatePathInfo("/a/b/c/d/e"), "5 segments allowed (boundary)");

echo "\n--- hasProto (':/' pattern, URI scheme) ---\n";
assert_equals("hasProto", validatePathInfo("/man/sftp://foo"), "sftp:// rejected");
assert_equals("hasProto", validatePathInfo("/man/http://evil.com"), "http:// rejected");
// Original scanner probe has 6+ segments, so it's caught by tooDeep first.
// Either rejection is correct — the guard's job is to block these URLs.
assert_not_equals("", validatePathInfo("/man/DUPLICITY/sftp:/onedrive:/gdocs:/x"),
    "original scanner probe rejected (any error code)");

echo "\n--- hasProtocolColon (':' NOT part of '::') ---\n";
// Protocol-prefix crawlers: single colons with non-identifier content
assert_equals("hasProtocolColon", validatePathInfo("/man/sftp:onedrive:gdocs:"),
    "3 single colons rejected");
assert_equals("hasProtocolColon", validatePathInfo("/man/Foo:Bar:Baz"),
    "odd number of colons (1 single) rejected");
assert_equals("hasProtocolColon", validatePathInfo("/man/Foo::Bar:Baz"),
    "mixed :: and : (1 single) rejected");
assert_equals("hasProtocolColon", validatePathInfo("/man/One:Two"),
    "single colon rejected");

// Valid Perl modules: all colons in '::' pairs
assert_equals("", validatePathInfo("/man/Foo::Bar"), "1 :: pair allowed");
assert_equals("", validatePathInfo("/man/Foo::Bar::Baz"), "2 :: pairs allowed");
assert_equals("", validatePathInfo("/man/Dpkg::Control::HashCore"),
    "REGRESSION: 2 :: pairs in one segment (4 colons) allowed");
assert_equals("", validatePathInfo("/perldoc/A::B::C::D::E"),
    "REGRESSION: 4 :: pairs in one segment (8 colons) allowed");

exit(test_summary());
