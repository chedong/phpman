#!/usr/bin/env php
<?php
/**
 * cli/build-sitemap.php — Generate compressed sitemap files from cached phpMan pages.
 *
 * Reads the cache DB for all successfully-cached content pages across
 * man, perldoc, info, pydoc, and ri modes, then writes a standard
 * sitemap XML to stdout or to compressed .xml.gz file(s) specified by --output.
 * Supports alternate Markdown/JSON endpoints and automatic chunking so each
 * sitemap stays below search-engine limits.
 *
 * Designed to run after build-index.php in the reindex pipeline.
 *
 * Usage:
 *   php cli/build-sitemap.php                     Print sitemap to stdout
 *   php cli/build-sitemap.php --output sitemap-phpman.xml.gz Write gzipped file
 *   php cli/build-sitemap.php --base-url https://www.chedong.com/phpMan.php
 *   php cli/build-sitemap.php --sitemap-url https://www.chedong.com/sitemap-phpman.xml.gz
 *   php cli/build-sitemap.php --formats html,markdown,json --max-urls 50000
 *
 * Integration with sitemap_index.xml:
 *   After generation, add this line to your sitemap_index.xml:
 *     <sitemap><loc>https://www.chedong.com/sitemap-phpman.xml.gz</loc></sitemap>
 */

require __DIR__ . '/_bootstrap.php';

// --- Parse CLI options ---
$outputFile = null;
$baseUrl    = null;
$sitemapUrl = null;
$formats    = ['html'];
$maxUrls    = 50000;
$args       = $argv ?? [];
for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '--output' && isset($args[$i + 1])) {
        $outputFile = $args[$i + 1];
        $i++;
    } elseif ($args[$i] === '--base-url' && isset($args[$i + 1])) {
        $baseUrl = $args[$i + 1];
        $i++;
    } elseif ($args[$i] === '--sitemap-url' && isset($args[$i + 1])) {
        $sitemapUrl = $args[$i + 1];
        $i++;
    } elseif ($args[$i] === '--formats' && isset($args[$i + 1])) {
        $formats = parseFormats($args[$i + 1]);
        $i++;
    } elseif ($args[$i] === '--max-urls' && isset($args[$i + 1])) {
        $maxUrls = max(1, (int)$args[$i + 1]);
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
if ($sitemapUrl !== null) {
    $sitemapUrl = trim($sitemapUrl);
}

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

    // Build canonical URL: /phpMan.php/{mode}/{name}[/section]
    $url  = $baseUrl . '/' . rawurlencode($mode) . '/' . rawurlencode($name);
    if ($section !== '' && $section !== '0') {
        $url .= '/' . rawurlencode($section);
    }

    foreach ($formats as $format) {
        $entryUrl = $url;
        if ($format !== 'html') {
            $entryUrl .= '/' . $format;
        }
        $entries[] = [
            'url'        => $entryUrl,
            'lastmod'    => gmdate('Y-m-d', $updated),
            'changefreq' => $changeFreq[$mode] ?? 'monthly',
            'priority'   => priorityFor($mode, $format),
        ];
    }
}
$db->close();

// --- Output ---
if ($outputFile !== null) {
    if ($sitemapUrl === null || $sitemapUrl === '') {
        $sitemapUrl = inferSitemapUrl($baseUrl, $outputFile);
    }
    $writtenFiles = writeSitemaps($outputFile, $sitemapUrl, $entries, $maxUrls);
    echo "Sitemap written: " . count($writtenFiles) . " file(s), " . count($entries) . " URLs\n";
    foreach ($writtenFiles as $path => $count) {
        echo "  - {$path} ({$count} URLs)\n";
    }
    echo "\n";
    echo "👉 Add generated .xml.gz URL(s) to the site sitemap index.\n";
} else {
    echo buildUrlset($entries);
}

function parseFormats(string $value): array {
    $allowed = ['html' => true, 'markdown' => true, 'json' => true];
    $out = [];
    foreach (explode(',', strtolower($value)) as $format) {
        $format = trim($format);
        if (isset($allowed[$format]) && !in_array($format, $out, true)) {
            $out[] = $format;
        }
    }
    return $out ?: ['html'];
}

function priorityFor(string $mode, string $format): string {
    if ($format === 'html') {
        return $mode === 'man' ? '0.8' : '0.7';
    }
    return '0.4';
}

function buildUrlset(array $entries): string {
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
    return implode("\n", $lines);
}

function buildSitemapIndex(array $locs): string {
    $lines   = [];
    $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $lines[] = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($locs as $loc) {
        $lines[] = '  <sitemap>';
        $lines[] = '    <loc>' . h($loc) . '</loc>';
        $lines[] = '    <lastmod>' . gmdate('Y-m-d') . '</lastmod>';
        $lines[] = '  </sitemap>';
    }
    $lines[] = '</sitemapindex>';
    $lines[] = '';
    return implode("\n", $lines);
}

function writeSitemaps(string $outputFile, string $sitemapUrl, array $entries, int $maxUrls): array {
    $chunks = array_chunk($entries, $maxUrls);
    if (count($chunks) === 0) {
        $chunks = [[]];
    }
    $multiple = count($chunks) > 1;
    $written = [];
    $chunkUrls = [];

    foreach ($chunks as $idx => $chunk) {
        $path = $multiple ? chunkPath($outputFile, $idx + 1) : $outputFile;
        $chunkUrls[] = $multiple ? chunkUrl($sitemapUrl, $idx + 1) : $sitemapUrl;
        $xml = buildUrlset($chunk);
        writeXmlMaybeGz($path, $xml);
        $written[$path] = count($chunk);
    }

    if ($multiple) {
        writeXmlMaybeGz($outputFile, buildSitemapIndex($chunkUrls));
        $written = [$outputFile => count($chunkUrls)] + $written;
    }
    return $written;
}

function writeXmlMaybeGz(string $path, string $xml): void {
    if (str_ends_with($path, '.gz')) {
        $data = gzencode($xml, 9);
        if ($data === false) {
            fwrite(STDERR, "Failed to gzip sitemap: {$path}\n");
            exit(1);
        }
    } else {
        $data = $xml;
    }
    if (file_put_contents($path, $data) === false) {
        fwrite(STDERR, "Failed to write: {$path}\n");
        exit(1);
    }
}

function chunkPath(string $outputFile, int $index): string {
    if (str_ends_with($outputFile, '.xml.gz')) {
        return substr($outputFile, 0, -7) . '-' . $index . '.xml.gz';
    }
    if (str_ends_with($outputFile, '.xml')) {
        return substr($outputFile, 0, -4) . '-' . $index . '.xml';
    }
    return $outputFile . '-' . $index;
}

function chunkUrl(string $sitemapUrl, int $index): string {
    return chunkPath($sitemapUrl, $index);
}

function inferSitemapUrl(string $baseUrl, string $outputFile): string {
    $parts = parse_url($baseUrl);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? 'www.chedong.com';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $scheme . '://' . $host . $port . '/' . basename($outputFile);
}
