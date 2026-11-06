<?php
/**
 * Golden-corpus check (#255, Task 5) — CI gate.
 *
 * Usage:
 *   php test/corpus/check.php [--corpus=DIR]
 *
 * Re-renders every frozen raw page through the real renderers and compares
 * against the committed goldens. Four checks per manifest entry, each printed
 * as `[FAIL] <id> <check>: <reason>` when it fails:
 *   golden-md      — re-rendered markdown vs golden/<id>.md, byte-for-byte
 *                    (mismatch prints the first differing line number and up
 *                    to 3 differing line pairs)
 *   tokens         — every token of corpusNormalizeText(raw) must appear in
 *                    the HTML rendering (corpusTextFromHtml), as a counted
 *                    multiset via corpusMissingTokens()
 *   fingerprint    — jsonStructureFingerprint(buildJsonData(...)) vs
 *                    golden/<id>.fingerprint
 *   json-contract  — formatToJSON() output: json_decode non-null, 'sections'
 *                    key present, json_encode re-serialises to a non-empty
 *                    string
 *
 * All checks pass → exit 0. Any failure → exit 1.
 *
 * Empty corpus (controller ruling): when raw/ holds no *.txt, print
 * `[SKIP] no corpus` and exit 0. Task 7 wires this gate into CI before Task 8
 * captures the real pages; without the skip the CI job would be red on an
 * empty corpus.
 *
 * Environment isolation mirrors cli/corpus.php render (Task 4): the constants
 * are defined BEFORE the bootstrap require, so the golden comparison cannot
 * drift with the generating host's user config (throwaway PHPMAN_HOME), local
 * TLDR fetches (PHPMAN_TLDR_DISABLE), or the host's base URL (pinned
 * PHPMAN_BASE_URL) — render and check must see the same environment for the
 * byte-for-byte comparison to be meaningful.
 *
 * CI never runs man/groff/perl here: this script only reads frozen files and
 * calls the renderers in src/ (pure PHP string transforms).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    die("CLI only\n");
}

/**
 * First differing line number + up to 3 differing line pairs, as indented
 * detail lines for the golden-md check. Truncation at 80 chars keeps the CI
 * log readable; the golden files themselves carry the full text.
 *
 * @return string[] detail lines (may be empty when texts are equal)
 *
 * @param string[] $expectedLines
 * @param string[] $actualLines
 */
function corpusCheckDiffDetail(array $expectedLines, array $actualLines): array {
    $n = max(count($expectedLines), count($actualLines));
    $diffs = [];
    for ($i = 0; $i < $n; $i++) {
        $e = $expectedLines[$i] ?? '(missing)';
        $a = $actualLines[$i] ?? '(missing)';
        if ($e !== $a) {
            $diffs[] = [$i, $e, $a];
        }
    }
    if ($diffs === []) {
        return [];
    }
    $out = ['first difference at line ' . ($diffs[0][0] + 1) . ' (of golden; showing up to 3)'];
    foreach (array_slice($diffs, 0, 3) as [$i, $e, $a]) {
        $out[] = '  line ' . ($i + 1) . ':';
        $out[] = '    golden: ' . (strlen($e) > 80 ? substr($e, 0, 77) . '...' : $e);
        $out[] = '    actual: ' . (strlen($a) > 80 ? substr($a, 0, 77) . '...' : $a);
    }
    return $out;
}

/**
 * Split raw bytes into renderer input lines, exactly like cli/corpus.php
 * render: preg_split on \n, drop the single trailing empty element the
 * trailing newline produces (exec() semantics). The pattern is a literal,
 * so preg_split cannot return false here.
 *
 * @return string[]
 */
function corpusCheckLines(string $raw): array {
    $lines = preg_split('/\n/', $raw);
    if ($lines === false) {
        // Unreachable for a literal pattern; kept for the strict return type.
        return [];
    }
    if ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }
    return $lines;
}

/**
 * Run the four checks for one manifest entry.
 *
 * @param array{id: string, tool: string, name: string, section: string} $entry
 * @return array<string, array{reason: string, detail: string[]}> check name => failure
 */
function corpusCheckPage(array $entry, string $rawDir, string $goldenDir): array {
    $id = $entry['id'];
    $name = $entry['name'];
    $section = $entry['section'];
    $mode = $entry['tool']; // 'man' | 'perldoc' — also buildJsonData()'s mode
    $fails = [];

    $rawFile = $rawDir . '/' . $id . '.txt';
    $raw = is_file($rawFile) ? file_get_contents($rawFile) : false;
    if ($raw === false || $raw === '') {
        // Without raw nothing downstream can be checked; one FAIL for the page.
        $fails['golden-md'] = ['reason' => 'raw output missing or empty: ' . $rawFile, 'detail' => []];
        return $fails;
    }
    $lines = corpusCheckLines($raw);

    // 1. golden-md — re-render, compare byte-for-byte.
    $goldenMdFile = $goldenDir . '/' . $id . '.md';
    $goldenMd = is_file($goldenMdFile) ? file_get_contents($goldenMdFile) : false;
    if ($goldenMd === false) {
        $fails['golden-md'] = ['reason' => 'golden file missing: ' . $goldenMdFile, 'detail' => []];
    } else {
        $linesMd = $lines;
        $md = $mode === 'perldoc'
            ? formatManPerlDocToMarkdown($linesMd, $name, 'perldoc')
            : formatManPerlDocToMarkdown($linesMd, $name, 'man', $section);
        if ($md !== $goldenMd) {
            $fails['golden-md'] = [
                'reason' => 'golden mismatch: ' . $goldenMdFile
                    . ' (golden ' . strlen($goldenMd) . 'B, actual ' . strlen($md) . 'B)',
                'detail' => corpusCheckDiffDetail(explode("\n", $goldenMd), explode("\n", $md)),
            ];
        }
    }

    // 2. tokens — every normalized raw token must survive into the HTML text.
    $linesHtml = $lines;
    $html = formatManPerlDoc($linesHtml, $mode);
    $missing = corpusMissingTokens(
        corpusTokens(corpusNormalizeText($raw)),
        corpusTokens(corpusTextFromHtml($html))
    );
    if ($missing !== []) {
        $shown = array_slice($missing, 0, 8);
        $fails['tokens'] = [
            'reason' => count($missing) . ' raw token(s) missing from rendered HTML: '
                . implode(' ', $shown)
                . (count($missing) > count($shown) ? ' ...' : ''),
            'detail' => [],
        ];
    }

    // 3. fingerprint — structural JSON fingerprint vs golden.
    $goldenFpFile = $goldenDir . '/' . $id . '.fingerprint';
    $goldenFp = is_file($goldenFpFile) ? file_get_contents($goldenFpFile) : false;
    if ($goldenFp === false) {
        $fails['fingerprint'] = ['reason' => 'golden file missing: ' . $goldenFpFile, 'detail' => []];
    } else {
        $linesFp = $lines;
        $fp = jsonStructureFingerprint(buildJsonData($linesFp, $name, $section, $mode));
        if ($fp !== $goldenFp) {
            $fails['fingerprint'] = [
                'reason' => 'fingerprint mismatch (golden ' . strlen($goldenFp)
                    . 'B, actual ' . strlen($fp) . 'B)',
                'detail' => [
                    'golden: ' . $goldenFp,
                    'actual: ' . $fp,
                ],
            ];
        }
    }

    // 4. json-contract — decode must yield an array with 'sections',
    //    re-encode must be non-empty.
    $linesContract = $lines;
    $jsonStr = formatToJSON($linesContract, $name, $section, $mode);
    $data = json_decode($jsonStr, true);
    if (!is_array($data)) {
        $fails['json-contract'] = [
            'reason' => 'json_decode returned ' . gettype($data)
                . ' (JSON error ' . json_last_error_msg() . ')',
            'detail' => [],
        ];
    } elseif (!array_key_exists('sections', $data)) {
        $fails['json-contract'] = [
            'reason' => "'sections' key missing from decoded JSON",
            'detail' => [],
        ];
    } else {
        $re = json_encode($data);
        if ($re === false || $re === '') {
            $fails['json-contract'] = [
                'reason' => 'json_encode re-serialisation empty ('
                    . json_last_error_msg() . ')',
                'detail' => [],
            ];
        }
    }

    return $fails;
}

/**
 * @param string[] $argv
 */
function corpusCheckMain(array $argv): int {
    $corpusDir = __DIR__; // default: the repo's test/corpus
    $argc = count($argv);
    for ($i = 1; $i < $argc; $i++) {
        if (preg_match('/^--corpus=(.+)$/', $argv[$i], $m) === 1) {
            $corpusDir = rtrim($m[1], '/');
        }
    }

    $manifestFile = $corpusDir . '/pages.txt';
    if (!is_file($manifestFile)) {
        fwrite(STDERR, "corpus: manifest not found: {$manifestFile} (--corpus=DIR, manifest expected at DIR/pages.txt)\n");
        return 1;
    }

    // Empty corpus: nothing frozen yet → skip, not fail (controller ruling).
    $rawDir = $corpusDir . '/raw';
    $rawFiles = is_dir($rawDir) ? glob($rawDir . '/*.txt') : [];
    if ($rawFiles === false || count($rawFiles) === 0) {
        echo "[SKIP] no corpus\n";
        return 0;
    }

    // Environment isolation BEFORE the bootstrap require — same as render.
    define('PHPMAN_HOME', sys_get_temp_dir() . '/phpman-corpus-check-home');
    define('PHPMAN_BASE_URL', 'https://www.chedong.com/phpMan.php');
    define('PHPMAN_TLDR_DISABLE', true);
    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
    require_once __DIR__ . '/normalize.php';
    require_once __DIR__ . '/fingerprint.php';

    $entries = corpusReadManifest($manifestFile);
    $goldenDir = $corpusDir . '/golden';
    $nFail = 0;
    $nPages = 0;
    foreach ($entries as $entry) {
        $id = $entry['id'];
        $nPages++;
        $fails = corpusCheckPage($entry, $rawDir, $goldenDir);
        if ($fails === []) {
            echo "[OK] {$id}\n";
            continue;
        }
        foreach ($fails as $check => $fail) {
            $nFail++;
            echo "[FAIL] {$id} {$check}: {$fail['reason']}\n";
            foreach ($fail['detail'] as $line) {
                echo "    {$line}\n";
            }
        }
    }

    if ($nFail > 0) {
        echo "checked {$nPages} page(s): {$nFail} check(s) failed\n";
        return 1;
    }
    echo "checked {$nPages} page(s): all pass\n";
    return 0;
}

// Main flow only when executed directly — a test may require this file.
if (isset($argv) && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(corpusCheckMain($argv));
}
