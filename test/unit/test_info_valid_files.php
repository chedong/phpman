<?php
/**
 * Unit tests: getValidInfoFiles() info directory discovery
 *
 * Requirement: #234 — the function hardcoded /usr/share/info, so on any host
 * where info lives elsewhere (MacPorts /opt/local/share/info, Homebrew
 * /usr/local/share/info, anything INFOPATH points at) it returned []. That is
 * not a graceful degradation: getInfoIndex()'s JSON/MCP branch *drops* entries
 * it cannot resolve, so an empty set empties the whole /info index.
 *
 * Design notes:
 * - Every case runs in a fresh subprocess: the function reads the environment
 *   (INFOPATH) and shells out, and the test runner's own INFOPATH must not
 *   decide a result.
 * - Nothing asserts a hardcoded system path. /usr/share/info does not exist on
 *   a MacPorts box and /opt/local/share/info does not exist on the server, so a
 *   test naming either would only pass on the machine it was written on. The
 *   discovery assertions are computed from what the environment actually has.
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

$SOURCE_INFO = realpath(__DIR__ . '/../../src/source_info.php');

/**
 * Run getValidInfoFiles() in a fresh process and return the names as a sorted list.
 * $infopath === null leaves INFOPATH unset entirely.
 */
function runValidInfoFiles(?string $infopath): array {
    global $SOURCE_INFO;
    $code = 'require ' . var_export($SOURCE_INFO, true) . ';'
          . ' echo implode("\n", array_keys(getValidInfoFiles()));';
    $cmd = $infopath === null ? 'env -u INFOPATH' : 'env INFOPATH=' . escapeshellarg($infopath);
    $cmd .= ' php -r ' . escapeshellarg($code) . ' 2>/dev/null';
    $out = trim((string)shell_exec($cmd));
    if ($out === '') return [];
    $names = array_filter(explode("\n", $out), fn($n) => $n !== '');
    sort($names);
    return $names;
}

echo "=== Unit: getValidInfoFiles() info directory discovery ===\n\n";

// ─── INFOPATH is honoured ───
echo "--- INFOPATH is honoured ---\n";
$tmpDir = sys_get_temp_dir() . '/phpman_info_' . getmypid();
@mkdir($tmpDir, 0755, true);
file_put_contents($tmpDir . '/foo.info', 'stub');
file_put_contents($tmpDir . '/bar.info-1.gz', 'stub');
// A name that must never appear: proves entries come from real files, not from
// the directory listing or from guessing.
file_put_contents($tmpDir . '/notes.txt', 'not an info file');

$names = runValidInfoFiles($tmpDir);
assert_equals(true, in_array('foo', $names, true), "INFOPATH dir: foo.info → 'foo'");
assert_equals(true, in_array('bar', $names, true), "INFOPATH dir: bar.info-1.gz → 'bar' (split-file suffix stripped)");
assert_equals(false, in_array('notes', $names, true), "INFOPATH dir: non-.info file is not treated as an entry");

// ─── INFOPATH is colon-separated ───
echo "\n--- INFOPATH is colon-separated ---\n";
$tmpDir2 = $tmpDir . '_b';
@mkdir($tmpDir2, 0755, true);
file_put_contents($tmpDir2 . '/baz.info', 'stub');
$names = runValidInfoFiles($tmpDir . PATH_SEPARATOR . $tmpDir2);
assert_equals(true, in_array('foo', $names, true), "first INFOPATH entry is read");
assert_equals(true, in_array('baz', $names, true), "second INFOPATH entry is read");

// ─── A bogus INFOPATH contributes nothing ───
echo "\n--- a bogus INFOPATH invents nothing ---\n";
$names = runValidInfoFiles('/nonexistent/phpman/info/dir');
assert_equals(false, in_array('foo', $names, true), "names from a deleted dir do not persist");
assert_equals(false, in_array('phpman', $names, true), "a nonexistent dir contributes no entries");

// ─── `info --where dir` discovery ───
// This is the branch that covers relocated installs whose prefix is in neither
// compiled-in default.
//
// Both sides must run in the same environment. The expectation used to be
// computed with the ambient INFOPATH while the implementation ran under
// `env -u INFOPATH`, so on a host whose INFOPATH points somewhere other than the
// build prefix the two answered about different installs and the test failed for
// a reason that had nothing to do with the code (macOS: INFOPATH=/opt/homebrew/
// share/info, build prefix /usr/local/share/info). Pin the environment on both
// sides and assert exact equality, so the test is host-independent: on a host
// where the reported directory holds no .info files there is nothing to compare
// and it says so instead of failing.
echo "\n--- info --where dir is consulted ---\n";
$dirFile = trim((string)shell_exec('env -u INFOPATH info --where dir 2>/dev/null'));
// The same directories getValidInfoFiles() consults when INFOPATH is unset:
// the compiled-in defaults, plus wherever this host's `info` keeps its dir.
$dirs = ['/usr/local/share/info', '/usr/share/info'];
if ($dirFile !== '' && is_file($dirFile)) {
    $dirs[] = dirname($dirFile);
}
$expected = [];
foreach (array_unique($dirs) as $dir) {
    foreach (glob($dir . '/*.info*') ?: [] as $f) {
        $n = preg_replace('/\.info.*$/', '', basename($f));
        if ($n !== '' && $n !== null) $expected[$n] = true;
    }
}
$expected = array_keys($expected);
sort($expected);

if ($expected === []) {
    echo "  ⏭  skipped — no .info files in the directories this host reports"
       . " (" . implode(', ', array_unique($dirs)) . ")\n";
} else {
    $names = runValidInfoFiles(null);
    assert_equals($expected, $names,
        "discovery matches the directories the implementation consults (" . count($expected) . " names)");
}

// ─── cleanup ───
foreach (glob($tmpDir . '/*') as $f) @unlink($f);
foreach (glob($tmpDir2 . '/*') as $f) @unlink($f);
@rmdir($tmpDir);
@rmdir($tmpDir2);

exit(test_summary());
