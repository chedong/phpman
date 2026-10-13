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
 * Flatten a value for a single-line log field (#244).
 *
 * The page name in a machine-format line comes straight from the URL, so a
 * request for a name containing a newline could otherwise append a forged
 * "[date] PHP Fatal error: ..." line to phpman_error.log — the file this
 * telemetry exists to be read from. Control characters collapse to a space and
 * an absurd name is truncated, so the line stays one line of bounded length.
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
    string $content, float $startedAt, int $thresholdMs, ?float $now = null
): ?string {
    if ($thresholdMs <= 0) return null;
    $now = $now ?? microtime(true);
    $elapsedMs = (int) round(($now - $startedAt) * 1000);
    if ($elapsedMs < $thresholdMs) return null;
    return sprintf(
        'mf mode=%s name=%s sec=%s fmt=%s elapsed=%dms peak=%.1fMB bytes=%d',
        phpManLogSafeField($mode),
        phpManLogSafeField($parameter),
        phpManLogSafeField($section, 24),
        phpManLogSafeField($format, 16),
        $elapsedMs,
        memory_get_peak_usage(true) / 1048576,
        strlen($content)
    );
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
    $threshold = defined('PHPMAN_MF_LOG_MS') ? PHPMAN_MF_LOG_MS : 0;
    if ($threshold <= 0) return;
    // CLI has no REQUEST_TIME_FLOAT. Stay silent rather than invent a start
    // time and log a meaningless elapsed.
    $startedAt = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
    if (!is_numeric($startedAt)) return;
    $line = phpManMachineRenderLine(
        $mode, $parameter, $section, $format, $content, (float) $startedAt, (int) $threshold
    );
    if ($line !== null) phpManLog($line);
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
