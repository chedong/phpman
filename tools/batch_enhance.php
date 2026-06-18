#!/usr/bin/env php
<?php
/**
 * batch_enhance.php — Offline batch LLM emoji enhancement for all indexed pages.
 *
 * Iterates entries from search_index_meta (man) plus perldoc/info/pydoc/ri
 * from the cache table, generates HTML/markdown via phpMan's own formatting
 * functions (completely offline, no HTTP dependency), then calls LLM to
 * generate emoji-enhanced versions (emoji_md + emoji_html).
 *
 * Rate-limited: minimum 2 minutes between LLM calls to respect API quotas.
 *
 * Usage:
 *   php tools/batch_enhance.php --status         # Show enhancement progress
 *   php tools/batch_enhance.php --status --stop   # Show status, then stop running batch
 *   php tools/batch_enhance.php --rebuild       # Force re-enhance already-done entries
 *   php tools/batch_enhance.php --mode=man --parameter=ls;tar;gzip  # Specific pages
 *   php tools/batch_enhance.php --rebuild --parameter=CGI::FormBuilder # Force redo one
 *   php tools/batch_enhance.php --dry-run        # Show plan without LLM calls
 *   php tools/batch_enhance.php --mode=man        # Only man pages
 *   php tools/batch_enhance.php --mode=man,perldoc # Multiple modes
 *   php tools/batch_enhance.php --limit=10        # Process max 10 entries
 *   php tools/batch_enhance.php --format=html     # Enhance emoji_html only
 *   php tools/batch_enhance.php --format=md       # Enhance emoji_md only
 *   php tools/batch_enhance.php --format=both     # Both formats (default)
 *   php tools/batch_enhance.php --yes             # Skip confirmation prompt
 *   php tools/batch_enhance.php --skip-errors     # Continue on error
 *   php tools/batch_enhance.php --cached-first    # Prioritize HTML-cached entries
 *
 * Requires phpman.config.php with LLM_API_URL, LLM_API_KEY, LLM_MODEL,
 * LLM_MAX_TOKENS, and PHPMAN_HOME (for the cache DB path).
 *
 * The target phpMan instance URL defaults to PHPMAN_BASE_URL env var,
 * falling back to 'http://localhost:8080/phpMan.php'.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    die("CLI only\n");
}

$opts = getopt('hyr', ['help', 'yes', 'dry-run', 'mode:', 'limit:', 'format:', 'resume-from:', 'skip-errors', 'cached-first', 'status', 'stop', 'pid-file:', 'rebuild', 'section:', 'parameter:']);

// No options → show help
$hasActionOpt = false;
foreach (['help','h','yes','y','dry-run','status','stop'] as $k) {
    if (isset($opts[$k])) { $hasActionOpt = true; break; }
}
if (!$hasActionOpt && !isset($opts['mode']) && !isset($opts['limit']) &&
    !isset($opts['format']) && !isset($opts['resume-from']) &&
    !isset($opts['skip-errors']) && !isset($opts['cached-first'])) {
    $opts['help'] = true;
}

if (isset($opts['help']) || isset($opts['h'])) {
    echo "batch_enhance.php — Offline batch LLM emoji enhancement\n\n";
    echo "Usage:\n";
    echo "  php tools/batch_enhance.php [options]\n\n";
    echo "Options:\n";
    echo "  --status           Show emoji enhancement progress per mode\n";
    echo "  --stop             Stop a running batch (reads PID from --pid-file)\n";
    echo "  --rebuild, -r      Force re-enhance even if emoji cache exists\n";
    echo "  --section=<s>      Manual section (e.g. '1', '3pm') for --parameter targets\n";
    echo "  --parameter=<p>    Specific pages to enhance (semicolon-separated)\n";
    echo "                      Requires --mode. Example: --mode=man --parameter=ls;tar\n";
    echo "  --dry-run          Show what would be done, no LLM calls\n";
    echo "  --yes, -y          Skip confirmation prompt (for cron/SSH)\n";
    echo "  --mode=<m>         Filter: man, perldoc, info, pydoc, ri (comma-separated)\n";
    echo "  --limit=<n>        Max entries to process (default: unlimited)\n";
    echo "  --format=<f>       html, md, or both (default: both)\n";
    echo "  --resume-from=<n>  Skip first N entries\n";
    echo "  --skip-errors      Continue on error instead of aborting\n";
    echo "  --cached-first     Sort: entries with HTML cache first\n";
    echo "  --pid-file=<path>  Write PID to file (auto: PHPMAN_HOME/logs/batch_enhance.pid)\n";
    echo "  --help             Show this help\n";
    exit;
}

// ── Load config ──
$configFile = __DIR__ . '/../phpman.config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "ERROR: phpman.config.php not found at $configFile\n");
    exit(1);
}
require $configFile;

// ── Load phpMan core (offline — no HTTP, no web server needed) ──
// configFile is a symlink to webroot — phpMan.php lives there too.
define('PHPMAN_NO_CLI_DISPATCH', true);
require_once dirname(realpath($configFile)) . '/phpMan.php';

if (!defined('PHPMAN_HOME') || PHPMAN_HOME === '') {
    fwrite(STDERR, "ERROR: PHPMAN_HOME not configured\n");
    exit(1);
}

$dbPath = rtrim(PHPMAN_HOME, '/') . '/db/phpman_cache.db';
$logsDir = rtrim(PHPMAN_HOME, '/') . '/logs';
$pidFile = $opts['pid-file'] ?? ($logsDir . '/batch_enhance.pid');

// ── Stop mode ──
$stopMode = isset($opts['stop']);
if ($stopMode) {
    // Status first if requested
    if (isset($opts['status'])) {
        showStatus($dbPath);
        echo "\n";
    }
    if (!file_exists($pidFile)) {
        echo "No PID file found at {$pidFile}\n";
        echo "No batch_enhance appears to be running.\n";
        exit(0);
    }
    $pid = (int)trim(file_get_contents($pidFile));
    echo "PID file: {$pidFile}\n";
    echo "PID: {$pid}\n";
    if ($pid > 0) {
        // Check if process actually exists
        if (posix_kill($pid, 0)) {
            posix_kill($pid, SIGTERM);
            echo "Sent SIGTERM to PID {$pid}. Waiting...\n";
            sleep(2);
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                echo "Process didn't stop — sent SIGKILL.\n";
            } else {
                echo "Process stopped.\n";
            }
        } else {
            echo "Process {$pid} not found — removing stale PID file.\n";
        }
    }
    @unlink($pidFile);
    echo "PID file removed.\n";
    exit(0);
}

// ── SSL verification skip for localhost ──
// (Not needed for batch, but keep for reference)
// stream_context_set_default(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

// ── Status mode ──
$statusMode = isset($opts['status']);
if ($statusMode) {
    showStatus($dbPath);
    exit;
}

// ── PID file (write after we know we're really running) ──
if (!is_dir($logsDir)) @mkdir($logsDir, 0755, true);
$ownPid = getmypid();
if ($ownPid) {
    file_put_contents($pidFile, (string)$ownPid);
    // Clean up on exit (normal or error)
    register_shutdown_function(function () use ($pidFile, $ownPid) {
        if (file_exists($pidFile) && (int)file_get_contents($pidFile) === $ownPid) {
            @unlink($pidFile);
        }
    });
    echo "PID {$ownPid} → {$pidFile}\n";
    echo "  Stop with: php tools/batch_enhance.php --stop\n";
    echo "            php tools/batch_enhance.php --status --stop\n";
}

foreach (['LLM_API_URL', 'LLM_API_KEY', 'LLM_MODEL', 'LLM_MAX_TOKENS'] as $c) {
    if (!defined($c) || constant($c) === '') {
        fwrite(STDERR, "ERROR: $c not configured in phpman.config.php\n");
        exit(1);
    }
}

$dryRun       = isset($opts['dry-run']);
$autoYes      = isset($opts['yes']) || isset($opts['y']);
$modeFilter   = [];
if (isset($opts['mode'])) {
    $raw = is_array($opts['mode']) ? $opts['mode'] : [$opts['mode']];
    foreach ($raw as $m) {
        foreach (explode(',', $m) as $part) {
            $part = trim($part);
            if ($part !== '') $modeFilter[] = $part;
        }
    }
}
$limit        = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$formatOpt    = $opts['format'] ?? 'both';
$resumeFrom   = isset($opts['resume-from']) ? (int)$opts['resume-from'] : 0;
$skipErrors   = isset($opts['skip-errors']);
$cachedFirst  = isset($opts['cached-first']);
$rebuild      = isset($opts['rebuild']) || isset($opts['r']);
$sectionFilter = $opts['section'] ?? '';
$paramList     = $opts['parameter'] ?? '';
$doMd         = ($formatOpt === 'md' || $formatOpt === 'both');
$doHtml       = ($formatOpt === 'html' || $formatOpt === 'both');

$rateLimitSec = 120; // 2 minutes between LLM calls

// ── Connect to cache DB for discovery queries ──
$dbPath = rtrim(PHPMAN_HOME, '/') . '/db/phpman_cache.db';
if (!file_exists($dbPath)) {
    fwrite(STDERR, "ERROR: cache DB not found at $dbPath\n");
    exit(1);
}
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->busyTimeout(10000);

// ── Discover entries ──
$entries = [];
$seen = [];

if ($paramList !== '') {
    // ── Direct parameter mode: enhance specific pages ──
    $params = array_filter(explode(';', $paramList), fn($s) => trim($s) !== '');
    if (empty($params)) {
        fwrite(STDERR, "ERROR: --parameter list is empty\n");
        exit(1);
    }
    // Default to man if no mode filter specified
    $targetModes = $modeFilter ?: ['man'];
    if (count($targetModes) > 1) {
        fwrite(STDERR, "ERROR: --parameter requires a single --mode (got: " . implode(',', $targetModes) . ")\n");
        exit(1);
    }
    $targetMode = $targetModes[0];
    $section = $sectionFilter; // may be ''
    foreach ($params as $p) {
        $entries[] = ['mode' => $targetMode, 'name' => trim($p), 'section' => $section];
    }
    echo "Target mode: {$targetMode}, " . count($params) . " page(s): " . implode(', ', $params) . "\n";
} else {
// 1. From search_index_meta (man — canonical post-build-index list)
$metaRes = $db->query("SELECT name, section, source FROM search_index_meta ORDER BY source, name");
while ($row = $metaRes->fetchArray(SQLITE3_ASSOC)) {
    $mode = $row['source']; // 'man'
    if ($modeFilter && !in_array($mode, $modeFilter)) continue;
    $key = $mode . '|' . $row['name'];
    $seen[$key] = true;
    $entries[] = ['mode' => $mode, 'name' => $row['name'], 'section' => $row['section']];
}

// 2. Cache-only modes (perldoc, info, pydoc, ri) — discover from cache table
$cacheModes = ['perldoc', 'info', 'pydoc', 'ri'];
foreach ($cacheModes as $cm) {
    if ($modeFilter && !in_array($cm, $modeFilter)) continue;
    $res = $db->query("SELECT DISTINCT name FROM cache WHERE mode='{$cm}' AND name != '__index__' ORDER BY name");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $key = $cm . '|' . $row['name'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $entries[] = ['mode' => $cm, 'name' => $row['name'], 'section' => ''];
    }
}
}

$total = count($entries);
echo "Found {$total} entries total\n";

// ── Annotate with cache status ──
$cacheCheck = new PageCache();
foreach ($entries as $i => $e) {
    $cachedHtml = $cacheCheck->get($e['mode'], $e['name'], '', 'html');
    $htmlOk = ($cachedHtml !== null && $cachedHtml !== '###NOT_FOUND###');
    if ($rebuild) {
        $entries[$i]['_html']       = $htmlOk;
        $entries[$i]['_emoji_md']   = false;
        $entries[$i]['_emoji_html'] = false;
    } else {
        $cachedMd = $cacheCheck->get($e['mode'], $e['name'], '', 'emoji_md');
        $cachedEmHtml = $cacheCheck->get($e['mode'], $e['name'], '', 'emoji_html');
        $entries[$i]['_html']       = $htmlOk;
        $entries[$i]['_emoji_md']   = ($cachedMd !== null && $cachedMd !== '###NOT_FOUND###');
        $entries[$i]['_emoji_html'] = ($cachedEmHtml !== null && $cachedEmHtml !== '###NOT_FOUND###');
    }
}

// Sort: cached-first puts entries with HTML cache (but without emoji) at the front
if ($cachedFirst) {
    usort($entries, function($a, $b) {
        $aReady = $a['_html'] && (!$doMd || !$a['_emoji_md'] || !$doHtml || !$a['_emoji_html']);
        $bReady = $b['_html'] && (!$doMd || !$b['_emoji_md'] || !$doHtml || !$b['_emoji_html']);
        // HTML-cached + needs enhance > needs both > already done
        $aScore = ($a['_html'] ? 1 : 0) + (($doMd && !$a['_emoji_md']) || ($doHtml && !$a['_emoji_html']) ? 1 : 0);
        $bScore = ($b['_html'] ? 1 : 0) + (($doMd && !$b['_emoji_md']) || ($doHtml && !$b['_emoji_html']) ? 1 : 0);
        return $bScore - $aScore;
    });
    echo "Sorted: HTML-cached entries first.\n";
}

// Filter by limit + resume
if ($resumeFrom > 0) {
    $entries = array_slice($entries, $resumeFrom);
    echo "Resuming from offset {$resumeFrom}: " . count($entries) . " remaining\n";
}
if ($limit > 0 && count($entries) > $limit) {
    $entries = array_slice($entries, 0, $limit);
    echo "Limited to {$limit} entries\n";
}

// ── Summary stats ──
$hasHtmlCache = 0; $hasEmojiMd = 0; $hasEmojiHtml = 0; $needsHtml = 0; $needsEmoji = 0;
foreach ($entries as $e) {
    if ($e['_html']) $hasHtmlCache++;
    if ($e['_emoji_md']) $hasEmojiMd++;
    if ($e['_emoji_html']) $hasEmojiHtml++;
    if (!$e['_html']) $needsHtml++;
    $needEmoji = ($doMd && !$e['_emoji_md']) || ($doHtml && !$e['_emoji_html']);
    if ($needEmoji) $needsEmoji++;
}

echo str_repeat('-', 60) . "\n";
echo sprintf("%-30s %s\n", "HTML cache exists:",   "{$hasHtmlCache}/{$total}");
echo sprintf("%-30s %s\n", "emoji_md exists:",     "{$hasEmojiMd}/{$total}");
echo sprintf("%-30s %s\n", "emoji_html exists:",   "{$hasEmojiHtml}/{$total}");
echo sprintf("%-30s %s\n", "Need HTML fetch:",     "{$needsHtml}");
echo sprintf("%-30s %s\n", "Need LLM enhance:",    "{$needsEmoji}");
echo sprintf("%-30s %s\n", "Rate limit:",          "{$rateLimitSec}s between calls");
echo sprintf("%-30s %s\n", "Dry run:",             $dryRun ? 'YES' : 'no');
echo str_repeat('-', 60) . "\n";

if ($dryRun) {
    echo "\nDry run — no changes made.\n";
    echo "Run without --dry-run to execute.\n";
    exit;
}

if ($needsEmoji === 0) {
    echo "\nAll entries already enhanced. Nothing to do.\n";
    exit;
}

// Estimate time
$estCalls = 0;
foreach ($entries as $e) {
    if ($doMd && !$e['_emoji_md']) $estCalls++;
    if ($doHtml && !$e['_emoji_html']) $estCalls++;
}
$estMin = ceil(($estCalls * $rateLimitSec) / 60);
$estHr = floor($estMin / 60);
$estMinRem = $estMin % 60;
$estStr = $estHr > 0 ? "{$estHr}h {$estMinRem}m" : "{$estMin}m";
echo "Estimated: {$estCalls} LLM calls, ~{$estStr}\n\n";

// ── Confirm ──
if (!$autoYes) {
    echo "Press Enter to start (Ctrl+C to abort)...";
    fgets(STDIN);
}

// ── Process ──
$processed = 0;
$enhanced = 0;
$errors = 0;
$lastLlmTime = 0;
$startTime = time();
$totalEntries = count($entries);

foreach ($entries as $idx => $e) {
    $mode = $e['mode'];
    $name = $e['name'];
    $section = $e['section'];
    $label = "{$mode}/{$name}";
    $entryNum = $idx + 1;

    echo "\n[" . date('H:i:s') . "] [{$entryNum}/{$totalEntries}] {$label}\n";

    $mdOk   = $e['_emoji_md'];
    $htmlEOk = $e['_emoji_html'];

    // ── Phase 0: ensure HTML cache (offline, direct function call) ──
    $htmlOk = $e['_html'];
    if (!$htmlOk) {
        echo "  Generating HTML via phpMan...\n";
        $genFn = contentGenerator($mode, $name, $section, 'html');
        $generated = cacheOrExecute($mode, $name, $section, 'html', $genFn);
        if ($generated === '') {
            echo "  Skipped: no content for {$label}\n";
            continue;
        }
        $htmlOk = true;
        echo "  HTML cached (" . strlen($generated) . " chars)\n";
    }

    // ── Phase 1: emoji_md ──
    if ($doMd && !$mdOk) {
        $lastLlmTime = waitForRateLimit($lastLlmTime, $rateLimitSec);

        $pcache = new PageCache();
        $plainMd = $pcache->get($mode, $name, '', 'markdown');
        if ($plainMd === null || $plainMd === '###NOT_FOUND###' || trim($plainMd) === '') {
            // Generate markdown on demand
            $genFn = contentGenerator($mode, $name, $section, 'markdown');
            $plainMd = cacheOrExecute($mode, $name, $section, 'markdown', $genFn);
        }
        if ($plainMd === '' || $plainMd === null) {
            echo "  ERROR: No markdown for {$label}\n";
            $errors++;
            if (!$skipErrors) break;
            continue;
        }

        echo "  Calling LLM for emoji_md (" . strlen($plainMd) . " chars md input)...\n";
        $enhancedMd = callLLM(getMdSystemPrompt(), $plainMd);
        $lastLlmTime = time();

        if ($enhancedMd !== '') {
            $enhancedMd = trim($enhancedMd);
            $enhancedMd = preg_replace('/^```(?:markdown|md)?\s*\n?/m', '', $enhancedMd);
            $enhancedMd = preg_replace('/\n?```\s*$/m', '', $enhancedMd);
            $pcache->set($mode, $name, '', 'emoji_md', $enhancedMd, 'found');
            echo "  [md] {$label}: enhanced (" . strlen($enhancedMd) . " chars)\n";
            $enhanced++;
        } else {
            echo "  [md] {$label}: LLM returned empty — skipping\n";
            $errors++;
        }
    } elseif ($doMd) {
        echo "  [md] already enhanced, skipping\n";
    }

    // ── Phase 2: emoji_html ──
    if ($doHtml && !$htmlEOk) {
        $lastLlmTime = waitForRateLimit($lastLlmTime, $rateLimitSec);

        $pcache = new PageCache();
        $rawHtml = $pcache->get($mode, $name, '', 'html');
        if ($rawHtml === null || $rawHtml === '###NOT_FOUND###' || trim($rawHtml) === '') {
            $genFn = contentGenerator($mode, $name, $section, 'html');
            $rawHtml = cacheOrExecute($mode, $name, $section, 'html', $genFn);
        }
        if ($rawHtml === '' || $rawHtml === null) {
            echo "  ERROR: No HTML for {$label}\n";
            $errors++;
            if (!$skipErrors) break;
            continue;
        }

        echo "  Calling LLM for emoji_html (" . strlen($rawHtml) . " chars html input)...\n";
        $enhancedHtml = callLLM(getHtmlSystemPrompt(), $rawHtml);
        $lastLlmTime = time();

        if ($enhancedHtml !== '') {
            $enhancedHtml = cleanEmojiHtml($enhancedHtml);
            $pcache->set($mode, $name, '', 'emoji_html', $enhancedHtml, 'found');
            echo "  [html] {$label}: enhanced (" . strlen($enhancedHtml) . " chars)\n";
            $enhanced++;
        } else {
            echo "  [html] {$label}: LLM returned empty — skipping\n";
            $errors++;
        }
    } elseif ($doHtml) {
        echo "  [html] already enhanced, skipping\n";
    }

    $processed++;

    // Progress report every 10 entries
    if ($processed > 0 && $processed % 10 === 0) {
        $elapsed = time() - $startTime;
        $rate = $processed > 0 ? round($elapsed / $processed, 1) : 0;
        $eta = $processed > 0 ? round(($totalEntries - $idx - 1) * $rate / 60, 1) : 0;
        echo "  --- Progress: {$processed} done, {$enhanced} enhanced, {$errors} errors, " .
             round($elapsed/60, 1) . "min elapsed (~{$rate}s/item, ETA {$eta}min) ---\n";
    }
}

// ── Final report ──
$elapsed = time() - $startTime;
echo "\n" . str_repeat('=', 60) . "\n";
echo "Done. {$processed} processed, {$enhanced} enhanced, {$errors} errors " .
     "in " . round($elapsed/60, 1) . " minutes.\n";

// ────────────────────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────────────────────

function contentGenerator(string $mode, string $name, string $section, string $format): callable {
    return match ($mode) {
        'man'     => fn() => getManPage($name, $section, $format),
        'perldoc' => fn() => getPerldocPage($name, $format),
        'info'    => fn() => getInfoPage($name, $format),
        'pydoc'   => fn() => getPydocPage($name, $format),
        'ri'      => fn() => getRiPage($name, $format),
        default   => fn() => '',
    };
}

function waitForRateLimit(int $lastCallTime, int $minInterval): int {
    $elapsed = time() - $lastCallTime;
    if ($lastCallTime > 0 && $elapsed < $minInterval) {
        $wait = $minInterval - $elapsed;
        echo "  Rate limit: waiting {$wait}s...";
        for ($i = $wait; $i > 0; $i--) {
            if ($i % 30 === 0 || $i <= 10) echo " {$i}s";
            sleep(1);
        }
        echo "\n";
    }
    return time();
}

function getMdSystemPrompt(): string {
    return "You are a Linux documentation emoji-enhancement assistant. Transform plain man page Markdown into an emoji-rich, visually scannable version optimized for both human developers and AI agents.\n\n" .
        "Output rules:\n" .
        "1. Output ONLY valid Markdown — no HTML tags, no code fences, no JSON wrapper, no preamble\n" .
        "2. Preserve ALL original technical information (options, flags, syntax, descriptions)\n" .
        "3. Do NOT invent new content — only reorganize and decorate existing content\n" .
        "4. Use ONLY Markdown formatting: `backticks` for code, **double stars** for bold, [text](url) for links. NEVER use <code>, <b>, <i>, <a> or any HTML tags.\n" .
        "5. Options and examples MUST use standard Markdown list syntax: start each item with \"- \" (dash+space) followed by content. NEVER use emoji as list markers.\n\n" .
        "Style rules:\n" .
        "- Every ## section heading gets ONE relevant emoji prefix\n" .
        "- ## NAME section: add emoji tagline below heading\n" .
        "- Add a Quick Reference table after SYNOPSIS with common use cases\n" .
        "- Group related options into ### subsections with emoji titles\n" .
        "- Each option row: \"- 📁 `-f`, `--flag`\" — dash+space then descriptive emoji matching the option's purpose. Do NOT use bullet-like emoji (🔹🔸▪️▫️➡️). Use meaningful ones: 📁 for files, 📋 for format, ⏱️ for time/sort, 🎨 for color, 🔗 for links, 🛡️ for security, etc.\n" .
        "- Usage examples: each line annotated with emoji comments after #\n" .
        "- Exit codes section: emoji table\n" .
        "- SEE ALSO section: each reference gets relevant emoji\n" .
        "- Keep all original command syntax and flags exactly as-is\n" .
        "- Emoji should be standard Unicode, widely supported";
}

function getHtmlSystemPrompt(): string {
    return "You are a Linux documentation emoji-enhancement assistant. Transform man page HTML into an emoji-rich, visually scannable version optimized for both human developers and AI agents.\n\n" .
        "CRITICAL heading rules:\n" .
        "1. NEVER use <h1> — the page already has an H1 title. Start from <h2>\n" .
        "2. Section titles (NAME, SYNOPSIS, DESCRIPTION, OPTIONS, EXAMPLES, SEE ALSO) → <h2> with ONE emoji prefix\n" .
        "3. Sub-sections within a section → <h3> with emoji prefix\n" .
        "4. Comments in code examples are NOT headings — do NOT wrap them in <h1>/<h2>/<h3>\n\n" .
        "CRITICAL code block rules:\n" .
        "5. ALL code MUST be wrapped in <pre><code>...</code></pre>\n" .
        "6. Code includes anything with: \$variable, ->method, use Module;, function(), flags like -f --long\n" .
        "7. Even single-line code statements need <pre><code> — never leave code as bare text with <br>\n" .
        "8. Existing <pre><code> blocks: preserve exact content\n\n" .
        "CRITICAL XSS prevention:\n" .
        "9. ANY < or > NOT part of an allowed HTML tag (<h2>, <h3>, <p>, <br>,\n" .
        "   <b>, <u>, <a>, <pre>, <code>, <table>, <tr>, <td>, <th>, <ul>, <ol>,\n" .
        "   <li>, <div>, <span>, <em>, <strong>, <hr>, <blockquote>) MUST be escaped\n" .
        "   as &lt; and &gt;. Example: print qq(<input>) → print qq(&lt;input&gt;)\n" .
        "10. Before output, scan your entire response. Fix any bare < or > outside\n" .
        "    allowed tags. This is a SECURITY requirement.\n\n" .
        "Output rules:\n" .
        "11. Output ONLY valid HTML — no code fences, no JSON wrapper, no preamble\n" .
        "12. Preserve ALL original technical information\n" .
        "13. Do NOT invent new content\n" .
        "14. Preserve <b>, <u>, <a href> tags — they carry semantic meaning\n" .
        "15. Add descriptive emoji to option descriptions and list items\n" .
        "16. Keep original HTML structure intact\n" .
        "17. Emoji should be standard Unicode, widely supported";
}

function showStatus(string $dbPath): void {
    if (!file_exists($dbPath)) {
        echo "No cache DB found at {$dbPath}\n";
        echo "Run php phpMan.php first to initialize the cache.\n";
        exit(0);
    }
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);

    $modes = ['man', 'perldoc', 'info', 'pydoc', 'ri'];
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  phpMan Emoji Enhancement Status\n";
    echo str_repeat('=', 70) . "\n\n";

    $totalAll = 0; $totalMd = 0; $totalHtml = 0;

    foreach ($modes as $mode) {
        $total = $db->querySingle("SELECT COUNT(*) FROM cache WHERE mode='{$mode}' AND format='html' AND name != '__index__'");
        if ($total === null) $total = 0;

        $md = $db->querySingle("SELECT COUNT(*) FROM cache WHERE mode='{$mode}' AND format='emoji_md' AND name != '__index__'");
        $html = $db->querySingle("SELECT COUNT(*) FROM cache WHERE mode='{$mode}' AND format='emoji_html' AND name != '__index__'");

        $total = (int)$total;
        $md = (int)$md;
        $html = (int)$html;

        $totalAll += $total;
        $totalMd += $md;
        $totalHtml += $html;

        $mdPct = $total > 0 ? sprintf('%5.1f%%', $md / $total * 100) : '   N/A';
        $htmlPct = $total > 0 ? sprintf('%5.1f%%', $html / $total * 100) : '   N/A';

        printf("  %-10s  html: %5d  emoji_md: %5d (%s)  emoji_html: %5d (%s)\n",
            $mode, $total, $md, $mdPct, $html, $htmlPct);

        // Show last 3 enhanced for this mode
        if ($md > 0 || $html > 0) {
            $recent = $db->query(
                "SELECT name, format, updated_at FROM cache " .
                "WHERE mode='{$mode}' AND format IN ('emoji_md','emoji_html') " .
                "ORDER BY updated_at DESC LIMIT 3"
            );
            while ($r = $recent->fetchArray(SQLITE3_ASSOC)) {
                $ts = date('m-d H:i', (int)$r['updated_at']);
                printf("    %-8s %-30s %s\n", $r['format'], $r['name'], $ts);
            }
        }
        echo "\n";
    }

    echo str_repeat('-', 70) . "\n";
    printf("  %-10s  html: %5d  emoji_md: %5d (%5.1f%%)  emoji_html: %5d (%5.1f%%)\n",
        'TOTAL', $totalAll, $totalMd,
        $totalAll > 0 ? $totalMd / $totalAll * 100 : 0,
        $totalHtml,
        $totalAll > 0 ? $totalHtml / $totalAll * 100 : 0
    );
    $remaining = ($totalAll * 2) - $totalMd - $totalHtml;
    $estMin = ceil(($remaining * 2) / 60);
    printf("  Remaining LLM calls: ~%d (%d md + %d html), est. ~%dm (@2min/call)\n",
        $remaining, $totalAll - $totalMd, $totalAll - $totalHtml, $estMin);
    echo str_repeat('=', 70) . "\n";

    $db->close();
}
