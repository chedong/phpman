<?php
declare(strict_types=1);
/**
 * Integration tests: the search index must not store a constant description (#280)
 *
 * `MATCH` searches the description column along with the name — that is what
 * makes the endpoint behave like `apropos` ("copy files" finds cp(1) through
 * its one-line summary). It also means a description shared by every row of a
 * source is a trap: pydoc stored "Python 3 module" for all 330 modules and ri
 * stored "Ruby class/module" for all 3,699 classes, so `/search/python` matched
 * every pydoc row and `/search/module` matched every pydoc *and* ri row.
 *
 * The listing commands behind those two sources (`pydoc3 modules`, `ri -l`)
 * carry names only — there is no summary to store — so the honest value is ''.
 * This test runs the real rebuild against stubbed sources and asserts that the
 * index holds no constant, and that a search for the old constant finds nothing.
 *
 * The three source commands are stubbed out of PATH, as in
 * test_search_rebuild_atomicity.php: no man database and no network needed.
 */
$tmpHome = sys_get_temp_dir() . '/phpman-descr-test-' . getmypid();
$stubDir = $tmpHome . '-bin';
@mkdir($tmpHome, 0755, true);
@mkdir($stubDir, 0755, true);

// No man pages: the sources under test are pydoc and ri, and an empty apropos
// keeps the rebuild fast. The rebuild only refuses to commit a *totally* empty
// index, and pydoc + ri supply four rows.
file_put_contents($stubDir . '/apropos', "#!/bin/sh\nexit 0\n");

file_put_contents($stubDir . '/pydoc3', <<<'SH'
#!/bin/sh
if [ "$1" = "modules" ]; then
  printf '\nPlease wait a moment while I gather a list of all available modules...\n\njson              os\n'
  exit 0
fi
exit 1
SH
);

file_put_contents($stubDir . '/ri', <<<'SH'
#!/bin/sh
if [ "$1" = "-l" ]; then
  printf 'Array\nHash\n'
  exit 0
fi
exit 1
SH
);

foreach (['apropos', 'pydoc3', 'ri'] as $bin) {
    chmod($stubDir . '/' . $bin, 0755);
}
// Must be set before phpMan.php is loaded: config.php resolves PHPMAN_HOME then.
putenv('PHPMAN_HOME=' . $tmpHome);
putenv('PATH=' . $stubDir . PATH_SEPARATOR . getenv('PATH'));

define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Integration: search index descriptions (#280) ===\n";

$db = cacheDb();
assert_not_equals(null, $db, "throwaway central DB is available");

echo "\n--- a rebuild over the stubbed sources ---\n";
$result = rebuildSearchIndex();
assert_equals(2, (int)$db->querySingle("SELECT COUNT(*) FROM search_index_meta WHERE source = 'pydoc'"),
    "pydoc indexed both stub modules (result: " . trim($result) . ")");
assert_equals(2, (int)$db->querySingle("SELECT COUNT(*) FROM search_index_meta WHERE source = 'ri'"),
    "ri indexed both stub classes");

echo "\n--- pydoc and ri store no description ---\n";
$pydocDescs = [];
$res = $db->query("SELECT DISTINCT description FROM search_fts WHERE section = 'pydoc'");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $pydocDescs[] = (string)$row['description']; }
assert_equals([''], $pydocDescs, "every pydoc row has description '' — no constant to match");

$riDescs = [];
$res = $db->query("SELECT DISTINCT description FROM search_fts WHERE section = 'ri'");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $riDescs[] = (string)$row['description']; }
assert_equals([''], $riDescs, "every ri row has description '' — no constant to match");

echo "\n--- a search for the old constants finds nothing ---\n";
// Each of these occurred in *every* row of its source before this change.
assert_equals([], searchFtsBySource('python', 'pydoc', 'json'),
    "'python' no longer matches every pydoc row through 'Python 3 module'");
assert_equals([], searchFtsBySource('module', 'ri', 'json'),
    "'module' no longer matches every ri row through 'Ruby class/module'");
assert_equals([], searchFtsBySource('ri', 'ri', 'json'),
    "'ri' no longer matches the ri section column");

echo "\n--- and the names are still findable ---\n";
$json = searchFtsBySource('json', 'pydoc', 'json');
assert_equals(1, count($json), "'json' finds the json module");
assert_equals('json', $json[0]['name'] ?? null, "the result is named json");
assert_equals('', $json[0]['description'] ?? null, "with an empty description, so the UI prints no dash");

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
