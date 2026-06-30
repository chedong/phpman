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
