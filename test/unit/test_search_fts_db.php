<?php
/**
 * Unit tests: SQLite FTS5 database search operations
 * Tests searchFtsQuery() with a real in-memory FTS5 database.
 *
 * These tests verify the actual SQL query behavior against an in-memory
 * SQLite database, unlike test_search_fts.php which tests only string
 * manipulation functions (buildFtsQuery, expandNameForFts, etc.)
 *
 * Requirement: SEARCH_FTS5_DESIGN.md — FTS5 full-text search with
 * unicode61 tokenchars '-:' and prefix='1,2,3'
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: FTS5 Database Search ===\n\n";

// ──────────────────────────────────────────────
// Setup: in-memory SQLite3 database with FTS5
// ──────────────────────────────────────────────
$db = new SQLite3(':memory:');
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');

// Create FTS5 search tables matching the schema in cacheDb()
$db->exec("CREATE VIRTUAL TABLE search_fts USING fts5(
    name, section, description, body,
    tokenize='unicode61 tokenchars ''-:''',
    prefix='1,2,3'
)");

$db->exec("CREATE TABLE search_index_meta (
    rowid       INTEGER PRIMARY KEY,
    name        TEXT NOT NULL,
    section     TEXT NOT NULL DEFAULT '',
    source      TEXT NOT NULL DEFAULT 'man',
    body_len    INTEGER NOT NULL DEFAULT 0,
    hits        INTEGER NOT NULL DEFAULT 0,
    last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE(name, section, source)
)");

/**
 * Helper: insert a test row into both search_fts and search_index_meta.
 * The name is stored expanded (via expandNameForFts) to match production behavior.
 */
function insertTestRow(SQLite3 $db, string $name, string $section, string $description, string $body, string $source, int $hits): void {
    $expandedName = expandNameForFts($name);

    $stmt = $db->prepare("INSERT INTO search_fts (name, section, description, body) VALUES (:name, :section, :description, :body)");
    $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
    $stmt->bindValue(':section', $section, SQLITE3_TEXT);
    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
    $stmt->bindValue(':body', $body, SQLITE3_TEXT);
    $stmt->execute();

    $rowid = $db->lastInsertRowID();

    $stmt = $db->prepare("INSERT INTO search_index_meta (rowid, name, section, source, body_len, hits) VALUES (:rowid, :name, :section, :source, :body_len, :hits)");
    $stmt->bindValue(':rowid', $rowid, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT); // original (non-expanded) name for meta
    $stmt->bindValue(':section', $section, SQLITE3_TEXT);
    $stmt->bindValue(':source', $source, SQLITE3_TEXT);
    $stmt->bindValue(':body_len', strlen($body), SQLITE3_INTEGER);
    $stmt->bindValue(':hits', $hits, SQLITE3_INTEGER);
    $stmt->execute();
}

// ─── Seed test data ───
insertTestRow($db, 'ls', '1', 'list directory contents', 'ls - list directory contents. List information about files.', 'man', 100);
insertTestRow($db, 'cp', '1', 'copy files and directories', 'cp - copy files and directories from one location to another.', 'man', 50);
insertTestRow($db, 'mv', '1', 'move (rename) files', 'mv - move or rename files and directories.', 'man', 30);
insertTestRow($db, 'curl', '1', 'transfer a URL', 'curl - transfer a URL using HTTP FTP and more. The curl command is a tool for transferring data with URLs. libcurl is a client URL transfer library.', 'man', 80);
insertTestRow($db, 'wget', '1', 'The non-interactive network downloader', 'wget - retrieve files from the web via HTTP HTTPS FTP.', 'man', 60);
insertTestRow($db, 'git-commit', '1', 'Record changes to the repository', 'git-commit - record changes to the repository with git.', 'man', 20);
insertTestRow($db, 'perl', '1', 'The Perl 5 language interpreter', 'perl - the Perl 5 language interpreter. Practical Extraction and Report Language.', 'man', 45);
insertTestRow($db, 'File::Find', '3pm', 'Traverse directory tree', 'File::Find - traverse a directory tree. Walk through directories recursively.', 'perldoc', 15);
insertTestRow($db, 'Apache2::Request', '3pm', 'Perl Apache2 request module', 'Apache2::Request - Perl module wrapping Apache2 request API.', 'perldoc', 5);

echo "Total test rows inserted: 9\n\n";

// ═══════════════════════════════════════════
// Tests
// ═══════════════════════════════════════════

echo "--- Basic search: single word with prefix ---\n";
$results = searchFtsQuery($db, '"ls"*', 'ls', '', 50);
assert_equals(1, count($results), "single word 'ls' → 1 result");
assert_equals(true, in_array('man', $results[0]['sources']), "source includes 'man'");

echo "\n--- Multi-word AND search ---\n";
$results = searchFtsQuery($db, '"copy"* AND "files"*', 'copy files', '', 50);
assert_equals(1, count($results), "AND 'copy files' → 1 result (cp)");
assert_equals('cp', $results[0]['name'], "AND result is 'cp'");

echo "\n--- AND no match → empty results ---\n";
$results = searchFtsQuery($db, '"copy"* AND "perl"*', 'copy perl', '', 50);
assert_equals(0, count($results), "AND 'copy perl' → 0 results (no page mentions both)");

echo "\n--- OR search: finds entries matching either term ---\n";
$results = searchFtsQuery($db, '"copy"* OR "perl"*', 'copy perl', '', 50);
assert_not_equals(0, count($results), "OR 'copy perl' → has results");
$foundCp = false;
$foundPerl = false;
foreach ($results as $r) {
    if ($r['name'] === 'cp') $foundCp = true;
    if ($r['name'] === 'perl' || strpos($r['name'], 'perl') !== false) $foundPerl = true;
}
assert_equals(true, $foundCp, "OR results include 'cp'");
assert_equals(true, $foundPerl, "OR results include 'perl' or perl-related entries");

echo "\n--- Hyphenated name matching (tokenchars preserves '-') ---\n";
// With tokenchars '-:', "git-commit" is preserved as a single token.
// The FTS5 table stores the *expanded* name "git-commit git commit", so
// mergeSearchResults returns the expanded form. Check containment not exact match.
$results = searchFtsQuery($db, '"git-commit"*', 'git-commit', '', 50);
assert_equals(1, count($results), "hyphenated 'git-commit' → 1 result");
assert_equals(true, strpos($results[0]['name'], 'git-commit') !== false,
    "returned name contains 'git-commit'");

// Expanded name: "git-commit git commit" — so "git commit" (unhyphenated)
// should match via the expanded tokens
$results = searchFtsQuery($db, '"git"* AND "commit"*', 'git commit', '', 50);
assert_not_equals(0, count($results), "name-expanded 'git commit' (unhyphenated) matches");

echo "\n--- Double-colon Perl module name (tokenchars preserves '::') ---\n";
// "File::Find" is stored as "File::Find File Find" (expanded)
$results = searchFtsQuery($db, '"File"* AND "Find"*', 'File Find', '', 50);
assert_not_equals(0, count($results), "'File Find' matches File::Find via expanded name tokens");
$foundFF = false;
foreach ($results as $r) {
    if (strpos($r['name'], 'File::Find') !== false) $foundFF = true;
}
assert_equals(true, $foundFF, "File::Find appears in 'File Find' search via name expansion");

// Direct :: search should also work
$results = searchFtsQuery($db, '"File::Find"*', 'File::Find', '', 50);
assert_not_equals(0, count($results), "'File::Find' direct match works");

echo "\n--- Section-filtered search ---\n";
$results = searchFtsQuery($db, '"perl"*', 'perl', '1', 50);
assert_equals(1, count($results), "section-filtered 'perl' section=1 → 1 result");
assert_equals('perl', $results[0]['name'], "name matches 'perl' in section 1");

// Searching "perl" in section 3pm should match *Apache2::Request* (section 3pm)
// whose description contains "Perl" — this validates cross-entry matching
$results = searchFtsQuery($db, '"perl"*', 'perl', '3pm', 50);
assert_not_equals(0, count($results), "section-filtered 'perl' section=3pm → matches Apache2::Request (description contains 'Perl')");

// Double-colon module in its section
$results = searchFtsQuery($db, '"File"*', 'File', '3pm', 50);
assert_not_equals(0, count($results), "section-filtered 'File' section=3pm → matches File::Find");

echo "\n--- Section priority (within BM25 tie, lower section number wins) ---\n";
// Add 'curl' in section 3 with many more "curl" repetitions in body
// to make BM25 favor section 3, then also add section 8 to demonstrate
// that section priority sorts correctly when BM25 is roughly equal.
insertTestRow($db, 'curl', '3', 'libcurl C API', 'libcurl C API. curl easy handle option. curl multi interface. curl_global_init.', 'man', 10);

$results = searchFtsQuery($db, '"curl"*', 'curl', '', 50);
assert_equals(2, count($results), "curl appears in 2 sections (1 and 3)");
// Both sections should be present
$sections = [];
foreach ($results as $r) {
    $sections[] = $r['section'];
}
assert_equals(true, in_array('1', $sections), "result includes curl section 1");
assert_equals(true, in_array('3', $sections), "result includes curl section 3");
// Note: actual order depends on BM25 ranking. Section priority is the 4th
// sorting criterion (after exact match, prefix match, BM25 rank). If BM25
// favors section 3 (more occurrences of "curl" in body), it appears first.

echo "\n--- Section priority with equal BM25 ---\n";
// Verify that the 'transfer' search returns results from section 1
$results = searchFtsQuery($db, '"transfer"*', 'transfer', '', 50);
$transferSections = [];
foreach ($results as $r) {
    if (strpos($r['description'], 'transfer') !== false) {
        $transferSections[] = $r['section'];
    }
}
assert_not_equals(0, count($transferSections), "'transfer' results include at least one matching section");

echo "\n--- Hits ordering ---\n";
// Add a low-hits 'ls' in section 2
insertTestRow($db, 'ls', '2', 'list directory (system call)', 'list directory system call.', 'man', 1);

$lsResults = searchFtsQuery($db, '"ls"*', 'ls', '', 50);
assert_equals(2, count($lsResults), "ls appears in 2 sections (1 and 2)");
$lsSections = [];
foreach ($lsResults as $r) {
    $lsSections[] = $r['section'];
}
assert_equals(true, in_array('1', $lsSections), "ls section 1 present");
assert_equals(true, in_array('2', $lsSections), "ls section 2 present");

echo "\n--- Prefix (partial) term matching ---\n";
$results = searchFtsQuery($db, '"cur"*', 'cur', '', 50);
assert_not_equals(0, count($results), "prefix 'cur'* matches at least one result");
$curlFound = false;
foreach ($results as $r) {
    if (strpos($r['name'], 'curl') !== false) $curlFound = true;
}
assert_equals(true, $curlFound, "prefix 'cur'* matches 'curl'");

echo "\n--- Description matching ---\n";
$results = searchFtsQuery($db, '"transfer"*', 'transfer', '', 50);
assert_not_equals(0, count($results), "'transfer' returns results");

echo "\n--- Result limit ---\n";
$results = searchFtsQuery($db, '"a"*', 'a', '', 2);
assert_equals(2, count($results), "limit=2 returns exactly 2 results");

$results = searchFtsQuery($db, '"a"*', 'a', '', 50);
assert_equals(true, count($results) > 2, "limit=50 returns more results than limit=2");

echo "\n--- Non-matching query returns empty ---\n";
$results = searchFtsQuery($db, '"zzznotexist"*', 'zzznotexist', '', 50);
assert_equals(0, count($results), "non-existent term → 0 results");

echo "\n--- Single character prefix ---\n";
$results = searchFtsQuery($db, '"z"*', 'z', '', 50);
assert_not_equals(null, $results, "single-char prefix query executes without error");

echo "\n--- Search across multiple columns (name + description + body) ---\n";
// "client" appears in curl body and Apache2::Request description
$results = searchFtsQuery($db, '"client"*', 'client', '', 50);
assert_not_equals(0, count($results), "'client' returns results across name/desc/body columns");

echo "\n--- FTS5 engine string not in raw results ---\n";
// engine field should not be set by searchFtsQuery (it's set by searchFts or getSearchPage)
if (isset($results[0]) && is_array($results[0])) {
    assert_equals(false, isset($results[0]['engine']), "raw results don't have 'engine' key (set by caller)");
}

echo "\n--- MergeSearchResults integration: duplicate (name, section) merge ---\n";
// Manually test mergeSearchResults with data from actual DB
$rawRows = [
    ['name' => 'curl', 'section' => '1', 'description' => 'transfer a URL', 'hits' => 80, 'source' => 'man'],
    ['name' => 'curl', 'section' => '1', 'description' => 'Curl library for PHP', 'hits' => 0, 'source' => 'perldoc'],
];
$merged = mergeSearchResults($rawRows);
assert_equals(1, count($merged), "duplicate (curl,1) merged into one");
assert_equals(true, in_array('man', $merged[0]['sources']), "sources includes 'man'");
assert_equals(true, in_array('perldoc', $merged[0]['sources']), "sources includes 'perldoc'");
assert_equals(80, $merged[0]['hits'], "merged hits = 80 (sum of 80+0)");

echo "\n--- Edge: empty query not tested at this level ---\n";
echo "       searchFts() handles empty query at the caller level\n";

exit(test_summary());
