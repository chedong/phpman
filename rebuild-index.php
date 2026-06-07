#!/usr/bin/env php
<?php
/**
 * phpMan FTS5 Search Index Rebuilder
 *
 * Standalone script — place in /home/chedong/cache/ or alongside phpm_cache.db.
 * Clears and rebuilds the FTS5 full-text search index from system apropos data.
 *
 * Usage:
 *   php rebuild-index.php /path/to/cache          rebuild index in specified dir
 *   php rebuild-index.php /path/to/cache --cron   cron mode — timestamped output
 *   php rebuild-index.php --help                  show this help
 */

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<'HELP'
phpMan FTS5 Search Index Rebuilder

Usage:
  php rebuild-index.php <cache-dir> [--cron]

Arguments:
  cache-dir     Path to the cache directory containing phpm_cache.db
                (e.g. /home/chedong/cache/demo or /home/chedong/cache/staging)
  --cron        Timestamped, concise output for cron job logging

Examples:
  php rebuild-index.php /home/chedong/cache/demo
  php rebuild-index.php /home/chedong/cache/staging --cron

Cron example (daily at 3am):
  0 3 * * * php /home/chedong/cache/rebuild-index.php /home/chedong/cache/demo --cron

The script deletes all rows from search_fts and search_index_meta,
then repopulates them by running "apropos -s N ." for each man section (1-9,n).

HELP;
    exit(0);
}

// ---- Parse arguments ----
$cacheDir = null;
$cronMode = false;

foreach ($argv as $i => $arg) {
    if ($i === 0) continue; // script name
    if ($arg === '--cron') {
        $cronMode = true;
    } elseif ($arg[0] !== '-') {
        $cacheDir = $arg;
    }
}

if ($cacheDir === null) {
    fwrite(STDERR, "ERROR: cache directory required. Use --help for usage.\n");
    exit(1);
}

if (!is_dir($cacheDir)) {
    fwrite(STDERR, "ERROR: not a directory: {$cacheDir}\n");
    exit(1);
}

$dbPath = rtrim($cacheDir, '/') . '/phpm_cache.db';

// ---- Main ----
$startTime = microtime(true);

if (!file_exists($dbPath)) {
    $msg = "ERROR: phpm_cache.db not found at {$dbPath}";
    echo $cronMode ? '[' . gmdate('Y-m-d H:i:s') . "] {$msg}\n" : "{$msg}\n";
    exit(1);
}

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->busyTimeout(15000);

    // Verify FTS5 support
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='search_fts'");
    if (!$tables->fetchArray()) {
        $msg = "ERROR: search_fts table not found — FTS5 may not be available";
        echo $cronMode ? '[' . gmdate('Y-m-d H:i:s') . "] {$msg}\n" : "{$msg}\n";
        exit(1);
    }

    // Clear existing index
    $db->exec("DELETE FROM search_fts");
    $db->exec("DELETE FROM search_index_meta");
    // Also clear stale search cache to force regeneration with new index
    $db->exec("DELETE FROM cache WHERE mode='search'");
    if (!$cronMode) echo "Cleared search index and search cache.\n\n";

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
                $stmt = $db->prepare(
                    "INSERT INTO search_fts (name, section, description, body)
                     VALUES (:name, :section, :description, '')"
                );
                $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
                $stmt->bindValue(':section', $sectionNum, SQLITE3_TEXT);
                $stmt->bindValue(':description', $description, SQLITE3_TEXT);
                $stmt->execute();

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
