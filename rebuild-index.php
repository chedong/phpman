#!/usr/bin/env php
<?php
/**
 * phpMan FTS5 Search Index Rebuilder
 *
 * Standalone script — place alongside phpm_cache.db in the cache directory.
 * Clears and rebuilds the FTS5 full-text search index from system man pages.
 *
 * Usage:
 *   php rebuild-index.php              # rebuild with progress output
 *   php rebuild-index.php --cron       # cron mode — timestamped, concise
 *   php rebuild-index.php --staging    # use staging cache path
 *
 * Configure CACHE_DIR below or set PHPMAN_CACHE_DIR env var.
 */

// ---- Configuration ----
$cacheDir = getenv('PHPMAN_CACHE_DIR') ?: dirname(__FILE__);
$dbPath = $cacheDir . '/phpm_cache.db';

// Staging override
if (in_array('--staging', $argv)) {
    $cacheDir = getenv('PHPMAN_STAGING_CACHE_DIR') ?: '/home/chedong/cache/staging';
    $dbPath = $cacheDir . '/phpm_cache.db';
}

$cronMode = in_array('--cron', $argv);

// ---- Main ----
$startTime = microtime(true);

if (!file_exists($dbPath)) {
    $msg = 'ERROR: Cache database not found at ' . $dbPath;
    echo $cronMode ? '[' . gmdate('Y-m-d H:i:s') . "] {$msg}\n" : "{$msg}\n";
    exit(1);
}

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->busyTimeout(10000);

    // Verify FTS5 support
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='search_fts'");
    if (!$tables->fetchArray()) {
        $msg = 'ERROR: search_fts table not found — FTS5 may not be available';
        echo $cronMode ? '[' . gmdate('Y-m-d H:i:s') . "] {$msg}\n" : "{$msg}\n";
        exit(1);
    }

    // Clear existing index
    $db->exec("DELETE FROM search_fts");
    $db->exec("DELETE FROM search_index_meta");
    if (!$cronMode) echo "Cleared existing search index.\n\n";

    $sections = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'n'];
    $total = 0;
    $errors = 0;

    foreach ($sections as $sec) {
        $cmd = 'apropos -s ' . escapeshellarg($sec) . ' . 2>/dev/null';
        $lines = [];
        exec($cmd, $lines, $exitCode);

        if ($exitCode !== 0 || empty($lines)) continue;

        $sectionCount = 0;
        foreach ($lines as $line) {
            // Parse apropos output
            $name = $sectionNum = $description = '';
            if (preg_match('/^(.+?)\s+\(((\d\w*|n)\w*)\)\s+[–-]\s+(.+)$/', $line, $m)) {
                $name = trim($m[1]);
                $sectionNum = trim($m[2]);
                $description = trim($m[4]);
            } elseif (preg_match('/^(.+?)\s+\[(.+?)\]\s+\(((\d\w*|n)\w*)\)\s*$/', $line, $m)) {
                $name = trim($m[1]);
                $description = trim($m[2]);
                $sectionNum = trim($m[3]);
            } else {
                continue;
            }

            if ($name === '' || $description === '') continue;
            $name = substr($name, 0, 200);

            // Expand name for FTS5 dual matching (hyphen/::)
            $expandedName = $name;
            if (str_contains($name, '-')) {
                $expandedName .= ' ' . str_replace('-', ' ', $name);
            }
            if (str_contains($name, '::')) {
                $expandedName .= ' ' . str_replace('::', ' ', $name);
            }

            try {
                // Insert into search_fts
                $stmt = $db->prepare(
                    "INSERT INTO search_fts (name, section, description, body)
                     VALUES (:name, :section, :description, '')"
                );
                $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
                $stmt->bindValue(':section', $sectionNum, SQLITE3_TEXT);
                $stmt->bindValue(':description', $description, SQLITE3_TEXT);
                $stmt->execute();

                // Insert into search_index_meta
                $stmt2 = $db->prepare(
                    "INSERT OR IGNORE INTO search_index_meta (name, section, source)
                     VALUES (:name, :section, 'man')"
                );
                $stmt2->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt2->bindValue(':section', $sectionNum, SQLITE3_TEXT);
                $stmt2->execute();

                $sectionCount++;
                $total++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        if (!$cronMode) echo "  Section {$sec}: {$sectionCount} entries indexed.\n";
        else if ($sectionCount > 0) {
            echo '[' . gmdate('Y-m-d H:i:s') . "] Section {$sec}: {$sectionCount} entries\n";
        }
    }

    // Update metadata
    $db->exec("INSERT OR REPLACE INTO meta (key, value) VALUES ('search_index_count', '{$total}')");
    $db->exec("INSERT OR REPLACE INTO meta (key, value) VALUES ('search_index_updated', '" . gmdate('Y-m-d\TH:i:s\Z') . "')");

    $elapsed = round(microtime(true) - $startTime, 2);
    $msg = "Done. {$total} entries indexed, {$errors} errors in {$elapsed}s.";
    echo $cronMode ? '[' . gmdate('Y-m-d H:i:s') . "] {$msg}\n" : "\n{$msg}\n";

    exit(0);
} catch (\Exception $e) {
    $msg = 'ERROR: ' . $e->getMessage();
    echo $cronMode ? '[' . gmdate('Y-m-d H:i:s') . "] {$msg}\n" : "{$msg}\n";
    exit(1);
}
