#!/usr/bin/env php
<?php
// cli/metrics.php — turn the logs into numbers (#256).
//
// Why
// ---
// Every question about this site was being answered by grepping a log by hand:
// "what is p95", "which 5xx family", "how many slots did we lose". That works
// once and is unrepeatable the next day — and `phpman_error.log` rotates on its
// own schedule, so the evidence is often gone before anyone asks. This tool
// computes the same answers from the files that are still there, in a form both
// a human (`--json`) and a scrape (`--out`, Prometheus textfile) can read.
//
// Inputs (all optional — a missing file is reported, not fatal):
//   $PHPMAN_MF_LOG_FILE       render telemetry, machine formats and sampled HTML
//   $PHPMAN_LOG_FILE          PHP errors/fatals
//   $PHPMAN_WEBLOG_DIR/access.log        requests, statuses, response sizes
//   $PHPMAN_WEBLOG_DIR/error.log         mod_fcgid slot exhaustion
//   the page-cache shards                 rows by mode and status
//
// PHPMAN_WEBLOG_DIR is site-specific on purpose (same contract as
// cli/log-analyze.sh): this file carries no domain or path of its own.
//
// Usage
//   php cli/metrics.php --since=1h --out=$HOME/.phpman/metrics/phpman.prom
//   php cli/metrics.php --since=24h --json
//   php cli/metrics.php --check-slo        # non-zero when an SLO is violated
//
// Deliberately NOT done here: the per-family 5xx pairing (EPIPE vs no-record).
// That logic lives in cli/logcheck.sh, which reaches the server over stdin
// (`ssh host bash -s < logcheck.sh`) and therefore cannot include a sibling
// file — a second copy here would be a second implementation with its own bugs.
// This tool reports the totals the SLOs need; use `make logcheck` to attribute
// them.

require __DIR__ . '/_bootstrap.php';

// ─── arguments ───────────────────────────────────────────────────────────────

$since   = '1h';
$outFile = null;
$asJson  = false;
$checkSlo = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--since=')) {
        $since = substr($arg, 8);
    } elseif (str_starts_with($arg, '--out=')) {
        $outFile = substr($arg, 6);
    } elseif ($arg === '--json') {
        $asJson = true;
    } elseif ($arg === '--check-slo') {
        $checkSlo = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "用法: php cli/metrics.php [--since=1h] [--out=FILE] [--json] [--check-slo]\n");
        fwrite(STDOUT, "  --since   窗口：30m / 1h / 24h / 7d，或秒数（默认 1h）\n");
        fwrite(STDOUT, "  --out     Prometheus textfile（原子写：先 .tmp 再 rename）\n");
        fwrite(STDOUT, "  --json    人读快照\n");
        fwrite(STDOUT, "  --check-slo  违反 SLO 时退出 1\n");
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

/** "1h" / "30m" / "7d" / "90" → seconds. */
function parseSince(string $s): int {
    if (preg_match('/^(\d+)([smhd]?)$/', $s, $m) !== 1) return 3600;
    $n = (int) $m[1];
    return match ($m[2]) {
        's' => $n,
        'm' => $n * 60,
        'd' => $n * 86400,
        default => $n * 3600,
    };
}

$window = parseSince($since);

// ─── reading ─────────────────────────────────────────────────────────────────

// A log can be large and this runs on a shared host: read a bounded tail, not
// the whole file. 200k lines covers well over a day of this site's traffic.
const MAX_LINES = 200000;

function tailLines(string $path, int $max = MAX_LINES): array {
    if (!is_file($path) || !is_readable($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    return count($lines) > $max ? array_slice($lines, -$max) : $lines;
}

/** Parse a timestamp to epoch seconds, or null. Accepts both log formats. */
function parseStamp(string $s): ?int {
    // phpMan log:  26-Oct-2026 21:51:03 America/Los_Angeles
    // Apache log:  18/Oct/2026:17:22:43 -0700
    // The numeric-month variant is not what PHP writes, but fixtures and
    // hand-edited logs produce it often enough to be worth accepting.
    foreach (['d-M-Y H:i:s T', 'd-m-Y H:i:s T', 'd/M/Y:H:i:s O'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d !== false) return $d->getTimestamp();
    }
    return null;
}

/** Nearest-rank percentile of an already-sorted list. */
function percentile(array $sorted, float $q): ?float {
    $n = count($sorted);
    if ($n === 0) return null;
    $idx = (int) ceil($q * $n) - 1;
    $idx = max(0, min($n - 1, $idx));
    return (float) $sorted[$idx];
}

$cutoff = time() - $window;
$notes  = [];

// ─── render telemetry ────────────────────────────────────────────────────────
// `mf mode=man name=x sec=1 fmt=json elapsed=2205ms peak=16.0MB bytes=1646555`
$render   = [];   // "mode|fmt" => ['durations' => [], 'bytes' => [], 'sampled' => n]
$mfPath   = defined('PHPMAN_MF_LOG_FILE') ? PHPMAN_MF_LOG_FILE : '';
$mfLines  = tailLines($mfPath);
$mfSeen   = 0;
foreach ($mfLines as $line) {
    if (preg_match('/^\[([^\]]+)\].*?\bmf mode=(\S+) .*?\bfmt=(\S+) elapsed=(\d+)ms .*?\bbytes=(\d+)(\s+sampled=1)?/', $line, $m) !== 1) {
        continue;
    }
    $ts = parseStamp($m[1]);
    if ($ts === null || $ts < $cutoff) continue;
    $key = $m[2] . '|' . $m[3];
    $render[$key]['durations'][] = (int) $m[4];
    $render[$key]['bytes'][]     = (int) $m[5];
    $render[$key]['sampled']     = ($render[$key]['sampled'] ?? 0) + (isset($m[6]) && $m[6] !== '' ? 1 : 0);
    $mfSeen++;
}
if ($mfPath === '') {
    $notes[] = 'PHPMAN_MF_LOG_FILE 未定义，渲染指标为空';
} elseif ($mfLines === []) {
    $notes[] = "读不到 {$mfPath}（不存在或不可读）";
}

// ─── HTTP ────────────────────────────────────────────────────────────────────
$weblogDir = getenv('PHPMAN_WEBLOG_DIR') ?: '';
$http = ['requests' => 0, '5xx' => 0, '5xx_zero_byte' => 0, 'window_from' => null, 'window_to' => null];
if ($weblogDir !== '' && is_dir($weblogDir)) {
    foreach (tailLines(rtrim($weblogDir, '/') . '/access.log') as $line) {
        // IP - - [18/Oct/2026:17:22:43 -0700] "GET /x HTTP/1.1" 500 83521 "ref" "ua"
        if (preg_match('/\[([^\]]+)\] "[^"]*" (\d{3}) (\d+|-)/', $line, $m) !== 1) continue;
        $ts = parseStamp($m[1]);
        if ($ts === null || $ts < $cutoff) continue;
        $http['requests']++;
        $http['window_from'] ??= $m[1];
        $http['window_to'] = $m[1];
        if ($m[2][0] === '5') {
            $http['5xx']++;
            $bytes = $m[3] === '-' ? 0 : (int) $m[3];
            if ($bytes === 0) $http['5xx_zero_byte']++;
        }
    }
} else {
    $notes[] = 'PHPMAN_WEBLOG_DIR 未设置或不存在，HTTP 指标为空';
}

// ─── slot exhaustion ─────────────────────────────────────────────────────────
$slots = 0;
if ($weblogDir !== '' && is_dir($weblogDir)) {
    foreach (tailLines(rtrim($weblogDir, '/') . '/error.log') as $line) {
        if (strpos($line, "can't apply process slot") === false) continue;
        if (preg_match('/^\[([^\]]+)\]/', $line, $m) !== 1) continue;
        $ts = parseStamp($m[1]);
        if ($ts !== null && $ts >= $cutoff) $slots++;
    }
}

// ─── cache rows ──────────────────────────────────────────────────────────────
$cacheRows = [];   // "mode|status" => n
foreach (pageCacheModes() as $mode) {
    $db = pageCacheDb($mode);
    if (!$db) continue;
    try {
        $res = $db->query("SELECT status, COUNT(*) AS n FROM cache GROUP BY status");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $cacheRows[$mode . '|' . $row['status']] = (int) $row['n'];
        }
    } catch (\Throwable $e) {
        $notes[] = "cache {$mode}: " . $e->getMessage();
    }
}

// ─── assemble ────────────────────────────────────────────────────────────────
$p95Machine = null;
$p95Html    = null;
$durations  = ['machine' => [], 'html' => []];
foreach ($render as $key => $r) {
    [$mode, $fmt] = explode('|', $key, 2);
    $sorted = $r['durations'];
    sort($sorted);
    $bucket = ($fmt === 'html') ? 'html' : 'machine';
    $durations[$bucket] = array_merge($durations[$bucket], $sorted);
}
foreach ($durations as $bucket => $list) {
    sort($list);
    $p = percentile($list, 0.95);
    if ($bucket === 'html') $p95Html = $p; else $p95Machine = $p;
}
$ratio = $http['requests'] > 0 ? $http['5xx'] / $http['requests'] : null;

// SLOs (#256), evaluated only when the underlying input exists — an empty
// window must never read as a pass.
$slos = [
    ['machine p95 < 3s',   $p95Machine !== null ? $p95Machine < 3000 : null, $p95Machine !== null ? round($p95Machine) . 'ms' : 'n/a'],
    ['HTML p95 < 2s',      $p95Html    !== null ? $p95Html    < 2000 : null, $p95Html    !== null ? round($p95Html) . 'ms'    : 'n/a'],
    ['5xx ratio < 1%',     $ratio      !== null ? $ratio      < 0.01 : null, $ratio      !== null ? sprintf('%.2f%%', $ratio * 100) : 'n/a'],
    ['slot exhausted = 0', $slots === 0 ? true : false, (string) $slots],
];

if ($checkSlo) {
    $bad = 0;
    foreach ($slos as [$label, $ok, $val]) {
        printf("%s %-22s %s\n", $ok === false ? '✗' : ($ok === null ? '·' : '✓'), $label, $val);
        if ($ok === false) $bad++;
    }
    exit($bad > 0 ? 1 : 0);
}

// ─── output: JSON ────────────────────────────────────────────────────────────
if ($asJson) {
    $snapshot = [
        'window'   => $since,
        'render'   => [],
        'http'     => $http,
        'slot_exhausted' => $slots,
        'cache'    => $cacheRows,
        'slo'      => array_map(fn($s) => ['label' => $s[0], 'pass' => $s[1], 'value' => $s[2]], $slos),
        'notes'    => $notes,
    ];
    foreach ($render as $key => $r) {
        [$mode, $fmt] = explode('|', $key, 2);
        $sorted = $r['durations'];
        sort($sorted);
        $b = $r['bytes'];
        sort($b);
        $snapshot['render'][] = [
            'mode' => $mode, 'format' => $fmt,
            'count' => count($sorted),
            'sampled' => $r['sampled'],
            'elapsed_p50' => percentile($sorted, 0.50),
            'elapsed_p95' => percentile($sorted, 0.95),
            'bytes_p95' => percentile($b, 0.95),
        ];
    }
    echo json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

// ─── output: Prometheus textfile ─────────────────────────────────────────────
$lines = [];
$lines[] = '# HELP phpman_render_count Renders observed in the window (HTML is sampled 1%).';
$lines[] = '# TYPE phpman_render_count gauge';
foreach ($render as $key => $r) {
    [$mode, $fmt] = explode('|', $key, 2);
    $lines[] = sprintf('phpman_render_count{mode="%s",format="%s"} %d', $mode, $fmt, count($r['durations']));
}
$lines[] = '# TYPE phpman_render_duration_ms gauge';
foreach ($render as $key => $r) {
    [$mode, $fmt] = explode('|', $key, 2);
    $sorted = $r['durations'];
    sort($sorted);
    // A list of pairs, NOT [0.5 => ..., 0.95 => ...]: PHP casts float array
    // keys to int, so both would collapse to key 0 and every "quantile" would
    // silently be p0 — the minimum. That is what the first version did.
    foreach ([[0.50, '0.50'], [0.95, '0.95']] as [$q, $label]) {
        $v = percentile($sorted, $q);
        if ($v === null) continue;
        $lines[] = sprintf('phpman_render_duration_ms{mode="%s",format="%s",quantile="%s"} %s', $mode, $fmt, $label, $v);
    }
}
$lines[] = '# TYPE phpman_render_bytes gauge';
foreach ($render as $key => $r) {
    [$mode, $fmt] = explode('|', $key, 2);
    $b = $r['bytes'];
    sort($b);
    $v = percentile($b, 0.95);
    if ($v !== null) {
        $lines[] = sprintf('phpman_render_bytes{mode="%s",format="%s",quantile="0.95"} %s', $mode, $fmt, $v);
    }
}
$lines[] = '# TYPE phpman_http_requests_total gauge';
$lines[] = sprintf('phpman_http_requests_total %d', $http['requests']);
$lines[] = '# TYPE phpman_http_5xx_total gauge';
$lines[] = sprintf('phpman_http_5xx_total %d', $http['5xx']);
$lines[] = '# TYPE phpman_http_5xx_zero_byte_total gauge';
$lines[] = sprintf('phpman_http_5xx_zero_byte_total %d', $http['5xx_zero_byte']);
if ($ratio !== null) {
    $lines[] = '# TYPE phpman_http_5xx_ratio gauge';
    $lines[] = sprintf('phpman_http_5xx_ratio %.6f', $ratio);
}
$lines[] = '# TYPE phpman_slot_exhausted_total gauge';
$lines[] = sprintf('phpman_slot_exhausted_total %d', $slots);
$lines[] = '# TYPE phpman_cache_rows gauge';
foreach ($cacheRows as $key => $n) {
    [$mode, $status] = explode('|', $key, 2);
    $lines[] = sprintf('phpman_cache_rows{mode="%s",status="%s"} %d', $mode, $status, $n);
}
$lines[] = '# TYPE phpman_metrics_window_seconds gauge';
$lines[] = sprintf('phpman_metrics_window_seconds %d', $window);

$payload = implode("\n", $lines) . "\n";

if ($outFile === null) {
    echo $payload;
    foreach ($notes as $n) fwrite(STDERR, "note: {$n}\n");
    exit(0);
}

// Atomic: a scrape must never see a half-written file.
$dir = dirname($outFile);
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "无法创建目录 {$dir}\n");
    exit(2);
}
$tmp = $outFile . '.tmp';
if (@file_put_contents($tmp, $payload) === false || !@rename($tmp, $outFile)) {
    @unlink($tmp);
    fwrite(STDERR, "无法写入 {$outFile}\n");
    exit(2);
}
foreach ($notes as $n) fwrite(STDERR, "note: {$n}\n");
exit(0);
