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

// A row written before #247 carries no body, but it still means "already
// searched" — so it is reused, and no migration is needed.
$cache->set('pydoc', 'legacy2', '', 'markdown', null, 'not_found');
$calls = ['page' => 0, 'fallback' => 0];
$isFallback = null;
$legacyReuse = cacheOrSearchFallback('pydoc', 'legacy2', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals('', $legacyReuse, "a legacy negative row is reused as-is");
assert_equals(true, $isFallback, "…and is still flagged as a fallback (404)");
assert_equals(0, $calls['fallback'], "…without re-running the scan — pre-#247 rows need no migration");

// A real page short-circuits before the fallback is ever considered.
$cache->set('pydoc', 'real', '', 'markdown', '# real', 'found');
$calls = ['page' => 0, 'fallback' => 0];
$isFallback = null;
$real = cacheOrSearchFallback('pydoc', 'real', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals('# real', $real, "a found row returns its content");
assert_equals(null, $isFallback, "…without flagging isFallback");
assert_equals(0, $calls['fallback'], "…and without running the fallback");

// The production case, and the one that failed the first time round: the name
// matches nothing, so the fallback renders ZERO bytes. The empty body is the
// answer, not a missing value — the negative row must still short-circuit the
// next request. Measured in production before this was fixed: three consecutive
// requests for one nonexistent name took 12.8s / 9.6s / 8.8s, with the row
// sitting at content_len = 0 throughout.
$emptyFb = function() use (&$calls) { $calls['fallback']++; return ''; };
$calls = ['page' => 0, 'fallback' => 0];
$isFallback = null;
$blank = cacheOrSearchFallback('pydoc', 'blank_fb', '', 'markdown', $pageGen, $emptyFb, $isFallback);
assert_equals('', $blank, "an empty fallback result is returned as-is");
assert_equals(true, $isFallback, "…and is still flagged as a fallback");
assert_equals(1, $calls['fallback'], "…having run the scan once");

$isFallback = null;
$blank2 = cacheOrSearchFallback('pydoc', 'blank_fb', '', 'markdown', $pageGen, $emptyFb, $isFallback);
assert_equals('', $blank2, "a second request for a name with no matches returns the same empty body");
assert_equals(true, $isFallback, "…still flagged as a fallback, so the caller answers 404");
assert_equals(1, $calls['fallback'],
    "…and does NOT re-run the scan — an empty body is the answer, not a missing value");

// A fallback that returns null means "could not determine" — its subprocess timed
// out. #248: that used to write nothing at all, so every repeat paid the full 20s
// scan again (production: the same name asked 2s to 42s later, `IPv…` ten times
// in a day). Now it writes a SHORT-lived negative row — short because a timeout
// says "we could not find out", not "it does not exist", so it must get to retry.
$calls = ['page' => 0, 'fallback' => 0];
$nullFb = function() use (&$calls) { $calls['fallback']++; return null; };
$isFallback = null;
$unknown = cacheOrSearchFallback('pydoc', 'timed_out', '', 'markdown', $pageGen, $nullFb, $isFallback);
assert_equals('', $unknown, "a null fallback still renders an empty body");
assert_equals(true, $isFallback, "…and is still flagged as a fallback, so the caller answers 404");

$row = $cache->getWithStatus('pydoc', 'timed_out', '', 'markdown');
assert_equals('not_found', $row['status'], "…and DOES write a negative row (#248)");

$timedOutRow = function() {
    return pageCacheDb('pydoc')->querySingle(
        "SELECT ttl, updated_at FROM cache
          WHERE mode='pydoc' AND name='timed_out' AND section='' AND format='markdown'", true);
};
$r1 = $timedOutRow();
assert_equals(PHPMAN_CACHE_TTL_TIMEOUT, (int)$r1['ttl'],
    "…with the SHORT timeout TTL, not the 24h one");
assert_equals(true, PHPMAN_CACHE_TTL_TIMEOUT < PHPMAN_CACHE_TTL_NOT_FOUND,
    "…which is strictly shorter than the ordinary not_found TTL");

// Inside the window the next request reuses it — no second scan. This is the fix.
$calls = ['page' => 0, 'fallback' => 0];
$isFallback = null;
$again = cacheOrSearchFallback('pydoc', 'timed_out', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals(0, $calls['fallback'],
    "a second request inside the window does NOT re-run the 20s scan — this is the #248 fix");
assert_equals('', $again, "…and answers from the negative row");

// …and the hit must NOT refresh the row. set()'s UPSERT rewrites updated_at and
// ttl on conflict, so rewriting on every timeout would renew the TTL for as long
// as traffic continues — "never retry" again, by another route. Age the row by
// hand and check the second call left it alone.
pageCacheDb('pydoc')->exec(
    "UPDATE cache SET updated_at = updated_at - 100
      WHERE mode='pydoc' AND name='timed_out' AND section='' AND format='markdown'");
$aged = $timedOutRow();
$calls = ['page' => 0, 'fallback' => 0];
cacheOrSearchFallback('pydoc', 'timed_out', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals((int)$aged['updated_at'], (int)$timedOutRow()['updated_at'],
    "a hit does NOT rewrite the row — otherwise sustained crawling renews the TTL forever");

// Once it expires the scan runs again — the whole point of keeping the TTL short.
pageCacheDb('pydoc')->exec(
    "UPDATE cache SET updated_at = updated_at - " . (PHPMAN_CACHE_TTL_TIMEOUT + 10) . "
      WHERE mode='pydoc' AND name='timed_out' AND section='' AND format='markdown'");
$calls = ['page' => 0, 'fallback' => 0];
$isFallback = null;
cacheOrSearchFallback('pydoc', 'timed_out', '', 'markdown', $pageGen, $fbGen, $isFallback);
assert_equals(1, $calls['fallback'],
    "after the short TTL expires the scan runs again — a timeout must not be frozen forever");
assert_equals('SCAN RESULT', $cache->getWithStatus('pydoc', 'timed_out', '', 'markdown')['content'],
    "…and a real answer this time is cached normally");

cleanupTmpDir();

exit(test_summary());
