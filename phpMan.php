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

/**
 * phpMan is a web interface of Unix command 'man', 'perldoc', 'info' and 'apropos'.
 * This script makes it easier to read man pages which is lengthy and require you
 * to use 'more' or 'pg' filters. Just try it if you feel hard to remember the command
 * for page back or need to dump man page into text/html format.
 * Compatible with GNU/Linux and FreeBSD under PHP 8.x.
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
$PHP_MAN_TITLE = "phpMan: Unix Man page/ Perldoc / Info page Web Interface";

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

function h (mixed $value): string {
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
function detectHeadingType (string $line): ?array {
    // Normalize: convert HTML bold/underline to markdown-style markers
    $line = preg_replace(['#</?b>#', '#</?u>#'], ['**', '_'], $line);

    // Level 1: ALL CAPS (3-50 chars) — strip ALL formatting markers first
    $plain = trim(str_replace(['**', '_'], '', $line));
    if (preg_match('/^[A-Z][A-Z0-9_ \/\x2d]{2,50}$/', $plain)) {
        return ['level' => 1, 'text' => $plain];
    }

    // Level 1: perldoc =head1 at column 0, mixed case — "In Practice"
    if (preg_match('/^[A-Z][a-z][\w\s:\x27;\-,\.\(\)\/]+$/D', $line)
        && !preg_match('/[.!?:]\s*$/', trim($line))
        && strlen($line) >= 3 && strlen($line) <= 60) {
        return ['level' => 1, 'text' => trim($line)];
    }

    // Level 2: perldoc .SS at column 0 — "Supported Encodings" (no indent)
    $noUnderline = str_replace('_', '', $line);
    if (preg_match('/^[A-Z][a-z][\w\s:\x27;\/\-\.\(\)]+$/D', $noUnderline)
        && !preg_match('/[.!?,;:]\s*$/', trim($noUnderline))
        && trim($noUnderline) !== strtoupper(trim($noUnderline))) {
        $text = trim($noUnderline);
        if (strlen($text) >= 3) {
            return ['level' => 2, 'text' => $text];
        }
    }

    // Level 2: perldoc .SS — "  Methods you should implement" (2-space indent)
    $noUnderline = str_replace('_', '', $line);
    if (preg_match('/^ {2}([A-Z][a-z][\w\s:\x27;\-,\.]+)$/', $noUnderline, $m)) {
        return ['level' => 2, 'text' => trim($m[1])];
    }

    // Level 2: man .SS italic — "_Subheading_" (entire line is italic)
    if (preg_match('/^_([A-Z][a-z][\w\s:\x27;\-,]+)_$/', $line, $m)) {
        return ['level' => 2, 'text' => trim($m[1])];
    }

    // Level 2: man .SS bold — "   **Packages**" or "   **Symbol** **Tables**"
    if (preg_match('/^ {2,8}((?:\*\*[^*]+\*\*\s*)+)$/', $line, $m)) {
        $text = str_replace('**', '', trim($m[1]));
        return ['level' => 2, 'text' => $text];
    }

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

function requestValue (array $source, string $key): string {
    if (!isset($source[$key]) || is_array($source[$key])) {
        return "";
    }

    return trim((string)$source[$key]);
}

function normalizeMode (mixed $mode): string {
    $mode = strtolower(trim((string)$mode));
    $allowed_modes = array(
        "man" => true,
        "perldoc" => true,
        "info" => true,
        "search" => true,
        "source" => true,
        "phpinfo" => true,
        "copyright" => true,
    );

    return isset($allowed_modes[$mode]) ? $mode : "man";
}

function normalizeParameter (mixed $parameter): string {
    $parameter = trim((string)$parameter);
    $parameter = str_replace(array("/", "\0"), array(" ", ""), $parameter);
    $parameter = preg_replace("/[\x00-\x1F\x7F]+/", " ", $parameter);

    return trim((string)$parameter);
}

function normalizeSection (mixed $section): string {
    $section = trim((string)$section);
    if (preg_match("/^[A-Za-z0-9_]+$/", $section) !== 1) {
        return "";
    }

    return $section;
}

// +--------------------------------------------------------------------------------+
// | parameter checking and format page output                                      |
// +--------------------------------------------------------------------------------+

//default options

//page content
$content = "";
//output mode
$mode = "";
$parameter = "";
$section = "";
$isSearchFallback = false;

$check['man'] = "";
$check['perldoc'] = "";
$check['info'] = "";
$check['search'] = "";

// Detect format preference (markdown or html)
$format = "html";
if (requestValue($_GET, "format") === "markdown") {
    $format = "markdown";
} else {
    $accept = serverValue("HTTP_ACCEPT");
    if (str_contains(strtolower($accept), "text/markdown") || str_contains(strtolower($accept), "text/x-markdown")) {
        $format = "markdown";
    }
}

/**
 * trans$_SERVER["ORIG_PATH_INFO"] to $_SERVER["PATH_INFO"]
 * for cgi/fcgi mode of php
 */
if ( serverValue("ORIG_PATH_INFO") !== "" ){
    $_SERVER["PATH_INFO"] = serverValue("ORIG_PATH_INFO");
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
    
    $allowed_modes = array("man", "perldoc", "info", "search", "source", "phpinfo", "copyright");
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
                if ($third_seg_lower === "html" || $third_seg_lower === "markdown") {
                    $format = $third_seg_lower;
                } else {
                    $section = $segments[2];
                }
            }
            if ($seg_count >= 4) {
                $fourth_seg_lower = strtolower($segments[3]);
                if ($fourth_seg_lower === "html" || $fourth_seg_lower === "markdown") {
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
                if ($second_seg_lower === "html" || $second_seg_lower === "markdown") {
                    $format = $second_seg_lower;
                } else {
                    $section = $segments[1];
                }
            }
            if ($seg_count >= 3) {
                $third_seg_lower = strtolower($segments[2]);
                if ($third_seg_lower === "html" || $third_seg_lower === "markdown") {
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
    }

    if ( requestValue($_GET, "parameter") != "" ) {
        $parameter = requestValue($_GET, "parameter");
    }

    if ( requestValue($_GET, "section") != "") {
        $section = requestValue($_GET, "section");
    }
}

// GET parameter always overrides
if ( requestValue($_GET, "format") != "" ) {
    $format = strtolower(trim(requestValue($_GET, "format")));
}

// set default mode
$mode = normalizeMode($mode);
$parameter = normalizeParameter($parameter);
$section = normalizeSection($section);

if ($parameter !== "" && $section === "" && $mode === "man") {
    $section = "1";
}

if ( $parameter != "" ) {
    if ( $section == "" ) {
        $PHP_MAN_TITLE = $parameter . " - " . $mode . " - phpMan";
    }
    else {
        $PHP_MAN_TITLE = $parameter . "(" . $section . ") - " . $mode . " - phpMan";
    }
}

//show source of file
if ( $mode == "source" ) {
    showHeader($PHP_MAN_TITLE, "", "", $mode);
    highlight_file(serverValue("SCRIPT_FILENAME", __FILE__));
    echo "</body></html>";
    exit;
}
//show php info
else if ( $mode == "phpinfo" ) {
    phpinfo();
    exit;
}
//show GPL
else if ( $mode == "copyright" ) {
    showHeader($PHP_MAN_TITLE, "", "", $mode);
    showCopyright();
    echo "</body></html>";
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
            $content = getManPage($parameter, $section, $format);

            // retry lower case if content is empty
            if ( preg_match("/^[A-Z\\._]+$/",$parameter) && trim($content) == ""){
                $content = getManPage(strtolower($parameter), $section, $format);
            }

            //not find command then try perldoc (for perl modules with :: or section 3pm/3perl)
            //before falling back to search
            if (trim($content) == "") {
                if (strpos($parameter, "::") !== false || $section === "3pm" || $section === "3perl") {
                    $content = getPerldocPage($parameter, $format);
                }
            }

            //still not found then redirect to search sections
            if (trim($content) == "") {
                $content = getSearchPage($parameter, $section, $format);
                $isSearchFallback = true;
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
            $content = getPerldocPage($parameter, $format);
        }
        else {
            //show all possable perl entrance by search keywords: 'perl'
            $content = getPerldocIndex($format);
        }
        break;
    case "info":
        $check['info'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = getInfoPage($parameter, $format);
        }
        else {
            $content = getInfoIndex($format);
        }
        break;
    case "search":
        $check['search'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = getSearchPage($parameter, $section, $format);
        }
        break;
}

// Show Markdown or HTML output
if ($format === "markdown") {
    header("Content-Type: text/markdown; charset=UTF-8");
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 3600 * 24 * 30) . " GMT");
    echo "# " . $PHP_MAN_TITLE . "\n\n" . $content;
    exit;
}

// +--------------------------------------------------------------------------------+
// | show output                                                                    |
// +--------------------------------------------------------------------------------+
// Line threshold: ~80 lines ≈ two screens at 14px monospace
$lineThreshold = 80;
// Determine if this page has real content (for robots meta)
$hasRealContent = (trim($content) !== "" && !$isSearchFallback);

// Count content lines and set body class for CSS-based show/hide
$showNav = false;
if ($hasRealContent && preg_match_all('/\n/', $content, $_dummy)) {
    $showNav = (intval(preg_match_all('/\n/', $content)) + 1 > $lineThreshold);
}

showHeader($PHP_MAN_TITLE, $parameter, $section, $mode, $hasRealContent, $showNav);
echo "<h1><a href=\"".h(scriptName())."\">".h($PHP_MAN_TITLE)."</a></h1>\n";
showForm($parameter, $check);
echo "<hr /><div id=\"content-wrap\">\n";

// For man page content, add section anchors and floating TOC
if ($mode !== "markdown" && $parameter !== "" && trim($content) !== "") {
    list($anchoredContent, $tocItems) = addManPageToc($content);

    if (count($tocItems) > 1 && $showNav) {
        echo "<div id=\"toc-sidebar\">\n";
        $pageLabel = $parameter . ($section !== "" ? "({$section})" : "");
        echo "<div class=\"toc-title\">" . h($pageLabel) . "</div>\n";
        foreach ($tocItems as $l1) {
            echo "<a href=\"#" . h($l1['id']) . "\">" . h($l1['label']) . "</a>\n";
            if (!empty($l1['children'])) {
                echo "<div class=\"toc-subs\">\n";
                foreach ($l1['children'] as $l2) {
                    echo "<a href=\"#" . h($l2['id']) . "\" class=\"toc-sub\">" . h($l2['label']) . "</a>\n";
                }
                echo "</div>\n";
            }
        }
        echo "</div>\n";
    }

    echo "<div id=\"man-content\"><pre>" . $anchoredContent . "</pre></div>\n";
} else {
    echo "<pre>" . $content . "</pre>\n";
}
echo "</div><hr />";

// Build markdown version URL for detail pages (actual man/perldoc/info content)
$markdownUrl = "";
if ($content !== ""
    && in_array($mode, ["man", "perldoc", "info"])
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

    if ($isDetailPage) {
        $script_name_path = scriptName();
        $markdownUrl = $script_name_path . "/" . $mode . "/" . urlencode($parameter);
        if ($mode === "man" && $section !== "") {
            $markdownUrl .= "/" . $section;
        }
        $markdownUrl .= "/markdown";
    }
}

showFooter($VALIDATOR, $markdownUrl, $showNav);


// +--------------------------------------------------------------------------------+
// | sub functions                                                                  |
// +--------------------------------------------------------------------------------+

//show html header
function showHeader (string $title = "", string $parameter = "", string $section = "", string $mode = "", bool $hasRealContent = true, bool $showNav = false): void {
    header("Content-Type: text/html; charset=UTF-8");
    // always modified now
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    // Expires one month later
    header("Expires: " .gmdate ("D, d M Y H:i:s", time() + 3600 * 24 * 30). " GMT");

    // Build SEO meta values
    $site_name = "phpMan";
    // Auto-detect base URL from current request (works for any deployment)
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : strtok($_SERVER['REQUEST_URI'], '?');
    $base_url = $proto . "://" . getSafeHost() . $script_path;
    $canonical_url = $base_url;
    $meta_description = "phpMan: Web interface for Unix/Linux man pages, Perl perldoc, and GNU info pages";
    $meta_keywords = "man page, unix manual, linux command, perldoc, info page, phpMan";

    if ($parameter !== "") {
        $section_suffix = $section !== "" ? "({$section})" : "";
        $canonical_url = $base_url . "/" . urlencode($mode ?: "man") . "/" . urlencode($parameter);
        if ($section !== "") {
            $canonical_url .= "/" . urlencode($section);
        }

        if ($mode === "man") {
            $meta_description = "Online man page for {$parameter}{$section_suffix}: read the Unix/Linux manual page in your browser";
            $meta_keywords = "{$parameter} man page, {$parameter} linux, {$parameter} unix, man {$parameter}, {$parameter} command";
        } elseif ($mode === "perldoc") {
            $meta_description = "Online perldoc for {$parameter}: read the Perl documentation in your browser";
            $meta_keywords = "{$parameter} perldoc, {$parameter} perl, perl {$parameter}, {$parameter} documentation";
        } elseif ($mode === "info") {
            $meta_description = "Online info page for {$parameter}: read the GNU info documentation in your browser";
            $meta_keywords = "{$parameter} info page, {$parameter} gnu, info {$parameter}, {$parameter} documentation";
        } elseif ($mode === "search") {
            $meta_description = "Search results for '{$parameter}' in Unix/Linux man pages, perldoc, and info pages";
            $meta_keywords = "{$parameter}, man page search, {$parameter} command, search manual";
        }
    }

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" ".
        "\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">".
        "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\">\n".
        "<head>\n".
        "<!-- phpMan v2026-05-22c - back to -Tascii for DreamHost -->\n".
        "<title>".h($title)."</title>\n".
        "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>\n".
        "<meta name=\"description\" content=\"".h($meta_description)."\"/>\n".
        "<meta name=\"keywords\" content=\"".h($meta_keywords)."\"/>\n".
        "<link rel=\"canonical\" href=\"".h($canonical_url)."\"/>\n".
        "<meta name=\"robots\" content=\"".($hasRealContent ? "index, follow" : "noindex, follow")."\"/>\n".
        // GEO: citation for AI/LLM attribution
        "<meta name=\"citation_title\" content=\"".h($title)."\"/>\n".
        "<meta name=\"citation_online_date\" content=\"".gmdate("Y/m/d")."\"/>\n".
        "<meta name=\"citation_author\" content=\"Che Dong\"/>\n";

    echo "<style type=\"text/css\">\n".
        "html {scroll-behavior:smooth;}\n".
        "body {color:#000000;background-color:#EEEEEE;font-family:'Courier New',Courier,monospace;font-size:14px;}\n".
        "b {color:#996600;background-color:#EEEEEE;}\n".
        "u {color:#008000;background-color:#EEEEEE;text-decoration:underline;}\n".
        "#content-wrap {max-width:90%;margin-right:360px;}\n".
        "#man-content pre {width:100%;overflow-x:auto;white-space:pre;}\n".
        "#toc-sidebar {position:fixed;top:20px;right:10px;width:320px;max-height:90vh;overflow-y:auto;".
            "background:#F8F8F8;border:1px solid #CCC;padding:8px;font-size:12px;z-index:100;".
            "display:none;}\n".
        "#toc-sidebar a {display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;".
            "color:#333;text-decoration:none;padding:2px 4px;border-radius:2px;}\n".
        "#toc-sidebar a:hover {background:#DDD;color:#000;}\n".
        "#toc-sidebar a.toc-sub {padding-left:18px;font-size:11px;color:#555;}\n".
        "#toc-sidebar a.toc-sub:hover {color:#000;}\n".
        "#toc-sidebar .toc-title {font-weight:bold;border-bottom:1px solid #CCC;margin-bottom:4px;padding-bottom:2px;}\n".
        "#back-to-top {position:fixed;bottom:20px;right:20px;z-index:100;display:none;}\n".
        "#back-to-top a {display:block;padding:8px 14px;background:#333;color:#FFF;text-decoration:none;".
            "border-radius:6px;font-size:13px;font-family:monospace;}\n".
        "#back-to-top a:hover {background:#555;}\n".
        "body.ext-nav #toc-sidebar, body.ext-nav #back-to-top {display:block;}\n".
        "@media (max-width:768px) {#toc-sidebar{display:none;}#content-wrap{margin-right:0;max-width:100%;}}\n".
        "</style>\n";

    // JSON-LD structured data for SEO/GEO
    if ($parameter !== "" && in_array($mode, ["man", "perldoc", "info"])) {
        $schema_type = "TechArticle";
        $section_label = $section !== "" ? " (section {$section})" : "";
        $schema_json = json_encode([
            "@context" => "https://schema.org",
            "@type" => $schema_type,
            "name" => $parameter . $section_label,
            "description" => $meta_description,
            "url" => $canonical_url,
            "author" => [
                "@type" => "Organization",
                "name" => $site_name,
                "url" => $base_url
            ],
            "publisher" => [
                "@type" => "Person",
                "name" => "Che Dong",
                "url" => $base_url
            ],
            "datePublished" => gmdate("Y-m-d"),
            "inLanguage" => "en"
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "<script type=\"application/ld+json\">\n{$schema_json}\n</script>\n";
    }

    $bodyClass = $showNav ? ' class="ext-nav"' : '';
    echo "</head>\n<body{$bodyClass}>\n<div id=\"top\"></div>\n";
}

//promter and recursive call
function showForm (string $parameter, array $check): void {
    $script_name = h(scriptName());
    $parameter_value = h(stripslashes((string)$parameter));

    echo "<form action=\"".$script_name."\" method=\"get\">\n".
        "<p>Command: ".
        "<input type=\"text\" size=\"20\" name=\"parameter\" value=\"".$parameter_value."\"/>\n".
        "<input type=\"radio\" name=\"mode\" value=\"man\"".$check['man']."/>".
        "<a href=\"".$script_name."/man\">man</a>\n".
        "<input type=\"radio\" name=\"mode\" value=\"perldoc\"".$check['perldoc']."/>".
        "<a href=\"".$script_name."/search/perl\">perldoc</a>\n".
        "<input type=\"radio\" name=\"mode\" value=\"info\"".$check['info']."/>".
        "<a href=\"".$script_name."/info\">info</a>\n".
        "<input type=\"radio\" name=\"mode\" value=\"search\"".$check['search']."/>".
        "<a href=\"".$script_name."/man/apropos\">search(apropos)</a>\n".
        "&nbsp;<input type=\"submit\"/></p>".
        "</form>\n";
}

//show footer
function showFooter (string $validator = "", string $markdownUrl = "", bool $showNav = false): void {
    $script_name = h(scriptName());
    $server_software = h(serverValue("SERVER_SOFTWARE", "unknown server"));
    $home_url = h("http://" . getSafeHost());
    $remote_addr = h(serverValue("REMOTE_ADDR", "unknown"));
    $user_agent = h(serverValue("HTTP_USER_AGENT", "unknown"));

    echo "<p>Generated by <a href=\"https://sourceforge.net/projects/phpunixman/\">phpMan</a>" .
        " Author: <a href=\"http://www.chedong.com/\">Che Dong</a>" .
        " On <a href=\"".$script_name."/phpinfo\">" . $server_software .
        "</a> Under <a href=\"".$script_name."/copyright\">GNU General Public License</a>" .
        ($markdownUrl !== "" ? " - <a href=\"" . h($markdownUrl) . "\">MarkDown Format</a>" : "") .
        "<br />" .
        "<a href=\"" . $home_url . "\">" . date("Y-m-d H:i") . " @". $remote_addr .
        " CrawledBy " . $user_agent . "</a>" .
        "<br />" . $validator . "</p>" .
        ($showNav ? '<div id="back-to-top"><a href="#top">^_back to top</a></div>' : "") .
        "</body></html>";
}

//get specified command's man page and convert to html format
function getManPage (string $parameter, string $section = "1", string $format = "html"): string {
    $lines = array();
    // use man -Tascii so formatManPerlDoc() converts overstrike to <b>/<u> tags
    // @version 2026-05-22c — back to -Tascii for DreamHost compatibility
    $command = "man -Tascii ";
    if ($section !== "") {
        $command .= escapeshellarg($section)." ";
    }
    $command .= escapeshellarg($parameter);

    exec($command, $lines);
    if ($format === "markdown") {
        return formatManPerlDocToMarkdown($lines);
    }
    return formatManPerlDoc($lines, "man");
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
            $label = trim(strip_tags($m[2]));
            $tocItems[] = array('id' => $m[1], 'label' => $label, 'children' => array());
            $currentL1Idx = count($tocItems) - 1;
            continue;
        }

        // Level 2 anchor already placed by formatManPerlDoc
        if (preg_match('/<a id="(sub-[^"]+)"><\/a>(.*)/', $line, $m)) {
            if ($currentL1Idx !== null) {
                $label = trim(strip_tags($m[2]));
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
    exec("perldoc ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        return $format === "markdown" ? formatManPerlDocToMarkdown($lines) : formatManPerlDoc($lines, "perldoc");
    }

    // try build in function
    $lines = array();
    exec("perldoc -f ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        return $format === "markdown" ? formatManPerlDocToMarkdown($lines) : formatManPerlDoc($lines, "perldoc");
    }

    // try perldoc search
    $lines = array();
    exec("perldoc -q ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        return $format === "markdown" ? formatManPerlDocToMarkdown($lines) : formatManPerlDoc($lines, "perldoc");
    }

    return "";
}

//get specified command's info page
function getInfoPage (string $parameter, string $format = "html"): string {
    $lines = array();
    exec("info ".escapeshellarg($parameter), $lines);
    return $format === "markdown" ? formatManPerlDocToMarkdown($lines) : formatManPerlDoc($lines, "info");
}

/**
 * search specified keyword by apropos and convert output link to man pages
 * Note: on linux, rebuild whatis database under root with:
 * /usr/sbin/makewhatis -w
 */
function getSearchPage (string $parameter, string $section = "", string $format = "html"): string {
    $script_name = scriptName();
    
    // get last parameter of search string
    // example: "1 GCC" ==> "GCC"
    $parameter_parts = preg_split("/\s+/", trim((string)$parameter));
    $parameter = $parameter_parts === false ? "" : (string)array_pop($parameter_parts);

    if ($parameter === "") {
        return "";
    }

    // detect section search pattern like "(1)", "(2)", etc.
    // use "apropos -s N ." for section listing instead of "apropos '(N)'"
    if ($section !== "" && preg_match("/^[0-9n]$/", $section)) {
        $cmd = "apropos -s " . escapeshellarg($section) . " .";
    } elseif (preg_match("/^\(([0-9n]+)\)$/", $parameter, $m)) {
        $cmd = "apropos -s " . escapeshellarg($m[1]) . " .";
    } else {
        $cmd = "apropos " . escapeshellarg($parameter);
    }
    $lines = array();
    exec($cmd, $lines);

    // determine link mode: perl modules (section 3pm or name with ::) use perldoc, others use man
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = $lines[$i];

        // detect perl module: section 3pm/3perl or name contains ::
        $is_perl = (preg_match("/\\((3pm|3perl)\\)/", $line) || preg_match("/\\w+::\\w+/", $line));
        $link_mode = $is_perl ? "perldoc" : "man";

        if ($format === "markdown") {
            $patterns = array(
                "/(.*\\/)?([\\w\\-\\.\\+:]+)((\\s+\\[)([\\w\\-\\.:]+)(\\]\\s+))\\(([\\dnol]\\w*)\\)/",
                "/([\\w+\\.\\-:]+)(\\s+)?(\\(([\\dnol]\\w*)\\))/"
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
            $patterns = array(
                "/&/",  //html special char: '&' => '&amp;';
                "/</",  //html special char: '<' => '&lt;';
                "/>/",  //html special char: '>' => '&gt;';
                //for linux format of search output
                "/(.*\\/)?([\\w\\-\\.\\+:]+)((\\s+\\[)([\\w\\-\\.:]+)(\\]\\s+))\\(([\\dnol]\\w*)\\)/",
                //'(command)' => man page of command;
                "/([\\w+\\.\\-:]+)(\\s+)?(\\(([\\dnol]\\w*)\\))/"
            );
            if ($link_mode === "perldoc") {
                $replace = array(
                    "&amp;",
                    "&lt;",
                    "&gt;",
                    '$1$2$4<a href="'.$script_name.'/perldoc/$5">$5</a>$6($7)',
                    '<a href="'.$script_name.'/perldoc/$1">$1</a>$2$3'
                );
            } else {
                $replace = array(
                    "&amp;",
                    "&lt;",
                    "&gt;",
                    '$1$2$4<a href="'.$script_name.'/man/$5/$7">$5</a>$6($7)',
                    '<a href="'.$script_name.'/man/$1/$4">$1</a>$2$3'
                );
            }
            $output .= preg_replace($patterns, $replace, $line) . "\n";
        }
    }
    return $output;
}

//link to man page list by searching section tag
function getManIndex (string $format = "html"): string {
    $script_name = scriptName();
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
    return getSearchPage("perl", $format);
}

//get info page index page
function getInfoIndex (string $format = "html"): string {
    $lines = array();
    exec("info", $lines);
    $script_name = scriptName();

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
    $patterns = array(
                    "/&/",  //html special char: '&' => chr(5) => '&gt;';
                    "/</",  //html special char: '>' => chr(6) => '&lt;';
                    "/>/",  //html special char: '<' => chr(7) => '&gt;';
                    //man page special chars
                    "/.".chr(8).".".chr(8)."(.)".chr(8)."./",	// ?^H?^H?^H? => <b>?</b>
                    "/_".chr(8)."(.)".chr(8)."./",  //_^H?^H? => <u>?</u>
                    "/_".chr(8)."(.)/",  //_^H? => <u>?</u>
                    "/.".chr(8)."(.)/",  //?^H? => <b>?</b>
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
                    //transfer related command to hyperlinks, but $b->func(#) will not be translate.
                    //'<b>command</b>(<b>#</b>),</b>' => ' command(#)' => link to command
                    //Man Page Howto: http://www.schweikhardt.net/man_page_howto.html
                    "/((<.>)|([\s,]))([\w\-\.\+]+)(<\/.>)?\((<.>)?([\dnol]\w*)(<\/.>)?\)(,)?(<\/.>)?/",
                    "/([\s,])([\w\-\.\+]+)\(([\dnol]\w*)\)/",
                    //translate link to related perl modules, but $obj->Module::Name-> will not be translate
                    //'<u>Module::Name</u>' => ' Module::Name'
                    "/((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/",
                    // SGR escape sequences (modern man): handle ESC[1m..ESC[0m and ESC[1m..ESC[22m
                    "/".chr(27)."\[1m(.*?)".chr(27)."\[(?:0|22)m/",
                    // SGR underline: handle ESC[4m..ESC[0m and ESC[4m..ESC[24m
                    "/".chr(27)."\[4m(.*?)".chr(27)."\[(?:0|24)m/",
                    "/(([\w\-\.]+)@([\w\-]+)(\.[\w\-]+)+)/",  //link to email
                    "/([\w]+:\/\/[\w%\-\?&;#~=\.\/\@]+[\w\/])/i", //link to url
                    "/".chr(7)."/",  //reverse '>'
                );

    $replace = array(
                   chr(5),
                   chr(6),
                   chr(7),
                   '<b>$1</b>',
                    '<u>$1</u>',
                    '<u>$1</u>',
                    '<b>$1</b>',
                   "&amp;",
                   "&lt;",
                   "",
                   "_",
                   "",
                   '<b>$1</b>',
                   '  <u>$1</u>',
                   '$3$4($7)$9',
                   '$1<a href="'.$script_name.'/man/$2/$3">$2($3)</a>',
                   '$3<a href="'.$script_name.'/'.$mode.'/$4">$4</a>$6',
                   '<b>$1</b>',
                   '<u>$1</u>',
                   '<a href="mailto:$2 AT $3$4">$2<u> AT </u>$3$4</a>',
                   '<a href="$1" rel="noopener noreferrer">$1</a>',
                   "&gt;",
               );
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = preg_replace($patterns, $replace, $lines[$i]);
        $heading = detectHeadingType($line);
        if ($heading) {
            $id = ($heading['level'] === 1 ? 'section-' : 'sub-')
                . strtolower(preg_replace('/[^A-Z0-9]+/i', '-', $heading['text']));
            $id = trim($id, '-');
            $line = '<a id="' . h($id) . '"></a>' . $line;
        }
        $output .= $line . "\n";
    }
    return $output;
}

//convert man perldoc output to markdown
function formatManPerlDocToMarkdown (array $lines): string {

    $patterns = array(
        "/.".chr(8).".".chr(8)."(.)".chr(8)."./",  // ?^H?^H?^H? => bold
        "/_".chr(8)."(.)/",  // _^H? => underline
        "/.".chr(8)."(.)/",  // ?^H? => bold
        "/".chr(27)."\[1m(.*?)".chr(27)."\[0m/",  // perldoc ANSI bold
        "/".chr(27)."\[4m(.*?)".chr(27)."\[24m/", // perldoc ANSI underline
    );
    $replace = array(
        "\x01$1\x02",
        "\x03$1\x04",
        "\x01$1\x02",
        "\x01$1\x02",
        "\x03$1\x04",
    );

    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = $lines[$i];
        
        // Format backspaces and ANSI escapes
        $line = preg_replace($patterns, $replace, $line);
        
        // Merge consecutive bold/underline
        $line = str_replace(array("\x02\x01", "\x04\x03"), "", $line);
        $line = str_replace(array("\x01", "\x02", "\x03", "\x04"), array("**", "**", "_", "_"), $line);
        
        // Section / Sub-section Headers: detect via shared function
        $heading = detectHeadingType($line);
        if ($heading) {
            $line = '## ' . $heading['text'];
        }

        // Email
        $line = preg_replace('/([\w\-\.]+)@([\w\-]+(?:\.[\w\-]+)+)/', '<$0>', $line);
        // URL: wrap as autolink, no need to escape :: in markdown
        $line = preg_replace('/(https?:\/\/[\w%\-\?&;#~=\.\/\@\:]+[\w\/])/i', '<$0>', $line);

        // Command references: show as bracketed reference without link
        $line = preg_replace_callback(
            '/(?<![\w])(?:\*\*|_)?([\w\-\.\+]+)(?:\*\*|_)?\((?:\*\*|_)?([\dnol]\w*)(?:\*\*|_)?\)/',
            function ($matches) {
                return '[' . $matches[0] . ']';
            },
            $line
        );
        
        // Perl modules: Module::Name → show as [Module::Name] without link
        $line = preg_replace_callback(
            '/(?<![\w])(?:\*\*|_)?(\w+(?:::\w+)+)(?:\*\*|_)?/',
            function ($matches) {
                return '[' . $matches[0] . ']';
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
