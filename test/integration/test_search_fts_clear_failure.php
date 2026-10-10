<?php
declare(strict_types=1);
/**
 * Integration tests: a failing search_fts clear must abort the rebuild (#242)
 *
 * rebuildSearchIndex() clears both tables inside one transaction. The
 * search_fts DELETE used to sit inside `catch (\Throwable $ignored) {}` while
 * the search_index_meta DELETE two lines below it did not. When the FTS clear
 * failed, the rebuild carried on with meta empty — so every `INSERT OR IGNORE`
 * found no conflict, `$isNew` was true for every entry, and each entry was
 * written a second time on top of the FTS rows that had survived. Permanently,
 * because search_fts is an FTS5 table and has no UNIQUE constraint to stop it.
 * Nothing logged it, nothing returned an error, and the empty-index guard could
 * not see it because that guard counts meta.
 *
 * A corrupt FTS5 index is not reproducible from a test, so the failure is
 * injected structurally instead: search_fts is replaced by a plain table whose
 * BEFORE DELETE trigger RAISE(ABORT)s. The rebuild cannot tell the difference —
 * its `sqlite_master` precheck looks for a *table* of that name, and
 * `CREATE VIRTUAL TABLE IF NOT EXISTS` over an existing name is a silent no-op —
 * so the DELETE fails exactly where a corrupt FTS index would.
 *
 * The second half covers the count invariant that sits beside the empty-index
 * guard. When it is the FTS *INSERT* that fails, the per-entry catch counts the
 * error and keeps going, which leaves search_fts holding fewer rows than
 * search_index_meta. The rebuild must refuse that too, rather than commit an
 * index whose two halves disagree.
 */
$tmpHome = sys_get_temp_dir() . '/phpman-fts-clear-test-' . getmypid();
$stubDir = $tmpHome . '-bin';
@mkdir($tmpHome, 0755, true);
@mkdir($stubDir, 0755, true);
// apropos emits one parseable line so the second half has something to index;
// pydoc3 and ri stay silent so only the man path contributes entries.
file_put_contents($stubDir . '/apropos', "#!/bin/sh\necho 'stubcmd (1) - stubbed manual page'\n");
file_put_contents($stubDir . '/pydoc3', "#!/bin/sh\nexit 0\n");
file_put_contents($stubDir . '/ri', "#!/bin/sh\nexit 0\n");
foreach (['apropos', 'pydoc3', 'ri'] as $bin) {
    chmod($stubDir . '/' . $bin, 0755);
}
// Must be set before phpMan.php is loaded: config.php resolves PHPMAN_HOME then.
putenv('PHPMAN_HOME=' . $tmpHome);
putenv('PATH=' . $stubDir . PATH_SEPARATOR . getenv('PATH'));

define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: search_fts clear failure (#242) ===\n";

$db = cacheDb();
assert_not_equals(null, $db, "throwaway central DB is available");

echo "\n--- a failing FTS clear aborts the rebuild ---\n";
$db->exec("INSERT INTO search_index_meta (name, section, source, body_len) VALUES ('seedcmd', '1', 'man', 0)");
$db->exec("DROP TABLE search_fts");
$db->exec("CREATE TABLE search_fts (name TEXT, section TEXT, description TEXT, body TEXT)");
$db->exec("INSERT INTO search_fts VALUES ('seedcmd', '1', 'seeded entry', '')");
$db->exec("CREATE TRIGGER fts_clear_boom BEFORE DELETE ON search_fts
           BEGIN SELECT RAISE(ABORT, 'simulated FTS5 clear failure'); END");
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_index_meta"), "seeded 1 meta row");
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_fts"), "seeded 1 FTS row");

$result = rebuildSearchIndex();
assert_contains("ERROR", $result, "a failing FTS clear is reported as ERROR, not swallowed");
assert_contains("simulated FTS5 clear failure", $result, "the underlying failure reaches the caller");
assert_not_contains("Cleared existing search index", $result, "the rebuild never got past the clear");
assert_not_contains("Done.", $result, "the rebuild did not commit");

echo "\n--- and the existing index survived the rollback ---\n";
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_index_meta"),
    "search_index_meta still holds the seeded row");
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_fts"),
    "search_fts still holds the seeded row");

echo "\n--- an FTS insert that fails leaves fts < meta, which is refused too ---\n";
$db->exec("DROP TRIGGER fts_clear_boom");
$db->exec("DROP TABLE search_fts");
$db->exec("CREATE TABLE search_fts (name TEXT, section TEXT, description TEXT, body TEXT)");
$db->exec("CREATE TRIGGER fts_insert_boom BEFORE INSERT ON search_fts
           BEGIN SELECT RAISE(ABORT, 'simulated FTS5 insert failure'); END");

$result = rebuildSearchIndex();
assert_contains("inconsistent index", $result, "the count invariant refuses the commit");
assert_contains("search_fts=0", $result, "the error names the FTS row count");
assert_not_contains("Done.", $result, "the inconsistent rebuild did not commit");

echo "\n--- and that rollback preserved the previous index as well ---\n";
assert_equals(1, (int)$db->querySingle("SELECT COUNT(*) FROM search_index_meta"),
    "search_index_meta still holds only the seeded row");

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
