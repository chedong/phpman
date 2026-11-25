#!/usr/bin/env php
<?php
/**
 * cli/changelog-cut.php — cut `## [Unreleased]` into a dated release section (#286).
 *
 * Why
 * ---
 * `make release` has tagged v4.12.3 … v4.12.27 while every entry kept
 * accumulating under `[Unreleased]`: a tag no longer told a reader what shipped.
 * Tagging became automatic in #250; this is the other half — `_release-tag`
 * runs this script with the same version string, so the notes are cut in the
 * same step that creates the tag.
 *
 * Contract
 * --------
 *   - the first `## [Unreleased]` becomes empty and the old body moves under
 *     `## [<version>] — <date>`;
 *   - idempotent: if `## [<version>]` already exists, exit 0 and change
 *     nothing. A release re-run after a failed push must not cut twice;
 *   - an empty Unreleased is exit 3 unless --allow-empty. By the time
 *     `_release-tag` runs, the code is already deployed and verified, so
 *     refusing to tag would leave deployed code with no tag — worse than a
 *     factual placeholder. The release passes --allow-empty and the placeholder
 *     says exactly that no user-facing changes were documented;
 *   - the write is atomic (temp + rename): an interrupted release must never
 *     leave a half-written CHANGELOG.
 *
 * Usage
 * -----
 *   php cli/changelog-cut.php 4.12.28
 *   php cli/changelog-cut.php 4.12.28 --date=2026-11-25 --note="..." --dry-run
 *   php cli/changelog-cut.php 4.12.28 --allow-empty
 */
declare(strict_types=1);

const CHANGELOG_EMPTY_LINE = '- No user-facing changes documented for this release.';

function changelogFail(string $message, int $code = 2): void {
    fwrite(STDERR, "changelog-cut: {$message}\n");
    exit($code);
}

/** @return array{0:string,1:array<string,string|bool>} */
function changelogArgs(array $argv): array {
    $version = '';
    $opts = ['date' => date('Y-m-d'), 'file' => 'CHANGELOG.md', 'note' => '', 'allow-empty' => false, 'dry-run' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "用法: php cli/changelog-cut.php <version> [--date=YYYY-MM-DD] [--file=PATH] [--note=TEXT] [--allow-empty] [--dry-run]\n");
            exit(0);
        }
        if ($arg === '--allow-empty') { $opts['allow-empty'] = true; continue; }
        if ($arg === '--dry-run') { $opts['dry-run'] = true; continue; }
        if (preg_match('/^--(date|file|note)=(.*)$/s', $arg, $m) === 1) {
            $opts[$m[1]] = $m[2];
            continue;
        }
        if ($version === '') { $version = $arg; continue; }
        changelogFail("未知参数: {$arg}");
    }
    $version = ltrim($version, 'v');
    if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1) {
        changelogFail("版本号必须是 x.y.z（可带 v 前缀），收到: " . ($version === '' ? '<空>' : $version));
    }
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string)$opts['date']) !== 1) {
        changelogFail("--date 必须是 YYYY-MM-DD");
    }
    if ($opts['note'] !== '' && !mb_check_encoding((string)$opts['note'], 'UTF-8')) {
        changelogFail("--note 不是合法 UTF-8（不要把一个多字节字符截断）");
    }
    return [$version, $opts];
}

/**
 * Pure transformation: returns the new file content.
 *
 * @param list<string> $lines file split without trailing newlines
 * @return list<string>
 */
function changelogCutLines(array $lines, string $version, string $date, string $note, bool $allowEmpty): array {
    $unreleased = null;
    $next = null;
    foreach ($lines as $i => $line) {
        if ($unreleased === null) {
            if (trim($line) === '## [Unreleased]') $unreleased = $i;
            continue;
        }
        if (preg_match('/^## \[/', $line) === 1) { $next = $i; break; }
    }
    if ($unreleased === null) changelogFail('找不到 "## [Unreleased]" 标题');
    if ($next === null) $next = count($lines);

    // Already cut: a re-run after a failed push must be a no-op.
    foreach ($lines as $line) {
        if (preg_match('/^## \[' . preg_quote($version, '/') . '\]/', $line) === 1) {
            return $lines;   // caller reports "already"
        }
    }

    $body = array_slice($lines, $unreleased + 1, $next - $unreleased - 1);
    while ($body !== [] && trim($body[0]) === '') array_shift($body);
    while ($body !== [] && trim($body[count($body) - 1]) === '') array_pop($body);

    $hasEntries = false;
    foreach ($body as $line) {
        if (str_starts_with($line, '- ')) { $hasEntries = true; break; }
    }
    if (!$hasEntries && !$allowEmpty) {
        changelogFail("[Unreleased] 里没有任何 \"- \" 条目。补上发布说明，或显式传 --allow-empty。", 3);
    }
    if (!$hasEntries) {
        $body = [CHANGELOG_EMPTY_LINE];
        fwrite(STDERR, "changelog-cut: 警告：[Unreleased] 为空，写入占位说明（--allow-empty）\n");
    }

    $out = array_slice($lines, 0, $unreleased);
    $out[] = '## [Unreleased]';
    $out[] = '';
    $out[] = "## [{$version}] — {$date}";
    $out[] = '';
    if ($note !== '') {
        // `/u` is load-bearing: without it PCRE's \R also matches 0x85 (NEL),
        // and 0x85 is a common *continuation* byte in Chinese UTF-8 — the split
        // lands inside a character and produces an invalid-UTF-8 CHANGELOG
        // (observed with "关系": E5 85 B3 E7 B3 BB).
        foreach (preg_split('/\R/u', $note) ?: [] as $noteLine) {
            $out[] = '> ' . $noteLine;
        }
        $out[] = '';
    }
    foreach ($body as $line) $out[] = $line;
    $out[] = '';
    foreach (array_slice($lines, $next) as $line) $out[] = $line;
    return $out;
}

[$version, $opts] = changelogArgs($argv);
$file = (string)$opts['file'];

if (!is_file($file) || !is_readable($file)) changelogFail("读不到 {$file}");
$raw = file($file, FILE_IGNORE_NEW_LINES);
if ($raw === false) changelogFail("读不到 {$file}");

$already = false;
foreach ($raw as $line) {
    if (preg_match('/^## \[' . preg_quote($version, '/') . '\]/', $line) === 1) { $already = true; break; }
}
if ($already) {
    // Checked before the transformation: on a re-run the Unreleased section is
    // legitimately empty, and the empty-section guard must not fire for it.
    fwrite(STDERR, "changelog-cut: 已经有 ## [{$version}]，未改动\n");
    exit(0);
}

$out = changelogCutLines($raw, $version, (string)$opts['date'], (string)$opts['note'], (bool)$opts['allow-empty']);
$new = implode("\n", $out) . "\n";
if ($opts['dry-run']) {
    echo $new;
    exit(0);
}

$mode = fileperms($file) & 0777;
$tmp = $file . '.tmp';
if (@file_put_contents($tmp, $new) === false) changelogFail("写不了 {$tmp}");
@chmod($tmp, $mode > 0 ? $mode : 0644);
if (!@rename($tmp, $file)) {
    @unlink($tmp);
    changelogFail("无法原子替换 {$file}");
}
fwrite(STDERR, "changelog-cut: ## [Unreleased] → ## [{$version}] — {$opts['date']}\n");
exit(0);
