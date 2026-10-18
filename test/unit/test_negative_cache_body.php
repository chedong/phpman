<?php
/**
 * Unit tests: negative cache rows carry their fallback body (phpman#247)
 *
 * The pydoc miss path shells out to `pydoc3 -k` (~10s) and then caches a
 * not_found row. That row used to be written with content = NULL; get() folds
 * not_found to the sentinel, and the caller's `trim($content) == ""` check read
 * that as "nothing cached" — so every request paid the scan again.
 *
 * These tests pin both halves of the fix: getWithStatus() surfaces the body
 * stored on a negative row, and cacheOrSearchFallback() does not call the
 * fallback generator a second time.
 *
 * Uses a temporary SQLite file to avoid polluting the production cache.
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';

$tmpDir = sys_get_temp_dir() . '/phpman_test_negcache_' . getmypid();
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
define('PHPMAN_CACHE_DIR', $tmpDir);

require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: negative cache carries its fallback body (#247) ===\n\n";

function cleanupTmpDir(): void {
    global $tmpDir;
    foreach (glob($tmpDir . '/*') as $f) @unlink($f);
    @rmdir($tmpDir);
    // Same reset as test_page_cache.php: the DB singletons are process-wide
    // statics, so without this the next block keeps writing through handles to
    // deleted inodes.
    cacheDb(true);
    pageCacheDb('', true);
}

cleanupTmpDir();

// ──────────────────────────────────────────────
// getWithStatus()
// ──────────────────────────────────────────────
echo "--- getWithStatus() ---\n";
$cache = new PageCache();

assert_equals(null, $cache->getWithStatus('pydoc', 'absent', '', 'markdown'),
    "getWithStatus() returns null on a miss");

$cache->set('pydoc', 'found_one', '', 'markdown', '# real page');
$hit = $cache->getWithStatus('pydoc', 'found_one', '', 'markdown');
assert_equals('found', $hit['status'], "a found row reports status=found");
assert_equals('# real page', $hit['content'], "a found row returns its content");

$cache->set('pydoc', 'missing_one', '', 'markdown', 'search results body', 'not_found');
$neg = $cache->getWithStatus('pydoc', 'missing_one', '', 'markdown');
assert_equals('not_found', $neg['status'], "a negative row reports status=not_found");
assert_equals('search results body', $neg['content'],
    "a negative row returns the body stored alongside the marker");

assert_equals(CACHE_SENTINEL_NOT_FOUND, $cache->get('pydoc', 'missing_one', '', 'markdown'),
    "get() still folds not_found to the sentinel — its contract is unchanged");

// A row written before #247 has no body at all.
$cache->set('pydoc', 'legacy', '', 'markdown', null, 'not_found');
$legacy = $cache->getWithStatus('pydoc', 'legacy', '', 'markdown');
assert_equals('not_found', $legacy['status'], "a legacy negative row still reports not_found");
assert_equals(null, $legacy['content'], "a legacy negative row has no body");

// ──────────────────────────────────────────────
// cacheOrSearchFallback()
// ──────────────────────────────────────────────
echo "\n--- cacheOrSearchFallback() ---\n";
$calls = ['page' => 0, 'fallback' => 0];
$pageGen = function() use (&$calls) { $calls['page']++; return ''; };
$fbGen   = function() use (&$calls) { $calls['fallback']++; return 'SCAN RESULT'; };

$isFallback = null;
$first = cacheOrSearchFallback('pydoc', 'scanned', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals('SCAN RESULT', $first, "the first call returns the fallback body");
assert_equals(true, $isFallback, "the first call flags isFallback so the caller answers 404");
assert_equals(1, $calls['fallback'], "the first call runs the fallback generator once");

$isFallback = null;
$second = cacheOrSearchFallback('pydoc', 'scanned', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals('SCAN RESULT', $second, "the second call returns the same body from cache");
assert_equals(true, $isFallback, "the second call still flags isFallback — the row is still a miss");
assert_equals(1, $calls['fallback'],
    "the second call did NOT re-run the fallback generator — this is the #247 fix");

$stored = $cache->getWithStatus('pydoc', 'scanned', '', 'markdown');
assert_equals('not_found', $stored['status'], "the reused row is still marked not_found");
assert_equals('SCAN RESULT', $stored['content'], "the negative row stores the fallback body");

// A legacy row (no body) must recompute rather than answer empty.
$cache->set('pydoc', 'legacy2', '', 'markdown', null, 'not_found');
$calls = ['page' => 0, 'fallback' => 0];
$isFallback = null;
$recomputed = cacheOrSearchFallback('pydoc', 'legacy2', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals('SCAN RESULT', $recomputed,
    "a legacy negative row with no body recomputes instead of rendering empty");
assert_equals(1, $calls['fallback'], "…and pays the fallback once");
$healed = $cache->getWithStatus('pydoc', 'legacy2', '', 'markdown');
assert_equals('SCAN RESULT', $healed['content'], "…then heals the row by storing the body");

// A real page short-circuits before the fallback is ever considered.
$cache->set('pydoc', 'real', '', 'markdown', '# real', 'found');
$calls = ['page' => 0, 'fallback' => 0];
$isFallback = null;
$real = cacheOrSearchFallback('pydoc', 'real', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals('# real', $real, "a found row returns its content");
assert_equals(null, $isFallback, "…without flagging isFallback");
assert_equals(0, $calls['fallback'], "…and without running the fallback");

// An empty fallback result stores no body — the next request must still be able
// to recompute rather than serve a permanently blank 404.
$emptyFb = function() use (&$calls) { $calls['fallback']++; return ''; };
$calls = ['page' => 0, 'fallback' => 0];
$isFallback = null;
$blank = cacheOrSearchFallback('pydoc', 'blank_fb', '', 'markdown', $pageGen, $emptyFb, $isFallback);
assert_equals('', $blank, "an empty fallback result is returned as-is");
assert_equals(true, $isFallback, "…and is still flagged as a fallback");
$blankRow = $cache->getWithStatus('pydoc', 'blank_fb', '', 'markdown');
assert_equals(null, $blankRow['content'],
    "an empty fallback stores no body, so a later request recomputes instead of serving a blank 404");

cleanupTmpDir();

exit(test_summary());
