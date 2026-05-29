<?php
/**
 * Unit tests: parseFlagJSON()
 * Requirement: SKILL.md §parseFlagJSON
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: parseFlagJSON() ===\n\n";

// Short flag only
echo "T1: Short flag -a\n";
$r = parseFlagJSON("-a");
assert_equals("-a", $r["flag"], "short flag -a");
assert_equals(null, $r["arg"], "no arg");

// Long flag only (--all → flag="" not null, long="--all")
echo "\nT2: Long flag --all\n";
$r = parseFlagJSON("--all");
assert_equals("--all", $r["long"], "long flag --all");
assert_equals(null, $r["arg"], "no arg");

// Short + long
echo "\nT3: Short + long\n";
$r = parseFlagJSON("-a, --all");
assert_equals("-a", $r["flag"], "short -a");
assert_equals("--all", $r["long"], "long --all");

// Long flag with inline arg (=VAL)
echo "\nT4: Long flag with =arg\n";
$r = parseFlagJSON("--output=FILE");
assert_equals("--output", $r["long"], "long --output");
assert_equals("FILE", $r["arg"], "inline arg FILE");

// Long flag with angle bracket arg
echo "\nT5: Long flag with <arg>\n";
$r = parseFlagJSON("--config <file>");
assert_equals("--config", $r["long"], "long --config");
assert_equals("<file>", $r["arg"], "angle bracket arg <file>");

// Combined short + long + arg
echo "\nT6: Combined -K, --config <file>\n";
$r = parseFlagJSON("-K, --config <file>");
assert_equals("-K", $r["flag"], "short -K");
assert_equals("--config", $r["long"], "long --config");
assert_equals("<file>", $r["arg"], "arg <file>");

// ALL CAPS placeholder arg
echo "\nT7: -f --file ARCHIVE\n";
$r = parseFlagJSON("-f --file ARCHIVE");
assert_equals("-f", $r["flag"], "short -f");
assert_equals("--file", $r["long"], "long --file");
assert_equals("ARCHIVE", $r["arg"], "ALL CAPS arg ARCHIVE");

// Short flag only
echo "\nT8: Short -n\n";
$r = parseFlagJSON("-n");
assert_equals("-n", $r["flag"], "short -n");

exit(test_summary());
