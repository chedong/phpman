<?php
/**
 * Unit tests: normalizeMcpArg() — MCP tool argument validation.
 *
 * Background: the web route normalizes command/section before dispatch
 * (phpMan.php normalizeParameter/normalizeSection), but the MCP path used to
 * pass arguments straight through (security review 2026-10-31). escapeshellarg()
 * prevents shell metacharacter injection but NOT option injection: a quoted
 * argv element still begins with "-", and getManPage() places the section
 * argument BEFORE the page operand, so man-db parses it as an option.
 *   section "-Hsh -c '…'"  →  man's -H (html pager) → /bin/sh -c execution
 *   section "-l"           →  man -l <command>       → arbitrary local file read
 *
 * normalizeMcpArg() applies the same normalizers the web route uses, plus an
 * explicit leading-dash rejection for command/query (normalizeSection already
 * reduces any option-shaped section to "").
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: MCP Argument Validation ===\n\n";

echo "--- normalizeMcpArg(): section (normalizeSection path) ---\n";
assert_equals("1", normalizeMcpArg("1", "section", true), "numeric section passes");
assert_equals("3pm", normalizeMcpArg("3pm", "section", true), "3pm section passes");
assert_equals("3perl", normalizeMcpArg("3perl", "section", true), "3perl section passes");
assert_equals("", normalizeMcpArg("-l", "section", true), "option-shaped section '-l' reduced to '' (man -l vector)");
assert_equals("", normalizeMcpArg("-Hsh -c 'echo PWNED'", "section", true), "option-shaped section '-H…' reduced to '' (man -H vector)");
assert_equals("", normalizeMcpArg("", "section", true), "empty section stays empty (omitted section is normal)");
assert_equals("", normalizeMcpArg("3 pm", "section", true), "section with space rejected (not alnum)");

echo "\n--- normalizeMcpArg(): command (normalizeParameter path + dash guard) ---\n";
assert_equals("ls", normalizeMcpArg("ls", "command"), "plain command passes");
assert_equals("File::Basename", normalizeMcpArg("File::Basename", "command"), "perl module name passes");
assert_equals("Array#map", normalizeMcpArg("Array#map", "command"), "ruby method name passes");
assert_equals("os.path", normalizeMcpArg("os.path", "command"), "python dotted name passes");
assert_equals("ls(1)", normalizeMcpArg("ls(1)", "command"), "man-reference form passes");
assert_equals("etc passwd", normalizeMcpArg("/etc/passwd", "command"), "path traversal neutralized ('/' → space, matches web route)");
assert_throws(
    fn(): string => normalizeMcpArg("-l", "command"),
    McpInvalidParams::class,
    "command '-l' rejected (option injection)"
);
assert_throws(
    fn(): string => normalizeMcpArg("-Hsh -c 'echo PWNED'", "command"),
    McpInvalidParams::class,
    "command '-H…' rejected (man -H browser gadget)"
);
assert_throws(
    fn(): string => normalizeMcpArg("--help", "command"),
    McpInvalidParams::class,
    "command '--help' rejected (double-dash option)"
);
assert_equals("", normalizeMcpArg("ls; id", "command"), "command with shell metachar neutralized to '' (normalizeParameter guard)");

echo "\n--- normalizeMcpArg(): query (executeCliSearch path) ---\n";
assert_equals("recursive delete", normalizeMcpArg("recursive delete", "query"), "plain query passes");
assert_throws(
    fn(): string => normalizeMcpArg("-k id", "query"),
    McpInvalidParams::class,
    "query '-k …' rejected (apropos option injection)"
);

echo "\n--- regression: MCP entry points reject hostile args (no exec path reached) ---\n";
// executeCliHelp with an option-shaped command must throw before any binary runs.
assert_throws(
    fn(): string => executeCliHelp(["command" => "-Hsh -c 'echo PWNED'"]),
    McpInvalidParams::class,
    "executeCliHelp rejects option-shaped command"
);
// Option-shaped section is neutralized, not executed: cli_help with command
// "zzz-nopage-xyz" and section "-Hid" must return an empty envelope (no man
// -H execution), NOT a page containing 'id' output.
$mcp_out = executeCliHelp(["command" => "zzz-nopage-xyz", "section" => "-Hid"]);
assert_true(
    strpos($mcp_out, "structuredContent") !== false || $mcp_out === "" || json_decode($mcp_out) !== null,
    "cli_help with option-shaped section degrades to empty envelope (no -H execution)"
);

echo "\n";

exit(test_summary());
