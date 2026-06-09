<?php
/**
 * Unit tests: PageCache class — set/get, TTL, hit counter, FTS sync, clear/stats
 * Requirement: Issue #101 — PageCache has zero test coverage
 *
 * Uses a temporary SQLite file to avoid polluting the production cache.
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';

// Override PHPMAN_CACHE_DIR before loading phpMan.php so cacheDb() uses a temp path
$tmpDir = sys_get_temp_dir() . '/phpman_test_cache_' . getmypid();
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
define('PHPMAN_CACHE_DIR', $tmpDir);

require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: PageCache ===\n\n";

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────
function cleanupTmpDir(): void {
    global $tmpDir;
    foreach (glob($tmpDir . '/*') as $f) @unlink($f);
    @rmdir($tmpDir);
}

cleanupTmpDir();

// ─── Basic set/get ───
echo "--- set() and get() basic round-trip ---\n";
$cache = new PageCache();

$ok = $cache->set('man', 'ls', '1', 'html', '<h1>ls</h1>');
assert_equals(true, $ok, "set() returns true");

$result = $cache->get('man', 'ls', '1', 'html');
assert_equals('<h1>ls</h1>', $result, "get() returns exact content that was set");

echo "\n--- get() cache miss ---\n";
$miss = $cache->get('man', 'nonexistent', '1', 'html');
assert_equals(null, $miss, "get() returns null for missing entry");

echo "\n--- set() with different formats ---\n";
$cache->set('man', 'ls', '1', 'html', '<p>HTML version</p>');
$cache->set('man', 'ls', '1', 'markdown', '# Markdown version');

$htmlResult = $cache->get('man', 'ls', '1', 'html');
$mdResult = $cache->get('man', 'ls', '1', 'markdown');
assert_equals('<p>HTML version</p>', $htmlResult, "HTML format stored and retrieved correctly");
assert_equals('# Markdown version', $mdResult, "Markdown format stored and retrieved correctly");

echo "\n--- set() with different sections ---\n";
$cache->set('man', 'printf', '1', 'html', 'printf(1)');
$cache->set('man', 'printf', '3', 'html', 'printf(3)');

$s1 = $cache->get('man', 'printf', '1', 'html');
$s3 = $cache->get('man', 'printf', '3', 'html');
assert_equals('printf(1)', $s1, "section 1 stored separately");
assert_equals('printf(3)', $s3, "section 3 stored separately");

echo "\n--- set() with different modes ---\n";
$cache->set('man', 'open', '2', 'html', 'man open');
$cache->set('perldoc', 'open', '2', 'html', 'perldoc open');

$manResult = $cache->get('man', 'open', '2', 'html');
$perldocResult = $cache->get('perldoc', 'open', '2', 'html');
assert_equals('man open', $manResult, "man mode stored separately");
assert_equals('perldoc open', $perldocResult, "perldoc mode stored separately");

// ─── not_found status ───
echo "\n--- set() with not_found status ---\n";
$cache->set('man', 'zzznoexist', '1', 'html', '', 'not_found');
$notFound = $cache->get('man', 'zzznoexist', '1', 'html');
assert_equals('###NOT_FOUND###', $notFound, "not_found entry returns sentinel value");

// ─── Overwrite (INSERT OR REPLACE) ───
echo "\n--- set() overwrites existing entry ---\n";
$cache->set('man', 'bash', '1', 'html', 'version 1');
$cache->set('man', 'bash', '1', 'html', 'version 2');
$overwritten = $cache->get('man', 'bash', '1', 'html');
assert_equals('version 2', $overwritten, "second set() overwrites first value");

// ─── Hit counter ───
echo "\n--- Hit counter increments on get() ---\n";
$cache->set('man', 'grep', '1', 'html', 'grep content');
$cache->get('man', 'grep', '1', 'html');
$cache->get('man', 'grep', '1', 'html');
$cache->get('man', 'grep', '1', 'html');

$db = cacheDb();
$hitsRow = $db->querySingle("SELECT hits FROM cache WHERE mode='man' AND name='grep' AND section='1'", false);
assert_equals(3, (int)$hitsRow, "hit counter incremented to 3 after 3 get() calls");

echo "\n--- Hit counter preserved across set() overwrite ---\n";
$cache->set('man', 'grep', '1', 'html', 'new grep content');
$hitsAfterOverwrite = $db->querySingle("SELECT hits FROM cache WHERE mode='man' AND name='grep' AND section='1'", false);
assert_equals(3, (int)$hitsAfterOverwrite, "hits preserved after INSERT OR REPLACE (COALESCE subquery)");

// ─── TTL ───
echo "\n--- TTL: not_found entries have 1-day TTL ---\n";
$cache->set('man', 'expired_cmd', '1', 'html', '', 'not_found');
$ttlRow = $db->querySingle("SELECT ttl FROM cache WHERE mode='man' AND name='expired_cmd' AND section='1'", false);
assert_equals(86400, (int)$ttlRow, "not_found entry TTL = 86400 (1 day)");

echo "\n--- TTL: found entries have 7-day TTL ---\n";
$cache->set('man', 'valid_cmd', '1', 'html', 'some content', 'found');
$ttlFound = $db->querySingle("SELECT ttl FROM cache WHERE mode='man' AND name='valid_cmd' AND section='1'", false);
assert_equals(604800, (int)$ttlFound, "found entry TTL = 604800 (7 days)");

echo "\n--- TTL: search not_found entries have 7-day TTL ---\n";
$cache->set('search', 'noresults', '', 'html', '', 'not_found');
$ttlSearch = $db->querySingle("SELECT ttl FROM cache WHERE mode='search' AND name='noresults'", false);
assert_equals(604800, (int)$ttlSearch, "search not_found entry TTL = 604800 (7 days)");

// ─── delete() ───
echo "\n--- delete() removes specific mode/name/section ---\n";
$cache->set('man', 'rm', '1', 'html', 'rm content');
$cache->set('man', 'rm', '1', 'markdown', '# rm');
$cache->delete('man', 'rm', '1');
$deleted = $cache->get('man', 'rm', '1', 'html');
assert_equals(null, $deleted, "delete() removes all formats for mode/name/section");

// ─── clear() ───
echo "\n--- clear() removes all entries ---\n";
$cache->set('man', 'cat', '1', 'html', 'cat');
$cache->set('man', 'dog', '1', 'html', 'dog');
$cache->clear();
$catResult = $cache->get('man', 'cat', '1', 'html');
$dogResult = $cache->get('man', 'dog', '1', 'html');
assert_equals(null, $catResult, "clear() removes cat entry");
assert_equals(null, $dogResult, "clear() removes dog entry");

// ─── stats() ───
echo "\n--- stats() returns correct counts ---\n";
cleanupTmpDir();
// Force fresh DB by creating new PageCache
$cache = new PageCache();
$cache->set('man', 'ls', '1', 'html', 'ls content');
$cache->set('man', 'cp', '1', 'html', 'cp content');
$cache->set('man', 'missing', '1', 'html', '', 'not_found');

$stats = $cache->stats();
assert_equals(3, $stats['total'], "stats total = 3 entries");
assert_equals(2, $stats['found'], "stats found = 2");
assert_equals(1, $stats['not_found'], "stats not_found = 1");
assert_equals(true, $stats['db_size'] >= 0, "stats db_size is non-negative");

// ─── FTS sync on set() ───
echo "\n--- FTS sync: found entries create cache_fts rows ---\n";
cleanupTmpDir();
$cache = new PageCache();
$cache->set('man', 'ftstest', '1', 'html', 'FTS sync test content');

$db = cacheDb();
// cache_fts is a content table (content='cache'), so direct SELECT reads from
// the cache table. Verify via the FTS5 rowid mapping instead.
$ftsRow = $db->querySingle("SELECT COUNT(*) FROM cache_fts WHERE cache_fts MATCH 'ftstest'", false);
assert_equals(1, (int)$ftsRow, "cache_fts has entry after set() with found status (MATCH query)");

echo "\n--- FTS sync: not_found entries skip FTS ---\n";
$cache->set('man', 'ftsmissing', '1', 'html', '', 'not_found');
$ftsMissing = $db->querySingle("SELECT COUNT(*) FROM cache_fts WHERE cache_fts MATCH 'ftsmissing'", false);
assert_equals(0, (int)$ftsMissing, "cache_fts has no entry for not_found (MATCH query)");

echo "\n--- FTS sync: search mode skips FTS ---\n";
$cache->set('search', 'testsearch', '', 'html', 'search content');
$ftsSearch = $db->querySingle("SELECT COUNT(*) FROM cache_fts WHERE cache_fts MATCH 'testsearch'", false);
assert_equals(0, (int)$ftsSearch, "cache_fts has no entry for search mode (MATCH query)");

// ─── Compression round-trip ───
echo "\n--- Content compression round-trip ---\n";
$longContent = str_repeat("This is a test string for compression. ", 100);
$cache->set('man', 'longpage', '1', 'html', $longContent);
$retrieved = $cache->get('man', 'longpage', '1', 'html');
assert_equals($longContent, $retrieved, "long content survives compression round-trip");

// ─── Special characters in content ───
echo "\n--- Special characters in content ---\n";
$specialContent = "Test &amp; <html> \"quotes\" 'apostrophes' \t tabs \n newlines";
$cache->set('man', 'special', '1', 'html', $specialContent);
$specialResult = $cache->get('man', 'special', '1', 'html');
assert_equals($specialContent, $specialResult, "special characters preserved in cache");

// Clean up
cleanupTmpDir();

exit(test_summary());
