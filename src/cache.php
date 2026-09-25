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

    if ($isNew) {
        $db->exec("CREATE TABLE IF NOT EXISTS cache (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            mode        TEXT NOT NULL,
            name        TEXT NOT NULL,
            section     TEXT NOT NULL DEFAULT '',
            format      TEXT NOT NULL DEFAULT 'raw',
            content     BLOB,
            content_len INTEGER DEFAULT 0,
            status      TEXT NOT NULL DEFAULT 'found'
                        CHECK(status IN ('found','not_found')),
            ttl         INTEGER NOT NULL DEFAULT 0,
            hits        INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            generator_version TEXT,
            UNIQUE(mode, name, section, format)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS meta (
            key   TEXT PRIMARY KEY,
            value TEXT
        )");
        $db->exec("INSERT OR IGNORE INTO meta (key, value)
                   VALUES ('schema_version', '" . CACHE_SCHEMA_VERSION . "')");

        try {
            $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS cache_fts
                       USING fts5(mode, name, section, title,
                                  tokenize='unicode61',
                                  content='cache',
                                  content_rowid='id')");
        } catch (\Throwable $e) {
            phpManLog("FTS5 content table: " . $e->getMessage());
        }

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

        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_lookup
                   ON cache(mode, name, section, format)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_status
                   ON cache(status, updated_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_hits
                   ON cache(hits DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_expiry
                   ON cache(updated_at) WHERE ttl > 0");
    } else {
        // Check schema version — clear cache if mismatch
        $row = $db->querySingle("SELECT value FROM meta WHERE key='schema_version'", false);
        if ($row !== CACHE_SCHEMA_VERSION) {
            // Cascading if blocks (not if/elseif) — each migration runs independently
            // so a DB at schema v1 upgraded to v5 runs ALL of v1→v2, v2→v3, v3→v4, v4→v5.

            if ($row === '1' || (int)$row < 2) {
                // v1 → v2: add search_fts and search_index_meta, clear search cache
                $db->exec("DELETE FROM cache WHERE mode='search'");
                try {
                    $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts
                               USING fts5(
                                   name, section, description, body,
                                   tokenize='unicode61 tokenchars ''-:''',
                                   prefix='1,2,3'
                               )");
                } catch (\Throwable $e) {
                    phpManLog("FTS5 meta prefix: " . $e->getMessage());
                }
                $db->exec("CREATE TABLE IF NOT EXISTS search_index_meta (
                    name TEXT NOT NULL,
                    section TEXT NOT NULL DEFAULT '',
                    source TEXT NOT NULL DEFAULT 'man',
                    body_len INTEGER NOT NULL DEFAULT 0,
                    hits INTEGER NOT NULL DEFAULT 0,
                    last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                    UNIQUE(name, section, source)
                )");
            }
            if ($row === '2' || (int)$row < 3) {
                // v2 → v3: search_fts.name now stores expanded name; rebuild needed
                try {
                    $db->exec("DELETE FROM search_fts");
                } catch (\Throwable $e) {
                    phpManLog("FTS5 delete: " . $e->getMessage());
                }
                $db->exec("DELETE FROM search_index_meta");
            }
            if ($row === '3' || (int)$row < 4) {
                // v3 → v4: per-format caching migration.
                // Clear all html/markdown/mcp/raw entries; keep only json/search/emoji.
                $db->exec("DELETE FROM cache WHERE format NOT IN ('json', 'search', 'emoji_md', 'emoji_html')");
            }
            if ($row === '4' || (int)$row < 5) {
                // v4 → v5: add generator_version column for tracking which phpman
                // version produced each cached entry.
                try { $db->exec("ALTER TABLE cache ADD COLUMN generator_version TEXT"); }
                catch (\Throwable $e) { /* column may already exist */ }
            }
            if ((int)$row >= 5) {
                // Unknown future schema — clear all cached content, keep schema_version
                $db->exec("DELETE FROM cache");
            }
            $db->exec("UPDATE meta SET value = '" . CACHE_SCHEMA_VERSION . "' WHERE key = 'schema_version'");
        }
    }

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
function pageCacheDb(string $mode, ?bool $reset = null): ?SQLite3 {
    static $shards = [];
    if ($reset === true) { $shards = []; return null; }

    $dbPath = pageCachePath($mode);
    if (isset($shards[$dbPath])) return $shards[$dbPath];

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

    if ($isNew) {
        // Same cache schema as the (legacy) central table, but per-mode.
        $db->exec("CREATE TABLE IF NOT EXISTS cache (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            mode        TEXT NOT NULL,
            name        TEXT NOT NULL,
            section     TEXT NOT NULL DEFAULT '',
            format      TEXT NOT NULL DEFAULT 'raw',
            content     BLOB,
            content_len INTEGER DEFAULT 0,
            status      TEXT NOT NULL DEFAULT 'found'
                        CHECK(status IN ('found','not_found')),
            ttl         INTEGER NOT NULL DEFAULT 0,
            hits        INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            generator_version TEXT,
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
    }

    $shards[$dbPath] = $db;
    return $db;
}

/**
 * PageCache — SQLite-based cache for rendered man/perldoc/info/pydoc/ri pages.
 * Since v4.11 the page cache is sharded per mode (see pageCacheDb above).
 */
class PageCache {
    private ?SQLite3 $db;

    public function __construct() {
        // Pre-warm the central connection; per-call page ops use the mode shard
        // resolved lazily in each method via pageCacheDb($mode).
        $this->db = cacheDb();
    }

    /** Resolve the DB shard for a given mode (falls back to central on failure). */
    private function dbFor(string $mode): ?SQLite3 {
        $shard = pageCacheDb($mode);
        return $shard !== null ? $shard : $this->db;
    }

    /**
     * Check if a cache result is the not-found sentinel.
     * Centralizes the magic-string check so callers don't need to know the value.
     */
    public static function isNotFound(?string $result): bool {
        return $result === CACHE_SENTINEL_NOT_FOUND;
    }

    public function get(string $mode, string $name, string $section, string $format): ?string {
        $db = $this->dbFor($mode);
        if (!$db) return null;
        $stmt = $db->prepare(
            "SELECT id, content, status, ttl, updated_at FROM cache
             WHERE mode = :mode AND name = :name AND section = :section AND format = :format"
        );
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
        $stmt->bindValue(':format', $format, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();

        if (!$row) return null;

        // Check TTL expiry for not_found entries
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

        if ($row['status'] === 'not_found') {
            return CACHE_SENTINEL_NOT_FOUND;
        }

        $data = $row['content'];
        if ($data === null) return null;

        $decompressed = @gzuncompress($data);
        return $decompressed !== false ? $decompressed : null;
    }

    public function set(string $mode, string $name, string $section, string $format, ?string $content, string $status = 'found'): bool {
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
        $ttl = ($status === 'not_found') ? PHPMAN_CACHE_TTL_NOT_FOUND : PHPMAN_CACHE_TTL_FOUND;
        // Search not-found entries live as long as found entries
        if ($mode === 'search' && $status === 'not_found') {
            $ttl = PHPMAN_CACHE_TTL_FOUND;
        }
        // Emoji-enhanced entries are expensive to regenerate — never auto-expire
        // them. Short TTLs once caused ~12K entries to vanish weekly.
        if ($format === CACHE_FORMAT_EMOJI_MD || $format === CACHE_FORMAT_EMOJI_HTML) {
            $ttl = 0;  // never expire
        }

        // UPSERT: single INSERT ... ON CONFLICT DO UPDATE replaces SELECT-then-UPDATE/INSERT.
        // Eliminates the SELECT round-trip and the SELECT→INSERT race window.
        $stmt = $db->prepare(
            "INSERT INTO cache (mode, name, section, format, content, content_len, status, ttl, created_at, updated_at, generator_version)
             VALUES (:mode, :name, :section, :format, :content, :content_len, :status, :ttl,
                     strftime('%s','now'), strftime('%s','now'), :genver)
             ON CONFLICT(mode, name, section, format) DO UPDATE SET
                 content = excluded.content,
                 content_len = excluded.content_len,
                 status = excluded.status,
                 ttl = excluded.ttl,
                 updated_at = strftime('%s','now'),
                 generator_version = excluded.generator_version"
        );
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
        $stmt->bindValue(':format', $format, SQLITE3_TEXT);
        $stmt->bindValue(':content', $compressed, SQLITE3_BLOB);
        $stmt->bindValue(':content_len', $contentLen, SQLITE3_INTEGER);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':ttl', $ttl, SQLITE3_INTEGER);
        $stmt->bindValue(':genver', defined('GIT_DESCRIBE') ? GIT_DESCRIBE : 'unknown', SQLITE3_TEXT);

        // Retry loop for SQLITE_BUSY — UPSERT can still trigger lock contention
        $maxAttempts = 8;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $ok = $stmt->execute() !== false;
                $cacheId = $ok ? $db->lastInsertRowID() : 0;

                // Sync FTS5 index for found entries (skip search mode — search results aren't indexed)
                if ($ok && $status === 'found' && $mode !== 'search') {
                    $this->syncFts($cacheId, $mode, $name, $section, $content);
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
            $shard = pageCacheDb($mode);
            if ($shard === null) { $ok = false; continue; }
            try { $shard->exec("DELETE FROM cache"); }
            catch (\Throwable $e) { $ok = false; }
        }
        // Also clear the legacy central cache table (may hold pre-shard entries)
        if ($this->db) {
            try { $this->db->exec("DELETE FROM cache"); }
            catch (\Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    public function stats(): array {
        $total = 0; $found = 0; $notFound = 0; $totalHits = 0; $dbSize = 0;
        foreach (pageCacheModes() as $mode) {
            $shard = pageCacheDb($mode);
            if ($shard === null) continue;
            try {
                $total += (int)$shard->querySingle("SELECT COUNT(*) FROM cache");
                $found += (int)$shard->querySingle("SELECT COUNT(*) FROM cache WHERE status='found'");
                $notFound += (int)$shard->querySingle("SELECT COUNT(*) FROM cache WHERE status='not_found'");
                $totalHits += (int)($shard->querySingle("SELECT SUM(hits) FROM cache") ?: 0);
                $path = pageCachePath($mode);
                if (file_exists($path)) $dbSize += filesize($path);
            } catch (\Throwable $e) {}
        }
        if ($this->db) {
            try {
                $total += (int)$this->db->querySingle("SELECT COUNT(*) FROM cache");
                $found += (int)$this->db->querySingle("SELECT COUNT(*) FROM cache WHERE status='found'");
                $notFound += (int)$this->db->querySingle("SELECT COUNT(*) FROM cache WHERE status='not_found'");
                $totalHits += (int)($this->db->querySingle("SELECT SUM(hits) FROM cache") ?: 0);
            } catch (\Throwable $e) {}
            if (file_exists(PHPMAN_CACHE_DB)) $dbSize += filesize(PHPMAN_CACHE_DB);
        }

        return [
            'total' => (int)$total,
            'found' => (int)$found,
            'not_found' => (int)$notFound,
            'total_hits' => (int)$totalHits,
            'db_size' => $dbSize,
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

    private function syncFts(int $cacheId, string $mode, string $name, string $section, ?string $content): void {
        $db = $this->dbFor($mode);
        if (!$db) return;
        $title = '';
        if ($content !== null && $content !== '') {
            // Extract first meaningful line as title for FTS
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && strlen($line) < 200) {
                    $title = mb_substr($line, 0, 120);
                    break;
                }
            }
        }
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
