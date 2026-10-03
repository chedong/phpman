<?php
function h ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

/**
 * Detect if a line (after backspace/ANSI processing) is a section heading,
 * and return its level + text.
 *
 * Handles 4 heading patterns:
 *   Level 1 (##): ALL_CAPS text (perldoc .SH, man .SH bold via **..**)
 *   Level 2 (###): Indented title case (perldoc .SS),
 *                   italic (man .SS via _.._),
 *                   indented bold groups (man .SS bold via **..** **..**)
 *
 * @return array{level:int, text:string}|null
 */
/**
 * Level 2 heading detection strategies.
 * Each returns ['level' => 2, 'text' => ...] or null.
 * Kept as private helpers called only by detectHeadingType().
 */

/**
 * L2: man .SS italic — "_Subheading_" (entire line wrapped in single _)
 * Must check BEFORE L1 because strip '_' from "_Filename_" → "Filename"
 * would otherwise hit the L1 mixed-case regex.
 */

function serverValue (string $key, string $default = ""): string {
    return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : $default;
}

/**
 * The configured public URL of this install: the PHPMAN_BASE_URL constant
 * (set in phpman.config.php), else the env var, else "" for "not configured".
 */
function configuredBaseUrl (): string {
    if (defined('PHPMAN_BASE_URL') && PHPMAN_BASE_URL !== '') return PHPMAN_BASE_URL;
    $env = getenv('PHPMAN_BASE_URL');
    return ($env !== false && $env !== '') ? $env : '';
}

function scriptName (): string {
    // Prefer PHPMAN_BASE_URL over $_SERVER['SCRIPT_NAME'], which returns local
    // filesystem paths in CLI. Falls back to $_SERVER, then default "phpMan.php".
    $path = parse_url(configuredBaseUrl(), PHP_URL_PATH);
    if (is_string($path) && $path !== '') return $path;
    return serverValue("SCRIPT_NAME", "phpMan.php");
}

/**
 * Get a safe host value for canonical URLs and Schema.org output.
 *
 * The install's configured public URL decides the host; the request supplies one
 * only when there is nothing configured to compare against. This is a match, not
 * a format check, and that is the point: "evil.com" is a perfectly well-formed
 * host, so validating HTTP_HOST never kept it out of <link rel=canonical> or
 * JSON-LD — only ignoring it does.
 */
function getSafeHost (): string {
    $base = configuredBaseUrl();
    $configured = parse_url($base, PHP_URL_HOST);
    if (is_string($configured) && $configured !== "") {
        $port = parse_url($base, PHP_URL_PORT);
        return is_int($port) ? $configured . ":" . $port : $configured;
    }

    $host = serverValue("HTTP_HOST", "");
    // Valid host: alphanumeric, hyphens, dots, optional port (e.g., "example.com:8080")
    if ($host !== "" && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?(\:\d+)?$/', $host) === 1) {
        return $host;
    }
    return serverValue("SERVER_NAME", "localhost");
}

/**
 * Get the complete base URL of the current script (e.g. https://www.example.com/phpMan.php).
 *
 * The scheme follows the same rule as the host in getSafeHost(): the configured
 * public URL wins, and the request is consulted only when nothing is configured.
 * Inferring it from the request alone broke behind a TLS-terminating proxy that
 * omits X-Forwarded-Proto — PHPMAN_BASE_URL said https, the request looked like
 * plain http, and <link rel=canonical> plus the Schema.org url went out as
 * http://. (The scheme cannot live in getSafeHost(): that returns host[:port]
 * and its callers pair it with a scheme they compute themselves.)
 */
function baseUrl(): string {
    $proto = parse_url(configuredBaseUrl(), PHP_URL_SCHEME);
    if (!is_string($proto) || $proto === "") {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        // Check X-Forwarded-Proto for TLS-terminating reverse proxies (Nginx, Cloudflare, AWS ELB)
        if ($proto === "http" && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] === "https" ? "https" : "http";
        }
    }
    return $proto . "://" . getSafeHost() . scriptName();
}

/**
 * Check if the current request is from localhost (like phpinfo() visibility).
 * Used to restrict server version disclosure to local access only.
 */
function isLocalRequest (): bool {
    $remoteAddr = serverValue("REMOTE_ADDR", "");
    // Loopback only — private network IPs (RFC1918) are NOT local.
    // Admin actions use CLI or explicit auth, not IP-based trust.
    if (in_array($remoteAddr, ["127.0.0.1", "::1", ""], true)) return true;
    return false;
}

function requestValue (array $source, string $key): string {
    if (!isset($source[$key]) || is_array($source[$key])) {
        return "";
    }

    return trim((string)$source[$key]);
}

/**
 * Get a GET parameter with amp; fallback for double-encoded &amp; workaround.
 * Some server configurations double-encode & to &amp; in query strings,
 * causing parameter names to be prefixed with "amp;".
 */
function getQueryParam (string $key): string {
    $value = requestValue($_GET, $key);
    if ($value !== "") {
        return $value;
    }
    return requestValue($_GET, "amp;" . $key);
}

function normalizeMode ($mode): string {
    $mode = strtolower(trim((string)$mode));
    $allowed_modes = array(
        "man" => true,
        "perldoc" => true,
        "info" => true,
        "search" => true,
        "copyright" => true,
        "mcp" => true,
        "pydoc" => true,
        "ri" => true,
    );

    return isset($allowed_modes[$mode]) ? $mode : "man";
}

function normalizeParameter ($parameter): string {
    $parameter = trim((string)$parameter);
    $parameter = str_replace(array("/", "\0"), array(" ", ""), $parameter);
    $parameter = preg_replace("/[\x00-\x1F\x7F]+/", " ", $parameter);
    // Defense-in-depth: reject unambiguous shell metacharacters.
    // All downstream exec() calls use escapeshellarg(), but this guard catches
    // any future call site that might forget. Allows (){}[] for man references
    // like 'ls(1)', perl modules 'Foo::Bar', Ruby classes 'Array#map'.
    if (preg_match('/[;&|`$!<>\n\r\\\\]/', $parameter)) {
        return '';
    }
    return trim((string)$parameter);
}

function normalizeSection ($section): string {
    $section = trim((string)$section);
    if (preg_match("/^[A-Za-z0-9_]+$/", $section) !== 1) {
        return "";
    }

    return $section;
}

/**
 * Validate raw PATH_INFO before segment parsing. Rejects URL attacks and
 * scanner probes (e.g. /man/DUPLICITY/sftp:/onedrive:/gdocs:/...).
 *
 * Catches four malformed shapes:
 *   - tooLong:  total path length > 100 chars (no valid phpMan URL is this long)
 *   - tooDeep:  more than 5 path segments
 *   - hasProto: ':/' pattern (URI scheme prefix like sftp://, http://)
 *   - hasProtocolColon: any segment contains a ':' that is NOT part of a '::'
 *                       pair (Perl package separator). Single colons indicate
 *                       protocol-prefix crawlers like sftp:, onedrive:, gdocs:.
 *                       Valid Perl modules (Dpkg::Control::HashCore, std::string)
 *                       always have colons in '::' pairs and pass through.
 *
 * Returns the failing condition name, or empty string if PATH_INFO is valid.
 */
function validatePathInfo (string $pathInfo): string {
    if ($pathInfo === "") {
        return "";
    }
    $rawSegments = explode('/', trim($pathInfo, '/'));
    if (strlen($pathInfo) > 100) {
        return "tooLong";
    }
    if (count($rawSegments) > 5) {
        return "tooDeep";
    }
    if (preg_match('#:/#', $pathInfo) === 1) {
        return "hasProto";
    }
    foreach ($rawSegments as $seg) {
        // Count ':' not adjacent to another ':' (i.e. NOT part of Perl '::').
        // Dpkg::Control::HashCore -> 0 single colons (all 4 colons are paired).
        // sftp:onedrive:gdocs:   -> 3 single colons (none are paired).
        $decoded = rawurldecode($seg);
        $singleColons = preg_match_all('/(?<!:):(?!:)/', $decoded);
        if ($singleColons > 0) {
            return "hasProtocolColon";
        }
    }
    return "";
}


// normalizeMode: validate and normalize the display mode parameter