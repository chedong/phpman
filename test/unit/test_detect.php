<?php
/**
 * Unit tests: detectHeadingType()
 * Requirement: SKILL.md §detectHeadingType
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: detectHeadingType() ===\n\n";

$tests = [
    // Level 1: ALL CAPS sections
    ["NAME",                         1, "NAME"],
    ["DESCRIPTION",                  1, "DESCRIPTION"],
    ["SEE ALSO",                     1, "SEE ALSO"],
    ["**NAME**",                     1, "NAME"],
    ["**SYNOPSIS**",                 1, "SYNOPSIS"],
    ["**OPTIONS**",                  1, "OPTIONS"],
    ["**EXAMPLES**",                 1, "EXAMPLES"],
    ["<b>DESCRIPTION</b>",           1, "DESCRIPTION"],
    ["COPYRIGHT NOTICE",             1, "COPYRIGHT NOTICE"],

    // Level 2: bold/flag subsections (indented)
    ["   **Packages**",              2, "Packages"],
    ["    -d, --directory",          2, "-d, --directory"],
    ["  <b>Options</b>",             2, "Options"],
    ["     -n",                      2, "-n"],
    ["     --archive",               2, "--archive"],

    // NOT headings (false positive rejection)
    ["This is regular body text.",   null, ""],
    ["       -a flag description",    null, ""],
    ["## markdown heading",          null, ""],
    ["",                             null, ""],
    ["   ",                          null, ""],
    ["   • bullet item",             null, ""],
    ["   Description:",              null, ""],
];

$pass = 0;
$fail = 0;
foreach ($tests as $test) {
    [$input, $expectedLevel, $expectedText] = $test;
    $result = detectHeadingType($input);

    if ($expectedLevel === null) {
        if ($result === null) {
            $pass++;
            echo "  ✅ reject: " . substr($input, 0, 40) . "\n";
        } else {
            $fail++;
            echo "  ❌ false positive: " . substr($input, 0, 40) . " → L{$result['level']}\n";
        }
    } else {
        if ($result === null) {
            $fail++;
            echo "  ❌ missed: " . substr($input, 0, 40) . "\n";
        } elseif ($result['level'] !== $expectedLevel) {
            $fail++;
            echo "  ❌ wrong level: " . substr($input, 0, 40) . " expected L{$expectedLevel} got L{$result['level']}\n";
        } elseif (trim($result['text']) !== trim($expectedText)) {
            $fail++;
            echo "  ❌ wrong text: " . substr($input, 0, 40) . " expected '{$expectedText}' got '{$result['text']}'\n";
        } else {
            $pass++;
            echo "  ✅ L{$expectedLevel}: " . substr($input, 0, 40) . "\n";
        }
    }
}

// ──────────────────────────────────────────────
// Mode gating (#281): the pydoc class/function heuristics must not fire in
// man mode. A man page line indented by exactly four spaces and starting with
// `name(` is body text, not an L2 section — treating it as one drops the
// cross-reference link and invents a TOC entry.
// ──────────────────────────────────────────────
echo "\n--- mode gating: pydoc patterns vs body text (#281) ---\n";
$modeTests = [
    // [input, mode, expectedLevel, expectedText]
    ["    other(1)",        "man",     null, ""],   // body text: must NOT become L2
    ["    ls(1)",           "man",     null, ""],
    ["    class Foo(Bar)",  "pydoc",   2,    "class Foo"],
    ["    func(args)",      "pydoc",   2,    "func"],
    ["    other(1)",        "perldoc", null, ""],
];
foreach ($modeTests as [$input, $mode, $expectedLevel, $expectedText]) {
    $result = detectHeadingType($input, $mode);
    $label = "[{$mode}] " . substr($input, 0, 30);
    if ($expectedLevel === null) {
        if ($result === null) {
            $pass++;
            echo "  ✅ reject: {$label}\n";
        } else {
            $fail++;
            echo "  ❌ false positive: {$label} → L{$result['level']}\n";
        }
    } elseif ($result === null) {
        $fail++;
        echo "  ❌ missed: {$label}\n";
    } elseif ($result['level'] !== $expectedLevel || trim($result['text']) !== $expectedText) {
        $fail++;
        echo "  ❌ wrong: {$label} expected L{$expectedLevel} '{$expectedText}' got L{$result['level']} '{$result['text']}'\n";
    } else {
        $pass++;
        echo "  ✅ L{$expectedLevel}: {$label}\n";
    }
}

echo "\n═══════════════════════════════════════\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "═══════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
