<?php
class Profiler {
    private static array $marks = [];
    private static float $startTime = 0.0;
    private static bool $enabled = false;

    public static function init(): void {
        self::$enabled = defined('PHPMAN_DEBUG') && PHPMAN_DEBUG;
        if (self::$enabled) {
            self::$startTime = microtime(true);
            self::$marks = [['init', 0.0]];
        }
    }

    public static function mark(string $label): void {
        if (!self::$enabled) return;
        self::$marks[] = [$label, microtime(true) - self::$startTime];
    }

    public static function getReport(): array {
        if (!self::$enabled || count(self::$marks) < 2) return [];
        $report = [];
        $prevTime = 0.0;
        foreach (self::$marks as [$label, $time]) {
            $report[] = [
                'label' => $label,
                'elapsed_ms' => round($time * 1000, 2),
                'delta_ms' => round(($time - $prevTime) * 1000, 2),
            ];
            $prevTime = $time;
        }
        $report['_total_ms'] = round((microtime(true) - self::$startTime) * 1000, 2);
        $report['_version'] = '1';
        return $report;
    }

    public static function getEnabled(): bool {
        return self::$enabled;
    }
}

/**
 * Append profiling data to an already-serialised JSON body.
 *
 * Injected as a string rather than json_decode()+json_encode(): re-serialising
 * a multi-MB response just to add one key costs a second copy of the whole
 * payload (info py peaks at 62MB / 127.6MB of chunks against a 128MB
 * memory_limit). Only a JSON object can take the extra key, so JSON arrays and
 * non-JSON bodies are returned unchanged.
 */
function appendProfilingJson (string $content, array $report): string {
    $profiling = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $body = rtrim($content);
    if ($profiling === false || strlen($body) < 2 || $body[0] !== "{" || substr($body, -1) !== "}") {
        return $content;
    }
    $head = rtrim(substr($body, 0, -1));
    return $head . (substr($head, -1) === "{" ? "" : ",") . "\n    \"_profiling\": " . $profiling . "\n}";
}

/**
 * Render profiling data as an HTML block for debug mode in showFooter().
 */
function profilerHtmlBlock (): string {
    $report = Profiler::getReport();
    if (empty($report)) return '';
    $total = $report['_total_ms'] ?? 0;
    unset($report['_total_ms'], $report['_version']);
    $rows = '';
    foreach ($report as $r) {
        $label = h($r['label']);
        $rows .= '<tr>'
               . '<td>' . $label . '</td>'
               . '<td style="text-align:right;padding:0 6px">' . $r['delta_ms'] . 'ms</td>'
               . '<td style="text-align:right;padding:0 6px">' . $r['elapsed_ms'] . 'ms</td>'
               . '</tr>';
    }
    return '<div id="profiling" style="margin:16px 0;padding:8px;border:1px solid #e0e0e0;background:#f5f5f5;font-size:12px;color:#333;font-family:monospace;">'
         . '<strong>Profiling (' . $total . 'ms total)</strong>'
         . '<table style="width:auto;border-collapse:collapse;">'
         . '<tr style="border-bottom:1px solid #ccc;"><th style="text-align:left">Phase</th><th style="text-align:right;padding:0 6px">Delta</th><th style="text-align:right;padding:0 6px">Elapsed</th></tr>'
         . $rows
         . '</table></div>';
}

/**
 * Log a message to the server error log. (#47)
 * Prefixes with "phpMan:" for easy grep/filter.
 */

function cacheDb(?bool $reset = null): ?SQLite3 {
    static $db = null;
    // Test-mode reset: cacheDb(true) clears the static singleton so tests
    // can simulate fresh database creation across migration scenarios.
    if ($reset === true) { $db = null; return null; }
    if ($db !== null) return $db;

    $dir = PHPMAN_CACHE_DIR;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            phpManLog("PHPMAN_CACHE_DIR not writable: " . $dir);
            return null;
        }
    }
    if (!is_writable($dir)) {
        phpManLog("PHPMAN_CACHE_DIR not writable: " . $dir);
        return null;
    }

    $dbPath = PHPMAN_CACHE_DB;
    $isNew = !file_exists($dbPath);

    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->busyTimeout(10000);  // must be before any exec() to avoid "database is locked"

    if ($isNew) {
        // journal_mode=WAL is persistent — set once on DB creation
        try {
            $db->exec('PRAGMA journal_mode=WAL');
        } catch (\Throwable $e) {
            phpManLog("cache init WAL: " . $e->getMessage());
        }
    }
    // synchronous=NORMAL is connection-level — set every connection
    $db->exec('PRAGMA synchronous=NORMAL');

    // Schema, created idempotently on every open — there is no migration ladder
    // and no version stamp. The pre-sharding central DB no longer exists and
    // rollback to older code is not supported, so there is no older shape to
    // migrate *from*; CREATE ... IF NOT EXISTS covers the additive change a
    // future bump would need anyway. (The ladder that stood here also held the
    // request path's two unguarded statements — an unconditional `DELETE FROM
    // cache` in the future-schema guard and the `cache` migration steps beside
    // it — which raised "no such table: cache" on a DB that has none.)
    //
    // NO page-cache table here. Since v4.11 the page cache is sharded
    // (phpman_cache_<mode>.db — see pageCacheDb()) and this file holds only the
    // search/TLDR infrastructure. Creating `cache` here would make a fresh
    // install diverge from every deployed one, so the two paths would disagree
    // about whether `SELECT ... FROM cache` against the central connection is
    // valid.
    $db->exec("CREATE TABLE IF NOT EXISTS meta (
        key   TEXT PRIMARY KEY,
        value TEXT
    )");

    // FTS5 search engine: independent full-text search table (v2)
    try {
        $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts
                   USING fts5(
                       name,         -- expanded name for dual matching
                       section,      -- section number
                       description,  -- apropos one-line summary
                       body,         -- full page text (cleaned)
                       tokenize='unicode61 tokenchars ''-:''',
                       prefix='1,2,3'
                   )");
    } catch (\Throwable $e) {
        phpManLog("FTS5 prefix index: " . $e->getMessage());
    }

    $db->exec("CREATE TABLE IF NOT EXISTS search_index_meta (
        name        TEXT NOT NULL,
        section     TEXT NOT NULL DEFAULT '',
        source      TEXT NOT NULL DEFAULT 'man',
        body_len    INTEGER NOT NULL DEFAULT 0,
        hits        INTEGER NOT NULL DEFAULT 0,
        last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
        UNIQUE(name, section, source)
    )");

    // TLDR persistent cache — avoids repeated GitHub/cheat.sh HTTP fetches
    $db->exec("CREATE TABLE IF NOT EXISTS tldr_cache (
        command     TEXT UNIQUE NOT NULL,
        source      TEXT NOT NULL,
        content     TEXT NOT NULL,
        fetched_at  INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tldr_cache_fetched
               ON tldr_cache(fetched_at)");

    return $db;
}

/**
 * Modes that have their own page-cache shard: PHPMAN_CONTENT_MODES + 'search'
 * (search results are cached with mode='search').
 */
function pageCacheModes(): array {
    $modes = defined('PHPMAN_CONTENT_MODES') ? PHPMAN_CONTENT_MODES : ['man', 'perldoc', 'info', 'pydoc', 'ri'];
    $modes[] = 'search';
    return $modes;
}

/**
 * Filesystem path of a mode's page-cache shard.
 * Single source of the shard-naming rule: pageCacheDb() and PageCache::stats()
 * both resolve through it.
 */
function pageCachePath(string $mode): string {
    // Sanitize mode into a safe filename fragment (mode=[a-z0-9_-]+ from normalizeMode).
    $safeMode = preg_replace('/[^a-z0-9_-]/i', '_', (string)$mode);
    if ($safeMode === '') $safeMode = 'default';
    return PHPMAN_CACHE_DIR . '/phpman_cache_' . $safeMode . '.db';
}

/**
 * Per-mode page-cache shard connection.
 *
 * Sharding rationale: the page `cache` table is the hot, high-concurrency
 * write path (every request that misses cache does an UPSERT). Before v4.11
 * all modes shared ONE SQLite file (phpman_cache.db) along with the search
 * infrastructure (search_fts, search_index_meta, tldr_cache). Under concurrent
 * bot/man traffic that single file became a write bottleneck → "database is
 * locked" / "cache set retries exhausted" in production.
 *
 * Now each mode gets its own file: phpman_cache_{mode}.db holding just the
 * `cache` + `cache_fts` tables for that mode. man/perldoc/info/pydoc/ri/search
 * writes land in independent files → no cross-mode lock contention. The
 * central DB (cacheDb()) keeps only the search/tldr infrastructure tables,
 * which are written mostly during batch reindex (single writer).
 */
function pageCacheDb(string $mode, ?bool $reset = null, bool $create = true): ?SQLite3 {
    static $shards = [];
    if ($reset === true) { $shards = []; return null; }

    $dbPath = pageCachePath($mode);
    if (isset($shards[$dbPath])) return $shards[$dbPath];

    // Read-only callers (stats, clear) must not materialise a shard that has
    // never been written: `new SQLite3($path)` creates the file, and the schema
    // below is then created in it — so merely counting rows would leave six
    // empty databases behind. (#228)
    if (!$create && !file_exists($dbPath)) return null;

    $dir = PHPMAN_CACHE_DIR;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            phpManLog("PHPMAN_CACHE_DIR not writable: " . $dir);
            return null;
        }
    }
    if (!is_writable($dir)) {
        phpManLog("PHPMAN_CACHE_DIR not writable: " . $dir);
        return null;
    }

    $isNew = !file_exists($dbPath);

    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->busyTimeout(10000);

    if ($isNew) {
        try { $db->exec('PRAGMA journal_mode=WAL'); }
        catch (\Throwable $e) { phpManLog("pageCache init WAL: " . $e->getMessage()); }
    }
    $db->exec('PRAGMA synchronous=NORMAL');

    // The page-cache schema, created idempotently on every open — no migration
    // ladder, no version stamp. Shards left behind by an older deploy no longer
    // exist and rollback to older code is not supported, so there is no older
    // shape to migrate from; the three columns the ladder used to add are all in
    // the CREATE below. (Verified before the ladder was removed: every
    // production shard was already at v8.)
    //
    // Since v4.11 this schema lives only here, per mode — the central DB has no
    // `cache` table at all (see cacheDb()).
    $db->exec("CREATE TABLE IF NOT EXISTS cache (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        mode        TEXT NOT NULL,
        name        TEXT NOT NULL,
        section     TEXT NOT NULL DEFAULT '',
        title       TEXT,
        format      TEXT NOT NULL DEFAULT 'raw',
        content     BLOB,
        content_len INTEGER DEFAULT 0,
        status      TEXT NOT NULL DEFAULT 'found'
                    CHECK(status IN ('found','not_found')),
        ttl         INTEGER NOT NULL DEFAULT 0,
        hits        INTEGER NOT NULL DEFAULT 0,
        renderer_version INTEGER NOT NULL DEFAULT 0,
        created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
        updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
        UNIQUE(mode, name, section, format)
    )");
    try {
        $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS cache_fts
                   USING fts5(mode, name, section, title,
                              tokenize='unicode61',
                              content='cache',
                              content_rowid='id')");
    } catch (\Throwable $e) {
        phpManLog("pageCache FTS5 content table: " . $e->getMessage());
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_lookup ON cache(mode, name, section, format)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_status ON cache(status, updated_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_hits ON cache(hits DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_expiry ON cache(updated_at) WHERE ttl > 0");

    $shards[$dbPath] = $db;
    return $db;
}

/**
 * PageCache — SQLite-based cache for rendered man/perldoc/info/pydoc/ri pages.
 * Since v4.11 the page cache is sharded per mode (see pageCacheDb above).
 */
class PageCache {
    /**
     * Resolve the DB shard for a given mode.
     *
     * No central fallback. One used to stand here, handing back cacheDb()'s
     * connection when a shard could not be opened — but the central DB has had
     * no page-cache table since v4.11, so that path ran `SELECT ... FROM cache`
     * against a database without one and raised "no such table" (exceptions are
     * enabled) instead of degrading. Returning null makes an unopenable shard a
     * cache miss: get() returns null and set() returns false, so the page is
     * regenerated rather than the request failing.
     */
    private function dbFor(string $mode): ?SQLite3 {
        return pageCacheDb($mode);
    }

    /**
     * Check if a cache result is the not-found sentinel.
     * Centralizes the magic-string check so callers don't need to know the value.
     */
    public static function isNotFound(?string $result): bool {
        return $result === CACHE_SENTINEL_NOT_FOUND;
    }

    /**
     * Fetch and validate the cache row, bumping the hit counter.
     * Returns null on miss, expiry, or when the stored body cannot be decoded.
     * Shared by get() and getWithStatus() so the lookup and its invariants
     * live in one place.
     */
    private function fetchRow(string $mode, string $name, string $section, string $format): ?array {
        $db = $this->dbFor($mode);
        if (!$db) return null;
        // renderer_version is part of the lookup, not just a column we write:
        // a row produced by a different renderer reads as a miss, so the caller
        // regenerates and set() overwrites it in place. Without this filter the
        // column would be exactly the dead weight cache.generator_version was
        // (schema v5→v6) — written on every set(), consulted by nobody, and
        // therefore unable to invalidate anything.
        $stmt = $db->prepare(
            "SELECT id, content, status, ttl, updated_at FROM cache
             WHERE mode = :mode AND name = :name AND section = :section AND format = :format
               AND renderer_version = :rv"
        );
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
        $stmt->bindValue(':format', $format, SQLITE3_TEXT);
        $stmt->bindValue(':rv', RENDERER_VERSION, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();

        if (!$row) return null;

        // TTL expiry. Not just for not_found rows: found rows carry
        // PHPMAN_CACHE_TTL_FOUND (210 days) and expire the same way — the old
        // comment here said "for not_found entries" and read as if found rows
        // never aged out.
        if ($row['ttl'] > 0 && (time() > $row['updated_at'] + $row['ttl'])) {
            $this->deleteEntry($mode, $name, $section, $format);
            return null;
        }

        // Count this cache hit (found or not_found) — non-critical, ignore lock errors
        try {
            $hitStmt = $db->prepare("UPDATE cache SET hits = hits + 1 WHERE id = :id");
            $hitStmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $hitStmt->execute();
        } catch (\Throwable $e) {}

        $row['content'] = self::decodeContent($row['content']);
        return $row;
    }

    /** gzuncompress with the "undecodable ⇒ null" contract callers already assume. */
    private static function decodeContent(?string $data): ?string {
        if ($data === null) return null;
        $decompressed = @gzuncompress($data);
        return $decompressed !== false ? $decompressed : null;
    }

    public function get(string $mode, string $name, string $section, string $format): ?string {
        $row = $this->fetchRow($mode, $name, $section, $format);
        if ($row === null) return null;
        if ($row['status'] === 'not_found') {
            return CACHE_SENTINEL_NOT_FOUND;
        }
        return $row['content'];
    }

    /**
     * Like get(), but reports the row's status instead of folding it into a
     * sentinel, and returns whatever body the row carries.
     *
     * get() collapses not_found to CACHE_SENTINEL_NOT_FOUND, which discards the
     * body. A negative row that caches its fallback body (phpman#247) needs both
     * — the caller must still answer 404 while reusing the stored body instead
     * of re-running the ~10s fallback scan on every request.
     *
     * A not_found row written before #247 has content = NULL; callers must treat
     * an empty body as "recompute", not as "nothing to show".
     *
     * @return array{status: string, content: ?string}|null null = miss
     */
    public function getWithStatus(string $mode, string $name, string $section, string $format): ?array {
        $row = $this->fetchRow($mode, $name, $section, $format);
        if ($row === null) return null;
        return ['status' => $row['status'], 'content' => $row['content']];
    }

    public function set(string $mode, string $name, string $section, string $format, ?string $content, string $status = 'found', ?int $ttl = null): bool {
        $db = $this->dbFor($mode);
        if (!$db) return false;

        // Guard: never overwrite valid (non-empty) content with empty not_found.
        // Any transient content-generation failure can race a reader that has
        // already cached the good result, and the UPSERT would blindly destroy it.
        if ($status === 'not_found' && ($content === null || $content === '')) {
            $existing = $this->get($mode, $name, $section, $format);
            if ($existing !== null && $existing !== '' && !PageCache::isNotFound($existing)) {
                return false;  // preserve existing valid content, skip overwrite
            }
        }

        $compressed = ($content !== null && $content !== '') ? gzcompress($content) : null;
        $contentLen = ($content !== null) ? strlen($content) : 0;
        // Stored in cache.title, not just handed to the FTS index: cache_fts is
        // an external-content table, so the index and the column have to agree.
        $title = self::extractTitle($content);
        // $ttl === null means "derive it from the status", which is what every
        // caller but the timeout path wants. An explicit value is for rows whose
        // lifetime does not follow from found/not_found alone (#248).
        if ($ttl === null) {
            $ttl = ($status === 'not_found') ? PHPMAN_CACHE_TTL_NOT_FOUND : PHPMAN_CACHE_TTL_FOUND;
            // Search not-found entries live as long as found entries
            if ($mode === 'search' && $status === 'not_found') {
                $ttl = PHPMAN_CACHE_TTL_FOUND;
            }
        }
        // UPSERT: single INSERT ... ON CONFLICT DO UPDATE replaces SELECT-then-UPDATE/INSERT.
        // Eliminates the SELECT round-trip and the SELECT→INSERT race window.
        $stmt = $db->prepare(
            "INSERT INTO cache (mode, name, section, title, format, content, content_len, status, ttl, renderer_version, created_at, updated_at)
             VALUES (:mode, :name, :section, :title, :format, :content, :content_len, :status, :ttl, :rv,
                     strftime('%s','now'), strftime('%s','now'))
             ON CONFLICT(mode, name, section, format) DO UPDATE SET
                 title = excluded.title,
                 content = excluded.content,
                 content_len = excluded.content_len,
                 status = excluded.status,
                 ttl = excluded.ttl,
                 renderer_version = excluded.renderer_version,
                 updated_at = strftime('%s','now')"
        );
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':format', $format, SQLITE3_TEXT);
        $stmt->bindValue(':content', $compressed, SQLITE3_BLOB);
        $stmt->bindValue(':content_len', $contentLen, SQLITE3_INTEGER);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':ttl', $ttl, SQLITE3_INTEGER);
        $stmt->bindValue(':rv', RENDERER_VERSION, SQLITE3_INTEGER);

        // The row's id has to be looked up rather than read from
        // last_insert_rowid(): the UPSERT's DO UPDATE branch does not update it,
        // so on an overwrite it yields 0 (fresh connection) or the id left over
        // from an earlier INSERT (long-lived connection). syncFts() writes that
        // value as the cache_fts rowid, and cache_fts is an external-content
        // table (content_rowid='id'), so a wrong value silently misaligns the
        // index — or, at 0, makes every MATCH throw "missing row 0". (#229)
        // Not RETURNING: install.sh accepts PHP 8.1+, whose bundled SQLite may
        // predate 3.35.
        $idStmt = $db->prepare(
            "SELECT id FROM cache
             WHERE mode = :mode AND name = :name AND section = :section AND format = :format"
        );
        $idStmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $idStmt->bindValue(':name', $name, SQLITE3_TEXT);
        $idStmt->bindValue(':section', $section, SQLITE3_TEXT);
        $idStmt->bindValue(':format', $format, SQLITE3_TEXT);

        // Retry loop for SQLITE_BUSY — UPSERT can still trigger lock contention
        $maxAttempts = 8;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $ok = $stmt->execute() !== false;
                $cacheId = 0;
                if ($ok) {
                    $idRes = $idStmt->execute();
                    $idRow = $idRes ? $idRes->fetchArray(SQLITE3_ASSOC) : null;
                    if ($idRes) $idRes->finalize();
                    $cacheId = (int)($idRow['id'] ?? 0);
                }

                // Sync FTS5 index for found entries (skip search mode — search results aren't indexed)
                if ($ok && $cacheId > 0 && $status === 'found' && $mode !== 'search') {
                    $this->syncFts($cacheId, $mode, $name, $section, $title);
                }

                return $ok;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if ($attempt < $maxAttempts - 1 && strpos($msg, 'database is locked') !== false) {
                    // Exponential backoff with jitter: 100–300ms, 200–600ms, etc.
                    // up to ~12s total wait across 8 attempts
                    $base = (1 << min($attempt, 4)) * 100000;  // 100ms, 200ms, 400ms, 800ms, 1600ms…
                    $jitter = random_int(0, $base * 2);
                    usleep($base + $jitter);
                    continue;
                }
                // Non-lock error or retries exhausted — log and fail gracefully
                if ($attempt >= $maxAttempts - 1) {
                    phpManLog("cache set retries exhausted for {$mode}/{$name}/{$section}: " . $msg);
                } else {
                    phpManLog("cache set error for {$mode}/{$name}/{$section}: " . $msg);
                }
                return false;
            }
        }
        return false;
    }

    public function delete(string $mode, string $name, string $section): bool {
        $this->deleteEntry($mode, $name, $section, '%');
        return true;
    }

    public function clear(): bool {
        $ok = true;
        foreach (pageCacheModes() as $mode) {
            // A shard that was never written is already clear, and pageCacheDb()
            // returns null for one without creating it — so this neither
            // materialises six empty databases nor misses a shard this process
            // still holds open. (#228)
            $shard = pageCacheDb($mode, null, false);
            if ($shard === null) continue;
            try { $shard->exec("DELETE FROM cache"); }
            catch (\Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    public function stats(): array {
        $total = 0; $found = 0; $notFound = 0; $totalHits = 0; $dbSize = 0; $stale = 0;
        // Rows left behind by an earlier renderer. They are invisible to get()
        // and get overwritten in place as their pages are next requested, so
        // this drains to 0 on its own — but seeing it drain is the only way to
        // tell a RENDERER_VERSION bump actually took effect.
        $staleWhere = "SELECT COUNT(*) FROM cache WHERE renderer_version != " . (int)RENDERER_VERSION;
        foreach (pageCacheModes() as $mode) {
            // Ask pageCacheDb() rather than testing the path: it returns null for
            // a shard that has never been written (so reporting cannot create
            // one), but returns the live connection when this process already
            // holds one. The filesystem alone is not the answer — a handle can
            // outlive its file. (#228)
            $shard = pageCacheDb($mode, null, false);
            if ($shard === null) continue;
            try {
                $total += (int)$shard->querySingle("SELECT COUNT(*) FROM cache");
                $found += (int)$shard->querySingle("SELECT COUNT(*) FROM cache WHERE status='found'");
                $notFound += (int)$shard->querySingle("SELECT COUNT(*) FROM cache WHERE status='not_found'");
                $totalHits += (int)($shard->querySingle("SELECT SUM(hits) FROM cache") ?: 0);
                $stale += (int)$shard->querySingle($staleWhere);
                $path = pageCachePath($mode);
                if (file_exists($path)) $dbSize += filesize($path);
            } catch (\Throwable $e) {}
        }

        return [
            'total' => (int)$total,
            'found' => (int)$found,
            'not_found' => (int)$notFound,
            'total_hits' => (int)$totalHits,
            'db_size' => $dbSize,
            'stale' => (int)$stale,
        ];
    }

    private function deleteEntry(string $mode, string $name, string $section, string $format): void {
        $db = $this->dbFor($mode);
        if (!$db) return;
        if ($format === '%') {
            $stmt = $db->prepare(
                "DELETE FROM cache WHERE mode = :mode AND name = :name AND section = :section"
            );
        } else {
            $stmt = $db->prepare(
                "DELETE FROM cache WHERE mode = :mode AND name = :name AND section = :section AND format = :format"
            );
            $stmt->bindValue(':format', $format, SQLITE3_TEXT);
        }
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * First non-empty line of the rendered page, capped — the title indexed in
     * cache_fts and stored in cache.title. Both, because cache_fts is an
     * external-content table: the value it indexes is read back from the cache
     * row, so a title that only ever reached the index was unreachable.
     */
    private static function extractTitle(?string $content): string {
        if ($content === null || $content === '') return '';
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line !== '' && strlen($line) < 200) {
                return mb_substr($line, 0, 120);
            }
        }
        return '';
    }

    private function syncFts(int $cacheId, string $mode, string $name, string $section, string $title): void {
        $db = $this->dbFor($mode);
        if (!$db) return;
        try {
            // INSERT OR REPLACE is DELETE+INSERT: replaces any existing FTS row for same rowid
            $stmt = $db->prepare(
                "INSERT OR REPLACE INTO cache_fts (rowid, mode, name, section, title)
                 VALUES (:id, :mode, :name, :section, :title)"
            );
            $stmt->bindValue(':id', $cacheId, SQLITE3_INTEGER);
            $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':section', $section, SQLITE3_TEXT);
            $stmt->bindValue(':title', $title, SQLITE3_TEXT);
            $stmt->execute();
        } catch (\Throwable $e) {
            // FTS5 not available — ignore
        }
    }
}

/**
 * Cache-aware wrapper: return cached content or execute generator and cache result.
 */
function cacheOrExecute(string $mode, string $name, string $section, string $format, callable $generator): string {
    $cache = new PageCache();
    $cached = $cache->get($mode, $name, $section, $format);
    if ($cached !== null) {
        return PageCache::isNotFound($cached) ? '' : $cached;
    }
    $content = $generator();
    if (trim($content) !== "") {
        $cache->set($mode, $name, $section, $format, $content, 'found');
    }
    // 1% chance: clean up expired cache entries to prevent unbounded growth
    if (mt_rand(0, 99) === 0) {
        foreach (pageCacheModes() as $cleanupMode) {
            try {
                $cleanupDb = pageCacheDb($cleanupMode);
                if ($cleanupDb) {
                    $cleanupDb->exec("DELETE FROM cache WHERE ttl > 0 AND (strftime('%s','now') - updated_at) > ttl");
                }
            } catch (\Throwable $e) {
                phpManLog("cache TTL cleanup: " . $e->getMessage());
            }
        }
    }
    return $content;
}

/**
 * Resolve a page whose miss path runs an expensive search fallback, reusing the
 * cached negative row instead of re-running that fallback (phpman#247).
 *
 * These fallbacks shell out, so re-running one per request *is* the cost of the
 * miss: `pydoc3 -k` measured 9.99s, `ri` ~3.9s. The negative row used to be
 * written with content = NULL, and get() folds not_found to '', which the
 * caller's `trim($content) == ""` check then read as "nothing cached" — so every
 * request paid the scan again.
 *
 * A negative row is reused whatever body it carries, including none. The row
 * means "already resolved"; an empty body is a real answer ("nothing matched"),
 * not a missing value. Rows written before #247 are in the same position and are
 * reused the same way, so no migration is needed.
 *
 * $fallbackGenerator may return null to mean "could not determine" — its
 * subprocess timed out. The answer is still rendered, but no negative row is
 * written, so a later request gets to retry instead of inheriting a 24h 404.
 *
 * Sets $isFallback so the caller can still answer 404 — the row remains a miss,
 * we are only reusing its result.
 */
function cacheOrSearchFallback(string $mode, string $name, string $section, string $format,
                               callable $pageGenerator, callable $fallbackGenerator,
                               ?bool &$isFallback = null): string {
    $cache = new PageCache();
    $row = $cache->getWithStatus($mode, $name, $section, $format);
    if ($row !== null && $row['status'] === 'not_found') {
        // The row means "resolved: the page is missing and the search has run".
        // Its body is that search's output, and it is EMPTY whenever nothing
        // matched — the common case for a name that does not exist at all, and
        // the very case this fix exists for. Requiring a non-empty body here
        // leaves exactly those requests re-running the scan: measured 12.8s /
        // 9.6s / 8.8s on three consecutive requests for one nonexistent name,
        // with the row sitting at content_len = 0 the whole time.
        $isFallback = true;
        return (string)$row['content'];
    }

    $content = cacheOrExecute($mode, $name, $section, $format, $pageGenerator);
    if (trim($content) !== '') {
        return $content;
    }

    $content = $fallbackGenerator();
    $isFallback = true;
    // A null from the fallback means "could not determine", not "nothing there" —
    // the subprocess behind it timed out.
    if ($content !== null) {
        $cache->set($mode, $name, $section, $format, $content, 'not_found');
    } else {
        // #248: write a *short-lived* negative row so repeated enumeration of the
        // same name collapses into one scan. Production measured the same name
        // asked again 2s to 42s later, each repeat paying the full 20s pydoc scan
        // — a handful of those concurrently is what saturated the FastCGI pool.
        //
        // Written only when no row is there yet. set()'s UPSERT refreshes
        // updated_at and ttl on conflict, so writing on every timeout would renew
        // the TTL for as long as traffic continues — i.e. never retry, which is
        // the "transient failure frozen forever" problem the old code avoided by
        // writing nothing at all. One write per window makes the TTL mean "10
        // minutes since the first timeout", not "10 minutes since the last hit".
        if ($cache->getWithStatus($mode, $name, $section, $format) === null) {
            $cache->set($mode, $name, $section, $format, '', 'not_found', PHPMAN_CACHE_TTL_TIMEOUT);
        }
    }
    return (string)$content;
}

// +--------------------------------------------------------------------------------+
// | FTS5 Search Engine                                                              |
// +--------------------------------------------------------------------------------+

/**
 * Expand a command name for FTS5 dual matching.
 * "git-commit"   → "git-commit git commit"
 * "File::Find"   → "File::Find File Find"
 * "ls"           → "ls" (unchanged)
 */

// Initialize profiler
Profiler::init();
