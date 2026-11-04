<?php
/**
 * Log a message with automatic context prefix.
 * Web requests: [WEB: full URL]
 * CLI scripts:  [CLI: full command line]
 */
function phpManLog(string $message): void {
    static $context = null;
    if ($context === null) {
        $context = _phpManLogContext();
    }
    error_log("phpMan: {$context} " . $message);
}

/**
 * Write one machine-render telemetry line to its own file (#251).
 *
 * Telemetry is not an error, so it does not go through error_log(): that wrote
 * it into phpman_error.log, where it crowded the error view out of
 * `make logcheck`. Same line format as phpManLog(), different destination —
 * so anything reading the telemetry needs PHPMAN_MF_LOG_FILE, not PHPMAN_LOG_FILE.
 */
function phpManLogMachine(string $message): void {
    if (!defined('PHPMAN_MF_LOG_FILE')) return;
    static $context = null;
    if ($context === null) {
        $context = _phpManLogContext();
    }
    // Mirror error_log()'s own framing so the two logs stay diffable.
    $line = '[' . date('d-M-Y H:i:s T') . '] phpMan: ' . $context . ' ' . $message . "\n";
    // LOCK_EX: several PHP workers append concurrently, and without it two
    // writes can interleave into a torn line. Failures are swallowed on
    // purpose — telemetry must never be able to break a request.
    @file_put_contents(PHPMAN_MF_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Flatten a value for a single-line log field (#244).
 *
 * The page name in a machine-format line comes straight from the URL, so a
 * request for a name containing a newline could otherwise append a forged
 * "[date] …" line to the telemetry file — the file these lines exist to be
 * read from. Control characters collapse to a space and an absurd name is
 * truncated, so the line stays one line of bounded length.
 */
function phpManLogSafeField(string $value, int $maxLen = 120): string {
    $clean = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value);
    if ($clean === null) $clean = '';          // invalid UTF-8 in the subject
    if (strlen($clean) > $maxLen) $clean = substr($clean, 0, $maxLen) . '...';
    return $clean;
}

/**
 * Build the telemetry line for a machine-format render, or null to stay silent.
 *
 * Elapsed is measured from REQUEST_TIME_FLOAT — when PHP started — so it is
 * PHP's own wall time and excludes the mod_fcgid queue wait, which PHP cannot
 * see. Comparing it against the client-observed time in the access log is what
 * exposes that wait.
 *
 * Pure: no side effects, so the threshold and the arithmetic are testable.
 */
function phpManMachineRenderLine(
    string $mode, string $parameter, string $section, string $format,
    string $content, float $startedAt, int $thresholdMs, int $thresholdBytes = 0,
    ?float $now = null, bool $sampled = false
): ?string {
    $now = $now ?? microtime(true);
    $elapsedMs = (int) round(($now - $startedAt) * 1000);
    $bytes = strlen($content);
    // A sampled line bypasses the thresholds by definition — it exists precisely
    // because the render was NOT over them (#256).
    if (!$sampled) {
        if ($thresholdMs <= 0 && $thresholdBytes <= 0) return null;
        // OR, not AND (#249): the requests that lose their body are large, not
        // slow — see the note on PHPMAN_MF_LOG_BYTES in src/config.php.
        $slow = $thresholdMs > 0 && $elapsedMs >= $thresholdMs;
        $large = $thresholdBytes > 0 && $bytes >= $thresholdBytes;
        if (!$slow && !$large) return null;
    }
    $line = sprintf(
        'mf mode=%s name=%s sec=%s fmt=%s elapsed=%dms peak=%.1fMB bytes=%d',
        phpManLogSafeField($mode),
        phpManLogSafeField($parameter),
        phpManLogSafeField($section, 24),
        phpManLogSafeField($format, 16),
        $elapsedMs,
        memory_get_peak_usage(true) / 1048576,
        $bytes
    );
    return $sampled ? $line . ' sampled=1' : $line;
}

/**
 * Deterministic sampling for the HTML telemetry (#256).
 *
 * A fast HTML render is logged only when it samples, and the sample must be a
 * slice of *traffic* — otherwise the log holds only the slow tail and a p95
 * computed over it is a percentile of the bad requests, not of the site.
 *
 * Keyed on the page identity rather than the clock or mt_rand(): the same page
 * always lands on the same side, so a page that samples once keeps sampling.
 * That makes two runs comparable and keeps a single popular page from swinging
 * in and out of the sample. md5 is not a security choice — it is stable and
 * evenly distributed, which is all this needs.
 */
function phpManSampleHit(string $key, float $rate): bool {
    if ($rate <= 0) return false;
    if ($rate >= 1) return true;
    $h = hexdec(substr(md5($key), 0, 8));   // 32 bits, 0 .. 4294967295
    return ($h / 4294967296.0) < $rate;
}

/**
 * Record a machine-format render that took long enough to matter (#244).
 *
 * A 5xx on /markdown, /json or /mcp leaves nothing in phpman_error.log, so
 * "the client abandoned a slow response" and "mod_fcgid killed a slow process"
 * look identical — and they want opposite fixes. This line separates them:
 *
 *   a 5xx with a matching line  -> the render finished; delivery failed
 *   a 5xx with no matching line -> the process died before reaching the end
 *
 * Only renders at or above PHPMAN_MF_LOG_MS are written. That threshold is the
 * whole volume control: measured on staging 2026-10-13, a cache hit renders in
 * 30-90ms and a cold render of the same page in 400-4200ms, so 500 keeps the
 * log proportional to pages crawled for the first time rather than to all
 * 28k/day machine-format requests.
 */
function phpManLogMachineRender(
    string $mode, string $parameter, string $section, string $format, string $content
): void {
    // #256: HTML is included now. It used to be machine formats only, which left
    // the format that produces almost all traffic (and almost all of the 31-Oct
    // load) with no telemetry whatsoever.
    $isMachine = $format === 'markdown' || $format === 'json' || $format === 'mcp';
    $threshold = $isMachine
        ? (defined('PHPMAN_MF_LOG_MS') ? PHPMAN_MF_LOG_MS : 0)
        : (defined('PHPMAN_HTML_LOG_MS') ? PHPMAN_HTML_LOG_MS : 0);
    $thresholdBytes = defined('PHPMAN_MF_LOG_BYTES') ? PHPMAN_MF_LOG_BYTES : 0;
    if ($threshold <= 0 && $thresholdBytes <= 0) return;
    // CLI has no REQUEST_TIME_FLOAT. Stay silent rather than invent a start
    // time and log a meaningless elapsed.
    $startedAt = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
    if (!is_numeric($startedAt)) return;
    $startedAt = (float) $startedAt;
    $line = phpManMachineRenderLine(
        $mode, $parameter, $section, $format, $content, $startedAt,
        (int) $threshold, (int) $thresholdBytes
    );
    if ($line === null && !$isMachine) {
        // Fast HTML render: take the deterministic 1% slice instead, so the log
        // describes traffic rather than only its slow tail.
        $rate = defined('PHPMAN_HTML_SAMPLE') ? (float) PHPMAN_HTML_SAMPLE : 0.0;
        if (phpManSampleHit($mode . '/' . $parameter . '/' . $section . '/' . $format, $rate)) {
            $line = phpManMachineRenderLine(
                $mode, $parameter, $section, $format, $content, $startedAt, 0, 0, null, true
            );
        }
    }
    if ($line !== null) phpManLogMachine($line);
}

// Build context string once per process/request.
// CLI: full command line (php binary + script path + all arguments)
// WEB: full request URL (scheme://host/path?query)
function _phpManLogContext(): string {
    if (PHP_SAPI === 'cli') {
        // $argv[0] = script path, rest = arguments
        $argv = $GLOBALS['argv'] ?? [];
        $parts = [PHP_BINARY];
        foreach ($argv as $arg) {
            $parts[] = $arg;
        }
        return '[CLI: ' . implode(' ', $parts) . ']';
    }
    // Web: full request URL
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return "[WEB: {$scheme}://{$host}{$uri}]";
}
