<?php
/**
 * Unit tests: getSafeHost() / baseUrl() Host header handling
 * Requirement: E2E P11 — a foreign Host must not reach canonical URLs or
 * Schema.org output.
 *
 * Design notes:
 * - getSafeHost() used to accept any *well-formed* HTTP_HOST, which is not a
 *   guard at all: "evil.com" is well-formed. Production reflected it into
 *   <link rel=canonical> and JSON-LD. Staging only looked safe because its
 *   vhost answers 421 before PHP runs, so the e2e test passed there vacuously
 *   and hid the difference.
 * - The install's own PHPMAN_BASE_URL now decides the host; the request's host
 *   is honoured only when it names the same host.
 * - A constant cannot be undefined once defined, so every case runs in a fresh
 *   subprocess. The env var is cleared first so a stray PHPMAN_BASE_URL in the
 *   test runner's environment cannot decide a result.
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

$UTIL = realpath(__DIR__ . '/../../src/util.php');

/**
 * Call a util function in a fresh PHP process with the given $_SERVER and config.
 * $base === null leaves the PHPMAN_BASE_URL constant undefined; $envBase sets
 * the environment variable instead.
 */
function runUtil(string $call, array $server, ?string $base, ?string $envBase = null): string {
    global $UTIL;
    $pre  = $base === null ? '' : 'define("PHPMAN_BASE_URL", ' . var_export($base, true) . ');';
    $code = $pre . '$_SERVER = ' . var_export($server, true) . ';'
          . ' require ' . var_export($UTIL, true) . '; echo ' . $call . ';';
    $cmd  = 'env -u PHPMAN_BASE_URL';
    if ($envBase !== null) $cmd .= ' PHPMAN_BASE_URL=' . escapeshellarg($envBase);
    $cmd .= ' php -r ' . escapeshellarg($code) . ' 2>/dev/null';
    return trim((string)shell_exec($cmd));
}

$PROD = "https://www.chedong.com/phpMan.php";

echo "=== Unit: getSafeHost() Host header handling ===\n\n";

echo "--- the configured host is authoritative ---\n";
assert_equals("www.chedong.com",
    runUtil('getSafeHost()', ["HTTP_HOST" => "www.chedong.com"], $PROD),
    "the install's own Host resolves to itself");
assert_equals("www.chedong.com",
    runUtil('getSafeHost()', ["HTTP_HOST" => "evil.com"], $PROD),
    "REGRESSION: a well-formed foreign Host must not be reflected");
assert_equals("www.chedong.com",
    runUtil('getSafeHost()', ["HTTP_HOST" => "www.chedong.com.evil.com"], $PROD),
    "a suffixed Host is not reflected");
assert_equals("www.chedong.com",
    runUtil('getSafeHost()', ["HTTP_HOST" => "WWW.CHEDONG.COM"], $PROD),
    "the configured spelling is used whatever case the Host used");
assert_equals("www.chedong.com",
    runUtil('getSafeHost()', ["HTTP_HOST" => "www.chedong.com:8080"], $PROD),
    "a port the configured URL does not name is not published");
assert_equals("www.chedong.com",
    runUtil('getSafeHost()', ["HTTP_HOST" => "not a host"], $PROD),
    "a malformed Host falls back to the configured host");
assert_equals("www.chedong.com",
    runUtil('getSafeHost()', [], $PROD),
    "a missing Host falls back to the configured host");

echo "\n--- a port in the configured URL ---\n";
$DEV = "http://localhost:8080/phpMan.php";
assert_equals("localhost:8080",
    runUtil('getSafeHost()', ["HTTP_HOST" => "localhost:8080"], $DEV),
    "the configured port is published");
assert_equals("localhost:8080",
    runUtil('getSafeHost()', ["HTTP_HOST" => "evil.com"], $DEV),
    "the configured port survives a foreign Host");

echo "\n--- the env var is honoured too ---\n";
assert_equals("cfg.example.com",
    runUtil('getSafeHost()', ["HTTP_HOST" => "evil.com"], null, "https://cfg.example.com/phpMan.php"),
    "env PHPMAN_BASE_URL is authoritative");

echo "\n--- unconfigured: nothing to compare against, so the request decides ---\n";
assert_equals("example.com",
    runUtil('getSafeHost()', ["HTTP_HOST" => "example.com"], null),
    "a well-formed Host is used as-is");
assert_equals("server.example",
    runUtil('getSafeHost()', ["HTTP_HOST" => "bad host", "SERVER_NAME" => "server.example"], null),
    "a malformed Host falls back to SERVER_NAME");
assert_equals("localhost",
    runUtil('getSafeHost()', [], null),
    "no Host and no SERVER_NAME falls back to localhost");

echo "\n--- end to end: baseUrl() ---\n";
assert_equals("https://www.chedong.com/phpMan.php",
    runUtil('baseUrl()', ["HTTPS" => "on", "HTTP_HOST" => "evil.com"], $PROD),
    "baseUrl() ignores a foreign Host");
assert_equals("https://www.chedong.com/phpMan.php",
    runUtil('baseUrl()', ["HTTPS" => "on", "HTTP_HOST" => "www.chedong.com"], $PROD),
    "baseUrl() is unchanged for a normal request");
assert_equals("/phpMan.php",
    runUtil('scriptName()', [], $PROD),
    "scriptName() still takes the path from the configured URL");

// #233: the scheme follows the configured URL, same rule as the host. Inferring
// it from the request alone emitted http:// behind a TLS-terminating proxy that
// omits X-Forwarded-Proto, which reached <link rel=canonical> and Schema.org.
echo "\n--- baseUrl() scheme follows the configured URL (#233) ---\n";
assert_equals("https://www.chedong.com/phpMan.php",
    runUtil('baseUrl()', ["HTTP_HOST" => "www.chedong.com"], $PROD),
    "configured https wins even when the request looks like plain http");
assert_equals("http://www.chedong.com/phpMan.php",
    runUtil('baseUrl()', ["HTTPS" => "on", "HTTP_HOST" => "www.chedong.com"],
        "http://www.chedong.com/phpMan.php"),
    "a configured http is honoured over an https-looking request");
assert_equals("https://www.chedong.com/phpMan.php",
    runUtil('baseUrl()', ["HTTP_HOST" => "www.chedong.com"], null, $PROD),
    "the PHPMAN_BASE_URL env var supplies the scheme too");
// Nothing configured: the request is still the only source, as before.
assert_equals("https://localhost/phpMan.php",
    runUtil('baseUrl()', ["HTTPS" => "on", "SCRIPT_NAME" => "/phpMan.php"], null),
    "unconfigured: HTTPS=on still infers https");
assert_equals("http://localhost/phpMan.php",
    runUtil('baseUrl()', ["SCRIPT_NAME" => "/phpMan.php"], null),
    "unconfigured: no HTTPS still infers http");

exit(test_summary());
