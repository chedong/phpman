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

function scriptName (): string {
    // Prefer PHPMAN_BASE_URL constant (set in phpman.config.php) over
    // $_SERVER['SCRIPT_NAME'] which returns local filesystem paths in CLI.
    // Falls back to env var, then $_SERVER, then default "phpMan.php".
    if (defined('PHPMAN_BASE_URL') && PHPMAN_BASE_URL !== '') {
        $path = parse_url(PHPMAN_BASE_URL, PHP_URL_PATH);
        if ($path !== null && $path !== false && $path !== '') return $path;
    }
    $envUrl = getenv('PHPMAN_BASE_URL');
    if ($envUrl !== false && $envUrl !== '') {
        $path = parse_url($envUrl, PHP_URL_PATH);
        if ($path !== null && $path !== false && $path !== '') return $path;
    }
    return serverValue("SCRIPT_NAME", "phpMan.php");
}

/**
 * Get a safe host value, validating HTTP_HOST against RFC 3986 format.
 * Falls back to SERVER_NAME if HTTP_HOST is malformed or missing.
 * Prevents Host header injection attacks on canonical URLs and Schema.org output.
 */
function getSafeHost (): string {
    $host = serverValue("HTTP_HOST", "");
    // Valid host: alphanumeric, hyphens, dots, optional port (e.g., "example.com:8080")
    if ($host !== "" && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?(\:\d+)?$/', $host) === 1) {
        return $host;
    }
    return serverValue("SERVER_NAME", "localhost");
}

/**
 * Get the complete base URL of the current script (e.g. https://www.example.com/phpMan.php).
 */
function baseUrl(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
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


// normalizeMode: validate and normalize the display mode parameter