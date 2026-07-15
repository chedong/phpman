#!/usr/bin/env php
<?php
/**
 * batch-enhance.php — Offline batch LLM emoji enhancement for all indexed pages.
 *
 * Iterates entries from search_index_meta (man) plus perldoc/info/pydoc/ri
 * from the cache table, generates HTML/markdown via phpMan's own formatting
 * functions (completely offline, no HTTP dependency), then calls LLM to
 * generate emoji-enhanced versions (emoji_md + emoji_html).
 *
 * Rate-limited: minimum 2 minutes between LLM calls to respect API quotas.
 *
 * Usage:
 *   php cli/batch-enhance.php <mode>:<name1>[,<name2>,...]   # Quick single/multi-page
 *   php cli/batch-enhance.php man:ls,tar,grep                # Shorthand for --mode=man --parameter=ls;tar;grep
 *   php cli/batch-enhance.php perldoc:File::Basename         # Also works with --rebuild, etc.
 *   php cli/batch-enhance.php --status         # Show enhancement progress
 *   php cli/batch-enhance.php --status --stop   # Show status, then stop running batch
 *   php cli/batch-enhance.php --rebuild       # Force re-enhance already-done entries
 *   php cli/batch-enhance.php --mode=man --parameter=ls;tar;gzip  # Specific pages
 *   php cli/batch-enhance.php --rebuild --parameter=CGI::FormBuilder # Force redo one
 *   php cli/batch-enhance.php --dry-run        # Show plan without LLM calls
 *   php cli/batch-enhance.php --mode=man        # Only man pages
 *   php cli/batch-enhance.php --mode=man,perldoc # Multiple modes
 *   php cli/batch-enhance.php --limit=10        # Process max 10 entries
 *   php cli/batch-enhance.php --format=html     # Enhance emoji_html only
 *   php cli/batch-enhance.php --format=md       # Enhance emoji_md only
 *   php cli/batch-enhance.php --format=both     # Both formats (default)
 *   php cli/batch-enhance.php --yes             # Skip confirmation prompt
 *   php cli/batch-enhance.php --cached-first    # Prioritize HTML-cached entries
 *
 * Requires phpman.config.php with LLM_API_URL, LLM_API_KEY, LLM_MODEL,
 * LLM_MAX_TOKENS, and PHPMAN_HOME (for the cache DB path).
 */

require __DIR__ . '/_bootstrap.php';

// ── Implicit mode:name positional argument (shorthand for --mode --parameter) ──
// Accepts: php cli/batch-enhance.php man:ls,tar,grep   or   man:ls,tar,grep --rebuild
$posMode = '';
$posParamList = '';
$posArgs = [];
for ($i = 1; $i < ($argc ?? 0); $i++) {
    if (isset($argv[$i]) && $argv[$i][0] !== '-' && preg_match('/^([a-z]+):(.+)$/', $argv[$i], $pm)) {
        $posMode = $pm[1];
        $posParamList = $pm[2]; // comma-separated names
        $posArgs[] = $i; // mark for removal from argv
    }
}
// Remove positional mode:name args so getopt doesn't choke on them
if (!empty($posArgs)) {
    foreach ($posArgs as $idx) unset($argv[$idx]);
    $argv = array_values($argv);
    $argc = count($argv);
}

$opts = getopt('hyrf', ['help', 'yes', 'dry-run', 'mode:', 'limit:', 'format:', 'resume-from:', 'cached-first', 'status', 'stop', 'restart', 'pid-file:', 'rebuild', 'section:', 'parameter:', 'fast', 'cache-only', 'rate-limit:', 'force']);

// Propagate positional mode:name into opts (existing --mode/--parameter take precedence)
if ($posMode !== '' && !isset($opts['mode'])) {
    $opts['mode'] = $posMode;
}
if ($posParamList !== '' && !isset($opts['parameter'])) {
    $opts['parameter'] = str_replace(',', ';', $posParamList); // comma → semicolon
}

// No options → show help
$hasActionOpt = false;
foreach (['help','h','yes','y','dry-run','status','stop','restart'] as $k) {
    if (isset($opts[$k])) { $hasActionOpt = true; break; }
}
if (!$hasActionOpt && !isset($opts['mode']) && !isset($opts['limit']) &&
    !isset($opts['format']) && !isset($opts['resume-from']) &&
    !isset($opts['cached-first'])) {
    $opts['help'] = true;
}

if (isset($opts['help']) || isset($opts['h'])) {
    echo "batch-enhance.php — Offline batch LLM emoji enhancement\n\n";
    echo "Usage:\n";
    echo "  php cli/batch-enhance.php <mode>:<name1>[,<name2>,...] [options]\n";
    echo "  php cli/batch-enhance.php [options]\n\n";
    echo "Quick enhance (shorthand):\n";
    echo "  php cli/batch-enhance.php man:ls                     # Single page\n";
    echo "  php cli/batch-enhance.php man:ls,tar,grep            # Multiple pages\n";
    echo "  php cli/batch-enhance.php man:ls,tar,grep --rebuild  # Force redo\n\n";
    echo "Options:\n";
    echo "  --status           Show enhancement progress + sample URLs per mode\n";
    echo "  --stop             Stop a running batch (reads PID from --pid-file)\n";
    echo "  --restart          Stop + restart batch (requires --mode)\n";
    echo "  --rebuild, -r      Force re-enhance even if emoji cache exists\n";
    echo "  --section=<s>      Manual section (e.g. '1', '3pm') for --parameter targets\n";
    echo "  --parameter=<p>    Specific pages (semicolon-separated, needs --mode)\n";
    echo "  --mode=<m>         Filter: man, perldoc, info, pydoc, ri (comma-separated)\n";
    echo "  --dry-run          Show what would be done, no LLM calls\n";
    echo "  --yes, -y          Skip confirmation prompt (for cron/SSH)\n";
    echo "  --limit=<n>        Max entries to process (default: unlimited)\n";
    echo "  --format=<f>       html, md, or both (default: both)\n";
    echo "  --resume-from=<n>  Skip first N entries\n";
    echo "  --cached-first     Sort: entries with HTML cache first\n";
    echo "  --cache-only       Generate HTML+MD cache only, skip LLM enhancement\n";
    echo "  --rate-limit=<s>   Seconds between LLM calls (default: 60, was 120)\n";
    echo "  --pid-file=<path>  Write PID to file (auto: PHPMAN_HOME/logs/batch_enhance.pid)\n";
    echo "  --force            Auto-kill existing process with same PID file on start\n";
    echo "  --help             Show this help\n";
    echo "\nShorthand <mode>:<name> is equivalent to --mode=<mode> --parameter=<name>.\n";
    echo "Multiple names separated by commas are converted to semicolons automatically.\n";
    exit;
}

$dbPath = rtrim(PHPMAN_HOME, '/') . '/db/phpman_cache.db';
$logsDir = rtrim(PHPMAN_HOME, '/') . '/logs';

// ── Deterministic PID file naming (mode + format → canonical path) ──
// Same options always produce the same PID file → --restart/--stop can find it.
function buildPidFilePath(array $opts, string $logsDir): string {
    // Explicit --pid-file always wins
    if (!empty($opts['pid-file'])) return $opts['pid-file'];

    $mode = isset($opts['mode']) ? strtolower(trim($opts['mode'])) : '';
    // Only single-mode gets a mode-specific PID file
    if ($mode === '' || strpos($mode, ',') !== false) {
        return $logsDir . '/batch_enhance.pid';
    }

    // Standard mappings (same as phpMan.php /status endpoint)
    $modeAbbrev = [
        'man'     => '/tmp/bm',
        'perldoc' => '/tmp/bp',
        'info'    => '/tmp/bi',
        'pydoc'   => '/tmp/bpy',
        'ri'      => '/tmp/br',
    ];
    $base = $modeAbbrev[$mode] ?? ($logsDir . '/batch_' . $mode);

    // Append format suffix for non-default (format=html|md, not "both")
    $format = isset($opts['format']) ? strtolower(trim($opts['format'])) : 'both';
    if ($format === 'html' || $format === 'md') {
        return "{$base}_{$format}.pid";
    }
    return "{$base}.pid";
}

$pidFile = buildPidFilePath($opts, $logsDir);
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
    $pidContent = trim(file_get_contents($pidFile));
    echo "PID file: {$pidFile}\n";
    // #148: PID file format: "PID START_TIME" — validate both to prevent TOCTOU signal race.
    $parts = explode(' ', $pidContent, 2);
    $pid = (int)$parts[0];
    $startTime = isset($parts[1]) ? (int)$parts[1] : 0;
    echo "PID: {$pid}, start time: {$startTime}\n";
    if ($pid > 0) {
        // Check if process actually exists AND has matching start time
        $procStart = 0;
        $statFile = "/proc/{$pid}/stat";
        if (file_exists($statFile)) {
            $stat = file_get_contents($statFile);
            if ($stat && preg_match('/^\d+\s+\([^)]+\)\s+\w+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $stat, $m)) {
                $procStart = (int)$m[1];
            }
        }
        if ($startTime > 0 && $procStart > 0 && $procStart !== $startTime) {
            echo "PID {$pid} is recycled (start time mismatch: expected {$startTime}, got {$procStart}) — will NOT signal. Removing stale PID file.\n";
        } elseif (posix_kill($pid, 0)) {
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

// ── Restart mode: stop existing process, then start a new one ──
$restartMode = isset($opts['restart']);
if ($restartMode) {
    if (!isset($opts['mode'])) {
        fwrite(STDERR, "ERROR: --restart requires --mode\n");
        exit(1);
    }
    if (file_exists($pidFile)) {
        $pidContent = trim(file_get_contents($pidFile));
        $parts = explode(' ', $pidContent, 2);
        $pid = (int)$parts[0];
        if ($pid > 0 && posix_kill($pid, 0)) {
            echo "Stopping existing process PID {$pid}...\n";
            posix_kill($pid, SIGTERM);
            sleep(2);
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                echo "Process didn't stop — sent SIGKILL.\n";
            } else {
                echo "Process stopped.\n";
            }
        }
    }
    @unlink($pidFile);
    echo "Restarting batch-enhance...\n";
    // Fall through to normal execution below
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
    // #148: flock() mutex + existing PID check to prevent race conditions.
    $pidFh = fopen($pidFile, 'c+');
    if ($pidFh && flock($pidFh, LOCK_EX | LOCK_NB)) {
        // Check if an existing process is still alive
        $existing = trim(stream_get_contents($pidFh));
        if ($existing !== '') {
            $parts = explode(' ', $existing, 2);
            $oldPid = (int)$parts[0];
            if ($oldPid > 0 && posix_kill($oldPid, 0)) {
                $force = isset($opts['force']) || isset($opts['yes']) || isset($opts['y']);
                if ($force) {
                    echo "Old process PID {$oldPid} still alive — sending SIGTERM...\n";
                    posix_kill($oldPid, SIGTERM);
                    $waited = 0;
                    while (posix_kill($oldPid, 0) && $waited < 15) {
                        usleep(500000); // 0.5s
                        $waited++;
                    }
                    if (posix_kill($oldPid, 0)) {
                        posix_kill($oldPid, SIGKILL);
                        echo "Sent SIGKILL to PID {$oldPid}.\n";
                        usleep(500000);
                    } else {
                        echo "Old process stopped after {$waited} ticks.\n";
                    }
                } else {
                    fwrite(STDERR, "ERROR: Another batch_enhance is already running (PID {$oldPid}). Use --stop, --restart, or --force first.\n");
                    flock($pidFh, LOCK_UN);
                    fclose($pidFh);
                    exit(1);
                }
            }
            ftruncate($pidFh, 0);
            rewind($pidFh);
        }
        // Store "PID START_TIME" for signal safety (#148)
        // Use /proc/uptime for Linux; fall back to time() on macOS/non-Linux.
        $startTime = 0;
        $uptimeFile = '/proc/uptime';
        if (file_exists($uptimeFile)) {
            $uptime = (int)file_get_contents($uptimeFile);
            $bootTime = (int)shell_exec('cat /proc/stat 2>/dev/null | grep btime | awk ' . "'{print \$2}'") ?: 0;
            $startTime = $bootTime + $uptime;
        } else {
            $startTime = time(); // fallback for non-Linux (macOS, BSD)
        }
        fwrite($pidFh, $ownPid . ' ' . $startTime);
        fflush($pidFh);
        // Clean up on exit (normal or error)
        register_shutdown_function(function () use ($pidFh, $pidFile, $ownPid) {
            flock($pidFh, LOCK_UN);
            fclose($pidFh);
            @unlink($pidFile);
        });
        echo "PID {$ownPid} (start {$startTime}) → {$pidFile}\n";
        echo "  Stop with: php cli/batch-enhance.php --stop\n";
        echo "            php cli/batch-enhance.php --status --stop\n";
    } else {
        fwrite(STDERR, "ERROR: Cannot acquire lock on {$pidFile} — another instance may be starting.\n");
        if ($pidFh) fclose($pidFh);
        exit(1);
    }
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
$cachedFirst  = isset($opts['cached-first']);
$fastMode     = isset($opts['fast']) || isset($opts['f']);
$cacheOnly    = isset($opts['cache-only']);
$rebuild      = isset($opts['rebuild']) || isset($opts['r']);
$sectionFilter = $opts['section'] ?? '';
$paramList     = $opts['parameter'] ?? '';
$doMd         = ($formatOpt === 'md' || $formatOpt === 'both');
$doHtml       = ($formatOpt === 'html' || $formatOpt === 'both');

$rateLimitSec = isset($opts['rate-limit']) ? (int)$opts['rate-limit'] : 60; // seconds between LLM calls

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
// Man pages come from search_index_meta, not the cache table.
$cacheModes = array_values(array_diff(PHPMAN_CONTENT_MODES, ['man']));
foreach ($cacheModes as $cm) {
    if ($modeFilter && !in_array($cm, $modeFilter)) continue;
    $stmtDisc = $db->prepare("SELECT DISTINCT name FROM cache WHERE mode=:mode AND name != '__index__' ORDER BY name");
    $stmtDisc->bindValue(':mode', $cm, SQLITE3_TEXT);
    $res = $stmtDisc->execute();
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
    $htmlOk = ($cachedHtml !== null && !PageCache::isNotFound($cachedHtml));
    if ($rebuild) {
        $entries[$i]['_html']       = $htmlOk;
        $entries[$i]['_emoji_md']   = false;
        $entries[$i]['_emoji_html'] = false;
    } else {
        $cachedMd = $cacheCheck->get($e['mode'], $e['name'], '', 'emoji_md');
        $cachedEmHtml = $cacheCheck->get($e['mode'], $e['name'], '', 'emoji_html');
        $entries[$i]['_html']       = $htmlOk;
        $entries[$i]['_emoji_md']   = ($cachedMd !== null && !PageCache::isNotFound($cachedMd));
        $entries[$i]['_emoji_html'] = ($cachedEmHtml !== null && !PageCache::isNotFound($cachedEmHtml));
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
	echo sprintf("%-30s %s\n", "Rate limit:",          $cacheOnly ? 'N/A (cache-only)' : "{$rateLimitSec}s between calls");
echo sprintf("%-30s %s\n", "Dry run:",             $dryRun ? 'YES' : 'no');
	echo sprintf("%-30s %s\n", "Cache-only:",          $cacheOnly ? 'YES' : 'no');
echo str_repeat('-', 60) . "\n";

if ($dryRun) {
    echo "\nDry run — no changes made.\n";
    echo "Run without --dry-run to execute.\n";
    exit;
}

	if ($cacheOnly) {
	    if ($needsHtml === 0) {
	        echo "\nAll HTML caches already exist. Nothing to do.\n";
	        exit;
	    }
	    echo "Generating content caches for {$needsHtml} entries...\n\n";
	} else if ($needsEmoji === 0) {
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
$consecutiveFailures = 0; // per-entry: both phases must fail to increment
$maxConsecutiveFailures = 10; // abort after N consecutive LLM failures per format
$mdFailures = 0;  // counter for emoji_md (reset on md success)
$htmlFailures = 0; // counter for emoji_html (reset on html success)

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

    // ── cache-only: also generate MD cache, then skip enhancement ──
    if ($cacheOnly) {
        $pcache = new PageCache();
        $plainMd = $pcache->get($mode, $name, '', 'markdown');
        if ($plainMd === null || PageCache::isNotFound($plainMd) || trim($plainMd) === '') {
            $genFn = contentGenerator($mode, $name, $section, 'markdown');
            $plainMd = cacheOrExecute($mode, $name, $section, 'markdown', $genFn);
            if ($plainMd !== '' && $plainMd !== null) {
                echo "  MD cached (" . strlen($plainMd) . " chars)\n";
            }
        } else {
            echo "  MD already cached (" . strlen($plainMd) . " chars)\n";
        }
        $processed++;
        continue;
    }

    // ── Phase 1: emoji_md ──
    if ($doMd && !$mdOk) {
        $lastLlmTime = $fastMode ? 0 : waitForRateLimit($lastLlmTime, $rateLimitSec);

        $pcache = new PageCache();
        $plainMd = $pcache->get($mode, $name, '', 'markdown');
        if ($plainMd === null || PageCache::isNotFound($plainMd) || trim($plainMd) === '') {
            // Generate markdown on demand
            $genFn = contentGenerator($mode, $name, $section, 'markdown');
            $plainMd = cacheOrExecute($mode, $name, $section, 'markdown', $genFn);
        }
        if ($plainMd === '' || $plainMd === null) {
            echo "  SKIP: No markdown for {$label}\n";
            $errors++;
            continue;
        }

        echo "  Calling LLM for emoji_md (" . strlen($plainMd) . " chars md input)...\n";
        $ctx = "{$mode}/{$name} emoji_md";
        $enhancedMd = callLLM(getMdEnhancePrompt(), $plainMd, $ctx);
        $lastLlmTime = time();

        if ($enhancedMd !== '') {
            $enhancedMd = trim($enhancedMd);
            $enhancedMd = preg_replace('/^```(?:markdown|md)?\s*\n?/m', '', $enhancedMd);
            $enhancedMd = preg_replace('/\n?```\s*$/m', '', $enhancedMd);
            $pcache->set($mode, $name, '', 'emoji_md', $enhancedMd, 'found');
            echo "  [md] {$label}: enhanced (" . strlen($enhancedMd) . " chars)\n";
            $enhanced++;
            $mdFailures = 0;
        } else {
            $mdFailures++;
            echo "  [md] {$label}: LLM returned empty — skipping ({$mdFailures}/{$maxConsecutiveFailures} consecutive md)\n";
            $errors++;
            if ($mdFailures >= $maxConsecutiveFailures) {
                echo "\nERROR: {$maxConsecutiveFailures} consecutive MD LLM failures — aborting.\n";
                echo "  Check phpman_error.log for details. Resume with --resume-from=" . ($entryNum - 1) . "\n";
                break;
            }
        }
    } elseif ($doMd) {
        echo "  [md] already enhanced, skipping\n";
    }

    // ── Phase 2: emoji_html ──
    if ($doHtml && !$htmlEOk) {
        $lastLlmTime = $fastMode ? 0 : waitForRateLimit($lastLlmTime, $rateLimitSec);

        $pcache = new PageCache();
        $rawHtml = $pcache->get($mode, $name, '', 'html');
        if ($rawHtml === null || PageCache::isNotFound($rawHtml) || trim($rawHtml) === '') {
            $genFn = contentGenerator($mode, $name, $section, 'html');
            $rawHtml = cacheOrExecute($mode, $name, $section, 'html', $genFn);
        }
        if ($rawHtml === '' || $rawHtml === null) {
            echo "  SKIP: No HTML for {$label}\n";
            $errors++;
            continue;
        }

        echo "  Calling LLM for emoji_html (" . strlen($rawHtml) . " chars html input)...\n";
        $ctx = "{$mode}/{$name} emoji_html";
        $enhancedHtml = callLLM(getHtmlEnhancePrompt(), $rawHtml, $ctx);
        $lastLlmTime = time();

        if ($enhancedHtml !== '') {
            $enhancedHtml = cleanEmojiHtml($enhancedHtml);
            $pcache->set($mode, $name, '', 'emoji_html', $enhancedHtml, 'found');
            echo "  [html] {$label}: enhanced (" . strlen($enhancedHtml) . " chars)\n";
            $enhanced++;
            $htmlFailures = 0;
        } else {
            $htmlFailures++;
            echo "  SKIP: emoji_html LLM returned empty for {$label} (large page?) — skipping ({$htmlFailures}/{$maxConsecutiveFailures} consecutive html)\n";
            $errors++;
            if ($htmlFailures >= $maxConsecutiveFailures) {
                echo "\nERROR: {$maxConsecutiveFailures} consecutive HTML LLM failures — aborting.\n";
                echo "  Check phpman_error.log for details. Resume with --resume-from=" . ($entryNum - 1) . "\n";
                break;
            }
            continue;
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


function showStatus(string $dbPath): void {
    if (!file_exists($dbPath)) {
        echo "No cache DB found at {$dbPath}\n";
        echo "Run php phpMan.php first to initialize the cache.\n";
        exit(0);
    }
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);

    // WAL checkpoint: flush pending writes so status reads latest data.
    // Without this, concurrent batch-enhance writers make status show stale 0 counts.
    // TRUNCATE may fail with SQLITE_BUSY if another process holds a read lock.
    // Fall back to PASSIVE (non-blocking) on failure.
    try {
        $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (\Throwable $e) {
        // TRUNCATE failed — try PASSIVE (doesn't block; may leave some WAL data uncheckpointed)
        try {
            $db->exec('PRAGMA wal_checkpoint(PASSIVE)');
        } catch (\Throwable $e2) {
            // Both failed — continue with possibly stale data
        }
    }

    $baseUrl = defined('PHPMAN_BASE_URL') ? PHPMAN_BASE_URL : (getenv('PHPMAN_BASE_URL') ?: 'http://localhost:8080/phpMan.php');
    $baseUrl = rtrim($baseUrl, '/');
    $modes = PHPMAN_CONTENT_MODES;
    $sampleCount = 3;

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  phpMan Enhancement Status\n";
    echo str_repeat('=', 70) . "\n\n";

    // TLDR cache count
    $tldrCount = $db->querySingle("SELECT COUNT(*) FROM tldr_cache") ?: 0;
    echo "  TLDR cache entries: {$tldrCount}\n\n";

    $totalAll = 0; $totalMd = 0; $totalHtml = 0;

    $stmtCount = $db->prepare("SELECT COUNT(*) FROM cache WHERE mode=:mode AND format=:format AND name != '__index__'");
    $stmtSample = $db->prepare("SELECT name FROM cache WHERE mode=:mode AND format='emoji_html' AND name != '__index__' ORDER BY RANDOM() LIMIT {$sampleCount}");

    foreach ($modes as $mode) {
        // denominator: search_index_meta for man (authoritative), cache for others
        if ($mode === 'man') {
            $total = (int)($db->querySingle("SELECT COUNT(*) FROM search_index_meta WHERE source != 'pydoc3' AND source != 'ri'") ?: 0);
        } else {
            $stmtCount->bindValue(':mode', $mode, SQLITE3_TEXT);
            $stmtCount->bindValue(':format', 'html', SQLITE3_TEXT);
            $result = $stmtCount->execute();
            $total = (int)($result->fetchArray(SQLITE3_NUM)[0] ?: 0);
            $result->finalize();
        }

        // Always bind :mode before emoji counts (man skips the html-count path above)
        $stmtCount->bindValue(':mode', $mode, SQLITE3_TEXT);

        $stmtCount->bindValue(':format', 'emoji_md', SQLITE3_TEXT);
        $result = $stmtCount->execute();
        $md = (int)($result->fetchArray(SQLITE3_NUM)[0] ?: 0);
        $result->finalize();

        $stmtCount->bindValue(':format', 'emoji_html', SQLITE3_TEXT);
        $result = $stmtCount->execute();
        $html = (int)($result->fetchArray(SQLITE3_NUM)[0] ?: 0);
        $result->finalize();

        $totalAll += $total;
        $totalMd += $md;
        $totalHtml += $html;

        $mdPct = $total > 0 ? sprintf('%5.1f%%', $md / $total * 100) : '   N/A';
        $htmlPct = $total > 0 ? sprintf('%5.1f%%', $html / $total * 100) : '   N/A';

        printf("  %-10s  html: %5d  emoji_md: %5d (%s)  emoji_html: %5d (%s)\n",
            $mode, $total, $md, $mdPct, $html, $htmlPct);

        // Show last 2 enhanced (recent) + sample URLs
        if ($md > 0 || $html > 0) {
            $stmtRecent = $db->prepare(
                "SELECT name, format, updated_at FROM cache " .
                "WHERE mode=:mode AND format IN ('emoji_md','emoji_html') " .
                "ORDER BY updated_at DESC LIMIT 2"
            );
            $stmtRecent->bindValue(':mode', $mode, SQLITE3_TEXT);
            $recent = $stmtRecent->execute();
            while ($r = $recent->fetchArray(SQLITE3_ASSOC)) {
                $ts = date('m-d H:i', (int)$r['updated_at']);
                printf("    %-8s %-30s %s\n", $r['format'], $r['name'], $ts);
            }
        }
        // Sample URLs for verification
        if ($html > 0) {
            $stmtSample->bindValue(':mode', $mode, SQLITE3_TEXT);
            $sr = $stmtSample->execute();
            $names = [];
            while ($row = $sr->fetchArray(SQLITE3_ASSOC)) $names[] = $row['name'];
            $sr->finalize();
            if (!empty($names)) {
                echo "    Enhanced samples ({$html}/{$total} pages, emoji_html → default view):\n";
                foreach ($names as $n) echo "      {$baseUrl}/{$mode}/{$n}\n";
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

    // ── Process runtime status ──
    echo "\n┌─ Processes ───────────────────────────────────────────────────────────┐\n";
    $logsDir = rtrim(PHPMAN_HOME, '/') . '/logs';
    $errorLog = $logsDir . '/phpman_error.log';
    $now = time();

    // Known PID file locations per mode
    $pidFiles = [
        'man'     => $logsDir . '/batch_man.pid',
        'perldoc' => '/tmp/bp.pid',
        'info'    => '/tmp/bi.pid',
        'pydoc'   => '/tmp/bpy.pid',
        'ri'      => '/tmp/br.pid',
    ];

    foreach ($pidFiles as $mode => $pidFile) {
        $pid = 0;
        $startTime = 0;
        $running = false;

        if (file_exists($pidFile)) {
            $content = @file_get_contents($pidFile);
            if ($content !== false) {
                $parts = explode(' ', trim($content), 2);
                $pid = (int)($parts[0] ?? 0);
                $startTime = (int)($parts[1] ?? 0);
            }
        }

        if ($pid > 0 && function_exists('posix_kill')) {
            $running = posix_kill($pid, 0);
        } elseif ($pid > 0) {
            // macOS/BSD fallback — check /proc or ps
            $running = (trim((string)@shell_exec("ps -p $pid -o pid= 2>/dev/null")) !== '');
        }

        // Count recent errors for this mode (past hour, or since process start)
        $errCount = 0;
        $sinceTime = $startTime > 0 ? $startTime : ($now - 3600);
        if (file_exists($errorLog)) {
            $escMode = preg_quote($mode, '/');
            $lines = @file($errorLog);
            if ($lines) {
                foreach ($lines as $line) {
                    // Check timestamp roughly — error lines start with [DD-Mon-YYYY]
                    if (preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4})\s+(\d{2}):(\d{2}):(\d{2})\]/', $line, $m)) {
                        $errTs = strtotime("{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}");
                        if ($errTs < $sinceTime) continue;
                    }
                    if (strpos($line, "mode={$mode}") !== false || strpos($line, "--mode={$mode}") !== false) {
                        $errCount++;
                    }
                }
            }
        }

        // Runtime
        $uptime = '';
        if ($running && $startTime > 0) {
            $elapsed = $now - $startTime;
            $h = intdiv($elapsed, 3600);
            $m = intdiv($elapsed % 3600, 60);
            $uptime = ($h > 0 ? "{$h}h{$m}m" : "{$m}m");
        }

        // Status indicator
        $statusIcon = '';
        $statusText = '';
        if ($running) {
            $idleThreshold = 300; // 5 min idle = stalled
            $lastEnhanced = 0;
            // Quick check: when was the last emoji entry written?
            try {
                $lastMd = $db->querySingle("SELECT MAX(updated_at) FROM cache WHERE mode='{$mode}' AND format='emoji_md'") ?: 0;
                $lastHtml = $db->querySingle("SELECT MAX(updated_at) FROM cache WHERE mode='{$mode}' AND format='emoji_html'") ?: 0;
                $lastEnhanced = (int)max($lastMd, $lastHtml);
            } catch (\Throwable $e) {}

            if ($lastEnhanced > 0 && ($now - $lastEnhanced) > $idleThreshold) {
                $statusIcon = '⚠️ ';
                $statusText = "(stalled — last enhanced " . ceil(($now - $lastEnhanced)/60) . "m ago)";
            } elseif ($lastEnhanced > 0) {
                $statusIcon = '✅ ';
                $statusText = "(@ " . date('H:i', $lastEnhanced) . ")";
            }
        } else {
            $statusIcon = '⏸️ ';
            $statusText = '(stopped)';
        }

        printf("  %s%-8s %-10s %5d errors%s  %s\n",
            $statusIcon,
            $mode,
            $running ? "running {$uptime}" : '',
            $errCount,
            ($pid > 0 ? "  PID $pid" : ''),
            $statusText
        );
    }
    echo "└──────────────────────────────────────────────────────────────────────────┘\n";

    // ── Config dump ──
    echo "\n┌─ Config ──────────────────────────────────────────────────────────────┐\n";
    $configs = ["PHPMAN_HOME", "PHPMAN_BASE_URL", "PHPMAN_GA_ID", "PHPMAN_WIDTH", "PHPMAN_TOC_THRESHOLD", "PHPMAN_GZIP_MIN_BYTES", "PHPMAN_TLDR_MAX_EXAMPLES", "PHPMAN_ENHANCE_MAX_CHARS", "LLM_API_URL", "LLM_API_KEY", "LLM_MODEL", "LLM_MAX_TOKENS", "MCP_API_KEY", "PHPMAN_DEBUG", "CACHE_SCHEMA_VERSION"];
    foreach ($configs as $key) {
        $val = defined($key) ? constant($key) : "(not defined)";
        if ($key === "MCP_API_KEY" && $val !== "" && $val !== "(not defined)") $val = substr($val, 0, 8) . "...";
        if ($key === "LLM_API_KEY" && $val !== "" && $val !== "(not defined)") $val = substr($val, 0, 8) . "...";
        if ($key === "LLM_API_URL" && strlen($val) > 45) $val = substr($val, 0, 42) . "...";
        printf("  %-28s %s\n", $key . ":", $val);
    }
    echo "└──────────────────────────────────────────────────────────────────────────┘\n";


    $db->close();
}