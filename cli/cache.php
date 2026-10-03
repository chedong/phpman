#!/usr/bin/env php
<?php
// cli/cache.php — inspect and flush the page cache.
//
// The cache has been sharded per mode (phpman_cache_<mode>.db) since v4.11, but
// the tooling still targeted only the central file: `rm -f phpman_cache.db*`
// matches the central DB and its -wal/-shm and NOTHING else — the shard names
// diverge at `_` vs `.` — so `make cache-flush` reported success while leaving
// all six shards in place. (#228)
//
// Usage: php cli/cache.php stats | flush

require __DIR__ . '/_bootstrap.php';

$cmd = $argv[1] ?? 'stats';

function shardFiles(string $mode): array {
    // The DB plus any -wal/-shm sidecars.
    return glob(pageCachePath($mode) . '*') ?: [];
}

if ($cmd === 'stats') {
    printf("%-10s %10s %14s\n", 'shard', 'rows', 'bytes');
    printf("%-10s %10s %14s\n", str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 14));
    $totalRows = 0;
    $totalBytes = 0;
    foreach (pageCacheModes() as $mode) {
        $path = pageCachePath($mode);
        // Read-only open: pageCacheDb() returns null for a shard that has never
        // been written, so stats never creates the thing it is counting. (#228)
        $db = pageCacheDb($mode, null, false);
        if ($db === null) {
            printf("%-10s %10s %14s\n", $mode, '(absent)', '-');
            continue;
        }
        $rows = (int)$db->querySingle("SELECT COUNT(*) FROM cache");
        $bytes = 0;
        foreach (shardFiles($mode) as $f) $bytes += filesize($f);
        $totalRows += $rows;
        $totalBytes += $bytes;
        printf("%-10s %10d %14d\n", $mode, $rows, $bytes);
    }
    printf("%-10s %10d %14d\n", 'TOTAL', $totalRows, $totalBytes);

    // The central DB is infrastructure, not a page shard: it holds the FTS
    // search index and the TLDR cache. Reported separately so it is never
    // mistaken for something `flush` may delete.
    $centralPath = PHPMAN_CACHE_DB;
    if (file_exists($centralPath)) {
        $centralBytes = 0;
        foreach (glob($centralPath . '*') ?: [] as $f) $centralBytes += filesize($f);
        $central = cacheDb();
        $rows = $central ? (int)$central->querySingle("SELECT COUNT(*) FROM cache") : 0;
        printf("\ncentral (search index + tldr, not flushed): %d rows, %d bytes\n", $rows, $centralBytes);
    }
    exit(0);
}

if ($cmd === 'flush') {
    $removed = 0;
    foreach (pageCacheModes() as $mode) {
        foreach (shardFiles($mode) as $f) {
            if (@unlink($f)) $removed++;
        }
    }
    // Central: drop the legacy page-cache table only. The FTS search index and
    // the TLDR cache live in the same file and must survive — deleting the file
    // (what the old target did) threw the search index away with it.
    $central = cacheDb();
    if ($central) {
        try { $central->exec("DELETE FROM cache"); }
        catch (\Throwable $e) { fwrite(STDERR, "central cache clear failed: {$e->getMessage()}\n"); }
    }
    printf("Flushed %d file(s) across %d shards. Cache rebuilds on next request.\n",
        $removed, count(pageCacheModes()));
    exit(0);
}

fwrite(STDERR, "usage: php cli/cache.php stats|flush\n");
exit(2);
