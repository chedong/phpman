<?php
/**
 * Golden-corpus check (#255, Task 5) — CI gate.
 *
 * Usage:
 *   php test/corpus/check.php [--corpus=DIR]
 *
 * Re-renders every frozen raw page through the real renderers and compares
 * against the committed goldens. Five checks per manifest entry, each printed
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
 *   xhtml          — the complete page (showHeader + formatManPerlDoc +
 *                    showFooter, what a user actually receives) validates
 *                    against the phpman override DTD
 *                    (test/corpus/dtd/xhtml1-transitional-phpman.dtd) via
 *                    `xmllint --noout --nonet --dtdvalid`; when xmllint is
 *                    absent the run prints `[SKIP] xhtml` instead of failing
 *                    (CI installs libxml2-utils, other environments may not),
 *                    and when it runs clean the summary line is
 *                    `[PASS] xhtml (N page(s) dtd-valid)`
 *
 * DTD vendoring (Task 6): the four W3C files in test/corpus/dtd/ were
 * downloaded byte-for-byte on 2026-11-06 from W3C (all HTTP 200 at download
 * time):
 *   https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd
 *   https://www.w3.org/TR/xhtml1/DTD/xhtml-lat1.ent
 *   https://www.w3.org/TR/xhtml1/DTD/xhtml-special.ent
 *   https://www.w3.org/TR/xhtml1/DTD/xhtml-symbol.ent
 * The DTD references the .ent files by relative SYSTEM id, so keeping all four
 * in one directory lets xmllint resolve them offline. The page's own DOCTYPE
 * points at the absolute w3.org URL; --dtdvalid ignores it and validates
 * against the local file (hence --dtdvalid, not --valid), and --nonet refuses
 * the network fetch that DOCTYPE would otherwise trigger.
 *
 * Validation uses test/corpus/dtd/xhtml1-transitional-phpman.dtd (phpman's
 * own five-line extension of the W3C DTD, provenance documented in that
 * file): it re-includes the W3C DTD and declares the two modern attributes
 * the pages deliberately emit — script/@nonce (#273 CSP) and script/@async
 * (GA). Without it every page failed on the nonce; with it, any other
 * non-XHTML1.0 construct still fails the check.
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

// ---------------------------------------------------------------------------
// XHTML full-page validation (Task 6, #255) — see the DTD-vendoring block in
// the file header for where test/corpus/dtd/ came from and why --dtdvalid.
// ---------------------------------------------------------------------------

/** Path of the phpman override DTD (W3C XHTML 1.0 Transitional + script/@nonce, script/@async). */
function corpusCheckDtdPath(): string {
    return __DIR__ . '/dtd/xhtml1-transitional-phpman.dtd';
}

/**
 * Resolve xmllint once per process. Null when the binary is absent: the
 * xhtml check then prints `[SKIP] xhtml` for the run instead of failing.
 * `command -v` is a shell builtin, so this works even with a stripped PATH
 * (which is exactly how the unit test simulates the absence).
 */
function corpusCheckXmllintPath(): ?string {
    static $path = false;
    if ($path === false) {
        $out = [];
        $rc = 1;
        exec('command -v xmllint 2>/dev/null', $out, $rc);
        $path = ($rc === 0 && isset($out[0]) && $out[0] !== '') ? $out[0] : null;
    }
    return $path;
}

/**
 * Per-run counters for the xhtml check. corpusCheckXhtmlValidate() bumps via
 * corpusCheckXhtmlStats(true, $failed); corpusCheckMain() reads with no
 * arguments to decide between the `[PASS] xhtml` and `[SKIP] xhtml` summary
 * lines.
 *
 * @return array{ran: int, failed: int}
 */
function corpusCheckXhtmlStats(bool $bump = false, bool $failed = false): array {
    static $ran = 0;
    static $nFailed = 0;
    if ($bump) {
        $ran++;
        if ($failed) {
            $nFailed++;
        }
    }
    return ['ran' => $ran, 'failed' => $nFailed];
}

/**
 * Assemble the complete page exactly the way the web router serves it:
 * showHeader + formatManPerlDoc body + showFooter. The footer carries
 * non-deterministic bits (timestamp, IP/UA) — fine: the xhtml check asserts
 * validity, not byte equality. Title mirrors phpMan.php's
 * "name(section) - mode - [phpMan]" shape (without the description lookup,
 * which would shell out).
 *
 * @param string[] $lines
 */
function corpusCheckRenderFullPage(array $lines, string $name, string $section, string $mode): string {
    $title = $name . ($section !== "" ? "({$section})" : "") . " - " . $mode . " - [phpMan]";
    ob_start();
    // showHeader() calls header() ~10 times before its first echo. Once any
    // output has been sent (here: earlier pages' [OK]/[FAIL] lines in the
    // same process), PHP raises E_WARNING — and that warning text would be
    // captured into the buffered page bytes, corrupting them before xmllint
    // ever sees them. Web serving never hits this (headers not yet sent), so
    // rather than touching src/, assembly swallows diagnostics raised inside
    // these three calls and forwards them to STDERR instead: the validated
    // bytes stay exactly what the renderers emitted, and the reason a page
    // came out broken is still visible in the subprocess log.
    set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
        // header() is meaningless in CLI SAPI — silence that one completely.
        if (strpos($msg, 'Cannot modify header information') !== false) {
            return true;
        }
        fwrite(STDERR, "phpman-corpus [diagnostic] {$msg} in {$file}:{$line}\n");
        return true; // handled — do not print into the page being captured
    });
    showHeader($title, $name, $section, $mode, true, false, "");
    echo formatManPerlDoc($lines, $mode);
    showFooter("", false, $mode, $name, $section, "", "");
    restore_error_handler();
    return (string)ob_get_clean();
}

/**
 * Validate one rendered full page against the vendored DTD with xmllint.
 *
 * Without xmllint this returns pass=true WITHOUT bumping the stats — the
 * caller (corpusCheckPage) skips the check and corpusCheckMain prints
 * `[SKIP] xhtml` for the run.
 *
 * @return array{pass: bool, rc: int, output: string} output: xmllint
 *         diagnostics, with the expected --nonet noise filtered out (see the
 *         file-header block: --nonet refuses the page's absolute-URL DOCTYPE;
 *         validation itself used the vendored DTD)
 *
 * @param string $id page id, only used to name the temp file for debuggability
 */
function corpusCheckXhtmlValidate(string $page, string $id): array {
    $xmllint = corpusCheckXmllintPath();
    if ($xmllint === null) {
        return ['pass' => true, 'rc' => 0, 'output' => ''];
    }
    $dtd = corpusCheckDtdPath();
    if (!is_file($dtd)) {
        corpusCheckXhtmlStats(true, true);
        return ['pass' => false, 'rc' => -1, 'output' => 'vendored DTD missing: ' . $dtd];
    }
    $tmpFile = sys_get_temp_dir() . '/phpman-corpus-xhtml-' . getmypid() . '-'
        . preg_replace('/[^A-Za-z0-9._-]/', '_', $id) . '.html';
    if (file_put_contents($tmpFile, $page) === false) {
        corpusCheckXhtmlStats(true, true);
        return ['pass' => false, 'rc' => -1, 'output' => 'cannot write temp file: ' . $tmpFile];
    }
    $out = [];
    $rc = 0;
    exec(escapeshellarg($xmllint) . ' --noout --nonet --dtdvalid '
        . escapeshellarg($dtd) . ' ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $rc);
    unlink($tmpFile);
    $diagnostics = implode("\n", array_filter($out, static function (string $line): bool {
        return preg_match(
            '/Attempt to load network entity|failed to load external entity|^\s*1\.0 Transitional|^\s*\^/',
            $line
        ) !== 1;
    }));
    $diagnostics = trim($diagnostics);
    $pass = ($rc === 0);
    corpusCheckXhtmlStats(true, !$pass);
    return ['pass' => $pass, 'rc' => $rc, 'output' => $diagnostics];
}

/**
 * Run the five checks for one manifest entry.
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

    // 5. xhtml — the complete page (header + body + footer, what a user
    //    actually receives) must validate against the phpman override DTD.
    //    Per page this check is silent when xmllint is absent (no fail, no
    //    stat bump); corpusCheckMain prints [SKIP] xhtml.
    if (corpusCheckXmllintPath() !== null) {
        $linesXhtml = $lines;
        $page = corpusCheckRenderFullPage($linesXhtml, $name, $section, $mode);
        $xhtml = corpusCheckXhtmlValidate($page, $id);
        if (!$xhtml['pass']) {
            $detail = $xhtml['output'] === '' ? [] : array_slice(explode("\n", $xhtml['output']), 0, 5);
            $fails['xhtml'] = [
                'reason' => 'full page does not validate against XHTML 1.0 Transitional'
                    . ' (xmllint exit ' . $xhtml['rc'] . ')',
                'detail' => $detail,
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

    // xhtml run summary (Task 6): one line for the whole run. No xmllint →
    // [SKIP] xhtml (never a failure); ran clean → [PASS] xhtml; any per-page
    // xhtml failure already printed its [FAIL] line in the loop.
    if (corpusCheckXmllintPath() === null) {
        echo "[SKIP] xhtml (xmllint not found)\n";
    } else {
        $xhtmlStats = corpusCheckXhtmlStats();
        if ($xhtmlStats['ran'] > 0 && $xhtmlStats['failed'] === 0) {
            echo "[PASS] xhtml ({$xhtmlStats['ran']} page(s) dtd-valid)\n";
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
