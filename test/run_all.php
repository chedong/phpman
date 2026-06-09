<?php
/**
 * Test runner — runs all unit + integration tests (no network required)
 * Usage: php test/run_all.php
 */

$testDir = __DIR__;
$suites = [
    "Unit" => [
        "{$testDir}/unit/test_detect.php",
        "{$testDir}/unit/test_normalize.php",
        "{$testDir}/unit/test_parseFlag.php",
        "{$testDir}/unit/test_overstrike.php",
        "{$testDir}/unit/test_search_fts.php",
        "{$testDir}/unit/test_search_fts_db.php",
        "{$testDir}/unit/test_page_cache.php",
        "{$testDir}/unit/test_cache_db.php",
    ],
    "Integration" => [
        "{$testDir}/integration/test_formatter_html.php",
        "{$testDir}/integration/test_formatter_markdown.php",
        "{$testDir}/integration/test_formatter_json.php",
        "{$testDir}/integration/test_formatter_mcp.php",
        "{$testDir}/integration/test_formatter_tldr.php",
    ],
];

$totalPass = 0;
$totalFail = 0;
$failed = [];

echo "╔══════════════════════════════════════════════╗\n";
echo "║  phpMan Test Suite                          ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";

foreach ($suites as $suite => $files) {
    echo "━━━ {$suite} ━━━\n";
    foreach ($files as $file) {
        $name = basename($file);
        if (!file_exists($file)) {
            echo "  ⏭️  {$name} (not found)\n";
            continue;
        }
        $output = [];
        $exitCode = 0;
        exec("php " . escapeshellarg($file) . " 2>&1", $output, $exitCode);
        
        // Extract pass/fail counts from any summary line in output
        $pass = 0;
        $fail = 0;
        foreach ($output as $line) {
            if (preg_match('/(\d+) passed, (\d+) failed/', $line, $m)) {
                $pass = (int)$m[1];
                $fail = (int)$m[2];
                break;
            }
        }
        
        $icon = $exitCode === 0 ? "✅" : "❌";
        echo "  {$icon} {$name}: {$pass} passed";
        if ($fail > 0) echo ", {$fail} failed";
        echo "\n";
        
        if ($exitCode !== 0) {
            $failed[] = $name;
            // Show failure details
            foreach ($output as $line) {
                if (strpos($line, "❌") !== false) {
                    echo "     {$line}\n";
                }
            }
        }
        
        $totalPass += $pass;
        $totalFail += $fail;
    }
    echo "\n";
}

echo "═══════════════════════════════════════════════\n";
echo "  Total: {$totalPass} passed, {$totalFail} failed\n";
if (count($failed) > 0) {
    echo "  Failed files: " . implode(", ", $failed) . "\n";
}
echo "═══════════════════════════════════════════════\n";

echo "\nℹ️  E2E tests require network — run separately:\n";
echo "   php test/e2e/test_user_scenarios.php\n";
echo "   php test/e2e/test_agent_scenarios.php\n";
echo "   php test/e2e/test_spider_scenarios.php\n";
echo "   php test/e2e/test_security.php\n";
echo "\nℹ️  External validation:\n";
echo "   bash phpman-regression.sh\n";

exit($totalFail > 0 ? 1 : 0);
