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
    printf("renderer_version = %d\n\n", (int)RENDERER_VERSION);
    printf("%-10s %10s %10s %14s\n", 'shard', 'rows', 'stale', 'bytes');
    printf("%-10s %10s %10s %14s\n", str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 14));
    $totalRows = 0;
    $totalStale = 0;
    $totalBytes = 0;
    foreach (pageCacheModes() as $mode) {
        $path = pageCachePath($mode);
        // Read-only open: pageCacheDb() returns null for a shard that has never
        // been written, so stats never creates the thing it is counting. (#228)
        $db = pageCacheDb($mode, null, false);
        if ($db === null) {
            printf("%-10s %10s %10s %14s\n", $mode, '(absent)', '-', '-');
            continue;
        }
        $rows = (int)$db->querySingle("SELECT COUNT(*) FROM cache");
        // Rows written by an older renderer. Invisible to PageCache::get(), and
        // overwritten in place as each page is next requested, so this drains on
        // its own — but it is the only visible sign a RENDERER_VERSION bump took.
        $stale = (int)$db->querySingle(
            "SELECT COUNT(*) FROM cache WHERE renderer_version != " . (int)RENDERER_VERSION);
        $bytes = 0;
        foreach (shardFiles($mode) as $f) $bytes += filesize($f);
        $totalRows += $rows;
        $totalStale += $stale;
        $totalBytes += $bytes;
        printf("%-10s %10d %10d %14d\n", $mode, $rows, $stale, $bytes);
    }
    printf("%-10s %10d %10d %14d\n", 'TOTAL', $totalRows, $totalStale, $totalBytes);
    if ($totalStale > 0) {
        printf("\n%d row(s) predate renderer %d; each is re-rendered on its next request.\n",
            $totalStale, (int)RENDERER_VERSION);
    }

    // The central DB is infrastructure, not a page shard: it holds the FTS
    // search index and the TLDR cache, and since v4.11 it has no page-cache
    // table at all. Reported separately so it is never mistaken for something
    // `flush` may delete.
    $centralPath = PHPMAN_CACHE_DB;
    if (file_exists($centralPath)) {
        $centralBytes = 0;
        foreach (glob($centralPath . '*') ?: [] as $f) $centralBytes += filesize($f);
        printf("\ncentral (search index + tldr, no page cache, not flushed): %d bytes\n", $centralBytes);
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
    // The central DB is deliberately untouched: it holds the FTS search index
    // and the TLDR cache, neither of which is page cache, and since v4.11 it has
    // no `cache` table to clear — the page cache lives entirely in the shards
    // removed above. (The old target deleted the whole file, which threw the
    // search index away with it.)
    printf("Flushed %d file(s) across %d shards. Cache rebuilds on next request.\n",
        $removed, count(pageCacheModes()));
    exit(0);
}

fwrite(STDERR, "usage: php cli/cache.php stats|flush\n");
exit(2);
