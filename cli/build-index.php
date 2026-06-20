#!/usr/bin/env php
<?php
/**
 * cli/build-index.php — Rebuild the FTS5 search index.
 *
 * Usage:
 *   php cli/build-index.php          Rebuild index
 *   php cli/build-index.php --cron   Rebuild with UTC timestamp (for cron)
 */

require __DIR__ . '/_bootstrap.php';

$cron = in_array('--cron', $argv ?? []);
$result = rebuildSearchIndex();

if ($cron) {
    echo '[' . gmdate('Y-m-d H:i:s') . "]\n";
}
echo $result;
