#!/usr/bin/env php
<?php
/**
 * cli/build-sitemap.php — Generate sitemap.xml from cached phpMan pages.
 *
 * Reads the cache DB for all successfully-cached content pages across
 * man, perldoc, info, pydoc, and ri modes, then writes a standard
 * sitemap.xml (or sitemap.xml.gz) to stdout or to the file specified
 * with --output.
 *
 * Designed to run after build-index.php in the reindex pipeline.
 *
 * Usage:
 *   php cli/build-sitemap.php                     Print sitemap to stdout
 *   php cli/build-sitemap.php --output sitemap.xml Write to file
 *   php cli/build-sitemap.php --output sitemap.xml.gz  Write gzipped file
 *   php cli/build-sitemap.php --base-url https://www.chedong.com/phpMan.php
 *
 * Integration with sitemap_index.xml:
 *   After generation, add this line to your sitemap_index.xml:
 *     <sitemap><loc>https://www.chedong.com/sitemap.phpman.xml</loc></sitemap>
 */

require __DIR__ . '/_bootstrap.php';

// --- Parse CLI options ---
$outputFile = null;
$baseUrl    = null;
$args       = $argv ?? [];
for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '--output' && isset($args[$i + 1])) {
        $outputFile = $args[$i + 1];
        $i++;
    } elseif ($args[$i] === '--base-url' && isset($args[$i + 1])) {
        $baseUrl = $args[$i + 1];
        $i++;
    }
}

// Resolve base URL: explicit > config constant > derive from PHPMAN_HOME_TITLE
if ($baseUrl === null) {
    $baseUrl = defined('PHPMAN_BASE_URL') ? PHPMAN_BASE_URL : '';
}
if ($baseUrl === '') {
    $baseUrl = 'https://www.chedong.com/phpMan.php';
}

// Ensure base URL has no trailing slash
$baseUrl = rtrim($baseUrl, '/');

// --- Open cache DB ---
$cacheDbPath = PHPMAN_CACHE_DIR . '/phpman_cache.db';
if (!file_exists($cacheDbPath)) {
    fwrite(STDERR, "Cache DB not found: {$cacheDbPath}\n");
    fwrite(STDERR, "Run build-index.php first to populate the cache.\n");
    exit(1);
}

$db = new SQLite3($cacheDbPath);
$db->enableExceptions(true);

// --- Query all successfully-cached content pages ---
$modes  = ['man', 'perldoc', 'info', 'pydoc', 'ri'];
$modeNames = [
    'man'     => 'Linux man page',
    'perldoc' => 'Perl documentation',
    'info'    => 'GNU Info page',
    'pydoc'   => 'Python 3 documentation',
    'ri'      => 'Ruby documentation',
];

// changefreq per mode: man pages rarely change, dynamic docs more often
$changeFreq = [
    'man'     => 'monthly',
    'perldoc' => 'weekly',
    'info'    => 'weekly',
    'pydoc'   => 'weekly',
    'ri'      => 'weekly',
];

$entries = [];

$stmt = $db->prepare(
    "SELECT mode, name, section, updated_at
     FROM cache
     WHERE format = 'html'
       AND status = 'found'
       AND name != '__index__'
       AND mode IN ('man','perldoc','info','pydoc','ri')
     ORDER BY mode, name, section"
);
$result = $stmt->execute();

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $mode    = $row['mode'];
    $name    = $row['name'];
    $section = $row['section'];
    $updated = (int)$row['updated_at'];

    // Build URL: /phpMan.php/{mode}/{name}[/section]
    $url  = $baseUrl . '/' . $mode . '/' . urlencode($name);
    if ($section !== '' && $section !== '0') {
        $url .= '/' . urlencode($section);
    }

    $entries[] = [
        'url'        => $url,
        'lastmod'    => gmdate('Y-m-d', $updated),
        'changefreq' => $changeFreq[$mode] ?? 'monthly',
        'priority'   => $mode === 'man' ? '0.8' : '0.7',
    ];
}
$db->close();

// --- Build XML ---
$lines   = [];
$lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
$lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

foreach ($entries as $entry) {
    $lines[] = '  <url>';
    $lines[] = '    <loc>' . h($entry['url']) . '</loc>';
    $lines[] = '    <lastmod>' . $entry['lastmod'] . '</lastmod>';
    $lines[] = '    <changefreq>' . $entry['changefreq'] . '</changefreq>';
    $lines[] = '    <priority>' . $entry['priority'] . '</priority>';
    $lines[] = '  </url>';
}

$lines[] = '</urlset>';
$lines[] = '';

$xml = implode("\n", $lines);

// --- Output ---
if ($outputFile !== null) {
    // Auto-detect gzip: .gz extension compresses output
    $isGz = (substr($outputFile, -3) === '.gz');
    if ($isGz) {
        $written = file_put_contents($outputFile, gzencode($xml, 6));
    } else {
        $written = file_put_contents($outputFile, $xml);
    }
    if ($written === false) {
        fwrite(STDERR, "Failed to write: {$outputFile}\n");
        exit(1);
    }
    $displayName = $isGz ? $outputFile . ' (' . basename(substr($outputFile, 0, -3)) . ')' : $outputFile;
    echo "Sitemap written: {$displayName} — " . count($entries) . " URLs, {$written} bytes" . ($isGz ? ' gzipped' : '') . "\n";
    echo "\n";
    echo "👉 To integrate with sitemap_index.xml, add this line:\n";
    echo "   <sitemap><loc>" . h(dirname($baseUrl) . '/' . basename($outputFile)) . "</loc></sitemap>\n";
} else {
    echo $xml;
}