<?php
/**
 * Golden-corpus tooling (#255).
 *
 * Usage:
 *   php cli/corpus.php render  [--out=DIR]   — render frozen raw into golden files
 *   php cli/corpus.php capture [--out=DIR]   — freeze raw tool output on a host
 *
 * capture (Task 3) runs the real documentation tools (man / perldoc pipeline)
 * on the current host and freezes their output byte-for-byte:
 *   DIR/raw/<id>.txt  — raw stdout of the tool (shell_exec, never exec(),
 *                       whose line splitting loses the exact byte stream)
 *   DIR/VERSIONS.md   — tool versions + per-page byte count and sha256
 *
 * render (Task 4) replays that frozen raw through the real renderers and
 * writes the regression baseline:
 *   DIR/golden/<id>.md           — formatManPerlDocToMarkdown() output
 *                                  (mode = the page's tool; man pages also
 *                                  carry their section)
 *   DIR/golden/<id>.fingerprint  — jsonStructureFingerprint(buildJsonData(...))
 *
 * Standalone capture by design: the capture path does NOT require _bootstrap.php
 * or src/. Copy cli/corpus.php to any host (`scp cli/corpus.php host:/tmp/`,
 * plus the manifest DIR/pages.txt) and run `capture` there — CI never runs
 * these tools. `render` is different: it replays the raw through the real
 * renderers, so it loads src/bootstrap.php. It is still safe to require this
 * file from a unit test: the main flow only runs when the file is executed
 * directly (realpath($argv[0]) === __FILE__; $argv may be unset when the file
 * is included, hence the isset guards), and render's bootstrap require lives
 * inside corpusRenderMain(), so a bare require defines functions only.
 *
 * WIDTH: the man command pins -rLL=100n and the pod2text pipeline pins
 * -w 100. Both match PHPMAN_WIDTH in src/config.php (default 100, override
 * via phpman.config.php) — the value is written literally here so capture
 * works without loading any phpman code. Keep the two in sync: the golden
 * corpus must be produced by the same commands the live server runs.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    die("CLI only\n");
}

/** Width for man (-rLL=100n) and pod2text (-w 100). Same source as PHPMAN_WIDTH (src/config.php, default 100). */
const PHPMAN_CORPUS_WIDTH = 100;

/**
 * Build the shell command for one manifest entry.
 *
 * man:     MANROFFOPT=-rLL=100n man -Tutf8 <section> <name>   (same as src/source_man.php)
 * perldoc: perldoc -l <name> | head -1 | tr '\n' '\0' | xargs -0 pod2text -w 100
 *          (same pipeline as src/source_perldoc.php; xargs -0 keeps spaces safe)
 *
 * @param array{id?: string, tool?: string, name?: string, section?: string} $entry
 */
function corpusBuildCommand(array $entry): string {
    $tool = (string)($entry['tool'] ?? '');
    $name = (string)($entry['name'] ?? '');
    $section = (string)($entry['section'] ?? '');
    if ($tool === 'perldoc') {
        return "perldoc -l " . escapeshellarg($name)
            . " 2>/dev/null | head -1 | tr '\\n' '\\0'"
            . " | xargs -0 pod2text -w " . PHPMAN_CORPUS_WIDTH . " 2>/dev/null";
    }
    // man (default tool): env prefix instead of putenv — one-shot, no leak.
    // Section is appended raw only after a strict allowlist check (man
    // section numbers/letters: 1, 5, 3perl, …) so the command matches the
    // brief's canonical form `man -Tutf8 1 'ls'`; anything else (or empty)
    // falls back to escapeshellarg / is omitted, as src/source_man.php does.
    if ($section !== "") {
        $sectionArg = preg_match('/^[0-9A-Za-z]+$/', $section) === 1
            ? $section
            : escapeshellarg($section);
        return "MANROFFOPT=-rLL=" . PHPMAN_CORPUS_WIDTH . "n man -Tutf8 "
            . $sectionArg . " " . escapeshellarg($name);
    }
    return "MANROFFOPT=-rLL=" . PHPMAN_CORPUS_WIDTH . "n man -Tutf8 "
        . escapeshellarg($name);
}

/**
 * VERSIONS.md body: one "- tool: version" line per tool.
 *
 * Tolerates missing tools: a false/null/empty value (command not found,
 * e.g. `pod2text --version` does not exist on every host) becomes
 * "(unavailable)" instead of an empty line.
 *
 * @param array<string, string|false|null> $versions tool => raw version output
 */
function corpusVersionsText(array $versions): string {
    $lines = ["# Tool versions", ""];
    foreach ($versions as $tool => $value) {
        $v = trim((string)$value);
        if ($v === '') {
            $v = '(unavailable)';
        }
        $lines[] = "- {$tool}: {$v}";
    }
    return implode("\n", $lines) . "\n";
}

/**
 * Read the tab-separated manifest (same format test/corpus/pages.txt,
 * parsed like corpusReadManifest in test/corpus/normalize.php — duplicated
 * here because capture must run standalone, without the test directory).
 *
 * @return array<int, array{id: string, tool: string, name: string, section: string}>
 */
function corpusLoadManifest(string $path): array {
    $rows = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $rows;
    }
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') { continue; }
        $f = explode("\t", $line);
        if (count($f) < 3) { continue; }
        $rows[] = [
            'id' => $f[0],
            'tool' => $f[1],
            'name' => $f[2],
            'section' => isset($f[3]) ? $f[3] : '',
        ];
    }
    return $rows;
}

/**
 * First non-empty-safe line of a command's output: shell_exec() failure
 * (null) and empty output both map to "", which corpusVersionsText()
 * renders as "(unavailable)". Used for `perl -v` whose first line is blank.
 */
function corpusFirstLine($output): string {
    if ($output === null || $output === false) { return ''; }
    $lines = explode("\n", trim((string)$output));
    return trim($lines[0]);
}

/**
 * capture: run every manifest entry, freeze raw bytes, record versions.
 * Exits 1 on the first page whose output is empty — a frozen corpus with a
 * zero-byte page is worse than no corpus: it would look like real evidence.
 *
 * @param string[] $argv
 */
function corpusCaptureMain(array $argv): int {
    $outDir = 'test/corpus';
    $argc = count($argv);
    for ($i = 2; $i < $argc; $i++) {
        if (preg_match('/^--out=(.+)$/', $argv[$i], $m) === 1) {
            $outDir = rtrim($m[1], '/');
        }
    }

    $manifestFile = $outDir . '/pages.txt';
    if (!is_file($manifestFile)) {
        fwrite(STDERR, "corpus: manifest not found: {$manifestFile} (--out=DIR, manifest expected at DIR/pages.txt)\n");
        return 1;
    }
    $entries = corpusLoadManifest($manifestFile);
    if (count($entries) === 0) {
        fwrite(STDERR, "corpus: no entries in {$manifestFile}\n");
        return 1;
    }

    $rawDir = $outDir . '/raw';
    if (!is_dir($rawDir) && !mkdir($rawDir, 0777, true)) {
        fwrite(STDERR, "corpus: cannot create {$rawDir}\n");
        return 1;
    }

    // shell_exec keeps the exact byte stream; exec() splits into lines and
    // would silently rewrite what we are trying to freeze.
    $pages = [];
    foreach ($entries as $entry) {
        $cmd = corpusBuildCommand($entry);
        echo "capture {$entry['id']}: {$cmd}\n";
        $bytes = shell_exec($cmd);
        if ($bytes === null || $bytes === '') {
            echo "FAIL {$entry['id']}: empty output\n";
            return 1;
        }
        file_put_contents($rawDir . '/' . $entry['id'] . '.txt', $bytes);
        $pages[] = [
            'id' => $entry['id'],
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ];
    }

    // Tool versions: pod2text --version does not exist on all hosts — a
    // missing tool is recorded as (unavailable), never a capture failure.
    $versions = [];
    foreach ([
        'man' => 'man --version',
        'groff' => 'groff --version',
        'perl' => 'perl -v',
        'pod2text' => 'pod2text --version',
    ] as $tool => $versionCmd) {
        $versions[$tool] = corpusFirstLine(shell_exec($versionCmd . ' 2>/dev/null'));
    }

    $md = corpusVersionsText($versions)
        . "\n## Pages\n\n| id | bytes | sha256 |\n|---|---|---|\n";
    foreach ($pages as $p) {
        $md .= "| {$p['id']} | {$p['bytes']} | {$p['sha256']} |\n";
    }
    file_put_contents($outDir . '/VERSIONS.md', $md);
    echo "wrote {$outDir}/VERSIONS.md (" . count($pages) . " pages)\n";
    return 0;
}

/**
 * render: replay the frozen raw output of every manifest entry through the
 * real renderers and write the golden regression baseline:
 *   DIR/golden/<id>.md           — markdown rendering (mode = page's tool)
 *   DIR/golden/<id>.fingerprint  — JSON structure fingerprint
 * Exits 1 when the manifest, a raw file, or a rendering is missing/empty —
 * an empty golden file would look like real evidence, same rule as capture.
 *
 * Environment isolation is set here, BEFORE the bootstrap require: the golden
 * baseline must not depend on the generating host's user config (PHPMAN_HOME
 * points at a throwaway temp dir, so no user cache/config is read or written)
 * nor on local TLDR fetches (PHPMAN_TLDR_DISABLE), and the pinned
 * PHPMAN_BASE_URL keeps every link identical no matter where render runs.
 *
 * Line splitting mirrors exec(): preg_split('/\n/') then drop the trailing
 * empty element (raw files end with a newline; exec() would not yield an
 * empty last line for it). Internal empty lines are kept, as exec() keeps.
 *
 * The renderers take $lines BY REFERENCE and mutate it (cleanTerminalOutput),
 * so every format call gets its own fresh copy of the line array.
 *
 * @param string[] $argv
 */
function corpusRenderMain(array $argv): int {
    $outDir = 'test/corpus';
    $argc = count($argv);
    for ($i = 2; $i < $argc; $i++) {
        if (preg_match('/^--out=(.+)$/', $argv[$i], $m) === 1) {
            $outDir = rtrim($m[1], '/');
        }
    }

    $manifestFile = $outDir . '/pages.txt';
    if (!is_file($manifestFile)) {
        fwrite(STDERR, "corpus: manifest not found: {$manifestFile} (--out=DIR, manifest expected at DIR/pages.txt)\n");
        return 1;
    }

    // Environment isolation, then the real renderers. render is a CLI tool in
    // the repo, so the corpus libraries are required by relative path too.
    define('PHPMAN_HOME', sys_get_temp_dir() . '/phpman-corpus-home');
    define('PHPMAN_BASE_URL', 'https://www.chedong.com/phpMan.php');
    define('PHPMAN_TLDR_DISABLE', true);
    require_once dirname(__DIR__) . '/src/bootstrap.php';
    require_once dirname(__DIR__) . '/test/corpus/normalize.php';
    require_once dirname(__DIR__) . '/test/corpus/fingerprint.php';

    $entries = corpusReadManifest($manifestFile);
    if (count($entries) === 0) {
        fwrite(STDERR, "corpus: no entries in {$manifestFile}\n");
        return 1;
    }

    $goldenDir = $outDir . '/golden';
    if (!is_dir($goldenDir) && !mkdir($goldenDir, 0777, true)) {
        fwrite(STDERR, "corpus: cannot create {$goldenDir}\n");
        return 1;
    }

    foreach ($entries as $entry) {
        $id = $entry['id'];
        $name = $entry['name'];
        $section = $entry['section'];
        $mode = $entry['tool']; // 'man' | 'perldoc' — also buildJsonData()'s mode

        $rawFile = $outDir . '/raw/' . $id . '.txt';
        if (!is_file($rawFile)) {
            fwrite(STDERR, "corpus: raw output missing: {$rawFile} (run capture first)\n");
            return 1;
        }
        $raw = file_get_contents($rawFile);
        if ($raw === false || $raw === '') {
            fwrite(STDERR, "corpus: raw output empty: {$rawFile}\n");
            return 1;
        }

        $lines = preg_split('/\n/', $raw);
        if ($lines === false) {
            fwrite(STDERR, "corpus: cannot split raw output: {$rawFile}\n");
            return 1;
        }
        // exec() does not yield an empty last line for the trailing newline
        // the tool output ends with — drop exactly that element.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        // One fresh copy per format: the renderers rewrite the buffer in place.
        $linesMd = $lines;
        $md = $mode === 'perldoc'
            ? formatManPerlDocToMarkdown($linesMd, $name, 'perldoc')
            : formatManPerlDocToMarkdown($linesMd, $name, 'man', $section);
        $linesJson = $lines;
        $fp = jsonStructureFingerprint(buildJsonData($linesJson, $name, $section, $mode));

        if ($md === '' || $fp === '') {
            fwrite(STDERR, "corpus: empty rendering for {$id} (md=" . strlen($md) . " fp=" . strlen($fp) . ")\n");
            return 1;
        }

        file_put_contents($goldenDir . '/' . $id . '.md', $md);
        file_put_contents($goldenDir . '/' . $id . '.fingerprint', $fp);

        // Progress line carries the page's canonical URL on the pinned base —
        // a second, human-visible guard that the baseline URL does not drift
        // with the generating environment.
        $canonical = $mode === 'perldoc'
            ? 'https://www.chedong.com/phpMan.php/perldoc/' . rawurlencode($name) . '/markdown'
            : 'https://www.chedong.com/phpMan.php/man/' . rawurlencode($name)
                . ($section !== '' ? '/' . rawurlencode($section) : '') . '/markdown';
        echo "render {$id}: {$canonical} md=" . strlen($md) . " fp=" . strlen($fp) . "\n";
        echo "  -> {$goldenDir}/{$id}.md {$goldenDir}/{$id}.fingerprint\n";
    }

    echo "wrote " . count($entries) . " golden pages to {$goldenDir}\n";
    return 0;
}

// Main flow only when executed directly — a unit test may require this file.
if (isset($argv) && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $command = isset($argv[1]) ? $argv[1] : '';
    if ($command === 'capture') {
        exit(corpusCaptureMain($argv));
    }
    if ($command === 'render') {
        exit(corpusRenderMain($argv));
    }
    fwrite(STDERR, "Usage: php corpus.php capture|render [--out=DIR]\n");
    exit(1);
}
