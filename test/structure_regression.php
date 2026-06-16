<?php
declare(strict_types=1);
/**
 * Structure regression test — validates JSON section structure fingerprints
 * for man, perldoc, and pydoc pages.
 *
 * Runs without network: uses pre-cached JSON or generates from local command output.
 */
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/test_helper.php';
require_once __DIR__ . '/../phpMan.php';

/**
 * Extract section structure fingerprint from JSON data.
 * Format: "L1_NAME(N_lines)>sub1|sub2 ; L1_NAME(N_lines)"
 */
function jsonStructureFingerprint(array $jsonData): string {
    $parts = [];
    foreach ($jsonData['sections'] ?? [] as $sec) {
        $name = $sec['name'] ?? '?';
        $nLines = is_array($sec['content'] ?? null) ? count($sec['content']) : 0;
        $fp = "{$name}({$nLines}lines)";

        // Flag count for OPTIONS section
        $nFlags = count($jsonData['flags'] ?? []);
        if ($nFlags > 0 && $name === 'OPTIONS') {
            $fp .= "[{$nFlags}flags]";
        }

        $subs = [];
        foreach ($sec['subsections'] ?? [] as $sub) {
            $subs[] = $sub['name'] ?? '?';
        }
        if ($subs) {
            $fp .= '>' . implode('|', array_slice($subs, 0, 5))
                 . (count($subs) > 5 ? '|...' : '');
        }
        $parts[] = $fp;
    }
    return implode(' ; ', $parts);
}

/**
 * Get JSON directly by running the command and calling formatToJSON.
 */
function formatToJSONFromRaw(string $mode, string $parameter, string $section = ''): string {
    $lines = [];
    switch ($mode) {
        case 'man':
            $lines = execAndGetLines($mode, $parameter, $section);
            break;
        case 'perldoc':
            $old = getenv('PERLDOC');
            putenv("PERLDOC=-MPod::Text -w80");
            $lines = execAndGetLines($mode, $parameter, '');
            putenv("PERLDOC=" . ($old ?: ''));
            break;
        case 'pydoc':
            $lines = execAndGetLines($mode, $parameter, '');
            break;
    }
    return formatToJSON($lines, $parameter, $section, $mode);
}

function execAndGetLines(string $mode, string $parameter, string $section = ''): array {
    $cmd = '';
    switch ($mode) {
        case 'man':
            $oldManroffopt = getenv('MANROFFOPT');
            $oldManwidth = getenv('MANWIDTH');
            putenv("MANROFFOPT=-rLL=100n");
            $cmd = "man -Tutf8 ";
            if ($section !== "") {
                $cmd .= escapeshellarg($section) . " ";
            }
            $cmd .= escapeshellarg($parameter) . " 2>/dev/null";
            $out = [];
            exec($cmd, $out, $code);
            $firstLine = count($out) > 0 ? trim($out[0]) : "";
            if ($code !== 0 || empty($out) ||
                preg_match('/\b(illegal|unknown|invalid)\s+option\b/i', $firstLine)) {
                putenv("MANWIDTH=100");
                $cmd = "man ";
                if ($section !== "") {
                    $cmd .= escapeshellarg($section) . " ";
                }
                $cmd .= escapeshellarg($parameter) . " 2>/dev/null";
                $out = [];
                exec($cmd, $out, $code);
            }
            putenv("MANROFFOPT" . ($oldManroffopt !== false ? "=" . $oldManroffopt : ""));
            putenv("MANWIDTH" . ($oldManwidth !== false ? "=" . $oldManwidth : ""));
            return $out;
        case 'perldoc':
            $cmd = "perldoc " . escapeshellarg($parameter) . " 2>/dev/null";
            $out = []; exec($cmd, $out);
            if (empty($out)) {
                $cmd = "perldoc -f " . escapeshellarg($parameter) . " 2>/dev/null";
                $out = []; exec($cmd, $out);
            }
            // Use pod2text pipeline for width control when available
            if (!empty($out)) {
                $locCmd = "perldoc -l " . escapeshellarg($parameter) . " 2>/dev/null | head -1";
                $loc = trim(shell_exec($locCmd) ?? '');
                if ($loc !== '') {
                    $podCmd = "pod2text -w 100 " . escapeshellarg($loc) . " 2>/dev/null";
                    $podOut = []; exec($podCmd, $podOut);
                    if (!empty($podOut)) {
                        $out = $podOut;
                    }
                }
            }
            return $out;
        case 'pydoc':
            $cmd = "pydoc3 " . escapeshellarg($parameter) . " 2>/dev/null";
            $out = []; exec($cmd, $out, $code);
            if ($code !== 0 || empty($out)) {
                $cmd = "pydoc " . escapeshellarg($parameter) . " 2>/dev/null";
                $out = []; exec($cmd, $out);
            }
            return $out;
        default:
            return [];
    }
}

// ─── Test data: 5 man + 5 perldoc + 5 pydoc ───

$testCases = [
    // --- man pages (section 1 user commands) ---
    ['mode' => 'man', 'param' => 'ls',     'section' => '1', 'desc' => 'basic command'],
    ['mode' => 'man', 'param' => 'bash',   'section' => '1', 'desc' => 'large page, many options'],
    ['mode' => 'man', 'param' => 'grep',   'section' => '1', 'desc' => 'medium page, flag options'],
    ['mode' => 'man', 'param' => 'tar',    'section' => '1', 'desc' => 'many flags, EXAMPLES section'],
    ['mode' => 'man', 'param' => 'awk',    'section' => '1', 'desc' => 'complex page, multi-section'],

    // --- perldoc (Perl modules) ---
    ['mode' => 'perldoc', 'param' => 'File::Basename', 'section' => '', 'desc' => 'standard Perl module'],
    ['mode' => 'perldoc', 'param' => 'Digest::MD5',    'section' => '', 'desc' => 'module with methods'],
    ['mode' => 'perldoc', 'param' => 'Getopt::Long',   'section' => '', 'desc' => 'option parsing module'],
    ['mode' => 'perldoc', 'param' => 'Cwd',            'section' => '', 'desc' => 'core module'],
    ['mode' => 'perldoc', 'param' => 'POSIX',          'section' => '', 'desc' => 'large core module'],

    // --- pydoc (Python modules) ---
    ['mode' => 'pydoc', 'param' => 'json',       'section' => '', 'desc' => 'stdlib module'],
    ['mode' => 'pydoc', 'param' => 'os.path',    'section' => '', 'desc' => 'submodule path'],
    ['mode' => 'pydoc', 'param' => 'datetime',   'section' => '', 'desc' => 'class-heavy module'],
    ['mode' => 'pydoc', 'param' => 're',         'section' => '', 'desc' => 'regex module'],
    ['mode' => 'pydoc', 'param' => 'subprocess', 'section' => '', 'desc' => 'subprocess module'],
];

// ─── Run tests ───

$passed = 0;
$failed = 0;
$skipped = 0;
$results = [];

echo "╔══════════════════════════════════════════════╗\n";
echo "║  JSON Structure Regression Test              ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";

foreach ($testCases as $i => $tc) {
    $label = "{$tc['mode']}/{$tc['param']}" . ($tc['section'] ? "/{$tc['section']}" : "");
    echo sprintf("[%2d/%2d] %-35s ", $i + 1, count($testCases), $label);

    try {
        $json = formatToJSONFromRaw($tc['mode'], $tc['param'], $tc['section']);
        $data = json_decode($json, true);

        if ($data === null) {
            echo "❌ JSON parse error\n";
            $failed++;
            $results[$label] = ['status' => 'FAIL', 'error' => 'JSON parse error'];
            continue;
        }

        if (empty($data['sections'] ?? null)) {
            echo "⚠️  SKIP (no sections)\n";
            $skipped++;
            $results[$label] = ['status' => 'SKIP', 'reason' => 'no sections'];
            continue;
        }

        // Validate structural invariants
        $errors = [];

        // 1. Must have NAME section
        $hasName = false;
        foreach ($data['sections'] as $sec) {
            if (($sec['name'] ?? '') === 'NAME') { $hasName = true; break; }
        }
        if (!$hasName) {
            $errors[] = 'missing NAME section';
        }

        // 2. Each section must have name, content
        foreach ($data['sections'] as $si => $sec) {
            if (empty($sec['name'])) {
                $errors[] = "section[$si] has empty name";
            }
            if (!isset($sec['content'])) {
                $errors[] = "section[{$sec['name']}] missing content";
            }
        }

        // 3. Subsections must have names
        foreach ($data['sections'] as $sec) {
            foreach ($sec['subsections'] ?? [] as $sub) {
                if (empty($sub['name'])) {
                    $errors[] = "subsection in {$sec['name']} has empty name";
                }
            }
        }

        // 4. Flags must have flag field if present
        foreach ($data['flags'] ?? [] as $fi => $flag) {
            if (empty($flag['flag'])) {
                $errors[] = "flag[$fi] missing flag field";
            }
        }

        // 5. Mode and parameter must match
        if (($data['mode'] ?? '') !== $tc['mode']) {
            $errors[] = "mode mismatch: expected {$tc['mode']}, got " . ($data['mode'] ?? 'null');
        }

        // Print fingerprint
        $fp = jsonStructureFingerprint($data);

        if ($errors) {
            echo "❌ FAIL\n";
            foreach ($errors as $e) {
                echo "     → $e\n";
            }
            echo "     Fingerprint: {$fp}\n";
            $failed++;
            $results[$label] = ['status' => 'FAIL', 'errors' => $errors, 'fingerprint' => $fp];
        } else {
            echo "✅ PASS\n";
            echo sprintf("     %-55s %3d sections, %3d flags, %3d examples\n",
                '', count($data['sections']), count($data['flags'] ?? []), count($data['examples'] ?? []));
            $passed++;
            $results[$label] = [
                'status' => 'PASS',
                'fingerprint' => $fp,
                'sections' => count($data['sections']),
                'flags' => count($data['flags'] ?? []),
                'examples' => count($data['examples'] ?? []),
                'summary' => $data['summary'] ?? '',
            ];
        }

    } catch (\Throwable $e) {
        echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
        $failed++;
        $results[$label] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
}

// ─── Summary ───
echo "\n";
echo str_repeat('═', 55) . "\n";
echo sprintf("  %-20s %d\n", 'Total:', count($testCases));
echo sprintf("  %-20s %d\n", 'Passed:', $passed);
echo sprintf("  %-20s %d\n", 'Failed:', $failed);
echo sprintf("  %-20s %d\n", 'Skipped:', $skipped);
echo str_repeat('═', 55) . "\n";

// Print all fingerprints
echo "\n=== Structure Fingerprints (for regression baselines) ===\n\n";
foreach ($results as $label => $r) {
    $status = $r['status'] === 'PASS' ? '✅' : ($r['status'] === 'SKIP' ? '⚠️' : '❌');
    echo "// {$label} {$status}";
    if (!empty($r['summary'])) {
        echo " — {$r['summary']}";
    }
    echo "\n'{$label}' => '" . ($r['fingerprint'] ?? ($r['error'] ?? 'unknown')) . "',\n\n";
}

exit($failed > 0 ? 1 : 0);
