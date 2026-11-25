<?php
/**
 * Unit tests: cli/changelog-cut.php (#286)
 *
 * Why this exists: `make release` tagged 25 versions while every entry stayed
 * under `[Unreleased]`. The cut is now part of `_release-tag`, so it must be
 * boring and idempotent — a half-cut or double-cut CHANGELOG at release time is
 * worse than no automation.
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

$repo = dirname(__DIR__, 2);
$script = $repo . '/cli/changelog-cut.php';
$tmp = sys_get_temp_dir() . '/phpman_changelog_cut_' . getmypid();
@mkdir($tmp, 0755, true);

/** Run the tool; stdout returned, stderr via $stderr. */
function runCut(string $script, string $args, ?string &$stderr = null): array {
    $errFile = tempnam(sys_get_temp_dir(), 'cuterr_');
    $cmd = 'php ' . escapeshellarg($script) . ' ' . $args . ' 2>' . escapeshellarg($errFile);
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    $stderr = (string) @file_get_contents($errFile);
    @unlink($errFile);
    return [implode("\n", $out), $rc];
}

function fixture(string $path, string $body): void {
    file_put_contents($path, $body);
}

echo "=== Unit: cli/changelog-cut.php (#286) ===\n\n";

$base = <<<'MD'
# Changelog

Intro line.

## [Unreleased]

### Fixed
- **Fix one** — detail.
- **Fix two** — detail.

### Added
- **Feature one** — detail.

## [1.2.2] — 2026-01-01

### Fixed
- old entry

## [1.2.1] — 2025-12-01
- older
MD;

// ── the cut ──
echo "--- cut ---\n";
$f = $tmp . '/changelog.md';
fixture($f, $base);
[$out, $rc] = runCut($script, '1.2.3 --date=2026-02-02 --file=' . escapeshellarg($f), $err);
$content = (string) file_get_contents($f);

assert_equals(0, $rc, "exit 0");
assert_contains('## [1.2.3] — 2026-02-02', $content, "new dated section");
assert_equals(1, substr_count($content, '## [Unreleased]'), "exactly one Unreleased heading");
assert_equals(1, substr_count($content, '## [1.2.3]'), "exactly one new section");
assert_equals(1, substr_count($content, '## [1.2.2]'), "the previous section is still there");

$u = strpos($content, '## [Unreleased]');
$n = strpos($content, '## [1.2.3]');
$p = strpos($content, '## [1.2.2]');
assert_equals(true, $u !== false && $n !== false && $p !== false && $u < $n && $n < $p,
    "order is Unreleased → new section → previous section");

$between = substr($content, $u + strlen('## [Unreleased]'), $n - $u - strlen('## [Unreleased]'));
assert_equals('', trim($between), "the new Unreleased section is empty");

foreach (['- **Fix one** — detail.', '- **Fix two** — detail.', '- **Feature one** — detail.'] as $entry) {
    assert_contains($entry, $content, "entry moved into the new section: {$entry}");
}
assert_contains('- old entry', $content, "the previous release's entries are untouched");
assert_contains('- older', $content, "the tail of the file is untouched");
assert_contains('# Changelog', $content, "the header is untouched");
assert_contains('Intro line.', $content, "the intro is untouched");

// ── idempotent ──
echo "\n--- re-run ---\n";
$before = md5($content);
[$out, $rc] = runCut($script, '1.2.3 --date=2026-02-02 --file=' . escapeshellarg($f), $err);
assert_equals(0, $rc, "a re-run exits 0");
assert_equals($before, md5((string) file_get_contents($f)), "…and changes nothing");
assert_contains('已经有', $err, "…and says why");

// ── empty Unreleased ──
echo "\n--- empty Unreleased ---\n";
$empty = "# Changelog\n\n## [Unreleased]\n\n## [1.2.2] — 2026-01-01\n\n- old\n";
$f2 = $tmp . '/empty.md';
fixture($f2, $empty);
[$out, $rc] = runCut($script, '1.2.3 --date=2026-02-02 --file=' . escapeshellarg($f2), $err);
assert_equals(3, $rc, "an empty Unreleased is exit 3 without --allow-empty");
assert_equals($empty, (string) file_get_contents($f2), "…and the file is unchanged");

[$out, $rc] = runCut($script, '1.2.3 --date=2026-02-02 --file=' . escapeshellarg($f2) . ' --allow-empty', $err);
assert_equals(0, $rc, "--allow-empty lets the release finish");
assert_contains('- No user-facing changes documented for this release.', (string) file_get_contents($f2),
    "…with a factual placeholder, not invented notes");

// ── note + dry-run ──
echo "\n--- note / dry-run ---\n";
$f3 = $tmp . '/note.md';
fixture($f3, $base);
$before = md5((string) file_get_contents($f3));
[$out, $rc] = runCut($script, '1.2.3 --date=2026-02-02 --file=' . escapeshellarg($f3)
    . ' --note="first line' . "\n" . '第二行 — 关系" --dry-run', $err);
assert_equals(0, $rc, "dry-run exits 0");
assert_equals($before, md5((string) file_get_contents($f3)), "dry-run does not write");
assert_contains('## [1.2.3] — 2026-02-02', $out, "dry-run prints the new section");
assert_contains('> first line', $out, "the note is a blockquote");
assert_contains('> 第二行 — 关系', $out, "multi-line notes keep their lines (UTF-8 intact)");
assert_equals(true, mb_check_encoding($out, 'UTF-8'),
    "the output is valid UTF-8 — \\R without /u splits on 0x85, a continuation byte in 关系");

// ── bad input ──
echo "\n--- bad input ---\n";
[$out, $rc] = runCut($script, 'not-a-version --file=' . escapeshellarg($f3), $err);
assert_equals(2, $rc, "an invalid version is exit 2");
assert_equals($before, md5((string) file_get_contents($f3)), "…and writes nothing");

// ── the real CHANGELOG, dry-run only ──
echo "\n--- real CHANGELOG (dry-run) ---\n";
$real = (string) file_get_contents($repo . '/CHANGELOG.md');
$realBullets = preg_match_all('/^- /m', substr($real, strpos($real, '## [Unreleased]'),
    strpos($real, '## [4.12.2]') - strpos($real, '## [Unreleased]')));
[$out, $rc] = runCut($script, '9.9.9 --date=2026-12-31 --file=' . escapeshellarg($repo . '/CHANGELOG.md') . ' --dry-run', $err);
assert_equals(0, $rc, "the real CHANGELOG cuts cleanly");
assert_contains('## [9.9.9] — 2026-12-31', $out, "…into the requested section");
$cutBullets = preg_match_all('/^- /m', substr($out, strpos($out, '## [9.9.9]'),
    strpos($out, '## [4.12.2]') - strpos($out, '## [9.9.9]')));
assert_equals($realBullets, $cutBullets, "all {$realBullets} entries moved, none lost");
assert_equals($real, (string) file_get_contents($repo . '/CHANGELOG.md'), "the real file was not touched");

// ── cleanup ──
@unlink($tmp . '/changelog.md'); @unlink($tmp . '/empty.md'); @unlink($tmp . '/note.md');
@rmdir($tmp);

exit(test_summary());
