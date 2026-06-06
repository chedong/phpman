<?php
/**
 * Minimal test framework — no PHPUnit dependency required.
 * Include at the top of every test file.
 */

$_test_pass = 0;
$_test_fail = 0;
$_test_errors = [];

function assert_equals($expected, $actual, string $msg = ""): void {
    global $_test_pass, $_test_fail, $_test_errors;
    if ($expected === $actual) {
        $_test_pass++;
        echo "  ✅ {$msg}\n";
    } else {
        $_test_fail++;
        $detail = "  ❌ {$msg}\n     expected: " . var_export($expected, true) . "\n     actual:   " . var_export($actual, true);
        echo $detail . "\n";
        $_test_errors[] = $msg;
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ""): void {
    global $_test_pass, $_test_fail, $_test_errors;
    if (strpos($haystack, $needle) !== false) {
        $_test_pass++;
        echo "  ✅ {$msg}\n";
    } else {
        $_test_fail++;
        $detail = "  ❌ {$msg}\n     expected to find: " . substr($needle, 0, 80);
        echo $detail . "\n";
        $_test_errors[] = $msg;
    }
}

function assert_not_contains(string $needle, string $haystack, string $msg = ""): void {
    global $_test_pass, $_test_fail, $_test_errors;
    if (strpos($haystack, $needle) === false) {
        $_test_pass++;
        echo "  ✅ {$msg}\n";
    } else {
        $_test_fail++;
        echo "  ❌ {$msg}\n     should NOT contain: " . substr($needle, 0, 80) . "\n";
        $_test_errors[] = $msg;
    }
}

function assert_not_equals($notExpected, $actual, string $msg = ""): void {
    global $_test_pass, $_test_fail, $_test_errors;
    if ($notExpected !== $actual) {
        $_test_pass++;
        echo "  ✅ {$msg}\n";
    } else {
        $_test_fail++;
        $detail = "  ❌ {$msg}\n     expected NOT: " . var_export($notExpected, true) . "\n     actual:       " . var_export($actual, true);
        echo $detail . "\n";
        $_test_errors[] = $msg;
    }
}

function assert_match(string $pattern, string $string, string $msg = ""): void {
    global $_test_pass, $_test_fail, $_test_errors;
    if (preg_match($pattern, $string)) {
        $_test_pass++;
        echo "  ✅ {$msg}\n";
    } else {
        $_test_fail++;
        echo "  ❌ {$msg}\n     pattern: {$pattern}\n     string:  " . substr($string, 0, 80) . "\n";
        $_test_errors[] = $msg;
    }
}

function assert_empty(string $value, string $msg = ""): void {
    assert_equals("", $value, $msg);
}

function test_summary(): int {
    global $_test_pass, $_test_fail, $_test_errors;
    echo "\n═══════════════════════════════════════\n";
    echo "  {$_test_pass} passed, {$_test_fail} failed\n";
    echo "═══════════════════════════════════════\n";
    if ($_test_fail > 0) {
        echo "  Failed tests:\n";
        foreach ($_test_errors as $e) echo "    - {$e}\n";
    }
    return $_test_fail > 0 ? 1 : 0;
}
