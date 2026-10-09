<?php
declare(strict_types=1);
/**
 * Integration tests: rebuildSearchIndex() atomicity and the empty-index guard
 *
 * The rebuild used to DELETE search_fts and search_index_meta *before*
 * BEGIN IMMEDIATE, so an interrupted run left both empty with nothing to roll
 * back to — search_fts_old was removed in v4.9.25, and an empty
 * search_index_meta also breaks resolveManSection(), so the damage reached
 * beyond search. Recovery meant re-running the long rebuild that had just been
 * interrupted.
 *
 * The clears now sit inside the transaction, and a rebuild that indexes nothing
 * is refused instead of committed. Both properties are exercised here for real:
 * the function runs against a throwaway PHPMAN_HOME with the three data sources
 * (apropos, pydoc3, ri) stubbed out of PATH, which makes "indexed nothing"
 * deterministic without needing a network or a man database.
 */
$tmpHome = sys_get_temp_dir() . '/phpman-rebuild-test-' . getmypid();
$stubDir = $tmpHome . '-bin';
@mkdir($tmpHome, 0755, true);
@mkdir($stubDir, 0755, true);
foreach (['apropos', 'pydoc3', 'ri'] as $bin) {
    file_put_contents($stubDir . '/' . $bin, "#!/bin/sh\nexit 0\n");
    chmod($stubDir . '/' . $bin, 0755);
}
// Must be set before phpMan.php is loaded: config.php resolves PHPMAN_HOME then.
putenv('PHPMAN_HOME=' . $tmpHome);
putenv('PATH=' . $stubDir . PATH_SEPARATOR . getenv('PATH'));

define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: search index rebuild atomicity ===\n";

$db = cacheDb();
assert_not_equals(null, $db, "throwaway central DB is available");

echo "\n--- seed an index that must survive a refused rebuild ---\n";
$db->exec("INSERT INTO search_fts (name, section, description, body) VALUES ('seedcmd', '1', 'seeded entry', '')");
$db->exec("INSERT INTO search_index_meta (name, section, source, body_len) VALUES ('seedcmd', '1', 'man', 0)");
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_index_meta"), "seeded 1 meta row");
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_fts"), "seeded 1 FTS row");

echo "\n--- a rebuild that indexes nothing is refused, not committed ---\n";
$result = rebuildSearchIndex();
assert_contains("empty index", $result, "result says the index was empty");
assert_contains("unchanged", $result, "result says the existing index is unchanged");

echo "\n--- and the existing index is intact (the rollback undid the clears) ---\n";
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_index_meta"),
    "search_index_meta still holds the seeded row");
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_fts"),
    "search_fts still holds the seeded row");

// cleanup — temp home and stub bin only
foreach (['apropos', 'pydoc3', 'ri'] as $bin) {
    @unlink($stubDir . '/' . $bin);
}
@rmdir($stubDir);
foreach (glob($tmpHome . '/db/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmpHome . '/db');
foreach (glob($tmpHome . '/logs/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmpHome . '/logs');
@rmdir($tmpHome);

exit(test_summary());
