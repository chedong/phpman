<?php
/**
 * Golden-corpus capture (#255, Task 3).
 *
 * Usage: php cli/corpus.php capture [--out=DIR]
 *
 * Runs the real documentation tools (man / perldoc pipeline) on the current
 * host and freezes their output byte-for-byte:
 *   DIR/raw/<id>.txt  — raw stdout of the tool (shell_exec, never exec(),
 *                       whose line splitting loses the exact byte stream)
 *   DIR/VERSIONS.md   — tool versions + per-page byte count and sha256
 *
 * Standalone by design: this file does NOT require _bootstrap.php or src/.
 * Copy it to any host (`scp cli/corpus.php host:/tmp/`, plus the manifest
 * DIR/pages.txt) and run it there — CI never runs these tools. It is still
 * safe to require from a unit test: the main flow only runs when the file is
 * executed directly (realpath($argv[0]) === __FILE__; $argv may be unset
 * when the file is included, hence the isset guards).
 *
 * WIDTH: the man command pins -rLL=100n and the pod2text pipeline pins
 * -w 100. Both match PHPMAN_WIDTH in src/config.php (default 100, override
 * via phpman.config.php) — the value is written literally here so this file
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

// Main flow only when executed directly — a unit test may require this file.
if (isset($argv) && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $command = isset($argv[1]) ? $argv[1] : '';
    if ($command !== 'capture') {
        fwrite(STDERR, "Usage: php corpus.php capture [--out=DIR]\n");
        exit(1);
    }
    exit(corpusCaptureMain($argv));
}
