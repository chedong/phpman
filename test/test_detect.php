<?php
declare(strict_types=1);

// Define test mode to bypass main execution in phpMan.php
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../phpMan.php';

$tests = [
    // Level 1
    ["NAME",                         1, "NAME"],
    ["DESCRIPTION",                  1, "DESCRIPTION"],
    ["SEE ALSO",                     1, "SEE ALSO"],
    ["**NAME**",                     1, "NAME"],
    ["**DESCRIPTION**",              1, "DESCRIPTION"],
    ["**SEE** **ALSO**",             1, "SEE ALSO"],
    ["**EXAMPLE** **CRON** **FILE**",1, "EXAMPLE CRON FILE"],
    ["<b>NAME</b>",                  1, "NAME"],
    ["<b>SEE</b> <b>ALSO</b>",      1, "SEE ALSO"],
    // Level 2 perldoc
    ["  Methods you should implement",   2, "Methods you should implement"],
    ["  Other methods in Encode",        2, "Other methods in Encode"],
    ["  <u>Methods you should implement</u>", 2, "Methods you should implement"],
    ["  <u>Other methods in Encode</u>",      2, "Other methods in Encode"],
    // Level 2 italic
    ["_Filename_",                   2, "Filename"],
    ["_Methods_",                    2, "Methods"],
    ["<u>Filename</u>",             2, "Filename"],
    ["<u>Methods</u>",              2, "Methods"],
    // Level 2 bold indent
    ["   **Packages**",              2, "Packages"],
    ["   **Symbol** **Tables**",     2, "Symbol Tables"],
    ["   **Is** **this** **the** **document**", 2, "Is this the document"],
    ["   **BEGIN,** **UNITCHECK**",  2, "BEGIN, UNITCHECK"],
    ["   **Making** **your** **module**", 2, "Making your module"],
    ["   <b>Packages</b>",           2, "Packages"],
    ["   <b>Symbol</b> <b>Tables</b>", 2, "Symbol Tables"],
    // False positives
    ["  This is body text",          null, null],
    ["  **-a**, **--all**   Show all", null, null],
    ["Some body text",               null, null],
    ["## NAME",                      null, null],
    ["  <b>-a</b>, <b>--all</b> Show all", null, null],
];

$pass = 0; $fail = 0;
foreach ($tests as $t) {
    $result = detectHeadingType($t[0]);
    $expLvl = $t[1];
    $expText = $t[2];
    if ($expLvl === null) {
        if ($result === null) {
            echo "PASS: null <- " . substr($t[0], 0, 50) . "\n";
            $pass++;
        } else {
            echo "FAIL: got L{$result["level"]} \"{$result["text"]}\", expected null\n";
            echo "      input: " . substr($t[0], 0, 50) . "\n";
            $fail++;
        }
    } else {
        if ($result === null) {
            echo "FAIL: got null, expected L$expLvl \"$expText\"\n";
            echo "      input: " . substr($t[0], 0, 50) . "\n";
            $fail++;
        } elseif ($result["level"] !== $expLvl || $result["text"] !== $expText) {
            echo "FAIL: got L{$result["level"]} \"{$result["text"]}\", expected L$expLvl \"$expText\"\n";
            echo "      input: " . substr($t[0], 0, 50) . "\n";
            $fail++;
        } else {
            echo "PASS: L$expLvl \"$expText\"\n";
            $pass++;
        }
    }
}
echo "\n$pass passed, $fail failed\n";
if ($fail > 0) {
    exit(1);
}
