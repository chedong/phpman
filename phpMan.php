<?php
declare(strict_types=1);
// +--------------------------------------------------------------------------------+
// | phpMan:      Unix Man page / Perldoc / Info page Web Interface                 |
// +--------------------------------------------------------------------------------+
// | Copyright (C) 2002 - 2026 Che, Dong chedong AT chedong.com                     |
// +--------------------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or                  |
// | modify it under the terms of the GNU General Public License                    |
// | the Free Software Foundation; either version 2 of the License, or              |
// | (at your option) any later version.                                            |
// |                                                                                |
// | This program is distributed in the hope that it will be useful,                |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of                 |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                  |
// | GNU General Public License for more details.                                   |
// |                                                                                |
// | You should have received a copy of the GNU General Public License              |
// | along with this program; if not, write to the Free Software                    |
// | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.    |
// +--------------------------------------------------------------------------------+
// $Id$

// Default terminal width for man/perldoc output (used as MANROFFOPT -rLL=NNNn)
define('MAN_PAGE_WIDTH', 100);         // #49: character width for man/perldoc output
$PHP_MAN_WIDTH = MAN_PAGE_WIDTH;

// ASCII character classes for overstrike pattern matching:
// RE_ASCII — plain printable ASCII, for raw terminal output (cleanTerminalOutput)
// RE_ASCII_SAFE — printable + placeholder bytes \x05\x06\x07 for &<>, used after
//                 formatManPerlDoc() replaces &<> with placeholders
define('RE_ASCII', '[ -~]');
define('RE_ASCII_SAFE', '[ -~' . "\x05\x06\x07" . ']');

// #49: Named constants for magic numbers
define('PHPMAN_VERSION', '3.0');        // current version (#67)
define('GIT_DESCRIBE', 'local');         // replaced by make deploy/release with git describe --tags
define('TOC_LINE_THRESHOLD', 80);      // min lines to show TOC sidebar
define('GZIP_MIN_BYTES', 1000);        // min response size for gzip compression
define('FLAG_DESC_MAX_LEN', 120);      // max length for flag descriptions
define('TLDR_MAX_EXAMPLES', 16);       // max examples in TLDR output


// --- Shared helper functions (#44: DRY refactoring) ---
// --- Load site-specific config (phpman.config.php) ---
// Uses define() pattern like WordPress wp-config.php — constants defined
// in phpman.config.php override the defaults below.
$_config_file = dirname(__FILE__) . '/phpman.config.php';
if (file_exists($_config_file)) {
    require $_config_file;
}

// Cache constants: phpman.config.php > defaults
if (!defined('CACHE_DIR')) define('CACHE_DIR', dirname(__FILE__) . '/cache');
define('CACHE_DB', CACHE_DIR . '/phpm_cache.db');
define('CACHE_SCHEMA_VERSION', '3');

// Debug mode: phpman.config.php > env var > default false
if (!defined('PHPMAN_DEBUG')) define('PHPMAN_DEBUG', getenv('PHPMAN_DEBUG') === 'true');
Profiler::init();

// LLM config (v3.0 reserved, not yet used)
if (!defined('LLM_API_KEY'))    define('LLM_API_KEY', '');
if (!defined('LLM_API_URL'))    define('LLM_API_URL', '');
if (!defined('LLM_MODEL'))      define('LLM_MODEL', '');
if (!defined('LLM_MAX_TOKENS')) define('LLM_MAX_TOKENS', 4096);

unset($_config_file);

/**
 * Lightweight request profiler — records timing marks at key lifecycle points.
 * Active only when PHPMAN_DEBUG is true. Results injected into output per format:
 *   HTML: visible footer block; Markdown: HTML comment; JSON/MCP: _profiling key
 */
class Profiler {
    private static array $marks = [];
    private static float $startTime = 0.0;
    private static bool $enabled = false;

    public static function init(): void {
        self::$enabled = defined('PHPMAN_DEBUG') && PHPMAN_DEBUG;
        if (self::$enabled) {
            self::$startTime = microtime(true);
            self::$marks = [['init', 0.0]];
        }
    }

    public static function mark(string $label): void {
        if (!self::$enabled) return;
        self::$marks[] = [$label, microtime(true) - self::$startTime];
    }

    public static function getReport(): array {
        if (!self::$enabled || count(self::$marks) < 2) return [];
        $report = [];
        $prevTime = 0.0;
        foreach (self::$marks as [$label, $time]) {
            $report[] = [
                'label' => $label,
                'elapsed_ms' => round($time * 1000, 2),
                'delta_ms' => round(($time - $prevTime) * 1000, 2),
            ];
            $prevTime = $time;
        }
        $report['_total_ms'] = round((microtime(true) - self::$startTime) * 1000, 2);
        $report['_version'] = '1';
        return $report;
    }

    public static function getEnabled(): bool {
        return self::$enabled;
    }
}

/**
 * Render profiling data as an HTML block for debug mode in showFooter().
 */
function profilerHtmlBlock (): string {
    $report = Profiler::getReport();
    if (empty($report)) return '';
    $total = $report['_total_ms'] ?? 0;
    unset($report['_total_ms'], $report['_version']);
    $rows = '';
    foreach ($report as $r) {
        $label = h($r['label']);
        $rows .= '<tr>'
               . '<td>' . $label . '</td>'
               . '<td style="text-align:right;padding:0 6px">' . $r['delta_ms'] . 'ms</td>'
               . '<td style="text-align:right;padding:0 6px">' . $r['elapsed_ms'] . 'ms</td>'
               . '</tr>';
    }
    return '<div id="profiling" style="margin:16px 0;padding:8px;border:1px solid #e0e0e0;background:#f5f5f5;font-size:12px;color:#333;font-family:monospace;">'
         . '<strong>Profiling (' . $total . 'ms total)</strong>'
         . '<table style="width:auto;border-collapse:collapse;">'
         . '<tr style="border-bottom:1px solid #ccc;"><th style="text-align:left">Phase</th><th style="text-align:right;padding:0 6px">Delta</th><th style="text-align:right;padding:0 6px">Elapsed</th></tr>'
         . $rows
         . '</table></div>';
}

/**
 * Log a message to the server error log. (#47)
 * Prefixes with "phpMan:" for easy grep/filter.
 */
function phpManLog (string $message): void {
    error_log("phpMan: " . $message);
}

/**
 * Get MCP tool definitions (shared by .well-known and tools/list).  (#48)
 */
function getMcpToolDefinitions (): array {
    return [
        [
            "name" => "cli_help",
            "description" => "Get structured man / perldoc / info / pydoc3 / ri page for a command or module. Returns sections with sub-sections, synopsis, and full content. Supports all Unix/Linux commands, Perl modules (e.g. File::Basename), and GNU info pages.",
            "inputSchema" => [
                "type" => "object",
                "properties" => [
                    "command" => [
                        "type" => "string",
                        "description" => "Command or module name (e.g. 'ls', 'git', 'File::Basename', 'bash', 'json', 'Array#map')"
                    ],
                    "section" => [
                        "type" => "string",
                        "description" => "Optional manual section number (e.g. '1' for user commands, '3pm' for Perl modules). Omit for best-match behavior."
                    ]
                ],
                "required" => ["command"]
            ]
        ],
        [
            "name" => "cli_search",
            "description" => "Search Unix/Linux man pages by keyword using apropos. Also searches Python 3 modules via pydoc3. Returns matching command names with sections and detail links.",
            "inputSchema" => [
                "type" => "object",
                "properties" => [
                    "query" => [
                        "type" => "string",
                        "description" => "Search keyword (e.g. 'recursive delete', 'network', 'cron')"
                    ],
                    "section" => [
                        "type" => "string",
                        "description" => "Optional: restrict to a specific manual section (e.g. '1', '8')"
                    ]
                ],
                "required" => ["query"]
            ]
        ]
    ];
}

/**
 * Clean terminal overstrike and ANSI escape sequences from man/perldoc output.
 * Shared by formatToJSON() and formatManPerlDocToMarkdown().
 * Returns array of cleaned lines with placeholder markers for bold/underline.
 *   \x01..\x02 = bold boundary,  \x03..\x04 = underline boundary
 */
function cleanTerminalOutput (array $lines): array {
    // Uses RE_ASCII (plain printable) — raw terminal output has no \x05\x06\x07 placeholders
    $ac = RE_ASCII;
    $patterns = array(
        "/{$ac}".chr(8)."{$ac}".chr(8)."({$ac})".chr(8)."{$ac}/",  // ?^H?^H?^H? => bold
        "/_".chr(8)."({$ac})/",  // _^H? => underline
        "/{$ac}".chr(8)."({$ac})/",  // ?^H? => bold
        "/".chr(27)."\[1m(.*?)".chr(27)."\[(?:0|22)m/",  // ANSI bold
        "/".chr(27)."\[4m(.*?)".chr(27)."\[(?:0|24)m/",  // ANSI underline
    );
    $replace = array(
        "\x01$1\x02",
        "\x03$1\x04",
        "\x01$1\x02",
        "\x01$1\x02",
        "\x03$1\x04",
    );
    $cleaned = array();
    foreach ($lines as $line) {
        $line = preg_replace($patterns, $replace, $line);
        $line = str_replace("\x08", "", $line);  // strip remaining backspaces
        $line = str_replace(array("\x02\x01", "\x04\x03"), "", $line);
        $line = str_replace(array("\x01", "\x02", "\x03", "\x04"), array("**", "**", "_", "_"), $line);
        $cleaned[] = $line;
    }
    return $cleaned;
}

/**
 * Extract flags from subsections when top-level flags array is empty.
 * Shared by formatMcpStructured(), buildLlmContext(), and formatTldr().
 */
function extractFlagsFromSections (array $data): array {
    $flags = [];
    foreach ($data["sections"] ?? [] as $sec) {
        foreach ($sec["subsections"] ?? [] as $sub) {
            if (!empty($sub["flag"]) || !empty($sub["long"])) {
                $flags[] = [
                    "flag" => $sub["flag"] ?? "",
                    "long" => $sub["long"] ?? null,
                    "arg" => $sub["arg"] ?? null,
                    "description" => trim(preg_replace('/\s+/', ' ', $sub["content"] ?? "")),
                ];
            }
        }
    }
    return $flags;
}

/**
 * phpMan is a web interface of Unix command 'man', 'perldoc', 'info' and 'apropos'.
 * This script makes it easier to read man pages which is lengthy and require you
 * to use 'more' or 'pg' filters. Just try it if you feel hard to remember the command
 * for page back or need to dump man page into text/html format.
 * Compatible with GNU/Linux and FreeBSD under PHP 7.2+.
 *
 * !!! Note: on Apache 2.0.x need configure: AcceptPathInfo On !!!
 *
 * You can also find other web interface:
 *   shell-sed-awk based script at:
 *     http://www.softlab.ntua.gr/~christia/man-cgi.html
 *   perl based script at:
 *     http://www.freebsd.org/cgi/man.cgi
 *     http://www.freebsd.org/cgi/man.cgi/source
 *   dwww of debian:
 *     http://packages.debian.org/stable/doc/dwww
 *
 * Sub function list:
 *     showHeader ( $title, $parameter, $section, $mode, $hasRealContent, $showNav )  //show html header with css style
 *     showForm ($parameter, $check)         //show input form and recursive call
 *     showFooter ( $validate )              //show html footer
 *     getManPage ($parameter, $mode)        //get html format man page
 *     getInfoPage ($parameter)              //get html format info page
 *     getPerldocPage ($parameter)           //get html format perldoc page
 *     getSearchPage ($parameter)            //get html format apropos page
 *     getManIndex ()                        //get man page index
 *     getPerldocIndex ()                    //get perldoc page index
 *     getInfoIndex ()                       //get info page index
 *     formatManPerlDoc ($lines)             //formate man, perldoc and info output
 *
 */

// +--------------------------------------------------------------------------------+
// | global configures: output html style and whether show xhtml validators         |
// +--------------------------------------------------------------------------------+

//app title
$PHP_MAN_TITLE = "phpman - Linux Command Reference, JSON API & MCP Server for AI Agents";

// TOC entries for floating right sidebar (populated when rendering man page content)
$TOC_ITEMS = array();

//use colored man page - merged into showHeader()

$VALIDATOR = "";

//unmask comments to show xhtml 1.1 and css validator
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $scheme . '://' . getSafeHost() . serverValue("REQUEST_URI", scriptName());
$VALIDATOR = "<a href=\"https://validator.w3.org/check?uri=" . urlencode($currentUrl) . "\">".
"<img style=\"border:0;width:88px;height:31px\"".
" src=\"https://www.w3.org/Icons/valid-xhtml10\"".
" alt=\"Valid XHTML 1.0 Transitional\" /></a>".
"<a href=\"https://jigsaw.w3.org/css-validator/validator?uri=" . urlencode($currentUrl) . "\">".
"<img style=\"border:0;width:88px;height:31px\"".
" src=\"https://jigsaw.w3.org/css-validator/images/vcss-blue\"".
" alt=\"Valid CSS!\" /></a>";

ini_set("default_charset", "UTF-8");

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
function detectL2ItalicSubheading (string $line): ?array {
    if (preg_match('/^_([A-Z][a-z][\w\s:\x27;\-,]+)_$/', $line, $m)) {
        return ['level' => 2, 'text' => trim($m[1])];
    }
    return null;
}

/**
 * L2: man .SS bold — "   **Packages**" or "   **Symbol** **Tables**"
 * Also matches at column 0 for e.g. "**Line** **Buffering**"
 * Multi-segment ALL CAPS bold (e.g. "**SEE** **ALSO**") is NOT L2 — falls through to L1.
 */
function detectL2BoldSubheading (string $line): ?array {
    if (preg_match('/^ {0,8}((?:\*\*[^*]+\*\*\s*)+)$/', $line, $m)) {
        $text = str_replace('**', '', trim($m[1]));
        $isAllCapsSection = preg_match('/^[A-Z][A-Z0-9_ \/\-]{2,50}$/', $text);
        // Single bold word at column 0 (e.g. "**Overview**") is L1, not L2
        if (!$isAllCapsSection
            && !(strpos($line, '**') === 0 && substr_count($line, '**') === 2)) {
            if (strlen($text) >= 3) {
                return ['level' => 2, 'text' => $text];
            }
        }
    }
    return null;
}

/**
 * L2: man .TP tagged paragraphs / option definition lines / perldoc =head2 /
 *     indented plain-text option flags.
 * Handles: bold+type pairs, bold option flags, perldoc head2, plain-text options.
 */
function detectL2IndentedPatterns (string $line): ?array {
    // man .TP tagged paragraphs with bold variable names + optional type
    // e.g. "       **CREATE**_**HOME** (boolean)"
    if (preg_match('/^ {7,}((?:(?:\*\*[^*]+\*\*[_\s]*)+\([a-z]+\)[,\s]*)+)/', $line, $m)) {
        $text = str_replace('**', '', trim($m[1]));
        if (strlen($text) >= 3) {
            return ['level' => 2, 'text' => $text];
        }
    }

    // man option definition lines — e.g. "       **-R**, **--root** _CHROOT_DIR_"
    if (preg_match('/^ {3,7}(\*\*-\w[\w\-]*)/', $line, $m)) {
        if (preg_match_all('/\*\*([^*]+)\*\*/', $line, $allMatches)) {
            $flags = [];
            foreach ($allMatches[1] as $seg) {
                if (preg_match('/^-/', $seg)) {
                    $flags[] = $seg;
                }
            }
            if (!empty($flags)) {
                $text = implode(' ', $flags);
                if (strlen($text) <= 80) {
                    return ['level' => 2, 'text' => $text];
                }
            }
        }
    }

    // perldoc =head2 — "  Methods you should implement" (2-space indent)
    $testLine = preg_replace('/_/', '', $line);
    if (preg_match('/^ {2}([A-Z][a-z][\w\s:\x27;\-,\\.]+)$/', $testLine, $m)) {
        $text = trim($m[1]);
        if (!preg_match('/^(This|That|These|Those|It|There)\s+(is|was|has|have|had|are|were)\b/i', $text)) {
            return ['level' => 2, 'text' => $text];
        }
    }

    // indented plain-text option flag (no bold markers)
    // e.g. "       -K, --config <file>"
    if (preg_match('/^ {4,8}(-{1,2}[a-zA-Z][\w\-]*'
            . '(?:\s*[<\[]\s*[^>\]]*[>\]])?'
            . '(?:\s*,\s*-{1,2}[a-zA-Z][\w\-]*(?:\s*[<\[]\s*[^>\]]*[>\]])?)*)'
            . '\s*$/',
            $line, $m)
        && strlen(trim($m[1])) <= 80) {
        return ['level' => 2, 'text' => trim($m[1])];
    }

    // pydoc class definitions: "    class Name(Parent)" (Parent may contain HTML link)
    $testLine = preg_replace('#<a[^>]*>|</a>#', '', $line);
    if (preg_match('/^ {4}class (\w+)\(/', $testLine, $m)) {
        return ['level' => 2, 'text' => 'class ' . $m[1]];
    }

    // pydoc function definitions: "    funcName(args)"
    if (preg_match('/^ {4}([a-z]\w*)\(/', $testLine, $m)
        && !preg_match('/^(class|def|if|for|while|with|try|import|from|return|yield|raise|print|assert|del|global|nonlocal|lambda|pass|break|continue|except|finally|elif|else|and|or|not|in|is)\b/', $m[1])) {
        return ['level' => 2, 'text' => $m[1]];
    }

    return null;
}

/**
 * L1: ALL CAPS section headings / perldoc =head1 / man .SH mixed case at column 0.
 * Includes header/footer rejection logic to avoid false positives.
 */
function detectL1Heading (string $line): ?array {
    // ALL CAPS — strip formatting markers first
    $plain = trim(str_replace(['**', '_'], '', $line));
    if (isset($line[0]) && $line[0] !== ' ' && $line[0] !== "\t"
        && preg_match('/^[A-Z][A-Z0-9_ \/\-]{2,50}$/', $plain)) {
        return ['level' => 1, 'text' => $plain];
    }

    // perldoc =head1 / man .SH at column 0, mixed case
    // Single-word titles: 3+ chars. Two-word: 10+. Three+: 16+.
    $noBold = str_replace(['**', '_'], '', $line);
    if (isset($line[0]) && $line[0] !== ' ' && $line[0] !== "\t"
        && preg_match('/^[A-Z][a-z][\w\s:\x27;\-,\.\(\)\/]+$/D', $noBold)
        && !preg_match('/[.!?:]\s*$/', $noBold)) {

        // Reject man page header/footer lines
        $noBoldTrimmed = trim($noBold);
        if (preg_match('/^(\w[\w\s.-]*?)\s{3,}.*\s{3,}\1\(\w+\)\s*$/', $noBoldTrimmed)) {
            return null;
        }
        if (preg_match('/^(\w+)\(\w+\)\s{3,}.*\s{3,}\1\(\w+\)\s*$/', $noBoldTrimmed)) {
            return null;
        }
        if (preg_match('/\w+\(\w+\)\s*$/', $noBoldTrimmed)
            && substr_count($noBoldTrimmed, '  ') >= 4) {
            return null;
        }
        $text = trim($noBold);
        $wordCount = substr_count($text, ' ') + 1;
        if ($wordCount === 1 && strlen($text) >= 3) {
            return ['level' => 1, 'text' => $text];
        }
        if ($wordCount === 2 && strlen($text) >= 10) {
            return ['level' => 1, 'text' => $text];
        }
        if ($wordCount >= 3 && strlen($text) >= 16) {
            return ['level' => 1, 'text' => $text];
        }
    }
    return null;
}

/**
 * Detect heading level and text from a man/perldoc line.
 * Returns ['level' => 1|2, 'text' => string] or null.
 * Dispatches to strategy functions in priority order: L2 patterns first, then L1.
 */
function detectHeadingType (string $line, string $mode = "man", ?string $nextLine = null): ?array {
    // Normalize: convert HTML bold/underline to markdown-style markers
    $line = preg_replace(['#</?b>#', '#</?u>#'], ['**', '_'], $line);

    // ri mode: only RDoc markup headings ("= L1", "== L2")
    if ($mode === "ri") {
        if (preg_match('/^= (.+)/', $line, $m)) {
            $text = trim(strip_tags(str_replace(['**', '_'], '', $m[1])));
            if ($text !== '' && $text !== '=') return ['level' => 1, 'text' => $text];
        }
        if (preg_match('/^== (.+)/', $line, $m)) {
            $text = trim(strip_tags(str_replace(['**', '_'], '', $m[1])));
            if ($text !== '' && $text !== '==') return ['level' => 2, 'text' => $text];
        }
        return null;
    }

    // info mode: Setext-style underline headings (text on current line, underline on next)
    // H1: *****  H2: =====  H3: -----
    if ($mode === "info" && $nextLine !== null) {
        $trimmedNext = trim($nextLine);
        $len = strlen($trimmedNext);
        if ($len >= 3) {
            $char = $trimmedNext[0];
            if (in_array($char, ['*', '=', '-'], true) && $trimmedNext === str_repeat($char, $len)) {
                $text = trim(strip_tags(str_replace(['**', '_'], '', $line)));
                if ($text !== '') {
                    $level = ($char === '*') ? 1 : (($char === '=') ? 2 : 3);
                    return ['level' => $level, 'text' => $text, 'skipNext' => true];
                }
            }
        }
        return null;
    }

    // L2 strategies (checked first — more specific patterns take priority)
    $result = detectL2ItalicSubheading($line);
    if ($result !== null) return $result;

    $result = detectL2BoldSubheading($line);
    if ($result !== null) return $result;

    $result = detectL2IndentedPatterns($line);
    if ($result !== null) return $result;

    // L1 strategies (checked last — broader patterns)
    $result = detectL1Heading($line);
    if ($result !== null) return $result;

    return null;
}

function serverValue (string $key, string $default = ""): string {
    return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : $default;
}

function scriptName (): string {
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
    $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : "phpMan.php";
    return $proto . "://" . getSafeHost() . $script_path;
}

/**
 * Check if the current request is from localhost (like phpinfo() visibility).
 * Used to restrict server version disclosure to local access only.
 */
function isLocalRequest (): bool {
    $remoteAddr = serverValue("REMOTE_ADDR", "");
    if (in_array($remoteAddr, ["127.0.0.1", "::1", ""], true)) return true;
    // Private network ranges: 10.x, 172.16-31.x, 192.168.x
    if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $remoteAddr)) return true;
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
 * Get or create the SQLite cache database connection.
 * Uses static $db for connection reuse within a single request.
 */
function cacheDb(): SQLite3 {
    static $db = null;
    if ($db !== null) return $db;

    $dir = CACHE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $dbPath = CACHE_DB;
    $isNew = !file_exists($dbPath);

    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);  // must be before any exec() to avoid "database is locked"
    // PRAGMA journal_mode=WAL requires an exclusive lock — can fail under concurrent access.
    // Wrap in try/catch so a transient lock doesn't crash the request.
    try {
        $db->exec('PRAGMA journal_mode=WAL');
    } catch (\Exception $e) {
        // Non-critical: WAL mode already active or transient lock; proceed without.
    }
    $db->exec('PRAGMA synchronous=NORMAL');

    if ($isNew) {
        $db->exec("CREATE TABLE IF NOT EXISTS cache (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            mode        TEXT NOT NULL,
            name        TEXT NOT NULL,
            section     TEXT NOT NULL DEFAULT '',
            format      TEXT NOT NULL DEFAULT 'raw',
            content     BLOB,
            content_len INTEGER DEFAULT 0,
            status      TEXT NOT NULL DEFAULT 'found'
                        CHECK(status IN ('found','not_found')),
            ttl         INTEGER NOT NULL DEFAULT 0,
            hits        INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(mode, name, section, format)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS meta (
            key   TEXT PRIMARY KEY,
            value TEXT
        )");
        $db->exec("INSERT OR IGNORE INTO meta (key, value)
                   VALUES ('schema_version', '" . CACHE_SCHEMA_VERSION . "')");

        try {
            $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS cache_fts
                       USING fts5(mode, name, section, title,
                                  tokenize='unicode61',
                                  content='cache',
                                  content_rowid='id')");
        } catch (\Exception $e) {
            // FTS5 not available — search index is optional
        }

        // FTS5 search engine: independent full-text search table (v2)
        try {
            $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts
                       USING fts5(
                           name,         -- expanded name for dual matching
                           section,      -- section number
                           description,  -- apropos one-line summary
                           body,         -- full page text (cleaned)
                           tokenize='unicode61 tokenchars ''-:''',
                           prefix='1,2,3'
                       )");
        } catch (\Exception $e) {
            // FTS5 not available — search falls back to apropos
        }

        $db->exec("CREATE TABLE IF NOT EXISTS search_index_meta (
            name        TEXT NOT NULL,
            section     TEXT NOT NULL DEFAULT '',
            source      TEXT NOT NULL DEFAULT 'man',
            body_len    INTEGER NOT NULL DEFAULT 0,
            hits        INTEGER NOT NULL DEFAULT 0,
            last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(name, section, source)
        )");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_lookup
                   ON cache(mode, name, section, format)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_status
                   ON cache(status, updated_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_hits
                   ON cache(hits DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_expiry
                   ON cache(updated_at) WHERE ttl > 0");
    } else {
        // Check schema version — clear cache if mismatch
        $row = $db->querySingle("SELECT value FROM meta WHERE key='schema_version'", false);
        if ($row !== CACHE_SCHEMA_VERSION) {
            if ($row === '1') {
                // v1 → v2: add search_fts and search_index_meta, clear search cache
                $db->exec("DELETE FROM cache WHERE mode='search'");
                try {
                    $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts
                               USING fts5(
                                   name, section, description, body,
                                   tokenize='unicode61 tokenchars ''-:''',
                                   prefix='1,2,3'
                               )");
                } catch (\Exception $e) {
                    // FTS5 not available — falls back to apropos
                }
                $db->exec("CREATE TABLE IF NOT EXISTS search_index_meta (
                    name TEXT NOT NULL,
                    section TEXT NOT NULL DEFAULT '',
                    source TEXT NOT NULL DEFAULT 'man',
                    body_len INTEGER NOT NULL DEFAULT 0,
                    hits INTEGER NOT NULL DEFAULT 0,
                    last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                    UNIQUE(name, section, source)
                )");
            } elseif ($row === '2') {
                // v2 → v3: search_fts.name now stores expanded name; rebuild needed
                try {
                    $db->exec("DELETE FROM search_fts");
                } catch (\Exception $e) {
                    // FTS5 not available — ignore
                }
                $db->exec("DELETE FROM search_index_meta");
            } else {
                $db->exec("DELETE FROM cache");
            }
            $db->exec("UPDATE meta SET value = '" . CACHE_SCHEMA_VERSION . "' WHERE key = 'schema_version'");
        }
    }

    return $db;
}

/**
 * PageCache — SQLite-based cache for rendered man/perldoc/info/pydoc/ri pages.
 */
class PageCache {
    private SQLite3 $db;

    public function __construct() {
        $this->db = cacheDb();
    }

    public function get(string $mode, string $name, string $section, string $format): ?string {
        $stmt = $this->db->prepare(
            "SELECT id, content, status, ttl, updated_at FROM cache
             WHERE mode = :mode AND name = :name AND section = :section AND format = :format"
        );
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
        $stmt->bindValue(':format', $format, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();

        if (!$row) return null;

        // Check TTL expiry for not_found entries
        if ($row['ttl'] > 0 && (time() > $row['updated_at'] + $row['ttl'])) {
            $this->deleteEntry($mode, $name, $section, $format);
            return null;
        }

        // Count this cache hit (found or not_found)
        $this->db->exec("UPDATE cache SET hits = hits + 1 WHERE id = {$row['id']}");

        if ($row['status'] === 'not_found') {
            return '###NOT_FOUND###';
        }

        $data = $row['content'];
        if ($data === null) return null;

        $decompressed = @gzuncompress($data);
        return $decompressed !== false ? $decompressed : null;
    }

    public function set(string $mode, string $name, string $section, string $format, ?string $content, string $status = 'found'): bool {
        $compressed = ($content !== null && $content !== '') ? gzcompress($content) : null;
        $contentLen = ($content !== null) ? strlen($content) : 0;
        $ttl = ($status === 'not_found') ? 86400 : 0;
        // Search not-found entries live longer (7 days vs 1 day)
        if ($mode === 'search' && $status === 'not_found') {
            $ttl = 604800;
        }

        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO cache (mode, name, section, format, content, content_len, status, ttl, hits, created_at, updated_at)
             VALUES (:mode, :name, :section, :format, :content, :content_len, :status, :ttl,
               COALESCE((SELECT c.hits FROM cache c WHERE c.mode = :m2 AND c.name = :n2 AND c.section = :s2 AND c.format = :f2), 0),
               strftime('%s','now'), strftime('%s','now'))"
        );
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
        $stmt->bindValue(':format', $format, SQLITE3_TEXT);
        $stmt->bindValue(':content', $compressed, SQLITE3_BLOB);
        $stmt->bindValue(':content_len', $contentLen, SQLITE3_INTEGER);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':ttl', $ttl, SQLITE3_INTEGER);
        $stmt->bindValue(':m2', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':n2', $name, SQLITE3_TEXT);
        $stmt->bindValue(':s2', $section, SQLITE3_TEXT);
        $stmt->bindValue(':f2', $format, SQLITE3_TEXT);

        $ok = $stmt->execute() !== false;

        // Sync FTS5 index for found entries (skip search mode — search results aren't indexed)
        if ($ok && $status === 'found' && $mode !== 'search') {
            $cacheId = $this->db->lastInsertRowID();
            $this->syncFts($cacheId, $mode, $name, $section, $content);
        }

        return $ok;
    }

    public function delete(string $mode, string $name, string $section): bool {
        $this->deleteEntry($mode, $name, $section, '%');
        return true;
    }

    public function clear(): bool {
        $this->db->exec("DELETE FROM cache");
        return true;
    }

    public function stats(): array {
        $total = $this->db->querySingle("SELECT COUNT(*) FROM cache");
        $found = $this->db->querySingle("SELECT COUNT(*) FROM cache WHERE status='found'");
        $notFound = $this->db->querySingle("SELECT COUNT(*) FROM cache WHERE status='not_found'");
        $totalHits = $this->db->querySingle("SELECT SUM(hits) FROM cache") ?: 0;
        $dbSize = file_exists(CACHE_DB) ? filesize(CACHE_DB) : 0;

        return [
            'total' => (int)$total,
            'found' => (int)$found,
            'not_found' => (int)$notFound,
            'total_hits' => (int)$totalHits,
            'db_size' => $dbSize,
        ];
    }

    private function deleteEntry(string $mode, string $name, string $section, string $format): void {
        if ($format === '%') {
            $stmt = $this->db->prepare(
                "DELETE FROM cache WHERE mode = :mode AND name = :name AND section = :section"
            );
        } else {
            $stmt = $this->db->prepare(
                "DELETE FROM cache WHERE mode = :mode AND name = :name AND section = :section AND format = :format"
            );
            $stmt->bindValue(':format', $format, SQLITE3_TEXT);
        }
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
        $stmt->execute();
    }

    private function syncFts(int $cacheId, string $mode, string $name, string $section, ?string $content): void {
        $title = '';
        if ($content !== null && $content !== '') {
            // Extract first meaningful line as title for FTS
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && strlen($line) < 200) {
                    $title = mb_substr($line, 0, 120);
                    break;
                }
            }
        }
        try {
            $stmt = $this->db->prepare(
                "INSERT OR REPLACE INTO cache_fts (rowid, mode, name, section, title)
                 VALUES (:id, :mode, :name, :section, :title)"
            );
            $stmt->bindValue(':id', $cacheId, SQLITE3_INTEGER);
            $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':section', $section, SQLITE3_TEXT);
            $stmt->bindValue(':title', $title, SQLITE3_TEXT);
            $stmt->execute();
        } catch (\Exception $e) {
            // FTS5 not available — ignore
        }
    }
}

/**
 * Cache-aware wrapper: return cached content or execute generator and cache result.
 */
function cacheOrExecute(string $mode, string $name, string $section, string $format, callable $generator): string {
    $cache = new PageCache();
    $cached = $cache->get($mode, $name, $section, $format);
    if ($cached !== null) {
        return ($cached === '###NOT_FOUND###') ? '' : $cached;
    }
    $content = $generator();
    if (trim($content) !== "") {
        $cache->set($mode, $name, $section, $format, $content, 'found');
    }
    return $content;
}

// +--------------------------------------------------------------------------------+
// | FTS5 Search Engine                                                              |
// +--------------------------------------------------------------------------------+

/**
 * Expand a command name for FTS5 dual matching.
 * "git-commit"   → "git-commit git commit"
 * "File::Find"   → "File::Find File Find"
 * "ls"           → "ls" (unchanged)
 */
function expandNameForFts(string $name): string {
    $expanded = $name;

    // Hyphen expansion: "git-commit" → append "git commit"
    if (str_contains($name, '-')) {
        $expanded .= ' ' . str_replace('-', ' ', $name);
    }

    // Double-colon expansion: "File::Find" → append "File Find"
    // Also lowercase for case-insensitive matching: "File Find" → "file find"
    if (str_contains($name, '::')) {
        $parts = str_replace('::', ' ', $name);
        $expanded .= ' ' . $parts . ' ' . mb_strtolower($parts);
    }

    // Dot expansion: "json.decoder" → append "json decoder"
    if (str_contains($name, '.')) {
        $expanded .= ' ' . str_replace('.', ' ', $name);
    }

    // Append lowercase version for case-insensitive FTS5 prefix matching
    $expanded .= ' ' . mb_strtolower($name);

    return $expanded;
}

/**
 * Build an FTS5 MATCH query from raw user input.
 * Preserves hyphens (-) and colons (:) — critical for command names and Perl modules.
 * Returns empty string if query is empty/invalid.
 */
function buildFtsQuery(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';

    // Replace Chinese/Unicode separator punctuation with spaces so terms split correctly.
    // Handles: enumeration comma (、), Chinese comma (，), Chinese semicolon (；).
    // Only Unicoded-specific punctuation is handled here; ASCII , and ; are
    // already stripped by the [^\p{L}\p{N}\.\-_:] regex below.
    $raw = preg_replace('/[、，；]/u', ' ', $raw);

    // Detect explicit FTS5 operators — pass through as-is
    if (preg_match('/\b(AND|OR|NOT|NEAR)\b/i', $raw)) {
        return $raw;
    }

    // Exact phrase (quoted) — pass through
    if (preg_match('/^".*"$/', $raw)) {
        return $raw;
    }

    // Default: prefix-match each term with AND
    $terms = preg_split('/\s+/', $raw);
    $parts = [];
    foreach ($terms as $t) {
        $t = trim($t);
        if ($t !== '') {
            // Preserve hyphens, colons, underscores, dots — critical for commands
            $t = preg_replace('/[^\p{L}\p{N}\.\-_:]/u', '', $t);
            if ($t !== '') {
                $parts[] = '"' . $t . '"*';
            }
        }
    }

    return $parts === [] ? '' : implode(' AND ', $parts);
}

/**
 * Merge search results from multiple sources by (name, section).
 * Same (name, section) from different sources are combined into one entry.
 */
function mergeSearchResults(array $rows): array {
    $merged = [];

    foreach ($rows as $row) {
        $displayName = $row['display_name'] ?? $row['name'];
        $key = $displayName . "\0" . $row['section'];

        if (!isset($merged[$key])) {
            $merged[$key] = [
                'name'        => $displayName,
                'section'     => $row['section'],
                'description' => $row['description'],
                'sources'     => [$row['source']],
                'hits'        => (int)($row['hits'] ?? 0),
            ];
        } else {
            if (!in_array($row['source'], $merged[$key]['sources'])) {
                $merged[$key]['sources'][] = $row['source'];
            }
            $merged[$key]['hits'] += (int)($row['hits'] ?? 0);
        }
    }

    return array_values($merged);
}

/**
 * Perform FTS5 full-text search.
 * Returns array of result rows, or empty array on failure/FTS5 unavailability.
 *
 * @param string $parameter  Search query
 * @param string $section    Optional section filter (e.g. '1', '8')
 * @param int    $limit      Max results (default 50)
 * @return array
 */
function searchFts(string $parameter, string $section = '', int $limit = 50): array {
    try {
        $db = cacheDb();

        // Check if search_fts exists
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='search_fts'");
        if (!$tables->fetchArray()) {
            return []; // FTS5 not available — caller will fall back
        }

        $ftsQuery = buildFtsQuery($parameter);
        if ($ftsQuery === '') {
            return [];
        }

        // Try AND query first (default for multi-word: all terms required)
        $results = searchFtsQuery($db, $ftsQuery, $parameter, $section, $limit);

        // Multi-word fallback: if AND returned nothing, try OR so that
        // "wget request" still matches entries containing just "wget" or "request".
        // This avoids falling to expensive apropos for partial matches.
        // Note: buildFtsQuery outputs {"term1"* AND "term2"*} — "* AND " not " AND ".
        // Check if the FTS query has multiple terms (contains AND), meaning
        // there's an OR fallback opportunity regardless of the original parameter's
        // punctuation. buildFtsQuery normalizes Chinese punctuation internally,
        // so "URL、curl" also produces "term1"* AND "term2"*.
        if (empty($results) && strpos($ftsQuery, ' AND ') !== false) {
            $orQuery = str_replace('"* AND "', '"* OR "', $ftsQuery);
            if ($orQuery !== $ftsQuery) {
                $results = searchFtsQuery($db, $orQuery, $parameter, $section, $limit);
            }
        }

        return $results;
    } catch (\Exception $e) {
        phpManLog("FTS5 search error: " . $e->getMessage());
        return []; // Graceful fallback
    }
}

/**
 * Search FTS5 index for pydoc or ri entries matching a query.
 * Returns results in the format expected by the search cascade:
 *   - 'json': array of ['name' => ..., 'description' => ..., 'link' => ...]
 *   - 'html': <ul><li>...</li></ul>
 *   - 'markdown': markdown list
 *
 * @param string $parameter  Search query
 * @param string $source     'pydoc' or 'ri'
 * @param string $format     'json', 'html', or 'markdown'
 * @return array|string      Formatted results (array for json, string for html/markdown)
 */
function searchFtsBySource(string $parameter, string $source, string $format) {
    try {
        $db = cacheDb();
        $ftsQuery = buildFtsQuery($parameter);
        if ($ftsQuery === '') return $format === 'json' ? [] : '';

        $stmt = $db->prepare(
            "SELECT s.name, s.section, s.description
             FROM search_fts s
             LEFT JOIN search_index_meta m ON m.section = s.section
                 AND (s.name = m.name OR s.name LIKE m.name || ' %')
             WHERE search_fts MATCH :q AND s.section = :source
             ORDER BY rank LIMIT 100"
        );
        $stmt->bindValue(':q', $ftsQuery, SQLITE3_TEXT);
        $stmt->bindValue(':source', $source, SQLITE3_TEXT);
        $result = $stmt->execute();

        $script_name = ($format === 'markdown' || $format === 'json') ? baseUrl() : scriptName();
        $entries = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $origName = trim(explode(' ', $row['name'])[0]);
            $entries[] = ['name' => $origName, 'description' => $row['description']];
        }

        if (empty($entries)) return $format === 'json' ? [] : '';

        if ($format === 'json') {
            $out = [];
            foreach ($entries as $e) {
                $out[] = [
                    'name' => $e['name'],
                    'description' => $e['description'],
                    'link' => $script_name . '/' . $source . '/' . urlencode($e['name']) . '/json',
                ];
            }
            return $out;
        }

        if ($format === 'html') {
            $out = "<ul>\n";
            foreach ($entries as $e) {
                $out .= '<li><a href="' . $script_name . '/' . $source . '/' . urlencode($e['name']) . '">'
                     . h($e['name']) . '</a>';
                if ($e['description'] !== '') {
                    $out .= ' — ' . h($e['description']);
                }
                $out .= "</li>\n";
            }
            $out .= "</ul>\n";
            return $out;
        }

        // markdown
        $out = '';
        foreach ($entries as $e) {
            $out .= '- [' . $e['name'] . '](' . $script_name . '/' . $source . '/' . urlencode($e['name']) . '/markdown)';
            if ($e['description'] !== '') {
                $out .= ' — ' . $e['description'];
            }
            $out .= "\n";
        }
        return $out;
    } catch (\Exception $e) {
        return $format === 'json' ? [] : '';
    }
}

/**
 * Execute a single FTS5 query and return merged results.
 *
 * Extracted so searchFts() can try multiple query strategies (AND, OR)
 * without duplicating the SQL setup.
 */
function searchFtsQuery(SQLite3 $db, string $ftsQuery, string $parameter, string $section, int $limit): array {
    // COALESCE(m.name, s.name) AS display_name: prefer original name from meta
    // (unexpanded), fall back to FTS expanded name. JOIN on section + name
    // prefix match because search_fts.name stores expandNameForFts() output.
    $baseFrom = "FROM search_fts s
             LEFT JOIN search_index_meta m ON m.section = s.section
                 AND (s.name = m.name OR s.name LIKE m.name || ' %')";
    $baseSelect = "SELECT COALESCE(m.name, s.name) AS display_name, s.name, s.section, s.description, m.hits, m.source";
    $baseOrder = "ORDER BY
                 CASE WHEN COALESCE(m.name, s.name) = :exact THEN 0 ELSE 1 END,
                 CASE WHEN COALESCE(m.name, s.name) LIKE :prefix THEN 0 ELSE 1 END,
                 rank,
                 CASE s.section
                     WHEN '1' THEN 1 WHEN '8' THEN 2 WHEN '3' THEN 3
                     WHEN '5' THEN 4 WHEN '7' THEN 5 WHEN '4' THEN 6
                     WHEN '6' THEN 7 WHEN '9' THEN 8 ELSE 9
                 END,
                 m.hits DESC";

    if ($section !== '') {
        $stmt = $db->prepare(
            "$baseSelect
             $baseFrom
             WHERE search_fts MATCH :query AND s.section = :section
             $baseOrder
             LIMIT :limit"
        );
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare(
            "$baseSelect
             $baseFrom
             WHERE search_fts MATCH :query
             $baseOrder
             LIMIT :limit"
        );
    }

    $stmt->bindValue(':query', $ftsQuery, SQLITE3_TEXT);
    $stmt->bindValue(':exact', $parameter, SQLITE3_TEXT);
    $stmt->bindValue(':prefix', $parameter . '%', SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $result->finalize();

    return mergeSearchResults($rows);
}

/**
 * Group search results by first character and render HTML with optional
 * alphabet sidebar when result count exceeds threshold.
 *
 * @return array ['html' => string, 'sidebar' => string (may be empty)]
 */
function renderGroupedResults(array $results, string $scriptName): array {
    $total = count($results);
    $ALPHA_THRESHOLD = 80;

    // Group by first character
    $groups = [];
    foreach ($results as $r) {
        $first = mb_substr($r['name'], 0, 1);
        if (ctype_digit($first)) {
            $key = '0-9';
        } elseif (preg_match('/^[a-zA-Z]/', $first)) {
            $key = strtoupper($first);
        } else {
            $key = '#';
        }
        $groups[$key][] = $r;
    }
    ksort($groups, SORT_STRING);

    // Build alphabet sidebar when count exceeds threshold
    $sidebar = '';
    if ($total > $ALPHA_THRESHOLD) {
        $allKeys = array_merge(['#', '0-9'], range('A', 'Z'));
        $existingKeys = array_keys($groups);
        $sb = '<div class="alpha-index">' . "\n";
        foreach ($allKeys as $k) {
            $label = ($k === '#') ? '#' : $k;
            if (in_array($k, $existingKeys, true)) {
                $sb .= '<a href="#alpha-' . $k . '">' . $label . '</a>' . "\n";
            } else {
                $sb .= '<a href="#alpha-' . $k . '" class="alpha-empty">' . $label . '</a>' . "\n";
            }
        }
        $sb .= '</div>';
        $sidebar = '<div id="alpha-sidebar">' . "\n"
                 . '<div class="alpha-title" id="alpha-toggle"'
                 . ' onclick="document.body.classList.toggle(\'alpha-open\');">'
                 . 'A-Z Index <span class="alpha-open-icon">&#9633;</span>'
                 . '<span class="alpha-close-icon">&#10005;</span></div>' . "\n"
                 . $sb . "\n"
                 . '</div>';
    }

    // Build grouped HTML
    $html = '';
    if ($total > $ALPHA_THRESHOLD) {
        foreach ($groups as $key => $items) {
            $html .= '<div class="alpha-group" id="alpha-' . $key . '"><h2>' . h($key) . '</h2>' . "\n<ul>\n";
            foreach ($items as $r) {
                $is_perl = str_contains($r['name'], '::');
                $sources = $r['sources'] ?? [];
                $link_mode = in_array('pydoc', $sources) ? 'pydoc'
                           : (in_array('ri', $sources) ? 'ri'
                           : ($is_perl ? 'perldoc' : 'man'));
                $desc = h($r['description'] ?? '');
                $sourceTag = !empty($sources) ? ' <span class="sources">[' . implode(', ', $sources) . ']</span>' : '';
                $html .= '<li><a href="' . $scriptName . '/' . $link_mode . '/' . urlencode($r['name']);
                if ($r['section'] !== '') {
                    $html .= '/' . urlencode($r['section']);
                }
                $html .= '">' . h($r['name']) . '</a>';
                if ($r['section'] !== '') {
                    $html .= ' <span class="section">(' . h($r['section']) . ')</span>';
                }
                if ($desc !== '') {
                    $html .= ' — ' . $desc;
                }
                $html .= $sourceTag . "</li>\n";
            }
            $html .= "</ul></div>\n";
        }
    } else {
        // Below threshold: flat list, no grouping
        $html = "<ul>\n";
        foreach ($results as $r) {
            $is_perl = str_contains($r['name'], '::');
            $sources = $r['sources'] ?? [];
            $link_mode = in_array('pydoc', $sources) ? 'pydoc'
                       : (in_array('ri', $sources) ? 'ri'
                       : ($is_perl ? 'perldoc' : 'man'));
            $desc = h($r['description'] ?? '');
            $sourceTag = !empty($sources) ? ' <span class="sources">[' . implode(', ', $sources) . ']</span>' : '';
            $html .= '<li><a href="' . $scriptName . '/' . $link_mode . '/' . urlencode($r['name']);
            if ($r['section'] !== '') {
                $html .= '/' . urlencode($r['section']);
            }
            $html .= '">' . h($r['name']) . '</a>';
            if ($r['section'] !== '') {
                $html .= ' <span class="section">(' . h($r['section']) . ')</span>';
            }
            if ($desc !== '') {
                $html .= ' — ' . $desc;
            }
            $html .= $sourceTag . "</li>\n";
        }
        $html .= "</ul>\n";
    }

    return ['html' => $html . $sidebar, 'sidebar' => $sidebar];
}

/**
 * Render search results to the requested format.
 */
function formatSearchResults(array $results, string $parameter, string $section, string $format): string {
    $scriptName = ($format === 'markdown' || $format === 'json' || $format === 'mcp')
        ? baseUrl() : scriptName();

    // JSON / MCP output
    if ($format === 'json' || $format === 'mcp') {
        $formatted = [];
        foreach ($results as $r) {
            $is_perl = str_contains($r['name'], '::');
            $link_mode = $is_perl ? 'perldoc' : 'man';
            $formatted[] = [
                'name'        => $r['name'],
                'description' => $r['description'] ?? '',
                'section'     => $r['section'],
                'sources'     => $r['sources'] ?? [],
                'link' => $scriptName . '/' . $link_mode . '/' . urlencode($r['name']) . '/' . urlencode($r['section']) . '/json',
            ];
        }
        $jsonData = [
            'name'       => 'search ' . $parameter . ($section !== '' ? " (section {$section})" : ''),
            'mode'       => 'search',
            'parameter'  => $parameter,
            'section'    => $section,
            'url'        => $scriptName . '/search/' . urlencode($parameter) . ($section !== '' ? '/' . urlencode($section) : '') . '/json',
            'generated'  => gmdate('Y-m-d\TH:i:s\Z'),
            'query'      => $parameter,
            'results'    => $formatted,
            'count'      => count($formatted),
            'engine'     => 'fts5',
        ];
        return formatForOutput(json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $format);
    }

    // HTML output
    if ($format === 'html') {
        $rendered = renderGroupedResults($results, $scriptName);
        if ($rendered['sidebar'] !== '') {
        }
        return $rendered['html'];
    }

    // Markdown output
    $output = '';
    foreach ($results as $r) {
        $is_perl = str_contains($r['name'], '::');
        $link_mode = $is_perl ? 'perldoc' : 'man';
        $desc = $r['description'] ?? '';
        $sources = !empty($r['sources']) ? ' [' . implode(', ', $r['sources']) . ']' : '';
        $output .= '- [' . $r['name'] . '(' . $r['section'] . ')](' . $scriptName . '/' . $link_mode . '/' . urlencode($r['name']) . '/' . urlencode($r['section']) . '/' . $format . ')';
        if ($desc !== '') {
            $output .= ' — ' . $desc;
        }
        $output .= $sources . "\n";
    }
    return $output;
}

/**
 * Parse an apropos-formatted output line into [name, section, description].
 *
 * Handles both BSD format (name [description] (section)) and
 * Linux format (name (section) — description).
 *
 * @return array|null  [name, section, description] or null if unparseable
 */
function parseAproposLine (string $line): ?array {
    // BSD: "name [description] (section)"
    if (preg_match('/^(.+)\s+\[\s*(.+?)\s*\]\s+\(((?:\d\w*|n)\w*)\)\s*$/', $line, $m)) {
        return [trim($m[1]), trim($m[3]), trim($m[2])];
    }
    // Linux em-dash: "name (section) — description"
    if (preg_match('/^(.+)\s+\(((?:\d\w*|n)\w*)\)\s+—\s+(.+)$/', $line, $m)) {
        return [trim($m[1]), trim($m[2]), trim($m[3])];
    }
    // Linux dash: "name (section) - description"
    if (preg_match('/^(.+)\s+\(((?:\d\w*|n)\w*)\)\s+-\s+(.+)$/', $line, $m)) {
        return [trim($m[1]), trim($m[2]), trim($m[3])];
    }
    return null;
}

/**
 * Dynamically index apropos results into the FTS5 search index.
 *
 * Called after every apropos fallback in getSearchPage(). Inserted entries
 * make future searches for the same term fast without a full pre-build.
 * Uses INSERT OR IGNORE so already-indexed entries are safe to re-visit.
 *
 * @param array $lines  apropos output lines
 * @return int          number of newly indexed entries
 */
function indexAproposLines (array $lines): int {
    if (empty($lines)) return 0;

    try {
        $db = cacheDb();

        // Verify FTS5 table exists (SQLite may lack FTS5 support)
        $check = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='search_fts'");
        if (!$check) return 0;

        $count = 0;
        $insertFts = $db->prepare(
            "INSERT OR IGNORE INTO search_fts(name, section, description, body) VALUES(:name, :section, :description, '')"
        );
        $insertMeta = $db->prepare(
            "INSERT OR IGNORE INTO search_index_meta(name, section, source, body_len, hits, last_indexed) VALUES(:name, :section, 'man', 0, 0, datetime('now'))"
        );
        $updateHits = $db->prepare(
            "UPDATE search_index_meta SET hits = hits + 1, last_indexed = datetime('now') WHERE name = :name AND section = :section AND source = 'man'"
        );

        foreach ($lines as $line) {
            $parsed = parseAproposLine($line);
            if ($parsed === null) continue;

            [$name, $section] = $parsed;

            // Only insert FTS5 entry when meta entry is new — FTS5 lacks UNIQUE
            // so INSERT OR IGNORE always succeeds, causing duplicates. Dedup via meta.
            $insertMeta->bindValue(':name', $name, SQLITE3_TEXT);
            $insertMeta->bindValue(':section', $section, SQLITE3_TEXT);
            $insertMeta->execute();
            $isNew = ($db->changes() === 1);
            $insertMeta->reset();

            if ($isNew) {
                $expandedName = expandNameForFts($name);
                $insertFts->bindValue(':name', $expandedName, SQLITE3_TEXT);
                $insertFts->bindValue(':section', $section, SQLITE3_TEXT);
                $insertFts->bindValue(':description', $parsed[2], SQLITE3_TEXT);
                $insertFts->execute();
                $insertFts->reset();
            } else {
                $updateHits->bindValue(':name', $name, SQLITE3_TEXT);
                $updateHits->bindValue(':section', $section, SQLITE3_TEXT);
                $updateHits->execute();
                $updateHits->reset();
            }

            $count++;
        }

        return $count;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Rebuild the FTS5 search index from system man pages.
 * Traverses all man sections, extracts names/descriptions via apropos,
 * and populates search_fts + search_index_meta.
 *
 * Returns a summary string for CLI/HTTP response.
 */
function rebuildSearchIndex(): string {
    $startTime = microtime(true);
    $sections = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'n'];
    $total = 0;
    $errors = 0;
    $output = [];

    try {
        $db = cacheDb();

        // Check if search_fts exists
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='search_fts'");
        if (!$tables->fetchArray()) {
            return "ERROR: FTS5 not available (search_fts table does not exist).\n";
        }

        // Clear existing search index: DROP+CREATE is much faster than DELETE FROM
        // on FTS5 (DELETE rebuilds internal indices per row)
        try {
            $db->exec("DROP TABLE IF EXISTS search_fts");
        } catch (\Exception $e) {
            // May fail if FTS5 not available
        }
        try {
            $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts
                       USING fts5(
                           name, section, description, body,
                           tokenize='unicode61 tokenchars ''-:''',
                           prefix='1,2,3'
                       )");
        } catch (\Exception $e) {
            // FTS5 not available — falls back to apropos
            return "ERROR: FTS5 not available, cannot create search_fts.\n";
        }
        $db->exec("DELETE FROM search_index_meta");
        $output[] = "Cleared existing search index.\n";

        // Wrap inserts in a transaction to prevent WAL bloat
        $db->exec("BEGIN IMMEDIATE");

        $logInterval = 500; // Report progress every N entries
        $lastLogTime = microtime(true);
        $entriesSinceLastLog = 0;

        foreach ($sections as $sec) {
            $cmd = 'apropos -s ' . escapeshellarg($sec) . ' . 2>/dev/null';
            $lines = [];
            exec($cmd, $lines, $exitCode);

            if ($exitCode !== 0 || empty($lines)) {
                continue;
            }

            $sectionCount = 0;
            foreach ($lines as $line) {
                // Parse apropos output: "name (section) - description"
                // or "name [description] (section)" (BSD style)
                if (preg_match('/^(.+?)\s+\(((\d\w*|n)\w*)\)\s+[–-]\s+(.+)$/', $line, $m)) {
                    $name = trim($m[1]);
                    $sectionNum = trim($m[2]);
                    $description = trim($m[4]);
                } elseif (preg_match('/^(.+?)\s+\[(.+?)\]\s+\(((\d\w*|n)\w*)\)\s*$/', $line, $m)) {
                    $name = trim($m[1]);
                    $description = trim($m[2]);
                    $sectionNum = trim($m[3]);
                } else {
                    continue;
                }

                // Skip entries with invalid characters
                if ($name === '' || $description === '') {
                    continue;
                }

                $name = substr($name, 0, 200);

                // Body text is intentionally skipped for performance on shared hosts.
                // Extracting body text requires forking `man` per entry (9,000+ processes)
                // which causes `fork: retry: Resource temporarily unavailable`.
                // Name + section + description from apropos is sufficient for search matching.
                $body = '';

                $expandedName = expandNameForFts($name);

                try {
                    $stmt = $db->prepare(
                        "INSERT INTO search_fts (name, section, description, body)
                         VALUES (:name, :section, :description, :body)"
                    );
                    $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
                    $stmt->bindValue(':section', $sectionNum, SQLITE3_TEXT);
                    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
                    $stmt->bindValue(':body', $body, SQLITE3_TEXT);
                    $stmt->execute();

                    // Also insert meta
                    $stmt2 = $db->prepare(
                        "INSERT OR IGNORE INTO search_index_meta (name, section, source, body_len)
                         VALUES (:name, :section, 'man', :body_len)"
                    );
                    $stmt2->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt2->bindValue(':section', $sectionNum, SQLITE3_TEXT);
                    $stmt2->bindValue(':body_len', strlen($body), SQLITE3_INTEGER);
                    $stmt2->execute();

                    $sectionCount++;
                    $total++;
                    $entriesSinceLastLog++;

                    // Log progress periodically
                    if ($entriesSinceLastLog >= $logInterval) {
                        $now = microtime(true);
                        $batchTime = round(($now - $lastLogTime) * 1000, 0);
                        $rate = $entriesSinceLastLog > 0
                            ? round($entriesSinceLastLog / ($now - $lastLogTime), 0)
                            : 0;
                        $output[] = "  [{$total}] Section {$sec}: +{$entriesSinceLastLog} entries in {$batchTime}ms ({$rate} ent/s)\n";
                        $entriesSinceLastLog = 0;
                        $lastLogTime = $now;
                    }
                } catch (\Exception $e) {
                    $errors++;
                }
            }

            $output[] = "  Section {$sec}: {$sectionCount} entries indexed.\n";

            // Flush remaining progress for this section
            if ($entriesSinceLastLog > 0) {
                $now = microtime(true);
                $batchTime = round(($now - $lastLogTime) * 1000, 0);
                $rate = $entriesSinceLastLog > 0
                    ? round($entriesSinceLastLog / ($now - $lastLogTime), 0)
                    : 0;
                $output[] = "  [{$total}] Section {$sec}: +{$entriesSinceLastLog} entries in {$batchTime}ms ({$rate} ent/s)\n";
                $entriesSinceLastLog = 0;
                $lastLogTime = $now;
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $output[] = "\nMan pages: {$total} entries indexed.\n";
        $output[] = "Man page errors: {$errors}.\n";

        // ================================================================
        // 2. Python 3 modules via pydoc3
        // ================================================================
        $pydocLines = [];
        exec('pydoc3 modules 2>/dev/null', $pydocLines, $pydocExit);
        if ($pydocExit === 0 && !empty($pydocLines)) {
            $pydocCount = 0;
            $pydocErrors = 0;
            $inBody = false;
            foreach ($pydocLines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    $inBody = true;
                    continue;
                }
                if (!$inBody) continue;
                if (preg_match('/^Enter any module name/i', $trimmed)) break;
                $parts = preg_split('/\s{2,}/', $trimmed);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '' || preg_match('/^\s*$/', $part)) continue;
                    if (preg_match('/^(Enter any module|Or, type|modules spam)/i', $part)) continue;
                    if (strpos($part, '_') === 0 && !preg_match('/^__[a-z]+__$/', $part)) continue;
                    $expandedName = expandNameForFts($part);
                    try {
                        $stmt = $db->prepare(
                            "INSERT INTO search_fts (name, section, description, body)
                             VALUES (:name, :section, :description, '')"
                        );
                        $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
                        $stmt->bindValue(':section', 'pydoc', SQLITE3_TEXT);
                        $stmt->bindValue(':description', 'Python 3 module', SQLITE3_TEXT);
                        $stmt->execute();

                        $stmt2 = $db->prepare(
                            "INSERT OR IGNORE INTO search_index_meta (name, section, source, body_len)
                             VALUES (:name, :section, 'pydoc', 0)"
                        );
                        $stmt2->bindValue(':name', $part, SQLITE3_TEXT);
                        $stmt2->bindValue(':section', 'pydoc', SQLITE3_TEXT);
                        $stmt2->execute();

                        $pydocCount++;
                        $total++;
                    } catch (\Exception $e) {
                        $pydocErrors++;
                        $errors++;
                    }
                }
            }
            $output[] = "  pydoc3: {$pydocCount} modules indexed.\n";
        }

        // ================================================================
        // 3. Ruby classes via ri
        // ================================================================
        $riLines = [];
        exec('ri -l 2>/dev/null', $riLines, $riExit);
        if ($riExit === 0 && !empty($riLines)) {
            $riCount = 0;
            $riErrors = 0;
            foreach ($riLines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') continue;
                $expandedName = expandNameForFts($trimmed);
                try {
                    $stmt = $db->prepare(
                        "INSERT INTO search_fts (name, section, description, body)
                         VALUES (:name, :section, :description, '')"
                    );
                    $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
                    $stmt->bindValue(':section', 'ri', SQLITE3_TEXT);
                    $stmt->bindValue(':description', 'Ruby class/module', SQLITE3_TEXT);
                    $stmt->execute();

                    $stmt2 = $db->prepare(
                        "INSERT OR IGNORE INTO search_index_meta (name, section, source, body_len)
                         VALUES (:name, :section, 'ri', 0)"
                    );
                    $stmt2->bindValue(':name', $trimmed, SQLITE3_TEXT);
                    $stmt2->bindValue(':section', 'ri', SQLITE3_TEXT);
                    $stmt2->execute();

                    $riCount++;
                    $total++;
                } catch (\Exception $e) {
                    $riErrors++;
                    $errors++;
                }
            }
            $output[] = "  ri: {$riCount} classes indexed.\n";
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $output[] = "\nDone. {$total} total entries indexed, {$errors} errors in {$elapsed}s.\n";

        // COMMIT the transaction
        $db->exec("COMMIT");

        // Update meta
        $db->exec("INSERT OR REPLACE INTO meta (key, value) VALUES ('search_index_count', '{$total}')");
        $db->exec("INSERT OR REPLACE INTO meta (key, value) VALUES ('search_index_updated', '" . gmdate('Y-m-d\TH:i:s\Z') . "')");

        return implode('', $output);
    } catch (\Exception $e) {
        try { $db->exec("ROLLBACK"); } catch (\Exception $ignored) {}
        return "ERROR: " . $e->getMessage() . "\n";
    }
}

// +--------------------------------------------------------------------------------+
// | parameter checking and format page output                                      |
// +--------------------------------------------------------------------------------+

// Test mode: define functions only, skip execution
if (defined('PHPMAN_TEST_MODE')) {
    return;
}

// CLI Mode: handle command-line invocation (e.g. --build-index)
if (PHP_SAPI === 'cli') {
    $options = getopt('', ['build-index', 'build-index-cron']);
    if (isset($options['build-index'])) {
        echo rebuildSearchIndex();
        exit;
    }
    if (isset($options['build-index-cron'])) {
        $result = rebuildSearchIndex();
        // Cron output: prefix with timestamp, avoid duplicated log lines
        echo '[' . gmdate('Y-m-d H:i:s') . "]\n" . $result;
        exit;
    }
}

// Web handler: ?build-index triggers search index rebuild (admin use, local only)
$buildIndexRequested = requestValue($_GET, 'build-index') !== '';
if ($buildIndexRequested) {
    if (!isLocalRequest()) {
        http_response_code(403);
        echo '<p>Forbidden: ?build-index is only available from local requests.</p>';
        exit;
    }
    $content = rebuildSearchIndex();
}

//default options

//page content
$content = "";
//output mode
$mode = "";
$parameter = "";
$section = "";
$isSearchFallback = false;
$isListContent = false;

$check['man'] = "";
$check['perldoc'] = "";
$check['info'] = "";
$check['search'] = "";
$check['pydoc'] = "";
$check['ri'] = "";

// Detect format preference via: 1) GET param, 2) Accept header, 3) PATH_INFO segment, 4) default HTML
$format = "html";
$formatSource = "default"; // track where format was decided

// 1) GET parameter always takes highest priority
if (requestValue($_GET, "format") === "json" || requestValue($_GET, "amp;format") === "json") {
    $format = "json";
    $formatSource = "get";
} elseif (requestValue($_GET, "format") === "markdown" || requestValue($_GET, "amp;format") === "markdown") {
    $format = "markdown";
    $formatSource = "get";
} else {
    // 2) Accept header negotiation
    $accept = strtolower(serverValue("HTTP_ACCEPT", ""));
    if (strpos($accept, "application/json") !== false) {
        $format = "json";
        $formatSource = "accept_header";
    } elseif (strpos($accept, "text/markdown") !== false || strpos($accept, "text/x-markdown") !== false) {
        $format = "markdown";
        $formatSource = "accept_header";
    }
}

// 3) PATH_INFO segment overrides Accept header (but not GET)
/**
 * trans$_SERVER["ORIG_PATH_INFO"] to $_SERVER["PATH_INFO"]
 * for cgi/fcgi mode of php
 */
if ( serverValue("ORIG_PATH_INFO") !== "" ){
    $_SERVER["PATH_INFO"] = serverValue("ORIG_PATH_INFO");
}

// Handle /.well-known/mcp.json for MCP server discovery (GET only)
if (serverValue("PATH_INFO") !== "" && strpos(serverValue("PATH_INFO"), "/.well-known/mcp.json") === 0) {
    handleWellKnown();
    exit;
}

/**
 * parse parameters from $_SERVER["PATH_INFO"]: phpMan.php/MODE/COMMAND/SECTION/FORMAT
 * or parse parameters from HTTP/GET
 */
if ( serverValue("PATH_INFO") !== "" && trim(serverValue("PATH_INFO")) != "") {
    $array_param = explode('/', serverValue("PATH_INFO"));
    $segments = [];
    foreach ($array_param as $p) {
        $p_trimmed = trim($p);
        if ($p_trimmed !== "") {
            $segments[] = $p_trimmed;
        }
    }
    
    $allowed_modes = array("man", "perldoc", "info", "search", "copyright", "mcp", ".well-known", "pydoc", "ri");
    $seg_count = count($segments);
    
    if ($seg_count >= 1) {
        $first_seg = strtolower($segments[0]);
        if (in_array($first_seg, $allowed_modes)) {
            $mode = $first_seg;
            
            if ($seg_count >= 2) {
                $parameter = urldecode($segments[1]);
            }
            if ($seg_count >= 3) {
                $third_seg_lower = strtolower($segments[2]);
                if ($third_seg_lower === "html" || $third_seg_lower === "markdown" || $third_seg_lower === "json" || $third_seg_lower === "mcp") {
                    $format = $third_seg_lower;
                } else {
                    $section = $segments[2];
                }
            }
            if ($seg_count >= 4) {
                $fourth_seg_lower = strtolower($segments[3]);
                if ($fourth_seg_lower === "html" || $fourth_seg_lower === "markdown" || $fourth_seg_lower === "json" || $fourth_seg_lower === "mcp") {
                    $format = $fourth_seg_lower;
                } else {
                    $section = $segments[3];
                }
            }
        } else {
            // Mode is NOT explicitly provided, default to 'man'
            $mode = "man";
            $parameter = urldecode($segments[0]);
            
            if ($seg_count >= 2) {
                $second_seg_lower = strtolower($segments[1]);
                if ($second_seg_lower === "html" || $second_seg_lower === "markdown" || $second_seg_lower === "json" || $second_seg_lower === "mcp") {
                    $format = $second_seg_lower;
                } else {
                    $section = $segments[1];
                }
            }
            if ($seg_count >= 3) {
                $third_seg_lower = strtolower($segments[2]);
                if ($third_seg_lower === "html" || $third_seg_lower === "markdown" || $third_seg_lower === "json" || $third_seg_lower === "mcp") {
                    $format = $third_seg_lower;
                } else {
                    $section = $segments[2];
                }
            }
        }
    }
}
else {
    if ( requestValue($_GET, "mode") != "" ) {
        $mode = requestValue($_GET, "mode");
    } elseif ( requestValue($_GET, "amp;mode") != "" ) {
        $mode = requestValue($_GET, "amp;mode");
    }

    if ( requestValue($_GET, "parameter") != "" ) {
        $parameter = requestValue($_GET, "parameter");
    } elseif ( requestValue($_GET, "amp;parameter") != "" ) {
        $parameter = requestValue($_GET, "amp;parameter");
    }

    if ( requestValue($_GET, "section") != "") {
        $section = requestValue($_GET, "section");
    } elseif ( requestValue($_GET, "amp;section") != "") {
        $section = requestValue($_GET, "amp;section");
    }
}

// GET parameter always overrides
if ( requestValue($_GET, "format") != "" ) {
    $format = strtolower(trim(requestValue($_GET, "format")));
} elseif ( requestValue($_GET, "amp;format") != "" ) {
    $format = strtolower(trim(requestValue($_GET, "amp;format")));
}
$format = in_array($format, ["html", "markdown", "json", "mcp"]) ? $format : "html";

// .well-known discovery endpoint (e.g. /.well-known/mcp.json)
if ( $mode === ".well-known" ) {
    handleWellKnown();
    exit;
}

// set default mode
$mode = normalizeMode($mode);
$parameter = normalizeParameter($parameter);
$section = normalizeSection($section);
Profiler::mark('parse');

// ETag/304 check before exec-heavy dispatch (#83)
// Key-based ETag: content is stable for same mode/parameter/section until system update
if ($format === "html" && $mode !== "mcp" && $mode !== "copyright" && $mode !== "search" && $parameter !== "") {
    $etag = '"' . md5($mode . '/' . $parameter . '/' . $section . '/' . PHPMAN_VERSION) . '"';
    $ifNoneMatch = serverValue("HTTP_IF_NONE_MATCH", "");
    if ($ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }
} else {
    $etag = "";
}

if ( $parameter != "" ) {
    if ( $section == "" ) {
        $PHP_MAN_TITLE = "phpman > " . $mode . " > " . $parameter;
    }
    else {
        $PHP_MAN_TITLE = "phpman > " . $mode . " > " . $parameter . "(" . $section . ")";
    }
} elseif ($mode !== "" && $mode !== "search" && in_array($mode, ["man", "perldoc", "info", "pydoc", "ri"])) {
    $PHP_MAN_TITLE = "phpman > " . $mode;
}

//show GPL
else if ( $mode == "copyright" ) {
    showHeader($PHP_MAN_TITLE, "", "", $mode);
    showCopyright();
    echo "</body></html>";
    exit;
}
// MCP (Model Context Protocol) mode: JSON-RPC over HTTP POST
if ( $mode == "mcp" ) {
    handleMcp();
    exit;
}
/**
 * option checker and get manual page content, if no parameter: get index tree
 * phpMan -- man     -- man page index: section list
 *        |          \- man page by section: command list(via search)
 *        |          \- man page: specified command
 *        \- perldoc -- command list: (by search)
 *        |          \- perldoc page: specified module
 *        \- info    -- info page index: list
 *        |          \- info page:
 *        \- search  -- apropos search results: man page entrance list
 */
switch ( $mode ) {
    case "man":
        $check['man'] = " checked=\"checked\"";
        //show man pages
        if ( $parameter != "" ) {
            // Pre-detect perldoc targets: :: prefix or 3pm/3perl
            if (strpos($parameter, "::") !== false || $section === "3pm" || $section === "3perl") {
                $content = cacheOrExecute('perldoc', $parameter, $section, $format,
                    function() use ($parameter, $format) { return getPerldocPage($parameter, $format); });
            } else {
                $content = cacheOrExecute('man', $parameter, $section, $format,
                    function() use ($parameter, $section, $format) { return getManPage($parameter, $section, $format); });
            }

            // retry lower case if content is empty
            if ( preg_match("/^[A-Z\\._]+$/",$parameter) && trim($content) == ""){
                $content = cacheOrExecute('man', strtolower($parameter), $section, $format,
                    function() use ($parameter, $section, $format) { return getManPage(strtolower($parameter), $section, $format); });
            }

            //not find command then try perldoc (for perl modules with :: or section 3pm/3perl)
            //before falling back to search
            if (trim($content) == "") {
                if (strpos($parameter, "::") !== false || $section === "3pm" || $section === "3perl") {
                    $content = cacheOrExecute('perldoc', $parameter, $section, $format,
                        function() use ($parameter, $format) { return getPerldocPage($parameter, $format); });
                }
            }

            //still not found then redirect to search sections
            if (trim($content) == "") {
                $content = getSearchPage($parameter, $section, $format);
                $isSearchFallback = true;
                http_response_code(404);
                // Cache 404 result
                $cache = new PageCache();
                $cache->set('man', $parameter, $section, $format, null, 'not_found');
            }
        }
        //redirect to search sections
        else {
            $content = getManIndex($format);
        }
        break;
    case "perldoc":
        $check['perldoc'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = cacheOrExecute('perldoc', $parameter, '', $format,
                function() use ($parameter, $format) { return getPerldocPage($parameter, $format); });
            // Cache 404 if empty
            if (trim($content) == "") {
                $cache = new PageCache();
                $cache->set('perldoc', $parameter, '', $format, null, 'not_found');
            }
        }
        else {
            //show all possable perl entrance by search keywords: 'perl'
            // Wrapped in cacheOrExecute like pydoc/ and ri/ indexes — FTS5
            // row-by-row fetching on NFS takes ~5s for 300 rows, caching the
            // rendered HTML makes subsequent visits instant.
            $content = cacheOrExecute('perldoc', '__index__', '', $format,
                function() use ($format) { return getPerldocIndex($format); });
            $isListContent = true;
        }
        break;
    case "info":
        $check['info'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = cacheOrExecute('info', $parameter, '', $format,
                function() use ($parameter, $format) { return getInfoPage($parameter, $format); });
            // Cache 404 if empty
            if (trim($content) == "") {
                $cache = new PageCache();
                $cache->set('info', $parameter, '', $format, null, 'not_found');
            }
        }
        else {
            $content = getInfoIndex($format);
        }
        break;
    case "search":
        $check['search'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = cacheOrExecute('search', $parameter, $section, $format,
                function() use ($parameter, $section, $format) {
                    $inner = getSearchPage($parameter, $section, $format);
                    Profiler::mark('fts:done');

                    // Always cascade to pydoc3 and ri — aggregate results
                    // from all three sources (apropos/pydoc3/ri).
                    // getSearchPage() already extracted pydoc/ri from FTS5 into
                    // $pydocFtsLines / $riFtsLines; use those as baseline,
                    // only fall back to command-line if FTS5 had no hits.
                    if ($format === "html") {
                        $inner = "<h2>apropos</h2>\n" . $inner . "\n";
                        $pydocResults = "";
                        if (!empty($pydocFtsLines)) {
                            // FTS5 already found pydoc entries — render them
                            $pydocResults = "<ul>\n";
                            foreach ($pydocFtsLines as $pl) {
                                if (preg_match('/^(.+)\s+\(pydoc\)\s+—\s+(.+)$/', $pl, $m)) {
                                    $pydocResults .= '<li><a href="'.scriptName().'/pydoc/'.urlencode(trim($m[1])).'">'.h(trim($m[1])).'</a>';
                                    if (trim($m[2]) !== '') $pydocResults .= ' — '.h(trim($m[2]));
                                    $pydocResults .= "</li>\n";
                                }
                            }
                            $pydocResults .= "</ul>\n";
                        } else {
                            $pydocResults = getPydocSearchPage($parameter, "html");
                        }
                        Profiler::mark('pydoc:done');
                        if ($pydocResults !== "") {
                            $inner .= "<h2>Python 3 (pydoc3)</h2>\n" . $pydocResults . "\n";
                        }
                        $riResults = "";
                        if (!empty($riFtsLines)) {
                            $riResults = "<ul>\n";
                            foreach ($riFtsLines as $rl) {
                                if (preg_match('/^(.+)\s+\(ri\)\s+—\s+(.+)$/', $rl, $m)) {
                                    $riResults .= '<li><a href="'.scriptName().'/ri/'.urlencode(trim($m[1])).'">'.h(trim($m[1])).'</a>';
                                    if (trim($m[2]) !== '') $riResults .= ' — '.h(trim($m[2]));
                                    $riResults .= "</li>\n";
                                }
                            }
                            $riResults .= "</ul>\n";
                        } else {
                            $riResults = getRiSearchPage($parameter, "html");
                        }
                        Profiler::mark('ri:done');
                        if ($riResults !== "") {
                            $inner .= "<h2>Ruby (ri)</h2>\n" . $riResults . "\n";
                        }
                    } elseif ($format === "markdown") {
                        $pydocResults = "";
                        if (!empty($pydocFtsLines)) {
                            foreach ($pydocFtsLines as $pl) {
                                if (preg_match('/^(.+)\s+\(pydoc\)\s+—\s+(.+)$/', $pl, $m)) {
                                    $pydocResults .= '- ['.trim($m[1]).']('.baseUrl().'/pydoc/'.urlencode(trim($m[1])).'/markdown)';
                                    if (trim($m[2]) !== '') $pydocResults .= ' — '.trim($m[2]);
                                    $pydocResults .= "\n";
                                }
                            }
                        } else {
                            $pydocResults = getPydocSearchPage($parameter, "markdown");
                        }
                        Profiler::mark('pydoc:done');
                        if ($pydocResults !== "") {
                            $inner .= "\n\n## Python 3 (pydoc3)\n\n" . $pydocResults;
                        }
                        $riResults = "";
                        if (!empty($riFtsLines)) {
                            foreach ($riFtsLines as $rl) {
                                if (preg_match('/^(.+)\s+\(ri\)\s+—\s+(.+)$/', $rl, $m)) {
                                    $riResults .= '- ['.trim($m[1]).']('.baseUrl().'/ri/'.urlencode(trim($m[1])).'/markdown)';
                                    if (trim($m[2]) !== '') $riResults .= ' — '.trim($m[2]);
                                    $riResults .= "\n";
                                }
                            }
                        } else {
                            $riResults = getRiSearchPage($parameter, "markdown");
                        }
                        Profiler::mark('ri:done');
                        if ($riResults !== "") {
                            $inner .= "\n\n## Ruby (ri)\n\n" . $riResults;
                        }
                    } elseif ($format === "json" || $format === "mcp") {
                        $current = json_decode($inner, true);
                        if ($current === null) $current = [];
                        // Use FTS5 pydoc results if available, otherwise command-line
                        if (!empty($pydocFtsLines)) {
                            $pydoc_results_arr = [];
                            foreach ($pydocFtsLines as $pl) {
                                if (preg_match('/^(.+)\s+\(pydoc\)\s+—\s+(.+)$/', $pl, $m)) {
                                    $pydoc_results_arr[] = [
                                        "name" => trim($m[1]),
                                        "description" => trim($m[2]),
                                        "link" => baseUrl() . "/pydoc/" . urlencode(trim($m[1])) . "/json",
                                    ];
                                }
                            }
                            $current["pydoc_results"] = $pydoc_results_arr;
                        } else {
                            $pydocJson = getPydocSearchPage($parameter, "json");
                            Profiler::mark('pydoc:done');
                            if ($pydocJson !== "") {
                                $pydocData = json_decode($pydocJson, true);
                                if ($pydocData !== null) $current["pydoc_results"] = $pydocData["results"] ?? [];
                            }
                        }
                        // Use FTS5 ri results if available, otherwise command-line
                        if (!empty($riFtsLines)) {
                            $ri_results_arr = [];
                            foreach ($riFtsLines as $rl) {
                                if (preg_match('/^(.+)\s+\(ri\)\s+—\s+(.+)$/', $rl, $m)) {
                                    $ri_results_arr[] = [
                                        "name" => trim($m[1]),
                                        "description" => trim($m[2]),
                                        "link" => baseUrl() . "/ri/" . urlencode(trim($m[1])) . "/json",
                                    ];
                                }
                            }
                            $current["ri_results"] = $ri_results_arr;
                        } else {
                            $riJson = getRiSearchPage($parameter, "json");
                            Profiler::mark('ri:done');
                            if ($riJson !== "") {
                                $riData = json_decode($riJson, true);
                                if ($riData !== null) $current["ri_results"] = $riData["results"] ?? [];
                            }
                        }
                        $inner = json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if ($format === "mcp") {
                            $inner = formatForOutput($inner, "mcp");
                        }
                    }
                    return $inner;
                });
        }
        break;
    case "pydoc":
        $check['pydoc'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = cacheOrExecute('pydoc', $parameter, '', $format,
                function() use ($parameter, $format) { return getPydocPage($parameter, $format); });
            if (trim($content) == "") {
                $content = getPydocSearchPage($parameter, $format);
                $isSearchFallback = true;
                http_response_code(404);
                // Cache 404
                $cache = new PageCache();
                $cache->set('pydoc', $parameter, '', $format, null, 'not_found');
            }
        }
        else {
            $content = cacheOrExecute('pydoc', '__index__', '', $format,
                function() use ($format) { return getPydocIndex($format); });
            $isListContent = true;
        }
        break;
    case "ri":
        $check['ri'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = cacheOrExecute('ri', $parameter, '', $format,
                function() use ($parameter, $format) { return getRiPage($parameter, $format); });
            if (trim($content) == "") {
                $content = getRiSearchPage($parameter, $format);
                $isSearchFallback = true;
                http_response_code(404);
                // Cache 404
                $cache = new PageCache();
                $cache->set('ri', $parameter, '', $format, null, 'not_found');
            }
        }
        else {
            $content = cacheOrExecute('ri', '__index__', '', $format,
                function() use ($format) { return getRiIndex($format); });
            $isListContent = true;
        }
        break;
}

// Profiler: content generation complete
Profiler::mark('content');

// Show Markdown or HTML output
if ($format === "markdown") {
    header("Content-Type: text/markdown; charset=UTF-8");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 3600 * 24 * 7) . " GMT");
    // Append profiling data as HTML comment
    if (Profiler::getEnabled()) {
        $report = Profiler::getReport();
        $total = $report['_total_ms'] ?? 0;
        unset($report['_total_ms'], $report['_version']);
        $lines = [];
        foreach ($report as $r) {
            $lines[] = sprintf("  %-20s %6.2fms  (+%6.2fms)", $r['label'], $r['elapsed_ms'], $r['delta_ms']);
        }
        $content .= "\n\n<!-- profile:\n" . implode("\n", $lines) . "\n  total: {$total}ms\n-->\n";
    }
    echo "# " . $PHP_MAN_TITLE . "\n\n" . $content;
    exit;
}

// Show JSON or MCP output
if ($format === "json" || $format === "mcp") {
    // Append profiling data as _profiling key
    if (Profiler::getEnabled()) {
        $data = json_decode($content, true);
        if ($data !== null && is_array($data)) {
            $profiling = Profiler::getReport();
            $data['_profiling'] = $profiling;
            $content = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    }
    header("Content-Type: application/json; charset=UTF-8");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    // ETag based on actual content hash — ensures 304 only when body is identical
    $etag = '"' . md5($content) . '"';
    header("ETag: {$etag}");
    $ifNoneMatch = serverValue("HTTP_IF_NONE_MATCH", "");
    if ($ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 3600 * 24 * 7) . " GMT");
    // Gzip compress large JSON responses (bash=351KB → ~97KB)
    $acceptEncoding = strtolower(serverValue("HTTP_ACCEPT_ENCODING", ""));
    if (strpos($acceptEncoding, "gzip") !== false && function_exists("gzencode") && strlen($content) > GZIP_MIN_BYTES) {
        $gzipped = gzencode($content, 6);
        if ($gzipped !== false) {
            header("Content-Encoding: gzip");
            header("Vary: Accept-Encoding");
            header("Content-Length: " . strlen($gzipped));
            echo $gzipped;
            exit;
        }
    }
    echo $content;
    exit;
}

// +--------------------------------------------------------------------------------+
// | show output                                                                    |
// +--------------------------------------------------------------------------------+
// Line threshold: ~80 lines ≈ two screens at 14px monospace
$lineThreshold = TOC_LINE_THRESHOLD;
// Determine if this page has real content (for robots meta)
// Search results and build-index pages should be noindex
$hasRealContent = (trim($content) !== "" && !$isSearchFallback && $mode !== "search" && !$buildIndexRequested);

// Count content lines and set body class for CSS-based show/hide
$showNav = false;
if ($hasRealContent) {
    $showNav = (substr_count($content, "\n") + 1 > $lineThreshold);
}

// ETag already computed before exec() dispatch (#83)
Profiler::mark('render');

showHeader($PHP_MAN_TITLE, $parameter, $section, $mode, $hasRealContent, $showNav, $etag);

// H1 breadcrumb: phpMan > mode > command(section)
$modes = ["man" => ["label" => "man", "url" => "/man"], "perldoc" => ["label" => "perldoc", "url" => "/search/perl"], "info" => ["label" => "info", "url" => "/info"], "pydoc" => ["label" => "pydoc", "url" => "/pydoc"], "ri" => ["label" => "ri", "url" => "/ri"]];
if ($parameter !== "" && $mode !== "" && $mode !== "search") {
    $bc_parts = [];
    $bc_parts[] = "<a href=\"".h(scriptName())."\">phpMan</a>";
    if (isset($modes[$mode])) {
        $bc_parts[] = "<a href=\"".h(scriptName() . $modes[$mode]["url"])."\">".h($modes[$mode]["label"])."</a>";
    }
    $section_label = $section !== "" ? "({$section})" : "";
    $bc_parts[] = h($parameter . $section_label);
    echo "<h1>" . implode(" &gt; ", $bc_parts) . "</h1>\n";
} elseif ($mode !== "" && $mode !== "search" && isset($modes[$mode])) {
    echo "<h1><a href=\"".h(scriptName())."\">phpMan</a> &gt; " . h($modes[$mode]["label"]) . "</h1>\n";
} else {
    echo "<h1><a href=\"".h(scriptName())."\">".h($PHP_MAN_TITLE)."</a></h1>\n";
}

// Build markdown/JSON URLs for format links (showForm below)
$markdownUrl = "";
$jsonUrl = "";
$script_name_path = baseUrl();

// Detail pages (actual man/perldoc/info/pydoc/ri content): pathinfo URLs
if ($content !== ""
    && in_array($mode, ["man", "perldoc", "info", "pydoc", "ri"])
    && $parameter !== ""
    && $mode !== "search"
) {
    // Determine if content is real content (not index/search fallback)
    // For man mode: if parameter was given and content is not from search redirect
    $isDetailPage = false;
    if ($mode === "man" && trim($content) !== "" && !$isSearchFallback) {
        $isDetailPage = true;
    }
    if ($mode === "perldoc" && $parameter !== "") {
        $isDetailPage = true;
    }
    if ($mode === "info" && $parameter !== "") {
        $isDetailPage = true;
    }
    if ($mode === "pydoc" && trim($content) !== "" && !$isSearchFallback) {
        $isDetailPage = true;
    }
    if ($mode === "ri" && trim($content) !== "" && !$isSearchFallback) {
        $isDetailPage = true;
    }

    if ($isDetailPage) {
        $markdownUrl = $script_name_path . "/" . $mode . "/" . urlencode($parameter);
        if ($mode === "man" && $section !== "") {
            $markdownUrl .= "/" . $section;
        }
        $markdownUrl .= "/markdown";

        $jsonUrl = $script_name_path . "/" . $mode . "/" . urlencode($parameter);
        if ($mode === "man" && $section !== "") {
            $jsonUrl .= "/" . $section;
        }
        $jsonUrl .= "/json";
    }
}
// Index pages (man/perldoc/info without parameter): query param URLs
elseif ($content !== "" && in_array($mode, ["man", "perldoc", "info", "pydoc", "ri"]) && $parameter === "") {
    $markdownUrl = $script_name_path . "?mode=" . urlencode($mode) . "&format=markdown";
    $jsonUrl = $script_name_path . "?mode=" . urlencode($mode) . "&format=json";
}
// Search results pages: query param URLs
elseif ($mode === "search" && $parameter !== "") {
    $markdownUrl = $script_name_path . "?parameter=" . urlencode($parameter) . "&mode=search&format=markdown";
    $jsonUrl = $script_name_path . "?parameter=" . urlencode($parameter) . "&mode=search&format=json";
}

echo "<div id=\"content-wrap\">\n";
showForm($parameter, $check, $markdownUrl, $jsonUrl, $mode, $section);

	// v2.2: TLDR block for man section 1 detail pages
	if ($mode === "man" && $parameter !== "" && trim($content) !== "") {
	    $tldrData = fetchOfficialTldr($parameter, $mode, $section);
	    // Filter out empty commands from malformed data before checking
	    $tldrExamples = [];
	    if (!empty($tldrData) && !empty($tldrData["examples"])) {
	        $tldrExamples = array_filter($tldrData["examples"], function ($ex) {
	            return !empty(trim($ex["command"] ?? ""));
	        });
	    }
	    // Only render when we have at least one real example with a command
	    if (!empty($tldrExamples)) {
	        $contentLines = substr_count($content, "\n") + 1;
	        $expanded = $contentLines > 200 ? " tldr-expanded" : "";
	        echo "<div class=\"tldr-block{$expanded}\">\n";
	        echo "<div class=\"tldr-header\" onclick=\"this.parentNode.classList.toggle('tldr-expanded')\">";
	        $src = $tldrData["source"] === "cheatsh" ? "cheat.sh" : "tldr-pages";
	        $tldrLink = $tldrData["source"] === "cheatsh"
	            ? "https://cheat.sh/" . urlencode(strtolower($parameter))
	            : "https://tldr.inbrowser.app/pages/common/" . urlencode(strtolower($parameter));
	        echo "&#9889; <a href=\"{$tldrLink}\" target=\"_blank\" rel=\"noopener\" style=\"color:inherit;text-decoration:none;border-bottom:1px dotted\">TLDR: " . h($parameter) . "</a> <span class=\"tldr-source\">({$src})</span></div>\n";
	        echo "<div class=\"tldr-body\">\n";
	        if (!empty($tldrData["description"])) {
	            echo "<p class=\"tldr-desc\">" . h($tldrData["description"]) . "</p>\n";
	        }
	        echo "<ul class=\"tldr-examples\">\n";
	        foreach (array_slice($tldrExamples, 0, 10) as $ex) {
	            $desc = $ex["description"] ?? "";
	            $desc = preg_replace('/\[(.)\]/', '<b>$1</b>', h($desc));
	            echo "<li>{$desc}<br /><code>" . h($ex["command"] ?? "") . "</code></li>\n";
	        }
	        echo "</ul>\n";
	        echo "</div></div>\n";
	    }
	}

	// For man page content, add section anchors and floating TOC
    // Sidebars are collected and output AFTER content for better SEO
    $tocSidebar = '';
    if ($mode !== "markdown" && $mode !== "search" && !$isSearchFallback && $parameter !== "" && trim($content) !== "") {
        list($anchoredContent, $tocItems) = addManPageToc($content);

        $hasTocContent = $showNav && (count($tocItems) > 1
            || (count($tocItems) === 1 && !empty($tocItems[0]['children'])));
        if ($hasTocContent) {
            $tocSidebar .= "<div id=\"toc-sidebar\">\n";
            $pageLabel = $parameter . ($section !== "" ? "({$section})" : "");
            $tocSidebar .= "<div class=\"toc-title\" id=\"toc-toggle\" onclick=\"document.body.classList.toggle('toc-open');\">" . h($pageLabel) . " <span class=\"toc-open-icon\">&#9633;</span><span class=\"toc-close-icon\">&#10005;</span></div>\n";
            foreach ($tocItems as $l1) {
                $tocSidebar .= "<a href=\"#" . h($l1['id']) . "\">" . h($l1['label']) . "</a>\n";
                if (!empty($l1['children'])) {
                    $tocSidebar .= "<div class=\"toc-subs\">\n";
                    foreach ($l1['children'] as $l2) {
                        $tocSidebar .= "<a href=\"#" . h($l2['id']) . "\" class=\"toc-sub\">" . h($l2['label']) . "</a>\n";
                    }
                    $tocSidebar .= "</div>\n";
                }
            }
            $tocSidebar .= "</div>\n";
        }

        echo "<div id=\"man-content\"><pre>" . $anchoredContent . "</pre></div>\n";
    } elseif ($isSearchFallback || $mode === "search" || $isListContent) {
        echo "<div id=\"man-content\">" . $content . "</div>\n";
    } else {
        echo "<pre>" . $content . "</pre>\n";
    }
    echo "</div>";
    echo $tocSidebar;

showFooter($VALIDATOR, $showNav);


// +--------------------------------------------------------------------------------+
// | sub functions                                                                  |
// +--------------------------------------------------------------------------------+

//show html header
function showHeader (string $title = "", string $parameter = "", string $section = "", string $mode = "", bool $hasRealContent = true, bool $showNav = false, string $etag = ""): void {
    header("Content-Type: text/html; charset=UTF-8");
    // Security response headers (#40, #36, #29)
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https://www.w3.org https://jigsaw.w3.org data:; script-src 'self' 'unsafe-inline'; frame-ancestors 'none';");
    if (!isLocalRequest()) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    }
    // MCP service discovery — sanitize $script_path against CRLF injection (#34)
    $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : strtok($_SERVER['REQUEST_URI'], '?');
    $script_path = preg_replace('/[\r\n].*/', '', $script_path);
    header('Link: <' . $script_path . '/mcp>; rel="mcp-server"');
    // ETag + caching (#60)
    if ($etag !== "") {
        header("ETag: {$etag}");
        header("Cache-Control: public, max-age=86400, stale-while-revalidate=604800");
    }
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    // Expires one month later
    header("Expires: " .gmdate ("D, d M Y H:i:s", time() + 3600 * 24 * 7). " GMT");
    // Gzip handled by Nginx/Cloudflare (#84)

    // Build SEO meta values
    $site_name = "phpMan";
    // Auto-detect base URL from current request (works for any deployment)
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : strtok($_SERVER['REQUEST_URI'], '?');
    $base_url = $proto . "://" . getSafeHost() . $script_path;
    $canonical_url = $base_url;
    $meta_description = "phpman: Open-source Linux command reference with JSON API and MCP Server for AI agents. Browse man pages, perldoc, and GNU info.";
    $meta_keywords = "man page, unix manual, linux command, perldoc, info page, phpman, json api, mcp server, ai agent";

    if ($parameter !== "") {
        $section_suffix = $section !== "" ? "({$section})" : "";
        $canonical_url = $base_url . "/" . urlencode($mode ?: "man") . "/" . urlencode($parameter);
        if ($section !== "") {
            $canonical_url .= "/" . urlencode($section);
        }

        if ($mode === "man") {
            $meta_description = "{$parameter}{$section_suffix} man page — Linux command reference with options, examples, and JSON API/MCP access via phpman";
            $meta_keywords = "{$parameter} man page, {$parameter} linux, {$parameter} unix, man {$parameter}, {$parameter} command, json api, mcp";
        } elseif ($mode === "perldoc") {
            $meta_description = "{$parameter} perldoc — Perl documentation with JSON API and MCP access via phpman";
            $meta_keywords = "{$parameter} perldoc, {$parameter} perl, perl {$parameter}, {$parameter} documentation, json api, mcp";
        } elseif ($mode === "info") {
            $meta_description = "{$parameter} info page — GNU documentation with JSON API and MCP access via phpman";
            $meta_keywords = "{$parameter} info page, {$parameter} gnu, info {$parameter}, {$parameter} documentation, json api, mcp";
        } elseif ($mode === "search") {
            $meta_description = "Search results for '{$parameter}' in Unix/Linux man pages, perldoc, and info pages via phpman";
            $meta_keywords = "{$parameter}, man page search, {$parameter} command, search manual, json api, mcp";
        } elseif ($mode === "pydoc") {
            $meta_description = "{$parameter} pydoc — Python 3 documentation with JSON API and MCP access via phpman";
            $meta_keywords = "{$parameter} pydoc, {$parameter} python, python {$parameter}, {$parameter} documentation, json api, mcp";
        } elseif ($mode === "ri") {
            $meta_description = "{$parameter} ri — Ruby documentation with JSON API and MCP access via phpman";
            $meta_keywords = "{$parameter} ri, {$parameter} ruby, ruby {$parameter}, {$parameter} documentation, json api, mcp";
        }
    }

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" ".
        "\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">".
        "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\">\n".
        "<head>\n".
        "<title>".h($title)."</title>\n".
        "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>\n".
        "<meta name=\"description\" content=\"".h($meta_description)."\"/>\n".
        "<meta name=\"keywords\" content=\"".h($meta_keywords)."\"/>\n".
        "<link rel=\"canonical\" href=\"".h($canonical_url)."\"/>\n".
        "<meta name=\"robots\" content=\"".($hasRealContent ? "index, follow" : "noindex, follow")."\"/>\n".
        // GEO: citation for AI/LLM attribution
        "<meta name=\"citation_title\" content=\"".h($title)."\"/>\n".
        "<meta name=\"citation_online_date\" content=\"".gmdate("Y/m/d")."\"/>\n".
        "<meta name=\"citation_author\" content=\"Che Dong\"/>\n".
        "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"/>\n".
        "<link rel=\"icon\" type=\"image/x-icon\" href=\"/favicon.ico\"/>\n";

    $css_path = str_replace('phpMan.php', 'phpman.css', scriptName());
    echo "<link rel=\"stylesheet\" type=\"text/css\" href=\"".h($css_path)."\"/>\n";

    // JSON-LD structured data for SEO/GEO (#64)
    if ($parameter !== "" && in_array($mode, ["man", "perldoc", "info", "pydoc", "ri"])) {
        $section_label = $section !== "" ? " (section {$section})" : "";
        $schema_json = json_encode([
            "@context" => "https://schema.org",
            "@type" => "TechArticle",
            "name" => $parameter . $section_label,
            "description" => $meta_description,
            "url" => $canonical_url,
            "author" => [
                "@type" => "Person",
                "name" => "Che Dong",
                "url" => $base_url
            ],
            "publisher" => [
                "@type" => "Organization",
                "name" => $site_name,
                "url" => $base_url
            ],
            "about" => [
                "@type" => "SoftwareApplication",
                "name" => $parameter,
                "applicationCategory" => "DeveloperApplication",
                "operatingSystem" => "Linux, Unix"
            ],
            "datePublished" => gmdate("Y-m-d"),
            "inLanguage" => "en"
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // #37: escape </ to prevent breaking out of <script> context
        $schema_json = str_replace('</', '<\/', $schema_json);
        echo "<script type=\"application/ld+json\">\n{$schema_json}\n</script>\n";
    } else {
        // Homepage/index: WebApplication schema
        $schema_json = json_encode([
            "@context" => "https://schema.org",
            "@type" => "WebApplication",
            "name" => $site_name,
            "description" => $meta_description,
            "url" => $canonical_url,
            "author" => [
                "@type" => "Person",
                "name" => "Che Dong",
                "url" => $base_url
            ],
            "applicationCategory" => "DeveloperApplication",
            "operatingSystem" => "Linux, Unix"
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $schema_json = str_replace('</', '<\/', $schema_json);
        echo "<script type=\"application/ld+json\">\n{$schema_json}\n</script>\n";
    }

    $bodyClass = $showNav ? ' class="ext-nav"' : '';
    echo "</head>\n<body{$bodyClass}>\n<div id=\"top\"></div>\n";
}

//promter and recursive call
function showForm (string $parameter, array $check, string $markdownUrl = "", string $jsonUrl = "", string $mode = "", string $section = ""): void {
    $script_name = h(scriptName());
    $parameter_value = h($parameter);

    echo "<form action=\"".$script_name."\" method=\"get\">\n".
        "<fieldset>\n";

    // Format links (Markdown | JSON | MCP) — only on detail pages (parameter set, not search mode)
    $fmtLinks = [];
    $cmd_label = h($parameter ?: "command");
    $isDetail = $parameter !== "" && in_array($mode, ["man", "perldoc", "info", "pydoc", "ri"]);
    if ($isDetail) {
        if ($markdownUrl !== "") {
            $fmtLinks[] = '<a href="' . h($markdownUrl) . '" title="' . $cmd_label . ' in Markdown format">Markdown</a>';
        }
        if ($jsonUrl !== "") {
            $fmtLinks[] = '<a href="' . h($jsonUrl) . '" title="' . $cmd_label . ' structured JSON API">JSON</a>';
        }
        // MCP link only when page has real content (not 404/search fallback)
        if ($markdownUrl !== "" || $jsonUrl !== "") {
            $mcp_href = scriptName() . "/" . urlencode($mode) . "/" . urlencode($parameter) . "/mcp";
            $fmtLinks[] = '<a href="' . h($mcp_href) . '" title="MCP Server integration">MCP</a>';
        }
    }

    $fmtStr = !empty($fmtLinks) ? implode(" |\n", $fmtLinks) . " &nbsp;" : "";

    echo "<p>" . $fmtStr . "<input type=\"text\" id=\"cmd-input\" size=\"20\" name=\"parameter\" value=\"".$parameter_value."\"/>\n".
        "<input type=\"radio\" name=\"mode\" value=\"man\" id=\"mode-man\"".$check['man']."/>".
        "<label for=\"mode-man\"><a href=\"".$script_name."/man\">man</a></label>\n".
        "<input type=\"radio\" name=\"mode\" value=\"perldoc\" id=\"mode-perldoc\"".$check['perldoc']."/>".
        "<label for=\"mode-perldoc\"><a href=\"".$script_name."/search/perl\">perldoc</a></label>\n".
        "<input type=\"radio\" name=\"mode\" value=\"info\" id=\"mode-info\"".$check['info']."/>".
        "<label for=\"mode-info\"><a href=\"".$script_name."/info\">info</a></label>\n".
        "<input type=\"radio\" name=\"mode\" value=\"pydoc\" id=\"mode-pydoc\"".$check['pydoc']."/>".
        "<label for=\"mode-pydoc\"><a href=\"".$script_name."/pydoc\">pydoc3</a></label>\n".
        "<input type=\"radio\" name=\"mode\" value=\"ri\" id=\"mode-ri\"".$check['ri']."/>".
        "<label for=\"mode-ri\"><a href=\"".$script_name."/ri\">ri</a></label>\n".
        "<input type=\"radio\" name=\"mode\" value=\"search\" id=\"mode-search\"".$check['search']."/>".
        "<label for=\"mode-search\"><a href=\"".$script_name."/man/apropos\">search</a></label>\n".
        "&nbsp;<input type=\"submit\" value=\"Go\"/></p>".
        "</fieldset>\n".
        "</form>\n";

    $isDetailPage = in_array($mode, ["man", "perldoc", "info", "pydoc", "ri"]) && $parameter !== "";
    $hasContent = ($markdownUrl !== "" || $jsonUrl !== "");
    $cmd_label = h($parameter ?: "command");
    if ($isDetailPage && !$hasContent) {
        // --- Not found: show external search/reference links ---
        echo "<p>";
        echo "Not found locally for <b>" . $cmd_label . "</b>. Try " .
            '<a href="https://www.google.com/search?q=' . urlencode($parameter) . '" target="_blank" rel="noopener">Google search</a>';

        if ($mode === "man") {
            echo ' | ' .
                '<a href="https://cheat.sh/' . urlencode($parameter) . '" target="_blank" rel="noopener">cheat.sh</a>';
        } elseif ($mode === "perldoc") {
            echo ' | ' .
                '<a href="https://metacpan.org/pod/' . urlencode($parameter) . '" target="_blank" rel="noopener">MetaCPAN</a>';
        } elseif ($mode === "pydoc") {
            echo ' | ' .
                '<a href="https://docs.python.org/3/search.html?q=' . urlencode($parameter) . '" target="_blank" rel="noopener">Python Docs</a>';
        } elseif ($mode === "ri") {
            echo ' | ' .
                '<a href="https://ruby-doc.org/search.html?q=' . urlencode($parameter) . '" target="_blank" rel="noopener">Ruby-Doc</a>';
        }
        echo "</p>\n";
    }
}

//show footer
function showFooter (string $validator = "", bool $showNav = false): void {
    $script_name = h(scriptName());
    $remote_addr = h(serverValue("REMOTE_ADDR", "unknown"));
    $user_agent = h(serverValue("HTTP_USER_AGENT", "unknown"));

    // Server software version: only visible from localhost (like phpinfo())
    $server_info = "";
    if (isLocalRequest()) {
        $server_info = " On " . h(serverValue("SERVER_SOFTWARE", "unknown server"));
    }

    echo "<p>Generated by <a href=\"https://github.com/chedong/phpman\">phpMan</a>" .
        " " . h(GIT_DESCRIBE) .
        " Author: <a href=\"https://www.chedong.com/\">Che Dong</a>" .
        $server_info .
        " Under <a href=\"".$script_name."/copyright\">GNU General Public License</a>" .
        "<br />" .
        date("Y-m-d H:i") . " @" . $remote_addr .
        "<br />CrawledBy " . $user_agent .
        "<br />" . $validator .
        // Profiling data for debug mode
        (Profiler::getEnabled() ? profilerHtmlBlock() : "") .
        "</p>" .
        ($showNav ? '<div id="back-to-top"><a href="#top">^_back to top</a></div>' : "") .
        "</body></html>";
}

/**
 * Serve MCP server discovery JSON at /.well-known/mcp.json path.
 * Returns JSON describing the MCP server location, available tools, and how to use them.
 * Handles GET requests and returns application/json.
 */
function handleWellKnown (): void {
    // Only allow GET requests for well-known discovery
    if (serverValue("REQUEST_METHOD") !== "GET") {
        http_response_code(405);
        header("Content-Type: application/json; charset=UTF-8");
        header("X-Content-Type-Options: nosniff");
        header("Allow: GET");
        echo json_encode(["error" => "Method not allowed. Use GET."], JSON_UNESCAPED_SLASHES);
        return;
    }

    header("Content-Type: application/json; charset=UTF-8");
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: public, max-age=3600");
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");

    $base = baseUrl();
    $mcpEndpoint = $base . "/mcp";

    $discovery = [
        "name" => "phpMan",
        "version" => PHPMAN_VERSION,
        "description" => "Unix/Linux man page, Perldoc, and Info page web interface with MCP support",
        "url" => $base,
        "mcp" => [
            "endpoint" => $mcpEndpoint,
            "protocolVersion" => "2024-11-05",
            "transport" => "streamable-http",
            "capabilities" => [
                "tools" => ["listChanged" => false]
            ]
        ],
        "tools" => getMcpToolDefinitions(),  // #48: shared definition
        "endpoints" => [
            "man" => $base . "/man/{command}/{section?}/json",
            "perldoc" => $base . "/perldoc/{module}/json",
            "info" => $base . "/info/{page}/json",
            "search" => $base . "/search/{query}/{section?}/json",
            "markdown" => $base . "/{mode}/{command}/{section?}/markdown"
        ]
    ];

    echo json_encode($discovery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

//handle Mcp protocol request
function handleMcp (): void {
    header("Content-Type: application/json; charset=UTF-8");
    header("X-Content-Type-Options: nosniff");
    // MCP uses text/event-stream for SSE transport, but StreamableHTTP uses plain POST
    // Allow both plain and SSE content types
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");

    // Only accept POST requests (#42)
    if (serverValue("REQUEST_METHOD") !== "POST") {
        http_response_code(405);
        header("Allow: POST");
        sendMcpError(null, -32600, "Method not allowed. Use POST.");
        return;
    }

    // Limit request body size to 64KB (#31, #42)
    $contentLength = (int)(serverValue("CONTENT_LENGTH", "0"));
    if ($contentLength > 65536) {
        sendMcpError(null, -32700, "Payload too large (max 64KB)");
        return;
    }

    // Read JSON-RPC body
    $rawBody = file_get_contents("php://input");
    if ($rawBody === false || trim($rawBody) === "") {
        sendMcpError(null, -32700, "Parse error: empty body");
        return;
    }

    $request = json_decode($rawBody, true);
    if ($request === null) {
        sendMcpError(null, -32700, "Parse error: invalid JSON");
        return;
    }

    $method = $request["method"] ?? "";
    $id = $request["id"] ?? null;

    // Also accept "notifications/initialized" as no-op
    if ($method === "notifications/initialized") {
        // MCP spec: client sends this after initialize, server can ignore
        http_response_code(202);
        echo json_encode(["jsonrpc" => "2.0"]);
        return;
    }

    if ($method === "") {
        sendMcpError($id, -32600, "Invalid Request: missing method");
        return;
    }

    switch ($method) {
        case "initialize":
            handleMcpInitialize($id);
            break;
        case "tools/list":
            handleMcpToolsList($id);
            break;
        case "tools/call":
            $params = $request["params"] ?? [];
            if (!is_array($params)) {
                sendMcpError($id, -32602, "Invalid params: params must be an object");
                break;
            }
            handleMcpToolsCall($id, $params);
            break;
        default:
            sendMcpError($id, -32601, "Method not found");
    }
}

function sendMcpError ($id, int $code, string $message): void {
    echo json_encode([
        "jsonrpc" => "2.0",
        "id" => $id,
        "error" => ["code" => $code, "message" => $message]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function sendMcpResult ($id, array $result): void {
    echo json_encode([
        "jsonrpc" => "2.0",
        "id" => $id,
        "result" => $result
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function handleMcpInitialize ($id): void {
    $base = baseUrl();
    sendMcpResult($id, [
        "protocolVersion" => "2024-11-05",
        "serverInfo" => [
            "name" => "phpMan",
            "version" => PHPMAN_VERSION
        ],
        "capabilities" => [
            "tools" => ["listChanged" => false]
        ],
        "instructions" => "phpMan provides structured access to Unix/Linux man pages, Perl perldoc modules, and GNU info pages. "
            . "Use cli_help to retrieve the full manual for a command or module (e.g. command='ls', command='git', or command='File::Basename' for Perl; "
            . "optionally pass section='3pm' for Perl modules or another manual section). "
            . "Use cli_search to find commands by keyword via apropos (e.g. query='recursive delete', query='network'). "
            . "Responses include a section outline, synopsis, flag/option table, examples, and see-also references — prefer the section outline to locate "
            . "specific content before reading full sections. "
            . "Web endpoint: {$base}/mcp"
    ]);
}

function handleMcpToolsList ($id): void {
    sendMcpResult($id, [
        "tools" => getMcpToolDefinitions()  // #48: shared definition
    ]);
}

function handleMcpToolsCall ($id, array $params): void {
    $name = $params["name"] ?? "";
    $args = $params["arguments"] ?? [];

    if ($name === "") {
        sendMcpError($id, -32602, "Invalid params: missing tool name");
        return;
    }
    if (!is_array($args)) {
        sendMcpError($id, -32602, "Invalid params: arguments must be an object");
        return;
    }

    try {
        $content = executeMcpTool($name, $args);
        // Content is already MCP-wrapped (format="mcp" produces {"content":[...]})
        // Send as raw result — the wrapper IS the result
        $result = json_decode($content, true);
        if ($result === null) {
            sendMcpError($id, -32603, "Internal error: invalid MCP output");
            return;
        }
        sendMcpResult($id, $result);
    } catch (Throwable $e) {
        phpManLog("MCP internal error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        sendMcpError($id, -32603, "Internal error");
    }
}

function executeMcpTool (string $name, array $args): string {
    switch ($name) {
        case "cli_help":
            return executeCliHelp($args);
        case "cli_search":
            return executeCliSearch($args);
        default:
            throw new Exception("Unknown tool: {$name}");
    }
}

function executeCliHelp (array $args): string {
    $command = trim($args["command"] ?? "");
    $section = trim($args["section"] ?? "");

    if ($command === "") {
        throw new Exception("Missing required parameter: command");
    }

    // Auto-detect documentation source
    $is_perl = (strpos($command, "::") !== false || $section === "3pm" || $section === "3perl");
    // Ruby instance methods use # (e.g. Array#map)
    $is_ruby = (strpos($command, "#") !== false);
    // Python qualified names use dotted notation without :: (e.g. os.path, json.loads)
    $is_python = (!$is_perl && !$is_ruby && strpos($command, ".") !== false);
    
    if ($is_perl) {
        return getPerldocPage($command, "mcp");
    }
    if ($is_ruby) {
        $content = getRiPage($command, "mcp");
        if ($content !== "") return $content;
    }
    if ($is_python) {
        $content = getPydocPage($command, "mcp");
        if ($content !== "") return $content;
    }
    
    // Try man first (default)
    $content = getManPage($command, $section, "mcp");
    if ($content !== "") return $content;
    
    // Fallback cascade: try pydoc, then ri
    $content = getPydocPage($command, "mcp");
    if ($content !== "") return $content;
    
    $content = getRiPage($command, "mcp");
    if ($content !== "") return $content;
    
    return $content;
}

function executeCliSearch (array $args): string {
    $query = trim($args["query"] ?? "");
    $section = trim($args["section"] ?? "");

    if ($query === "") {
        throw new Exception("Missing required parameter: query");
    }

    return getSearchPage($query, $section, "mcp");
}

//get specified command's man page and convert to html format
function getManPage (string $parameter, string $section = "", string $format = "html"): string {
    $lines = array();
    // Save and restore env vars to prevent leaks across requests (PHP-FPM/mod_php)
    $oldManroffopt = getenv('MANROFFOPT');
    $oldManwidth = getenv('MANWIDTH');
    try {
        putenv("MANROFFOPT=-rLL=" . $GLOBALS['PHP_MAN_WIDTH'] . "n");
        // Prefer -Tutf8 (GNU man) for SGR-encoded bold/underline output.
        // Falls back to bare man on BSD/macOS, which uses overstrike (X^HX).
        // Both formats are handled by formatManPerlDoc().
        $command = "man -Tutf8 ";
        if ($section !== "") {
            $command .= escapeshellarg($section)." ";
        }
        $command .= escapeshellarg($parameter);

        exec($command, $lines, $return_code);
        // #26: BSD fallback detection — three conditions trigger the fallback path:
        //   1. $return_code !== 0         — man exits with error (GNU man: command not found)
        //   2. count($lines) === 0        — man produces no output (edge case on some BSDs)
        //   3. illegal/unknown/invalid     — macOS man outputs error to stdout with exit 0
        // On macOS, `man -Tutf8` outputs "illegal option -- T" on stdout (not stderr),
        // so we must check the first line for error patterns even when return_code is 0.
        // This dual-detection handles both GNU and BSD man behavior across platforms.
        $first_line = count($lines) > 0 ? trim($lines[0]) : "";
        if ($return_code !== 0 || count($lines) === 0 ||
            preg_match('/\b(illegal|unknown|invalid)\s+option\b/i', $first_line)) {
            // Fallback: bare man with MANWIDTH (BSD/macOS).
            // BSD man doesn't support -Tutf8 or groff's -rLL,
            // but it respects MANWIDTH for line-width control.
            $lines = array();
            putenv("MANWIDTH=" . $GLOBALS['PHP_MAN_WIDTH']);
            $fallback = "man ";
            if ($section !== "") {
                $fallback .= escapeshellarg($section)." ";
            }
            $fallback .= escapeshellarg($parameter);
            exec($fallback, $lines, $return_code);
            if ($return_code !== 0 || count($lines) === 0) {
                return "";
            }
        }
        if ($format === "markdown") {
            return formatManPerlDocToMarkdown($lines, $parameter, "man", $section);        }
        if ($format === "json" || $format === "mcp") {
            return formatForOutput(formatToJSON($lines, $parameter, $section, "man"), $format);
        }
        return formatManPerlDoc($lines, "man");
    } finally {
        // Restore env vars to prevent leaks to subsequent requests
        putenv("MANROFFOPT" . ($oldManroffopt !== false ? "=" . $oldManroffopt : ""));
        putenv("MANWIDTH" . ($oldManwidth !== false ? "=" . $oldManwidth : ""));
    }
}

/**
 * Add anchor IDs to man page section headings and build floating TOC.
 * Two levels:
 *   Level 1: all-caps section names on their own line (e.g., NAME, DESCRIPTION)
 *   Level 2: indented lines (≥3 spaces) starting with &lt;b&gt;, grouped under
 *            the most recent Level 1 section (e.g., option flags under DESCRIPTION)
 * @return array [htmlWithAnchors, tocItems]
 *   Where tocItems is array of ['id'=>, 'label'=>, 'children'=>[['id'=>, 'label'=>],...]]
 */
function addManPageToc (string $html): array {
    $lines = explode("\n", $html);

    // Hierarchical TOC: [ ['id'=>, 'label'=>, 'children'=>[...]], ... ]
    $tocItems = array();
    $currentL1Idx = null;

    foreach ($lines as $i => $line) {
        // Level 1 anchor already placed by formatManPerlDoc
        if (preg_match('/<a id="(section-[^"]+)"><\/a>(.*)/', $line, $m)) {
            // Decode entities first (e.g. &lt;strong&gt; → <strong>), then strip system
            // formatting tags (<b>, <u>) but NOT <strong> (it's original content that
            // should render as bold in the TOC). Avoid strip_tags() which would treat
            // <sys/socket.h> as an HTML tag and remove it.
            $label = trim(strip_tags(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')));
            // Strip RDoc/ri heading prefixes: "= Heading" → "Heading"
            $label = preg_replace('/^=\s*/', '', $label);
            $tocItems[] = array('id' => $m[1], 'label' => $label, 'children' => array());
            $currentL1Idx = count($tocItems) - 1;
            continue;
        }

        // Level 2 anchor already placed by formatManPerlDoc
        if (preg_match('/<a id="(sub-[^"]+)"><\/a>(.*)/', $line, $m)) {
            if ($currentL1Idx !== null) {
                $label = trim(strip_tags(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')));
                // Strip RDoc/ri heading prefixes: "== Heading" → "Heading"
                $label = preg_replace('/^==\s*/', '', $label);
                $tocItems[$currentL1Idx]['children'][] = array('id' => $m[1], 'label' => $label);
            }
            continue;
        }
    }

    return array($html, $tocItems);
}

//get specified perl module's man page and convert to html format
function getPerldocPage (string $parameter, string $format = "html"): string {
    $lines = array();
    $width = intval($GLOBALS['PHP_MAN_WIDTH']);
    // pod2text -w controls output width at the POD formatter level (replaces MANWIDTH
    // pod2text -w controls output width at the POD formatter level (replaces MANWIDTH
    // Pipeline: perldoc -l locates source → head -1 picks first file → pod2text formats.
    // head -1 prevents multi-file concatenation when perldoc -l returns multiple paths.
    // Falls back to raw perldoc if pod2text pipeline fails (e.g. source not found).
    $cmd = "perldoc -l ".escapeshellarg($parameter)." 2>/dev/null | head -1 | tr '\\n' '\\0' | xargs -0 pod2text -w {$width} 2>/dev/null";  // #24: xargs -0 for space-safe paths
    exec($cmd, $lines, $return_code);
    if ($return_code === 0 && count($lines) > 0) {
        if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "perldoc");        if ($format === "json" || $format === "mcp") return formatForOutput(formatToJSON($lines, $parameter, "", "perldoc"), $format);
        return formatManPerlDoc($lines, "perldoc");
    }

    // Fallback: raw perldoc (for entries pod2text can't process, e.g. virtual docs)
    $lines = array();
    exec("perldoc ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "perldoc");        if ($format === "json" || $format === "mcp") return formatForOutput(formatToJSON($lines, $parameter, "", "perldoc"), $format);
        return formatManPerlDoc($lines, "perldoc");
    }

    // try build in function
    $lines = array();
    exec("perldoc -f ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "perldoc", "-f");        if ($format === "json" || $format === "mcp") return formatForOutput(formatToJSON($lines, $parameter, "-f", "perldoc"), $format);
        return formatManPerlDoc($lines, "perldoc");
    }

    // try perldoc search
    $lines = array();
    exec("perldoc -q ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "perldoc", "-q");        if ($format === "json" || $format === "mcp") return formatForOutput(formatToJSON($lines, $parameter, "-q", "perldoc"), $format);
        return formatManPerlDoc($lines, "perldoc");
    }

    return "";
}

//get specified module's pydoc3 page
function getPydocPage (string $parameter, string $format = "html"): string {
    $lines = array();
    exec("pydoc3 ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code !== 0 || count($lines) === 0) {
        return "";
    }
    if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "pydoc");
    if ($format === "json" || $format === "mcp") return formatForOutput(formatToJSON($lines, $parameter, "", "pydoc"), $format);
    return formatManPerlDoc($lines, "pydoc");
}

//get specified class/method's ri page
function getRiPage (string $parameter, string $format = "html"): string {
    $lines = array();
    exec("ri ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code !== 0 || count($lines) === 0) {
        return "";
    }
    if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "ri");
    if ($format === "json" || $format === "mcp") return formatForOutput(formatToJSON($lines, $parameter, "", "ri"), $format);
    return formatManPerlDoc($lines, "ri");
}

//get pydoc3 module index (pydoc3 modules)
function getPydocIndex (string $format = "html"): string {
    $lines = array();
    exec("pydoc3 modules", $lines, $return_code);
    if ($return_code !== 0 || empty($lines)) {
        return "";
    }
    // pydoc3 modules outputs multi-column format. Split each line on whitespace
    // to extract individual module names, skipping header/footer lines.
    $modules = array();
    $in_body = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === "") {
            $in_body = true;  // blank line separates header from module list
            continue;
        }
        if (!$in_body) continue;
        // Stop at the footer line
        if (preg_match('/^Enter any module name/i', $trimmed)) break;
        // Split on 2+ spaces (multi-column layout)
        $parts = preg_split('/\s{2,}/', $trimmed);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== "" && !preg_match('/^\s*$/', $part)) {
                $modules[] = $part;
            }
        }
    }
    if (empty($modules)) return "";
    sort($modules);

    $script_name = ($format === "markdown" || $format === "json" || $format === "mcp") ? baseUrl() : scriptName();
    if ($format === "markdown") {
        $output = "# Python 3 Module Index (pydoc)\n\n";
        foreach ($modules as $mod) {
            $output .= "- [{$mod}]({$script_name}/pydoc/".urlencode($mod)."/markdown)\n";
        }
        return $output;
    }
    if ($format === "json" || $format === "mcp") {
        $items = array();
        foreach ($modules as $mod) {
            $items[] = array(
                "name" => $mod,
                "link" => $script_name . "/pydoc/" . urlencode($mod) . "/json"
            );
        }
        $result = json_encode(array(
            "mode" => "pydoc",
            "parameter" => "modules",
            "count" => count($items),
            "entries" => $items
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($format === "mcp") {
            $result = json_encode(array(
                "content" => array(array("type" => "text", "text" => $result)),
                "structuredContent" => json_decode($result, true)
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return $result;
    }
    $output = "";
    $results = [];
    foreach ($modules as $mod) {
        $results[] = ['name' => $mod, 'section' => '', 'description' => '', 'sources' => ['pydoc']];
    }
    $rendered = renderGroupedResults($results, $script_name);
    if ($rendered['sidebar'] !== '') {
    }
    return $rendered['html'];
}

//get ri class index (ri -l)
function getRiIndex (string $format = "html"): string {
    $lines = array();
    exec("ri -l", $lines, $return_code);
    if ($return_code !== 0 || empty($lines)) {
        return "";
    }
    $script_name = ($format === "markdown" || $format === "json" || $format === "mcp") ? baseUrl() : scriptName();
    if ($format === "markdown") {
        $output = "# Ruby Class Index (ri)\n\n";
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== "") {
                $output .= "- [{$trimmed}]({$script_name}/ri/".urlencode($trimmed)."/markdown)\n";
            }
        }
        return $output;
    }
    if ($format === "json" || $format === "mcp") {
        $items = array();
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== "") {
                $items[] = array(
                    "name" => $trimmed,
                    "link" => $script_name . "/ri/" . urlencode($trimmed) . "/json"
                );
            }
        }
        $result = json_encode(array(
            "mode" => "ri",
            "parameter" => "index",
            "entries" => $items
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($format === "mcp") {
            $result = json_encode(array(
                "content" => array(array("type" => "text", "text" => $result)),
                "structuredContent" => json_decode($result, true)
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return $result;
    }
    $results = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== "") {
            $results[] = ['name' => $trimmed, 'section' => '', 'description' => '', 'sources' => ['ri']];
        }
    }
    $rendered = renderGroupedResults($results, $script_name);
    if ($rendered['sidebar'] !== '') {
    }
    return $rendered['html'];
}

//get pydoc3 keyword search results
function getPydocSearchPage (string $parameter, string $format = "html"): string {
    $lines = array();
    exec("pydoc3 -k ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code !== 0 || empty($lines)) {
        return "";
    }
    $script_name = ($format === "markdown" || $format === "json" || $format === "mcp") ? baseUrl() : scriptName();
    if ($format === "markdown") {
        $output = "# pydoc3 search: {$parameter}\n\n";
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(\S+)\s*-\s*(.+)/', $trimmed, $m)) {
                $output .= "- [{$m[1]}]({$script_name}/pydoc/".urlencode($m[1])."/markdown) — {$m[2]}\n";
            } elseif ($trimmed !== "") {
                $output .= "- [{$trimmed}]({$script_name}/pydoc/".urlencode($trimmed)."/markdown)\n";
            }
        }
        return $output;
    }
    if ($format === "json" || $format === "mcp") {
        $items = array();
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(\S+)\s*-\s*(.+)/', $trimmed, $m)) {
                $items[] = array(
                    "name" => $m[1],
                    "description" => $m[2],
                    "link" => $script_name . "/pydoc/" . urlencode($m[1]) . "/json"
                );
            } elseif ($trimmed !== "") {
                $items[] = array(
                    "name" => $trimmed,
                    "link" => $script_name . "/pydoc/" . urlencode($trimmed) . "/json"
                );
            }
        }
        $result = json_encode(array(
            "mode" => "pydoc",
            "parameter" => $parameter,
            "results" => $items
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($format === "mcp") {
            $result = json_encode(array(
                "content" => array(array("type" => "text", "text" => $result)),
                "structuredContent" => json_decode($result, true)
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return $result;
    }
    $output = "<ul>\n";
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^(\S+)\s*-\s*(.+)/', $trimmed, $m)) {
            $output .= '<li><a href="'.$script_name.'/pydoc/'.urlencode($m[1]).'">'.h($m[1]).'</a> — '.h($m[2]).'</li>'."\n";
        } elseif ($trimmed !== "") {
            $output .= '<li><a href="'.$script_name.'/pydoc/'.urlencode($trimmed).'">'.h($trimmed).'</a></li>'."\n";
        }
    }
    $output .= "</ul>\n";
    return $output;
}

//get ri search results (ri already does fuzzy matching, try as direct lookup)
function getRiSearchPage (string $parameter, string $format = "html"): string {
    $lines = array();
    exec("ri ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code !== 0 || count($lines) === 0) {
        return "";
    }
    $first_line = count($lines) > 0 ? trim($lines[0]) : "";
    if (preg_match('/^Nothing known about/i', $first_line) || preg_match('/^\.json not found/i', $first_line)) {
        return "";
    }
    if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "ri");
    if ($format === "json" || $format === "mcp") return formatForOutput(formatToJSON($lines, $parameter, "", "ri"), $format);
    return formatManPerlDoc($lines, "ri");
}

//get specified command's info page
function getInfoPage (string $parameter, string $format = "html"): string {
    $lines = array();
    $exitCode = 0;
    exec("info ".escapeshellarg($parameter), $lines, $exitCode);  // #45: check return code
    if ($exitCode !== 0 || empty($lines)) {
        return "";
    }
    if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "info");    if ($format === "json" || $format === "mcp") return formatForOutput(formatToJSON($lines, $parameter, "", "info"), $format);
    return formatManPerlDoc($lines, "info");
}

/**
 * search specified keyword by apropos and convert output link to man pages
 * Note: on linux, rebuild whatis database under root with:
 * /usr/sbin/makewhatis -w
 */
function getSearchPage (string $parameter, string $section = "", string $format = "html"): string {
    $script_name = ($format === "markdown" || $format === "json" || $format === "mcp") ? baseUrl() : scriptName();
    
    // Parse optional section prefix from search string (e.g. "1 GCC" => section=1, query=GCC)
    // Otherwise keep full query for multi-word searches (e.g. "recursive delete")
    if ($section === "" && preg_match("/^([0-9n])\s+(.+)$/", trim((string)$parameter), $m)) {
        $section = $m[1];
        $parameter = $m[2];
    } else {
        $parameter = trim((string)$parameter);
    }

    // Normalize Chinese / Unicode separator punctuation to spaces so that
    // e.g. "URL、curl" works as a two-word search for both FTS5 and apropos.
    // Only handles Chinese-specific punctuation; ASCII , and ; are already
    // stripped by the regex in buildFtsQuery() and cause no issues in apropos.
    $parameter = preg_replace('/[、，；]/u', ' ', $parameter);

    if ($parameter === "") {
        return "";
    }

    // Detect section listing pattern like "(1)", "(2)" — these are not real
    // search queries. Skip FTS5 (which would produce a very broad prefix match
    // like '"1"*' against 15K+ rows, hanging for 100+ seconds) and go directly
    // to apropos for a complete section listing.
    $sectionOnly = preg_match("/^\(([0-9n]+)\)$/", $parameter, $m);

    // --- KEYWORD SEARCH: try FTS5 first (faster multi-word, no fork overhead) ---
    // Section-only listings like "(1)" go directly to apropos (need ALL entries).
    $lines = [];
    $pydocFtsLines = [];
    $riFtsLines = [];

    if (!$sectionOnly) {
        $ftsQuery = buildFtsQuery($parameter);
        if ($ftsQuery !== '') {
            try {
                $db = cacheDb();
                // Use FTS5 if any source has indexed data (man/pydoc/ri)
                $totalIndexed = $db->querySingle("SELECT COUNT(*) FROM search_index_meta");
                if ($totalIndexed > 0) {
                    if ($section !== "" && preg_match("/^[0-9n]$/", $section)) {
                        $stmt = $db->prepare(
                            "SELECT s.name, s.section, s.description
                             FROM search_fts s
                             LEFT JOIN search_index_meta m ON m.section = s.section
                                 AND (s.name = m.name OR s.name LIKE m.name || ' %')
                             WHERE search_fts MATCH :q AND s.section = :sec
                             ORDER BY rank LIMIT 300"
                        );
                        $stmt->bindValue(':sec', $section, SQLITE3_TEXT);
                    } else {
                        $stmt = $db->prepare(
                            "SELECT s.name, s.section, s.description
                             FROM search_fts s
                             LEFT JOIN search_index_meta m ON m.section = s.section
                                 AND (s.name = m.name OR s.name LIKE m.name || ' %')
                             WHERE search_fts MATCH :q
                             ORDER BY rank LIMIT 300"
                        );
                    }
                    $stmt->bindValue(':q', $ftsQuery, SQLITE3_TEXT);
                    $result = $stmt->execute();
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                        // expandNameForFts stores "git-commit git commit" — take first token
                        $origName = trim(explode(' ', $row['name'])[0]);
                        $sectionNum = $row['section'];
                        $desc = $row['description'];
                        // Route to appropriate bucket by section
                        if ($sectionNum === 'pydoc') {
                            $pydocFtsLines[] = $origName . ' (pydoc) — ' . $desc;
                        } elseif ($sectionNum === 'ri') {
                            $riFtsLines[] = $origName . ' (ri) — ' . $desc;
                        } else {
                            // Man page entries: pseudo-apropos format
                            $lines[] = $origName . ' (' . $sectionNum . ') — ' . $desc;
                        }
                    }
                }
            } catch (Exception $e) {
                // FTS5 unavailable — fall through to apropos
            }
        }
    }

    // --- FALLBACK: system apropos (when FTS5 empty/unavailable, or section-only) ---
    if (empty($lines)) {
        if ($section !== "" && preg_match("/^[0-9n]$/", $section) && !$sectionOnly) {
            // Section + keyword: search within section
            $cmd = "apropos -s " . escapeshellarg($section) . " " . escapeshellarg($parameter);
        } elseif ($sectionOnly) {
            $cmd = "apropos -s " . escapeshellarg($m[1]) . " .";
        } else {
            $cmd = "apropos " . escapeshellarg($parameter);
        }
        exec($cmd, $lines);

        // Warm up FTS5 cache from apropos results (keyword searches only)
        if (!empty($lines) && !$sectionOnly) {
            indexAproposLines($lines);
        }
    }

    // json / mcp output
    if ($format === "json" || $format === "mcp") {
        $results = array();
        $pydoc_results = array();
        $ri_results = array();
        // Parse man page results from $lines
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^(.+)\s+\[\s*(.+?)\s*\]\s+\(((\d\w*|n)\w*)\)\s*$/', $line, $m)) {
                $name = trim($m[1]);
                $description = trim($m[2]);
                $section_num = trim($m[3]);
                $is_perl = preg_match('/:/', $name);
                $link_mode = $is_perl ? "perldoc" : "man";
                $results[] = array(
                    "name" => $name,
                    "description" => $description,
                    "section" => $section_num,
                    "link" => $script_name . "/" . $link_mode . "/" . urlencode($name) . "/" . urlencode($section_num) . "/json",
                );
            } elseif (preg_match('/^(.+)\s+\(((\d\w*|n)\w*)\)\s+—\s+(.+)$/', $line, $m)) {
                $name = trim($m[1]);
                $section_num = trim($m[2]);
                $description = trim($m[3]);
                $is_perl = preg_match('/:/', $name);
                $link_mode = $is_perl ? "perldoc" : "man";
                $results[] = array(
                    "name" => $name,
                    "description" => $description,
                    "section" => $section_num,
                    "link" => $script_name . "/" . $link_mode . "/" . urlencode($name) . "/" . urlencode($section_num) . "/json",
                );
            } elseif (preg_match('/^([\w\.\:\-\+]+)\s+\(((\d\w*|n)\w*)\)\s+—\s+(.+)$/', $line, $m)) {
                $is_perl = (preg_match('/:/', $m[1]));
                $link_mode = $is_perl ? "perldoc" : "man";
                $results[] = array(
                    "name" => trim($m[1]),
                    "description" => trim($m[3]),
                    "section" => trim($m[2]),
                    "link" => $script_name . "/" . $link_mode . "/" . urlencode(trim($m[1])) . "/" . urlencode(trim($m[2])) . "/json",
                );
            } elseif (preg_match('/^(.+)\s+\(((\d\w*|n)\w*)\)\s+-\s+(.+)$/', $line, $m)) {
                // Linux "apropos" output: command (section) - description (with hyphens, not em dashes)
                $name = trim($m[1]);
                $section_num = trim($m[2]);
                $description = trim($m[3]);
                $is_perl = preg_match('/:/', $name);
                $link_mode = $is_perl ? "perldoc" : "man";
                $results[] = array(
                    "name" => $name,
                    "description" => $description,
                    "section" => $section_num,
                    "link" => $script_name . "/" . $link_mode . "/" . urlencode($name) . "/" . urlencode($section_num) . "/json",
                );
            }
        }
        // Merge FTS5 pydoc/ri results (from getSearchPage's FTS5 query)
        foreach ($pydocFtsLines as $pl) {
            if (preg_match('/^(.+)\s+\(pydoc\)\s+—\s+(.+)$/', $pl, $m)) {
                $pydoc_results[] = array(
                    "name" => trim($m[1]),
                    "description" => trim($m[2]),
                    "link" => $script_name . "/pydoc/" . urlencode(trim($m[1])) . "/json",
                );
            }
        }
        foreach ($riFtsLines as $rl) {
            if (preg_match('/^(.+)\s+\(ri\)\s+—\s+(.+)$/', $rl, $m)) {
                $ri_results[] = array(
                    "name" => trim($m[1]),
                    "description" => trim($m[2]),
                    "link" => $script_name . "/ri/" . urlencode(trim($m[1])) . "/json",
                );
            }
        }
        $jsonData = array(
            "name" => "apropos " . urlencode($parameter) . ($section !== "" ? " (section {$section})" : ""),
            "mode" => "search",
            "parameter" => $parameter,
            "section" => $section,
            "url" => $script_name . "/search/" . urlencode($parameter) . ($section !== "" ? "/" . urlencode($section) : "") . "/json",
            "generated" => gmdate("Y-m-d\TH:i:s\Z"),
            "query" => $parameter,
            "results" => $results,
            "count" => count($results),
        );
        if (!empty($pydoc_results)) {
            $jsonData["pydoc_results"] = $pydoc_results;
        }
        if (!empty($ri_results)) {
            $jsonData["ri_results"] = $ri_results;
        }
        return formatForOutput(json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $format);
    }

    // determine link mode: perl modules (section 3pm or name with ::) use perldoc, others use man
    // HTML: parse apropos lines into structured array for renderGroupedResults()
    if ($format === "html") {
        $parsed = [];
        foreach ($lines as $line) {
            $parsed_line = parseAproposLine($line);
            if ($parsed_line === null) continue;
            $name = $parsed_line[0];
            $section_num = $parsed_line[1];
            $description = $parsed_line[2];
            $is_perl = str_contains($name, '::');
            $parsed[] = [
                'name'        => $name,
                'section'     => $section_num,
                'description' => $description,
                'sources'     => $is_perl ? ['perldoc'] : ['man'],
            ];
        }
        $rendered = renderGroupedResults($parsed, $script_name);
        if ($rendered['sidebar'] !== '') {
        }
        return $rendered['html'];
    }

    // Markdown: render apropos lines directly
    $output = "<ul>\n";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = $lines[$i];

        // detect perl module: section 3pm/3perl or name contains ::
        $is_perl = (preg_match("/\((3pm|3perl)\)/", $line) || preg_match("/\w+::\w+/", $line));
        $link_mode = $is_perl ? "perldoc" : "man";

        if ($format === "markdown") {
            $patterns = array(
                "/(.*\/)?([\w\-\.\+:]+)((\s+\[)([\w\-\.:]+)(\]\s+))\(((\d\w*|n)\w*)\)/",
                "/([\w+\.\-:]+)(\s+)?(\(((\d\w*|n)\w*)\))/"
            );
            if ($link_mode === "perldoc") {
                $replace = array(
                    '$1$2$4[$5($7)]('.$script_name.'/perldoc/$5/markdown)$6($7)',
                    '[$1($3)]('.$script_name.'/perldoc/$1/markdown)'
                );
            } else {
                $replace = array(
                    '$1$2$4[$5($7)]('.$script_name.'/man/$5/$7/markdown)$6($7)',
                    '[$1($3)]('.$script_name.'/man/$1/$3/markdown)'
                );
            }
            $output .= preg_replace($patterns, $replace, $line) . "\n";
        } else {
            $escaped = h($line);
            // Link patterns for apropos output
            $link_patterns = array(
                "/(.*\/)?([\w\-\.\+:]+)((\s+\[)([\w\-\.:]+)(\]\s+))\(((\d\w*|n)\w*)\)/",
                "/([\w+\.\-:]+)(\s+)?(\(((\d\w*|n)\w*)\))/"
            );
            if ($link_mode === "perldoc") {
                $link_replace = array(
                    '$1$2$4<a href="'.$script_name.'/perldoc/$5">$5</a>$6($7)',
                    '<a href="'.$script_name.'/perldoc/$1">$1</a>$2$3'
                );
            } else {
                $link_replace = array(
                    '$1$2$4<a href="'.$script_name.'/man/$5/$7">$5</a>$6($7)',
                    '<a href="'.$script_name.'/man/$1/$4">$1</a>$2$3'
                );
            }
            $output .= '<li>' . preg_replace($link_patterns, $link_replace, $escaped) . '</li>' . "\n";
        }
    }
    $output .= "</ul>\n";
    return $output;
}

//link to man page list by searching section tag
function getManIndex (string $format = "html"): string {
    $script_name = ($format === "markdown" || $format === "json" || $format === "mcp") ? baseUrl() : scriptName();
    if ($format === "markdown") {
        return "[1 - General Commands](".$script_name."/search/(1)/markdown) [intro(1)](".$script_name."/man/intro/1/markdown)\n" .
               "[2 - System Calls](".$script_name."/search/(2)/markdown) [intro(2)](".$script_name."/man/intro/2/markdown)\n" .
               "[3 - Subroutines](".$script_name."/search/(3)/markdown) [intro(3)](".$script_name."/man/intro/3/markdown)\n" .
               "[4 - Special Files](".$script_name."/search/(4)/markdown) [intro(4)](".$script_name."/man/intro/4/markdown)\n" .
               "[5 - File Formats](".$script_name."/search/(5)/markdown) [intro(5)](".$script_name."/man/intro/5/markdown)\n" .
               "[6 - Games](".$script_name."/search/(6)/markdown) [intro(6)](".$script_name."/man/intro/6/markdown)\n" .
               "[7 - Macros and Conventions](".$script_name."/search/(7)/markdown) [intro(7)](".$script_name."/man/intro/7/markdown)\n" .
               "[8 - Maintenance Commands](".$script_name."/search/(8)/markdown) [intro(8)](".$script_name."/man/intro/8/markdown)\n" .
               "[9 - Kernel Interface](".$script_name."/search/(9)/markdown) [intro(9)](".$script_name."/man/intro/9/markdown)\n" .
               "[n - New Commands](".$script_name."/search/(n)/markdown)\n";
    }

    // json / mcp output
    if ($format === "json" || $format === "mcp") {
        $sections = array(
            array("name" => "1 - General Commands", "section" => "1"),
            array("name" => "2 - System Calls", "section" => "2"),
            array("name" => "3 - Subroutines", "section" => "3"),
            array("name" => "4 - Special Files", "section" => "4"),
            array("name" => "5 - File Formats", "section" => "5"),
            array("name" => "6 - Games", "section" => "6"),
            array("name" => "7 - Macros and Conventions", "section" => "7"),
            array("name" => "8 - Maintenance Commands", "section" => "8"),
            array("name" => "9 - Kernel Interface", "section" => "9"),
            array("name" => "n - New Commands", "section" => "n"),
        );
        $sectionItems = array();
        foreach ($sections as $s) {
            $sectionItems[] = array(
                "name" => $s["name"],
                "link" => $script_name . "/search/(" . urlencode($s["section"]) . ")/json",
                "intro_link" => $script_name . "/man/intro/" . urlencode($s["section"]) . "/json",
            );
        }
        $jsonData = array(
            "name" => "man pages index",
            "mode" => "index",
            "index_type" => "man",
            "url" => $script_name . "/man/json",
            "generated" => gmdate("Y-m-d\TH:i:s\Z"),
            "sections" => $sectionItems,
            "count" => count($sectionItems),
        );
        return formatForOutput(json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $format);
    }

    $script_name_html = h($script_name);
    $output = "<a href=\"".$script_name_html."/search/(1)\">1 - General Commands</a> ".
               "<a href=\"".$script_name_html."/man/intro/1\">intro(1)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(2)\">2 - System Calls</a> ".
               "<a href=\"".$script_name_html."/man/intro/2\">intro(2)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(3)\">3 - Subroutines</a> ".
               "<a href=\"".$script_name_html."/man/intro/3\">intro(3)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(4)\">4 - Special Files</a> ".
               "<a href=\"".$script_name_html."/man/intro/4\">intro(4)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(5)\">5 - File Formats</a> ".
               "<a href=\"".$script_name_html."/man/intro/5\">intro(5)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(6)\">6 - Games</a> ".
               "<a href=\"".$script_name_html."/man/intro/6\">intro(6)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(7)\">7 - Macros and Conventions</a> ".
               "<a href=\"".$script_name_html."/man/intro/7\">intro(7)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(8)\">8 - Maintenance Commands</a> ".
               "<a href=\"".$script_name_html."/man/intro/8\">intro(8)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(9)\">9 - Kernel Interface</a> ".
               "<a href=\"".$script_name_html."/man/intro/9\">intro(9)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(n)\">n - New Commands</a>\n";

    return $output;
}

//get perldoc list by searching perl related keywords
function getPerldocIndex (string $format = "html"): string {
    return getSearchPage("perl", "", $format);
}

//get info page index page
function getInfoIndex (string $format = "html"): string {
    $lines = array();
    $exitCode = 0;
    exec("info", $lines, $exitCode);  // #45: check return code
    if ($exitCode !== 0 || empty($lines)) {
        return "";
    }
    $script_name = ($format === "markdown" || $format === "json" || $format === "mcp") ? baseUrl() : scriptName();

    if ($format === "markdown") {
        $patterns = array(
            "/\(([a-z0-9_\-]+)\)([a-z0-9_\+]+)/",
            "/\(([a-z0-9_\-]+)\)/"
        );
        $replace = array(
            '([$1]('.$script_name.'/info/$1/markdown))[$2]('.$script_name.'/info/$2/markdown)',
            '([$1]('.$script_name.'/info/$1/markdown))'
        );
        $output = "";
        $count = count($lines);
        for ( $i = 0; $i < $count; $i ++ ) {
            $output .= preg_replace($patterns, $replace, $lines[$i]) . "\n";
        }
        return $output;
    }

    // json / mcp output
    if ($format === "json" || $format === "mcp") {
        $items = array();
        $seen = array();
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);
            // Parse "(group)command" or "(command)" format
            if (preg_match('/\(([a-z0-9_\-]+)\)([a-z0-9_\+]+)/', $line, $m)) {
                $name = $m[2];
                if (!isset($seen[$name])) {
                    $seen[$name] = true;
                    $items[] = array(
                        "name" => $name,
                        "group" => $m[1],
                        "link" => $script_name . "/info/" . urlencode($name) . "/json",
                    );
                }
            } elseif (preg_match('/\(([a-z0-9_\-]+)\)/', $line, $m)) {
                $name = $m[1];
                if (!isset($seen[$name])) {
                    $seen[$name] = true;
                    $items[] = array(
                        "name" => $name,
                        "link" => $script_name . "/info/" . urlencode($name) . "/json",
                    );
                }
            }
        }
        $jsonData = array(
            "name" => "info pages index",
            "mode" => "index",
            "index_type" => "info",
            "url" => $script_name . "/info/json",
            "generated" => gmdate("Y-m-d\TH:i:s\Z"),
            "items" => $items,
            "count" => count($items),
        );
        return formatForOutput(json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $format);
    }

    $patterns = array(
                    "/&/",  //html special char: '&' => '&gt;';
                    "/</",  //html special char: '>' => '&lt;';
                    "/>/",  //html special char: '<' => '&gt;';
                    "/\(([a-z0-9_\-]+)\)([a-z0-9_\+]+)/", //'(group)command' => info page of command;
                    "/\(([a-z0-9_\-]+)\)/"     //'(command)' => info page of command;
                );
    $replace = array(
                   "&amp;",
                   "&lt;",
                   "&gt;",
                   '(<a href="'.$script_name.'/info/$1">$1</a>)'.
                   '<a href="'.$script_name.'/info/$2">$2</a>',
                   '(<a href="'.$script_name.'/info/$1">$1</a>)'
                );
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $output .= preg_replace($patterns, $replace, $lines[$i]);
        $output .= " \n";
    }
    return $output;
}

//convert man perldoc output to html
function formatManPerlDoc (array $lines, string $mode = "man"): string {
    $script_name = h(scriptName());
    $mode = h($mode);
    // Use global constant RE_ASCII_SAFE for overstrike pattern matching
    $ac = RE_ASCII_SAFE;
    $patterns = array(
                    "/&/",  //html special char: '&' => chr(5) => '&gt;';
                    "/</",  //html special char: '>' => chr(6) => '&lt;';
                    "/>/",  //html special char: '<' => chr(7) => '&gt;';
                    //man page special chars
                    // CRITICAL: Use $ac instead of . to avoid splitting multibyte UTF-8
                    "/{$ac}".chr(8)."{$ac}".chr(8)."({$ac})".chr(8)."{$ac}/",  // ?^H?^H?^H? => <b>?</b>
                    "/_".chr(8)."({$ac})".chr(8)."{$ac}/",  //_^H?^H? => <u>?</u>
                    "/_".chr(8)."({$ac})/",  //_^H? => <u>?</u>
                    "/_".chr(8)."/",  // Cleanup: strip orphan _^H (e.g. before non-ASCII UTF-8)
                    "/{$ac}".chr(8)."({$ac})/",  //?^H? => <b>?</b>
                    //reverse html special chars
                    "/".chr(5)."/",  //reverse '&'
                    "/".chr(6)."/",  //reverse '<'
                    //removed duplicated html tag
                    "/<\/u><u>/",           // '</u><u>' => ''
                    "/<u>_<\/u>/",          // '<u>_</u>' => '_'
                    "/<\/b><b>/",       // '<\/b><b>' => ''
                    //perldoc specific: plain text headings (no overstrike sequences in perldoc output)
                    "/^([A-Z][A-Z0-9][A-Z0-9\/\s]{1,50})\s*$/",
                    "/^ {2}([A-Z][a-z][\w\s:\x27;,-]+)\s*$/",
                );

    $replace = array(
                   chr(5),
                   chr(6),
                   chr(7),
                   '<b>$1</b>',
                    '<u>$1</u>',
                    '<u>$1</u>',
                    '',  // strip orphan _^H
                    '<b>$1</b>',
                   "&amp;",
                   "&lt;",
                   "",
                   "_",
                   "",
                   '<b>$1</b>',
                   '  <u>$1</u>',
               );

    // Mode-specific link patterns
    if ($mode === "pydoc") {
        // pydoc: class ParentClass links
        $patterns[] = "/class (\w+)\((\w+(?:\.\w+)*)\)/";
        $replace[] = 'class $1(<a href="'.$script_name.'/pydoc/$2">$2</a>)';
    } elseif ($mode === "ri") {
        // ri: Class or Class#method references
        $patterns[] = "/class (\w+)\((\w+(?:\.\w+)*)\)/";
        $replace[] = 'class $1(<a href="'.$script_name.'/ri/$2">$2</a>)';
        // Ruby constant/module refs with :: notation
        $patterns[] = "/((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/";
        $replace[] = '$3<a href="'.$script_name.'/ri/$4">$4</a>$6';
    } else {
        // man and perldoc: standard command(section) and module linking
        $patterns[] = "/((<.>)|([\s,]))([\w\-\.\+]+)(<\/.>)?\((<.>)?(\d\w*|n)(<\/.>)?\)(,)?(<\/.>)?/";
        $replace[] = '$3$4($7)$9';
        $patterns[] = "/([\s,])([\w\-\.\+]+)\((\d\w*|n)\)/";
        $replace[] = '$1<a href="'.$script_name.'/'.$mode.'/$2/$3">$2($3)</a>';
        //translate link to related perl modules, but $obj->Module::Name-> will not be translate
        //'<u>Module::Name</u>' => ' Module::Name'
        $patterns[] = "/((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/";
        $replace[] = '$3<a href="'.$script_name.'/'.$mode.'/$4">$4</a>$6';
    }

    // SGR escape sequences (common to all modes)
    $patterns[] = "/".chr(27)."\[1m(.*?)".chr(27)."\[(?:0|22)m/";
    $replace[] = '<b>$1</b>';
    $patterns[] = "/".chr(27)."\[4m(.*?)".chr(27)."\[(?:0|24)m/";
    $replace[] = '<u>$1</u>';

    // Common patterns: email, URL, closing >
    $patterns[] = "/(([\w\-\.]+)@([\w\-]+)(\.[\w\-]+)+)/";  //link to email
    $replace[] = '<a href="mailto:$2 AT $3$4">$2<u> AT </u>$3$4</a>';
    $patterns[] = "/([\w]+:\/\/[\w%\-\?&;#~=\.\/\@]+[\w\/])/i"; //link to url
    $replace[] = '<a href="$1" rel="noopener noreferrer">$1</a>';
    $patterns[] = "/".chr(7)."/";  //reverse '>'
    $replace[] = "&gt;";
    $seenIds = [];
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = preg_replace($patterns, $replace, $lines[$i]);
        $nextLine = ($i + 1 < $count) ? $lines[$i + 1] : null;
        $heading = detectHeadingType($line, $mode, $nextLine);
        if ($heading) {
            $id = ($heading['level'] === 1 ? 'section-' : 'sub-')
                . strtolower(preg_replace('/[^A-Z0-9]+/i', '-', $heading['text']));
            $id = trim($id, '-');
            // Ensure unique id for XHTML validation
            if (isset($seenIds[$id])) {
                $seenIds[$id]++;
                $id = $id . '-' . $seenIds[$id];
            } else {
                $seenIds[$id] = 0;
            }
            $line = '<a id="' . h($id) . '"></a>' . $line;
            // Skip the underline line in info mode (Setext-style heading)
            if (!empty($heading['skipNext'])) {
                $output .= $line . "\n";
                $i++;  // skip the underline
                continue;
            }
        }
        $output .= $line . "\n";
    }
    // Safety net: strip any remaining invalid UTF-8 bytes
    // mb_convert_encoding with substitute removes lone surrogates and bad sequences
    $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
    return $output;
}

// Wraps JSON string in MCP content format if needed.
// $format === "mcp" → {"content":[{"type":"text","text":"<json>"}]}
// $format === "json" → raw JSON as-is
function formatForOutput (string $jsonStr, string $format): string {
    if ($format === "mcp") {
        $data = json_decode($jsonStr, true);
        if ($data === null) {
            // Fallback: wrap raw string in text content
            $result = ["content" => [["type" => "text", "text" => $jsonStr]]];
            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $markdown = formatMcpMarkdown($data);
        $structured = formatMcpStructured($data);
        $result = [
            "content" => [["type" => "text", "text" => $markdown]],
            "structuredContent" => $structured
        ];
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    return $jsonStr;
}

/**
 * Convert man/perldoc JSON data to agent-friendly markdown for MCP output.
 * Returns a scannable section outline + flags table + full content.
 */
function formatMcpMarkdown (array $data): string {
    $mode = $data["mode"] ?? "man";
    $param = $data["parameter"] ?? "";
    $section = $data["section"] ?? "";
    $label = $param;
    if ($section !== "" && $section !== "-f" && $section !== "-q") {
        $label .= "({$section})";
    }

    $out = "# {$label} ({$mode})\n\n";

    // NAME / Summary
    if (!empty($data["summary"])) {
        $out .= "## NAME\n\n{$data["summary"]}\n\n";
    }

    // SYNOPSIS
    if (!empty($data["synopsis"])) {
        $out .= "## SYNOPSIS\n\n{$data["synopsis"]}\n\n";
    }

    // DESCRIPTION — first paragraph only (#88)
    $sections = $data["sections"] ?? [];
    foreach ($sections as $name => $sec) {
        if (strtoupper($name) === "DESCRIPTION") {
            $content = trim($sec["content"] ?? "");
            // Extract first paragraph (up to first blank line)
            $paragraphs = preg_split('/\n\s*\n/', $content, 2);
            $firstPara = trim($paragraphs[0] ?? "");
            if ($firstPara !== "") {
                $out .= "## DESCRIPTION\n\n{$firstPara}\n\n";
            }
            break;
        }
    }

    // TLDR (only for man section 1)
    $tldr = fetchOfficialTldr($param, $mode, $section);
    if (!empty($tldr)) {
        $out .= "## TLDR\n\n";
        if (!empty($tldr["description"])) {
            $out .= "> {$tldr["description"]}\n\n";
        }
        foreach (array_slice($tldr["examples"] ?? [], 0, 8) as $ex) {
            $out .= "- {$ex["description"]}:\n  `{$ex["command"]}`\n";
        }
        $src = ($tldr["source"] ?? "") === "cheatsh" ? "cheat.sh" : "tldr-pages";
        $out .= "\n*Source: {$src}*\n\n";
    }

    // Section outline — navigation guide for agent
    if (count($sections) > 0) {
        $out .= "## Sections\n\n";
        foreach ($sections as $name => $sec) {
            $subCount = count($sec["subsections"] ?? []);
            $extra = $subCount > 0 ? " ({$subCount} subsections)" : "";
            $out .= "- **{$name}**{$extra}\n";
        }
        $out .= "\nUse structuredContent.sections for detailed options, examples, and full documentation.\n";
    }

    return $out;
}

/**
 * Extract structured data for MCP structuredContent field.
 * Gives agents programmatic access to flags, examples, section outlines.
 */
function formatMcpStructured (array $data): array {
    $outline = [];
    foreach ($data["sections"] ?? [] as $name => $sec) {
        $item = [
            "name" => $name,
            "lines" => substr_count($sec["content"] ?? "", "\n") + 1,
        ];
        $item["subsections"] = [];
        foreach ($sec["subsections"] ?? [] as $sub) {
            $subItem = [
                "name" => $sub["name"] ?? "",
                "lines" => substr_count($sub["content"] ?? "", "\n") + 1,
            ];
            if (!empty($sub["flag"])) $subItem["flag"] = $sub["flag"];
            if (!empty($sub["long"])) $subItem["long"] = $sub["long"];
            if (!empty($sub["arg"])) $subItem["arg"] = $sub["arg"];
            $item["subsections"][] = $subItem;
        }
        $outline[] = $item;
    }

    // Collect all flags from all sections (not just OPTIONS)
    $allFlags = $data["flags"] ?? [];
    if (empty($allFlags)) {
        // #44: use shared extractFlagsFromSections()
        $allFlags = extractFlagsFromSections($data);
    }

    // v2.2: Fetch TLDR for agent consumption (only for man section 1)
    $param = $data["parameter"] ?? "";
    $tldrMode = $data["mode"] ?? "man";
    $tldrSection = $data["section"] ?? "";
    $tldrData = $param !== "" ? fetchOfficialTldr($param, $tldrMode, $tldrSection) : [];
    $tldrSummary = !empty($tldrData) ? ($tldrData["description"] ?? null) : null;
    $tldrExamples = !empty($tldrData) ? array_slice($tldrData["examples"] ?? [], 0, 12) : [];
    $tldrSource = !empty($tldrData) ? ($tldrData["source"] ?? null) : null;

    return [
        "command" => $data["parameter"] ?? "",
        "section" => $data["section"] ?? "",
        "mode" => $data["mode"] ?? "man",
        "summary" => $data["summary"] ?? null,
        "synopsis" => $data["synopsis"] ?? null,
        "tldr_summary" => $tldrSummary,
        "tldr_examples" => $tldrExamples,
        "tldr_source" => $tldrSource,
        "flags" => $allFlags,
        "examples" => $data["examples"] ?? [],
        "see_also" => $data["see_also"] ?? [],
        "section_outline" => $outline,
        "sections" => $data["sections"] ?? [],  // full section content for agent consumption
    ];
}

/**
 * Convert man page structured JSON to TLDR-style cheatsheet markdown.
 *
 * Auto-generates from man pages: extracts description, key examples,
 * and common flags. Follows tldr-pages format conventions:
 * - Title matches command name
 * - Description in > blockquote
 * - Examples as bullet list with code blocks
 * - Uses {{placeholder}} for user-supplied values
 * - 5-8 examples max
 * - --help and --version at the end
 */
// ────────────────────────────────────────────
//  v2.2: Official tldr-pages + cheat.sh fetcher
// ────────────────────────────────────────────

/**
 * Fetch TLDR from official tldr-pages (primary) or cheat.sh (fallback).
 * Returns structured TLDR data or empty array on failure.
 */
function fetchOfficialTldr(string $command, string $mode = "man", string $section = ""): array {
    // Lowercase for cache key and tldr-pages / cheat.sh lookup
    $command = strtolower($command);
    $cacheKey = $command;

    // Static cache first — avoids repeated validation checks (#87)
    static $cache = [];
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    // Only fetch TLDR for man section 1 commands — tldr-pages only covers
    // common CLI tools, not Perl modules (perldoc), info nodes, or man pages
    // in sections 2-8 (syscalls, library functions, file formats, etc.)
    if ($mode !== "man") return [];
    if ($section !== "" && !preg_match('/^1[a-z]*$/', $section)) return [];
    // Skip commands with :: (Perl/Ruby module names) — tldr-pages never covers these
    if (strpos($command, '::') !== false) return [];
    // Skip commands with non-simple names (dots, special chars beyond [-_.])
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $command)) return [];

    $result = fetchTldrPages($command);
    if (empty($result)) $result = fetchCheatShTldr($command);
    $cache[$cacheKey] = $result;
    return $result;
}

/**
 * Fetch from tldr-pages GitHub raw.
 * Lookup order: common/ → linux/ → osx/
 */
function fetchTldrPages(string $command): array {
    $pages = ["common", "linux", "osx"];
    foreach ($pages as $page) {
        $url = "https://raw.githubusercontent.com/tldr-pages/tldr/main/pages/{$page}/" . urlencode($command) . ".md";
        $ctx = stream_context_create([
            "http" => [
                "timeout" => 5,
                "header" => "User-Agent: phpMan/2.2\r\n",
            ],
        ]);
        $md = file_get_contents($url, false, $ctx);
        if ($md === false) {
            // 404 is normal — most man pages don't have tldr-pages entries.
            // No need to log; the static cache above prevents repeated lookups.
            continue;
        }
        if (strlen($md) > 20) {
            return parseTldrMarkdown($md, $command, "official");
        }
    }
    return [];
}

/**
 * Fetch from cheat.sh as fallback.
 */
function fetchCheatShTldr(string $command): array {
    $url = "https://cheat.sh/" . urlencode($command) . "?T";
    $ctx = stream_context_create([
        "http" => [
            "timeout" => 5,
            "header" => "User-Agent: phpMan/2.2\r\n",
        ],
    ]);
    $raw = file_get_contents($url, false, $ctx);
    if ($raw === false) {
        // cheat.sh miss is normal — no need to log.
        return [];
    }
    if (strlen($raw) < 20) return [];
    return parseCheatShOutput($raw, $command);
}

/**
 * Parse tldr-pages markdown format to structured array.
 */
function parseTldrMarkdown(string $md, string $command, string $source): array {
    $lines = explode("\n", $md);
    $description = "";
    $examples = [];
    $currentDesc = "";
    $inDescription = true;
    $collectingExample = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip title line (# command) and empty lines
        if ($trimmed === "" || $trimmed === "# " . $command || preg_match('/^# /', $trimmed)) continue;

        // Description: lines starting with >
        if (preg_match('/^>\s*(.*)/', $trimmed, $m)) {
            $text = trim($m[1]);
            if (stripos($text, "More information") === 0) continue;
            if ($text === "") continue;
            if ($description === "") {
                $description = $text;
            }
            $inDescription = true;
            continue;
        }

        // Example: "- Description:" followed by `command`
        if (preg_match('/^-\s*(.+):\s*$/', $trimmed, $m)) {
            $currentDesc = trim($m[1]);
            $collectingExample = true;
            $inDescription = false;
            continue;
        }

        // Backtick-wrapped command
        if ($collectingExample && preg_match('/^`(.+)`$/', $trimmed, $m)) {
            $cmd = trim($m[1]);
            // Clean up tldr-pages syntax: {{...}} → keep, [-X|--long] → --long
            $cmd = preg_replace('/\{\{[-\[\]\|]/', '{{', $cmd);
            $cmd = preg_replace('/[-\[\]\|]\}\}/', '}}', $cmd);
            $examples[] = [
                "description" => $currentDesc,
                "command" => $cmd,
            ];
            $collectingExample = false;
            $currentDesc = "";
            continue;
        }

        // Bare command line (non-backtick example)
        if ($collectingExample && strlen($trimmed) > 1 && $trimmed[0] !== "#" && $trimmed[0] !== ">") {
            $cmd = preg_replace('/^`|`$/', '', $trimmed);
            $examples[] = [
                "description" => $currentDesc,
                "command" => $cmd,
            ];
            $collectingExample = false;
            $currentDesc = "";
            continue;
        }
    }

    if (empty($examples)) return [];

    return [
        "source" => $source,
        "description" => $description,
        "examples" => array_slice($examples, 0, 16),
    ];
}

/**
 * Parse cheat.sh plain-text output (?T flag).
 */
function parseCheatShOutput(string $raw, string $command): array {
    $lines = explode("\n", $raw);
    $description = "";
    $examples = [];
    $currentDesc = "";

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip source header, blank lines
        if ($trimmed === "" || preg_match('/^#\[.+\]/', $trimmed)) continue;

        // Description line: # text (but not just #)
        if (preg_match('/^#\s+(.+)\.?\s*$/', $trimmed, $m)) {
            $text = trim($m[1]);
            if ($text === "" || stripos($text, "see also") === 0) continue;
            // First non-empty # line is the description
            if ($description === "" && !preg_match('/^[a-z]/i', $text)) {
                $description = rtrim($text, ".");
                continue;
            }
            // Subsequent # lines are example descriptions
            $currentDesc = rtrim($text, ".");
            continue;
        }

        // Command line
        if ($currentDesc !== "" && strlen($trimmed) > 2) {
            // Replace concrete args with placeholders
            $cmd = preg_replace('/ (\/[\w\/.-]+)/', ' {{path}}', $trimmed);
            $cmd = preg_replace('/ ([\w.-]+\.(txt|gz|tgz|tar|zip|json|xml|pem))/i', ' {{file}}', $cmd);
            $examples[] = [
                "description" => $currentDesc,
                "command" => $cmd,
            ];
            $currentDesc = "";
        }
    }

    if (empty($examples)) return [];

    return [
        "source" => "cheatsh",
        "description" => $description,
        "examples" => array_slice($examples, 0, 16),
    ];
}


/**
 * Format structured TLDR data (from official tldr-pages or cheat.sh) to markdown.
 * v2.2: Used when official data sources are available.
 */
function formatTldrFromStructured(array $tldr, string $command): string {
    $base = baseUrl();
    $canonical = "{$base}/man/" . urlencode($command);
    $out = "# {$command}\n\n";
    if (!empty($tldr["description"])) {
        $out .= "> {$tldr["description"]}.\n";
    }
    $source = $tldr["source"] ?? "";
    $sourceLabel = $source === "cheatsh" ? "cheat.sh" : "tldr-pages";
    $out .= "> More information: {$canonical}  \n";
    $out .= "> Source: {$sourceLabel}\n\n";
    foreach ($tldr["examples"] as $ex) {
        $desc = $ex["description"] ?? "";
        $cmd = $ex["command"] ?? "";
        if ($desc !== "" && $cmd !== "") {
            $out .= "- {$desc}:\n  `{$cmd}`\n";
        }
    }
    return $out;
}

function formatTldr (?array $data): string {
    if ($data === null) return "";

    $command = $data["parameter"] ?? "";
    $summary = $data["summary"] ?? "";
    $synopsis = $data["synopsis"] ?? "";
    $flags = $data["flags"] ?? [];
    $examples = $data["examples"] ?? [];

    // Fallback: extract flags from all sections if top-level is empty
    // #44: use shared extractFlagsFromSections()
    if (empty($flags)) {
        $flags = extractFlagsFromSections($data);
    }

    $mode = $data["mode"] ?? "man";
    $section = $data["section"] ?? "";
    $base = baseUrl();
    $canonical = "{$base}/{$mode}/" . urlencode($command);
    if ($section !== "" && $section !== "-f" && $section !== "-q") {
        $canonical .= "/" . urlencode($section);
    }

    // Title
    $out = "# {$command}\n\n";

    // Description from NAME section
    if ($summary !== "") {
        $out .= "> {$summary}.\n";
    } elseif ($synopsis !== "") {
        $out .= "> {$synopsis}\n";
    }
    $out .= "> More information: {$canonical}.\n\n";

    $exampleCount = 0;
    $maxExamples = TLDR_MAX_EXAMPLES;

    // If man page has explicit EXAMPLES section, use those first
    if (!empty($examples)) {
        foreach ($examples as $ex) {
            if ($exampleCount >= $maxExamples - 2) break;
            $ex = trim($ex);
            if ($ex === "" || strlen($ex) < 3) continue;
            // Skip lines that are section headers or descriptions
            if (preg_match('/^[A-Z][A-Z\s]{5,}$/', $ex)) continue;
            // Wrap in backticks if it looks like a command
            $cleaned = preg_replace('/\s+/', ' ', $ex);
            $out .= "- Example:\n  `{$cleaned}`\n";
            $exampleCount++;
        }
    }

    // Generate examples from flag descriptions

    $usedFlags = []; // track which flags we've already generated examples for
    foreach ($flags as $f) {
        if ($exampleCount >= $maxExamples - 2) break;
        $shortFlag = $f["flag"] ?? "";
        $longFlag = $f["long"] ?? "";
        $desc = $f["description"] ?? "";

        // Skip flags we've already shown
        $flagKey = $shortFlag ?: $longFlag;
        if ($flagKey === "" || isset($usedFlags[$flagKey])) continue;
        $usedFlags[$flagKey] = true;

        // Skip help/version flags (we handle them at the end)
        if ($shortFlag === "-h" && !$longFlag) continue;
        if ($longFlag === "--help") continue;
        if ($shortFlag === "-V" && !$longFlag) continue;
        if ($longFlag === "--version") continue;

        // Build the TLDR example
        $flagStr = $longFlag ?: $shortFlag;
        $argStr = "";
        if (!empty($f["arg"])) {
            $argStr = " " . str_replace(["<", ">", "[", "]"], ["{{", "}}", "{{", "}}"], $f["arg"]);
        }

        // Determine description quality — use actual man page descriptions only
        $shortDesc = "";
        if ($desc !== "") {
            // Filter out low-quality descriptions (sentence fragments, too long)
            if (strlen($desc) > 80) continue; // skip multi-line prose
            if (preg_match('/^[a-z].*\.\.\.$/', $desc)) continue; // skip fragments ending with ...
            if (preg_match('/^[);,.]/', $desc)) continue; // skip continuation fragments
            $shortDesc = $desc;
        } else {
            continue; // no description at all
        }

        $out .= "- {$shortDesc}:\n  `{$command} {$flagStr}{$argStr}`\n";
        $exampleCount++;
    }

    // Always add help and version as last two
    $hasHelpFlag = false;
    $hasVersionFlag = false;
    foreach ($flags as $f) {
        if (($f["flag"] ?? "") === "-h" || ($f["long"] ?? "") === "--help") $hasHelpFlag = true;
        if (($f["flag"] ?? "") === "-V" || ($f["long"] ?? "") === "--version") $hasVersionFlag = true;
    }

    if ($exampleCount < $maxExamples) {
        $helpFlag = $hasHelpFlag ? "{{[-h|--help]}}" : "--help";
        $out .= "- Display help:\n  `{$command} {$helpFlag}`\n";
        $exampleCount++;
    }

    if ($exampleCount < $maxExamples) {
        $versionFlag = $hasVersionFlag ? "{{[-V|--version]}}" : "--version";
        $out .= "- Display version:\n  `{$command} {$versionFlag}`\n";
    }

    return $out;
}
function formatToJSON (array $lines, string $parameter, string $section = "", string $mode = "man"): string {
    // #44: use shared cleanTerminalOutput() instead of inline patterns
    $lines = cleanTerminalOutput($lines);

    $section_label = "";
    if ($section !== "" && $section !== "-f" && $section !== "-q") {
        $section_label = "({$section})";
    } elseif ($section !== "") {
        $section_label = " (-{$section})";
    }

    $script_name = baseUrl();
    $canonical_url = $script_name . "/" . $mode . "/" . urlencode($parameter);
    if ($section !== "" && $section !== "-f" && $section !== "-q") {
        $canonical_url .= "/" . urlencode($section);
    }
    $canonical_url .= "/json";

    // Detect sections and subsections — uses shared detectHeadingType()
    // to stay consistent with HTML and Markdown output
    $sections = array();
    $currentSection = null;   // reference to current section or subsection (for content accumulation)
    $currentL1 = null;        // reference to current L1 section (for adding L2 subsections)

    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        $rawLine = $lines[$i];
        $plainLine = trim(str_replace(array("**", "_"), "", $rawLine));

        if ($plainLine === "") {
            if ($currentSection !== null) {
                $currentSection["content"][] = "";
            }
            continue;
        }

        // Use shared heading detection (same as HTML/Markdown paths)
        $nextRawLine = ($i + 1 < $count) ? $lines[$i + 1] : null;
        $heading = detectHeadingType($rawLine, $mode, $nextRawLine);

        if ($heading !== null) {
            if ($heading['level'] === 1) {
                // Break previous references before creating new section
                unset($currentSection);
                $currentSection = array(
                    "name" => $heading['text'],
                    "level" => 1,
                    "content" => array(),
                    "subsections" => array(),
                );
                $sections[] = &$currentSection;
                // L1 is also the current L1 for subsection attachment
                unset($currentL1);
                $currentL1 = &$currentSection;
            } elseif ($heading['level'] === 2 && $currentL1 !== null) {
                // L2 subsections always attach to the current L1 section
                unset($subsection);
                $subsection = array(
                    "name" => $heading['text'],
                    "level" => 2,
                    "content" => array(),
                );
                $currentL1["subsections"][] = &$subsection;
                // Switch content accumulation to the new subsection
                unset($currentSection);
                $currentSection = &$subsection;
            }
            // Skip the underline line in info mode (Setext-style heading)
            if (!empty($heading['skipNext'])) {
                $i++;
            }
            continue;
        }

        // Regular content line
        if ($currentSection !== null) {
            $clean = trim($plainLine);
            if ($clean !== "") {
                $currentSection["content"][] = $clean;
            }
        }
    }

    // Build JSON structure
    $jsonData = array(
        "mode" => $mode,
        "parameter" => $parameter,
        "section" => $section,
        "url" => $canonical_url,
        "generated" => gmdate("Y-m-d\TH:i:s\Z"),
    );

    // Extract SYNOPSIS section as top-level field
    foreach ($sections as $sec) {
        if ($sec["name"] === "SYNOPSIS") {
            $synopsisText = implode("\n", array_filter($sec["content"], function($line) {
                return trim($line) !== "";
            }));
            $jsonData["synopsis"] = trim($synopsisText);
            break;
        }
    }

    // Add structured sections
    $jsonSections = array();
    foreach ($sections as $sec) {
        $cleanSec = array();
        $textParts = array();
        foreach ($sec["content"] as $cl) {
            if ($cl !== "" || !empty($textParts)) {
                $textParts[] = $cl;
            }
        }
        $cleanSec["content"] = implode("\n", $textParts);

        $subsections = array();
        foreach ($sec["subsections"] as $sub) {
            $subText = array();
            foreach ($sub["content"] as $cl) {
                if ($cl !== "" || !empty($subText)) {
                    $subText[] = $cl;
                }
            }
            $cleanName = trim($sub["name"]);
            $entry = array(
                "name" => $cleanName,
                "content" => implode("\n", $subText),
            );
            // Add semantic fields for flag-like subsections
            if (strlen($cleanName) > 0 && $cleanName[0] === "-") {
                $parsed = parseFlagJSON($cleanName);
                if ($parsed["flag"] !== "") {
                    $entry["flag"] = $parsed["flag"];
                }
                if ($parsed["long"] !== null) {
                    $entry["long"] = $parsed["long"];
                }
                if ($parsed["arg"] !== null) {
                    $entry["arg"] = $parsed["arg"];
                }
            }
            $subsections[] = $entry;
        }
        $cleanSec["subsections"] = $subsections;
        // Use L1 heading name as the key
        $jsonSections[$sec["name"]] = $cleanSec;
    }
    $jsonData["sections"] = $jsonSections;

    // === Semantic extraction for agent consumption ===

    // 1. Summary from NAME section
    foreach ($sections as $sec) {
        if ($sec["name"] === "NAME") {
            $contentStr = is_array($sec["content"]) ? implode(" ", $sec["content"]) : $sec["content"];
            $nameText = trim($contentStr);
            if ($nameText !== "") {
                $jsonData["summary"] = $nameText;
            }
            break;
        }
    }

    // 2. Flags from OPTIONS section (or DESCRIPTION as fallback)
    $flags = array();
    $flagSections = array("OPTIONS", "DESCRIPTION");
    foreach ($flagSections as $targetSection) {
        foreach ($sections as $sec) {
            if ($sec["name"] === $targetSection) {
                if (count($sec["subsections"]) > 0) {
                    foreach ($sec["subsections"] as $sub) {
                        $cleanName = trim($sub["name"], "[] ");
                        // Flag subsections start with "-"
                        if (strlen($cleanName) > 0 && $cleanName[0] === "-") {
                            $flag = parseFlagJSON($cleanName);
                            $descStr = is_array($sub["content"]) ? implode(" ", $sub["content"]) : $sub["content"];
                            $flag["description"] = trim(preg_replace('/\s+/', ' ', $descStr));
                            $flags[] = $flag;
                        }
                    }
                }
                break 2; // Stop after first section with flags
            }
        }
    }
    $jsonData["flags"] = $flags;

    // 3. Examples from EXAMPLES / EXAMPLE section
    $examples = array();
    foreach ($sections as $sec) {
        if ($sec["name"] === "EXAMPLES" || $sec["name"] === "EXAMPLE") {
            $allContent = is_array($sec["content"]) ? implode("\n", $sec["content"]) : $sec["content"];
            foreach ($sec["subsections"] as $sub) {
                $allContent .= "\n" . (is_array($sub["content"]) ? implode("\n", $sub["content"]) : $sub["content"]);
            }
            $lines = explode("\n", $allContent);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== "" && strlen($line) > 1) {
                    $examples[] = $line;
                }
            }
            break;
        }
    }
    $jsonData["examples"] = $examples;

    // 4. See Also from SEE ALSO section
    $seeAlso = array();
    foreach ($sections as $sec) {
        if ($sec["name"] === "SEE ALSO") {
            $allContent = is_array($sec["content"]) ? implode("\n", $sec["content"]) : $sec["content"];
            foreach ($sec["subsections"] as $sub) {
                $allContent .= "\n" . (is_array($sub["content"]) ? implode("\n", $sub["content"]) : $sub["content"]);
            }
            // Strip man page footer lines (e.g. "curl 7.81.0  ...  curl(1)")
            // from SEE ALSO content before extracting references
            $allContent = preg_replace('/^\S.{2,}\S[ ]{3,}.*[ ]{3,}\w+\(\w+\)\s*$/m', '', $allContent);
            preg_match_all('/([a-zA-Z0-9_.-]+)\((\w+)\)/', $allContent, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                // Filter out self-references (man page footer lines bleed into content)
                if ($m[1] === $parameter) {
                    continue;
                }
                $seeAlso[] = array(
                    "name" => $m[1],
                    "section" => $m[2],
                    "url" => baseUrl() . "/man/" . urlencode($m[1]) . "/" . urlencode($m[2]) . "/json"  // #46: dynamic URL
                );
            }
            break;
        }
    }
    $jsonData["see_also"] = $seeAlso;

    // v2.2: Inject TLDR from official sources (only for man section 1)
    $tldr = fetchOfficialTldr($parameter, $mode, $section);
    if (!empty($tldr)) {
        $jsonData["tldr"] = $tldr;
    }

    $result = json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return $result !== false ? $result : '{}';
}

// Parse a flag name like "-K, --config <file>" into structured {flag, long, arg}
function parseFlagJSON(string $name): array {
    $result = array("flag" => "", "long" => null, "arg" => null);
    $parts = preg_split('/\s+/', trim($name));
    $hasFlag = false;  // track if we've already captured a flag name

    foreach ($parts as $part) {
        // Strip trailing comma from short flags like "-K," → "-K"
        $part = rtrim($part, ',');

        // Standalone argument placeholder: <file>, ARCHIVE, [=name], etc.
        if ($hasFlag && preg_match('/^(<[^>]+>|\[[^\]]+\])$/', $part)) {
            // Angle-bracket or square-bracket placeholder: <file>, [=password]
            $result["arg"] = $part;
            continue;
        }
        if ($hasFlag && preg_match('/^[A-Z][A-Z0-9_]{1,}$/', $part)) {
            // ALL CAPS placeholder after a flag: ARCHIVE, FILE, COMMAND
            $result["arg"] = $part;
            continue;
        }

        if (preg_match('/^-[a-zA-Z0-9?]$/', $part)) {
            // Short flag: -X
            $result["flag"] = $part;
            $hasFlag = true;
        } elseif (preg_match('/^--[a-zA-Z0-9][a-zA-Z0-9._-]*=(.+)$/', $part, $m)) {
            // Long flag with embedded arg: --option=VAL
            $result["long"] = explode("=", $part)[0];
            $result["arg"] = $m[1];
            $hasFlag = true;
        } elseif (preg_match('/^--[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $part)) {
            // Long flag: --option
            $result["long"] = $part;
            $hasFlag = true;
        }
    }

    return $result;
}

//convert man perldoc output to markdown
function formatManPerlDocToMarkdown (array $lines, string $parameter = "", string $mode = "man", string $section = ""): string {
    // #44: use shared cleanTerminalOutput() instead of inline patterns
    $lines = cleanTerminalOutput($lines);

    $output = "";

    // v2.2: Inject TLDR from official sources at top of markdown (only for man section 1)
    if ($parameter !== "") {
        $tldr = fetchOfficialTldr($parameter, $mode, $section);
        if (!empty($tldr)) {
            if (!empty($tldr["description"])) {
                $output .= "> **TLDR:** {$tldr["description"]}\n>\n";
            }
            foreach (array_slice($tldr["examples"] ?? [], 0, 8) as $ex) {
                $output .= "- {$ex["description"]}:\n  `{$ex["command"]}`\n";
            }
            $src = ($tldr["source"] ?? "") === "cheatsh" ? "cheat.sh" : "tldr-pages";
            $output .= "\n*Source: {$src}*\n\n---\n\n";
        }
    }

    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = $lines[$i];

        // Section / Sub-section Headers: detect via shared function
        $nextLine = ($i + 1 < $count) ? $lines[$i + 1] : null;
        $heading = detectHeadingType($line, $mode, $nextLine);
        if ($heading) {
            // L1 → ## (man .SH), L2 → ### (man .SS) to match HTML TOC hierarchy
            $prefix = ($heading['level'] === 1) ? '## ' : '### ';
            $line = $prefix . $heading['text'];
            // Skip the underline line in info mode (Setext-style heading)
            if (!empty($heading['skipNext'])) {
                $output .= $line . "\n";
                $i++;
                continue;
            }
        }

        // Email
        $line = preg_replace('/([\w\-\.]+)@([\w\-]+(?:\.[\w\-]+)+)/', '<$0>', $line);
        // URL: wrap as autolink, no need to escape :: in markdown
        $line = preg_replace('/(https?:\/\/[\w%\-\?&;#~=\.\/\@\:]+[\w\/])/i', '<$0>', $line);

        // Command references: show as absolute markdown links
        $line = preg_replace_callback(
            '/(?<![\w])(?:\*\*|_)?([\w\-\.\+]+)(?:\*\*|_)?\((?:\*\*|_)?((\d\w*|n)\w*)(?:\*\*|_)?\)/',
            function ($matches) {
                $name = str_replace(['**', '_'], '', $matches[1]);
                $sec = str_replace(['**', '_'], '', $matches[2]);
                $base = baseUrl();
                return '[' . $matches[0] . '](' . $base . '/man/' . urlencode($name) . '/' . urlencode($sec) . '/markdown)';
            },
            $line
        );
        
        // Perl modules: Module::Name → show as absolute markdown links
        $line = preg_replace_callback(
            '/(?<![\w])(?:\*\*|_)?(\w+(?:::\w+)+)(?:\*\*|_)?/',
            function ($matches) {
                $name = str_replace(['**', '_'], '', $matches[1]);
                $base = baseUrl();
                return '[' . $matches[0] . '](' . $base . '/perldoc/' . urlencode($name) . '/markdown)';
            },
            $line
        );

        $output .= $line . "\n";
    }
    return $output;
}

// +--------------------------------------------------------------------------------+
// | GNU GENERAL PUBLIC LICENSE   Version 2                                         |
// |        http://www.gnu.org/licenses/gpl.txt                                     |
// +--------------------------------------------------------------------------------+
function showCopyright (): void {
echo <<<END_OF_COPYRIGHT
<pre>
		    GNU GENERAL PUBLIC LICENSE
		       Version 2, June 1991

 Copyright (C) 1989, 1991 Free Software Foundation, Inc.
                       59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 Everyone is permitted to copy and distribute verbatim copies
 of this license document, but changing it is not allowed.

			    Preamble

  The licenses for most software are designed to take away your
freedom to share and change it.  By contrast, the GNU General Public
License is intended to guarantee your freedom to share and change free
software--to make sure the software is free for all its users.  This
General Public License applies to most of the Free Software
Foundation's software and to any other program whose authors commit to
using it.  (Some other Free Software Foundation software is covered by
the GNU Library General Public License instead.)  You can apply it to
your programs, too.

  When we speak of free software, we are referring to freedom, not
price.  Our General Public Licenses are designed to make sure that you
have the freedom to distribute copies of free software (and charge for
this service if you wish), that you receive source code or can get it
if you want it, that you can change the software or use pieces of it
in new free programs; and that you know you can do these things.

  To protect your rights, we need to make restrictions that forbid
anyone to deny you these rights or to ask you to surrender the rights.
These restrictions translate to certain responsibilities for you if you
distribute copies of the software, or if you modify it.

  For example, if you distribute copies of such a program, whether
gratis or for a fee, you must give the recipients all the rights that
you have.  You must make sure that they, too, receive or can get the
source code.  And you must show them these terms so they know their
rights.

  We protect your rights with two steps: (1) copyright the software, and
(2) offer you this license which gives you legal permission to copy,
distribute and/or modify the software.

  Also, for each author's protection and ours, we want to make certain
that everyone understands that there is no warranty for this free
software.  If the software is modified by someone else and passed on, we
want its recipients to know that what they have is not the original, so
that any problems introduced by others will not reflect on the original
authors' reputations.

  Finally, any free program is threatened constantly by software
patents.  We wish to avoid the danger that redistributors of a free
program will individually obtain patent licenses, in effect making the
program proprietary.  To prevent this, we have made it clear that any
patent must be licensed for everyone's free use or not licensed at all.

  The precise terms and conditions for copying, distribution and
modification follow.

		    GNU GENERAL PUBLIC LICENSE
   TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION

  0. This License applies to any program or other work which contains
a notice placed by the copyright holder saying it may be distributed
under the terms of this General Public License.  The "Program", below,
refers to any such program or work, and a "work based on the Program"
means either the Program or any derivative work under copyright law:
that is to say, a work containing the Program or a portion of it,
either verbatim or with modifications and/or translated into another
language.  (Hereinafter, translation is included without limitation in
the term "modification".)  Each licensee is addressed as "you".

Activities other than copying, distribution and modification are not
covered by this License; they are outside its scope.  The act of
running the Program is not restricted, and the output from the Program
is covered only if its contents constitute a work based on the
Program (independent of having been made by running the Program).
Whether that is true depends on what the Program does.

  1. You may copy and distribute verbatim copies of the Program's
source code as you receive it, in any medium, provided that you
conspicuously and appropriately publish on each copy an appropriate
copyright notice and disclaimer of warranty; keep intact all the
notices that refer to this License and to the absence of any warranty;
and give any other recipients of the Program a copy of this License
along with the Program.

You may charge a fee for the physical act of transferring a copy, and
you may at your option offer warranty protection in exchange for a fee.

  2. You may modify your copy or copies of the Program or any portion
of it, thus forming a work based on the Program, and copy and
distribute such modifications or work under the terms of Section 1
above, provided that you also meet all of these conditions:

    a) You must cause the modified files to carry prominent notices
    stating that you changed the files and the date of any change.

    b) You must cause any work that you distribute or publish, that in
    whole or in part contains or is derived from the Program or any
    part thereof, to be licensed as a whole at no charge to all third
    parties under the terms of this License.

    c) If the modified program normally reads commands interactively
    when run, you must cause it, when started running for such
    interactive use in the most ordinary way, to print or display an
    announcement including an appropriate copyright notice and a
    notice that there is no warranty (or else, saying that you provide
    a warranty) and that users may redistribute the program under
    these conditions, and telling the user how to view a copy of this
    License.  (Exception: if the Program itself is interactive but
    does not normally print such an announcement, your work based on
    the Program is not required to print an announcement.)

These requirements apply to the modified work as a whole.  If
identifiable sections of that work are not derived from the Program,
and can be reasonably considered independent and separate works in
themselves, then this License, and its terms, do not apply to those
sections when you distribute them as separate works.  But when you
distribute the same sections as part of a whole which is a work based
on the Program, the distribution of the whole must be on the terms of
this License, whose permissions for other licensees extend to the
entire whole, and thus to each and every part regardless of who wrote it.

Thus, it is not the intent of this section to claim rights or contest
your rights to work written entirely by you; rather, the intent is to
exercise the right to control the distribution of derivative or
collective works based on the Program.

In addition, mere aggregation of another work not based on the Program
with the Program (or with a work based on the Program) on a volume of
a storage or distribution medium does not bring the other work under
the scope of this License.

  3. You may copy and distribute the Program (or a work based on it,
under Section 2) in object code or executable form under the terms of
Sections 1 and 2 above provided that you also do one of the following:

    a) Accompany it with the complete corresponding machine-readable
    source code, which must be distributed under the terms of Sections
    1 and 2 above on a medium customarily used for software interchange; or,

    b) Accompany it with a written offer, valid for at least three
    years, to give any third party, for a charge no more than your
    cost of physically performing source distribution, a complete
    machine-readable copy of the corresponding source code, to be
    distributed under the terms of Sections 1 and 2 above on a medium
    customarily used for software interchange; or,

    c) Accompany it with the information you received as to the offer
    to distribute corresponding source code.  (This alternative is
    allowed only for noncommercial distribution and only if you
    received the program in object code or executable form with such
    an offer, in accord with Subsection b above.)

The source code for a work means the preferred form of the work for
making modifications to it.  For an executable work, complete source
code means all the source code for all modules it contains, plus any
associated interface definition files, plus the scripts used to
control compilation and installation of the executable.  However, as a
special exception, the source code distributed need not include
anything that is normally distributed (in either source or binary
form) with the major components (compiler, kernel, and so on) of the
operating system on which the executable runs, unless that component
itself accompanies the executable.

If distribution of executable or object code is made by offering
access to copy from a designated place, then offering equivalent
access to copy the source code from the same place counts as
distribution of the source code, even though third parties are not
compelled to copy the source along with the object code.

  4. You may not copy, modify, sublicense, or distribute the Program
except as expressly provided under this License.  Any attempt
otherwise to copy, modify, sublicense or distribute the Program is
void, and will automatically terminate your rights under this License.
However, parties who have received copies, or rights, from you under
this License will not have their licenses terminated so long as such
parties remain in full compliance.

  5. You are not required to accept this License, since you have not
signed it.  However, nothing else grants you permission to modify or
distribute the Program or its derivative works.  These actions are
prohibited by law if you do not accept this License.  Therefore, by
modifying or distributing the Program (or any work based on the
Program), you indicate your acceptance of this License to do so, and
all its terms and conditions for copying, distributing or modifying
the Program or works based on it.

  6. Each time you redistribute the Program (or any work based on the
Program), the recipient automatically receives a license from the
original licensor to copy, distribute or modify the Program subject to
these terms and conditions.  You may not impose any further
restrictions on the recipients' exercise of the rights granted herein.
You are not responsible for enforcing compliance by third parties to
this License.

  7. If, as a consequence of a court judgment or allegation of patent
infringement or for any other reason (not limited to patent issues),
conditions are imposed on you (whether by court order, agreement or
otherwise) that contradict the conditions of this License, they do not
excuse you from the conditions of this License.  If you cannot
distribute so as to satisfy simultaneously your obligations under this
License and any other pertinent obligations, then as a consequence you
may not distribute the Program at all.  For example, if a patent
license would not permit royalty-free redistribution of the Program by
all those who receive copies directly or indirectly through you, then
the only way you could satisfy both it and this License would be to
refrain entirely from distribution of the Program.

If any portion of this section is held invalid or unenforceable under
any particular circumstance, the balance of the section is intended to
apply and the section as a whole is intended to apply in other
circumstances.

It is not the purpose of this section to induce you to infringe any
patents or other property right claims or to contest validity of any
such claims; this section has the sole purpose of protecting the
integrity of the free software distribution system, which is
implemented by public license practices.  Many people have made
generous contributions to the wide range of software distributed
through that system in reliance on consistent application of that
system; it is up to the author/donor to decide if he or she is willing
to distribute software through any other system and a licensee cannot
impose that choice.

This section is intended to make thoroughly clear what is believed to
be a consequence of the rest of this License.

  8. If the distribution and/or use of the Program is restricted in
certain countries either by patents or by copyrighted interfaces, the
original copyright holder who places the Program under this License
may add an explicit geographical distribution limitation excluding
those countries, so that distribution is permitted only in or among
countries not thus excluded.  In such case, this License incorporates
the limitation as if written in the body of this License.

  9. The Free Software Foundation may publish revised and/or new versions
of the General Public License from time to time.  Such new versions will
be similar in spirit to the present version, but may differ in detail to
address new problems or concerns.

Each version is given a distinguishing version number.  If the Program
specifies a version number of this License which applies to it and "any
later version", you have the option of following the terms and conditions
either of that version or of any later version published by the Free
Software Foundation.  If the Program does not specify a version number of
this License, you may choose any version ever published by the Free Software
Foundation.

  10. If you wish to incorporate parts of the Program into other free
programs whose distribution conditions are different, write to the author
to ask for permission.  For software which is copyrighted by the Free
Software Foundation, write to the Free Software Foundation; we sometimes
make exceptions for this.  Our decision will be guided by the two goals
of preserving the free status of all derivatives of our free software and
of promoting the sharing and reuse of software generally.

			    NO WARRANTY

  11. BECAUSE THE PROGRAM IS LICENSED FREE OF CHARGE, THERE IS NO WARRANTY
FOR THE PROGRAM, TO THE EXTENT PERMITTED BY APPLICABLE LAW.  EXCEPT WHEN
OTHERWISE STATED IN WRITING THE COPYRIGHT HOLDERS AND/OR OTHER PARTIES
PROVIDE THE PROGRAM "AS IS" WITHOUT WARRANTY OF ANY KIND, EITHER EXPRESSED
OR IMPLIED, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.  THE ENTIRE RISK AS
TO THE QUALITY AND PERFORMANCE OF THE PROGRAM IS WITH YOU.  SHOULD THE
PROGRAM PROVE DEFECTIVE, YOU ASSUME THE COST OF ALL NECESSARY SERVICING,
REPAIR OR CORRECTION.

  12. IN NO EVENT UNLESS REQUIRED BY APPLICABLE LAW OR AGREED TO IN WRITING
WILL ANY COPYRIGHT HOLDER, OR ANY OTHER PARTY WHO MAY MODIFY AND/OR
REDISTRIBUTE THE PROGRAM AS PERMITTED ABOVE, BE LIABLE TO YOU FOR DAMAGES,
INCLUDING ANY GENERAL, SPECIAL, INCIDENTAL OR CONSEQUENTIAL DAMAGES ARISING
OUT OF THE USE OR INABILITY TO USE THE PROGRAM (INCLUDING BUT NOT LIMITED
TO LOSS OF DATA OR DATA BEING RENDERED INACCURATE OR LOSSES SUSTAINED BY
YOU OR THIRD PARTIES OR A FAILURE OF THE PROGRAM TO OPERATE WITH ANY OTHER
PROGRAMS), EVEN IF SUCH HOLDER OR OTHER PARTY HAS BEEN ADVISED OF THE
POSSIBILITY OF SUCH DAMAGES.

		     END OF TERMS AND CONDITIONS

	    How to Apply These Terms to Your New Programs

  If you develop a new program, and you want it to be of the greatest
possible use to the public, the best way to achieve this is to make it
free software which everyone can redistribute and change under these terms.

  To do so, attach the following notices to the program.  It is safest
to attach them to the start of each source file to most effectively
convey the exclusion of warranty; and each file should have at least
the "copyright" line and a pointer to where the full notice is found.

    <one line to give the program's name and a brief idea of what it does.>
    Copyright (C) <year>  <name of author>

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA


Also add information on how to contact you by electronic and paper mail.

If the program is interactive, make it output a short notice like this
when it starts in an interactive mode:

    Gnomovision version 69, Copyright (C) year name of author
    Gnomovision comes with ABSOLUTELY NO WARRANTY; for details type `show w'.
    This is free software, and you are welcome to redistribute it
    under certain conditions; type `show c' for details.

The hypothetical commands `show w' and `show c' should show the appropriate
parts of the General Public License.  Of course, the commands you use may
be called something other than `show w' and `show c'; they could even be
mouse-clicks or menu items--whatever suits your program.

You should also get your employer (if you work as a programmer) or your
school, if any, to sign a "copyright disclaimer" for the program, if
necessary.  Here is a sample; alter the names:

  Yoyodyne, Inc., hereby disclaims all copyright interest in the program
  `Gnomovision' (which makes passes at compilers) written by James Hacker.

  <signature of Ty Coon>, 1 April 1989
  Ty Coon, President of Vice

This General Public License does not permit incorporating your program into
proprietary programs.  If your program is a subroutine library, you may
consider it more useful to permit linking proprietary applications with the
library.  If this is what you want to do, use the GNU Library General
Public License instead of this License.

</pre>
END_OF_COPYRIGHT;

}
