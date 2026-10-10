<?php
/**
 * Unit tests: malformedSegmentRedirect()
 * Requirement: #45 — a segment in a format-eligible position that is neither a
 * known output format nor a valid section name must converge on the canonical
 * URL, instead of being erased by normalizeSection() and silently served as the
 * default section in HTML (200).
 *
 * Design notes:
 * - Trigger in the wild: phpMan's own markdown output links are
 *   "[ls](https://.../man/ls/1/markdown)"; clients extracting URLs with a naive
 *   /https?:\S+/ regex keep the closing ")", producing /man/ls/1/markdown).
 * - The canonical path drops every malformed segment. When the LAST segment was
 *   malformed and begins with a currently-valid format token, the token is kept.
 * - Retired format tokens (e.g. "mcp") are NOT recovered — their canonical URL
 *   no longer resolves, so recovering one would only add a redirect hop.
 * - A segment that IS a valid section name is never touched, even when it starts
 *   with a format token ("markdowns", "html5"). That is what keeps the
 *   prefix match from eating real sections.
 * - #241: on modes where a section does not select content (perldoc/info/pydoc/
 *   ri) a section-position segment is treated as malformed too, so those URLs
 *   converge instead of answering 200 with "perldoc(notaformat)" in the title.
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: malformedSegmentRedirect() — #45 ===\n\n";

// $start is 2 when segments[0] is a mode, 1 when the mode was omitted.
// Helper keeps the tables below readable.
function redirect_for(array $segments): ?string {
    $modes = ['man', 'perldoc', 'info', 'search', 'copyright', 'mcp', '.well-known', 'pydoc', 'ri'];
    $mode = in_array(strtolower($segments[0]), $modes) ? strtolower($segments[0]) : "";
    return malformedSegmentRedirect($segments, $mode !== "" ? 2 : 1, $mode);
}

echo "--- section on a mode that ignores one → converge it away (#241) ---\n";
assert_equals("/perldoc/File::Find", redirect_for(["perldoc", "File::Find", "notaformat"]),
    "perldoc: bogus section dropped");
assert_equals("/perldoc/File::Find", redirect_for(["perldoc", "File::Find", "mcp"]),
    "perldoc: retired token in the section position is not recovered either");
assert_equals("/perldoc/File::Find/json", redirect_for(["perldoc", "File::Find", "notaformat", "json"]),
    "perldoc: a real format after the bogus section survives");
assert_equals("/perldoc/File::Find/json", redirect_for(["perldoc", "File::Find", "3pm", "json"]),
    "perldoc: 3pm is display-only there (getPerldocPage ignores the section)");
assert_equals("/info/coreutils", redirect_for(["info", "coreutils", "notaformat"]),
    "info: bogus section dropped");
assert_equals("/pydoc/os", redirect_for(["pydoc", "os", "notaformat"]), "pydoc: bogus section dropped");
assert_equals("/ri/Array", redirect_for(["ri", "Array", "notaformat"]), "ri: bogus section dropped");

echo "\n--- modes where a section IS content selection stay untouched ---\n";
assert_equals(null, redirect_for(["man", "ls", "1"]), "man keeps its section");
assert_equals(null, redirect_for(["man", "ls", "3p"]), "man keeps an unusual but valid section");
assert_equals(null, redirect_for(["info", "emacs"]), "no section given → nothing to converge");
assert_equals(null, redirect_for(["pydoc", "os"]), "no section given → nothing to converge");
assert_equals(null, redirect_for(["ls", "1", "notaformat"]),
    "mode omitted: no section semantics assumed, so no redirect");

echo "--- junk tail from phpMan's own markdown links (the reported case) ---\n";
assert_equals("/man/ls/1/markdown", redirect_for(["man", "ls", "1", "markdown)"]),
    "trailing ')' → format recovered");
assert_equals("/man/ls/1/markdown", redirect_for(["man", "ls", "1", "markdown)."]),
    "trailing ').' (sentence end) → format recovered");
assert_equals("/man/ls/1/markdown", redirect_for(["man", "ls", "1", 'markdown)($ssl']),
    "trailing code fragment → format recovered");
assert_equals("/man/ls/markdown", redirect_for(["man", "ls", "markdown)"]),
    "3-segment form → format recovered");
assert_equals("/foo/markdown", redirect_for(["foo", "markdown)"]),
    "mode omitted: format position is segment 1");

echo "\n--- percent-encoded form (phpMan may see either, depending on SAPI) ---\n";
assert_equals("/man/ls/1/markdown", redirect_for(["man", "ls", "1", "markdown%29"]),
    "%29 (encoded ')') → format recovered");

echo "\n--- junk with no recoverable format: drop the segment ---\n";
assert_equals("/man/ls/1", redirect_for(["man", "ls", "1", "!!"]),
    "no format prefix → segment dropped");
assert_equals("/man/ls/1", redirect_for(["man", "ls", "1", "mcp)"]),
    "RETIRED format token 'mcp' must NOT be recovered");
assert_equals("/man/ls", redirect_for(["man", "ls", "!!", "??"]),
    "all malformed segments dropped → single hop, no chain");
assert_equals("/man/ls/json", redirect_for(["man", "ls", "!!", "json"]),
    "malformed segment not last: later format survives, nothing appended");

echo "\n--- must NOT redirect (no false positives) ---\n";
assert_equals(null, redirect_for(["man", "ls", "1"]), "plain page");
assert_equals(null, redirect_for(["man", "ls"]), "no section");
assert_equals(null, redirect_for(["man", "ls", "1", "markdown"]), "valid section+format");
assert_equals(null, redirect_for(["man", "ls", "1", "json"]), "json format");
assert_equals(null, redirect_for(["man", "markdown"]), "man page named 'markdown'");
assert_equals(null, redirect_for(["man", "markdown", "markdown"]), "man page 'markdown', markdown format");
assert_equals(null, redirect_for(["man", "html2text", "1"]), "REGRESSION: html2text is a real page");
assert_equals(null, redirect_for(["man", "json_pp", "1"]), "REGRESSION: json_pp is a real page");
assert_equals(null, redirect_for(["man", "htmlclean", "1p"]), "REGRESSION: htmlclean/1p is a real page");
assert_equals(null, redirect_for(["man", "ls", "1", "markdowns"]),
    "REGRESSION: 'markdowns' is a valid section name — prefix match must not fire");
assert_equals(null, redirect_for(["man", "ls", "1", "html5"]),
    "REGRESSION: 'html5' is a valid section name — prefix match must not fire");
assert_equals(null, redirect_for(["man", "ls", "1", "zzz"]),
    "alphanumeric wrong section → existing 301 path, not this one");
assert_equals(null, redirect_for(["man", "Dpkg::Control::HashCore", "3pm"]),
    "Perl module with :: in the parameter position");
assert_equals(null, redirect_for(["Dpkg::Control::HashCore", "3pm"]),
    "Perl module with :: and no mode");
assert_equals(null, redirect_for([".well-known", "mcp.json"]),
    "REGRESSION: .well-known discovery endpoint");
assert_equals(null, redirect_for(["man"]), "single segment");
assert_equals(null, redirect_for(["ls"]), "single segment, no mode");
assert_equals(null, redirect_for(["search", "keyword", "1"]), "search mode");
assert_equals(null, redirect_for(["info", "emacs"]), "info mode, no section");

echo "\n--- never returns an empty path ---\n";
// The malformed index is always >= $start >= 1, so at least one segment survives.
// (["man","!!"] is deliberately absent: with only 2 segments the "!!" sits in the
// parameter position, which is not format-eligible, so it correctly returns null.)
foreach ([["ls", "!!"], ["man", "ls", "!!"], ["man", "ls", "1", "!!"], ["!!", "x)"]] as $segs) {
    $out = redirect_for($segs);
    assert_not_equals("/", $out, "non-empty path for [" . implode(",", $segs) . "]");
    assert_not_equals(null, $out, "redirects for [" . implode(",", $segs) . "]");
}

assert_equals(null, redirect_for(["man", "!!"]),
    "2 segments: '!!' is the parameter, not format-eligible");

exit(test_summary());
