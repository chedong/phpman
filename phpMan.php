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


// ASCII character classes for overstrike pattern matching:
// RE_ASCII — plain printable ASCII, for raw terminal output (cleanTerminalOutput)
// RE_ASCII_SAFE — printable + placeholder bytes \x05\x06\x07 for &<>, used after
//                 formatManPerlDoc() replaces &<> with placeholders
define('RE_ASCII', '[ -~]');
define('RE_ASCII_SAFE', '[ -~' . "\x05\x06\x07" . ']');

// #49: Named constants for magic numbers
define('PHPMAN_VERSION', '4.4.0');        // current version (#67)
define('GIT_DESCRIBE', 'v4.1.1-10-gd2a3e77-dirty');         // replaced by make deploy/release with git describe --tags



// Load site-specific config before defaults (define() guard pattern)
$_config_file = __DIR__ . "/phpman.config.php";
if (file_exists($_config_file)) { require $_config_file; }
unset($_config_file);

// Load all source files (config defaults + functions + classes)
// Resolve src/: dev (next to phpMan.php) or deployed (PHPMAN_HOME/src/)
$srcDir = is_dir(__DIR__ . '/src') ? __DIR__ . '/src' : PHPMAN_HOME . '/src';
require $srcDir . '/config.php';
require $srcDir . '/bootstrap.php';

// Test mode: load functions only, skip dispatch
if (defined("PHPMAN_TEST_MODE")) { return; }

// CLI tools define PHPMAN_NO_CLI_DISPATCH to skip web dispatch
if (defined("PHPMAN_NO_CLI_DISPATCH")) return;


// page content
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
// Key-based ETag: include cache timestamp so re-indexing invalidates stale ETags
if ($format === "html" && $mode !== "mcp" && $mode !== "copyright" && $mode !== "search" && $parameter !== "") {
    $cacheAge = '';
    try {
        $db = cacheDb();
        if ($db) {
            $cacheAge = $db->querySingle("SELECT value FROM meta WHERE key = 'search_index_updated'") ?: '';
        }
    } catch (\Throwable $ignored) {}
    $etag = '"' . md5($mode . '/' . $parameter . '/' . $section . '/' . PHPMAN_VERSION . '/' . $cacheAge) . '"';
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
        $PHPMAN_TITLE = "phpman > " . $mode . " > " . $parameter;
    }
    else {
        $PHPMAN_TITLE = "phpman > " . $mode . " > " . $parameter . "(" . $section . ")";
    }
} elseif ($mode !== "" && $mode !== "search" && in_array($mode, PHPMAN_CONTENT_MODES)) {
    $PHPMAN_TITLE = "phpman > " . $mode;
}

//show GPL
else if ( $mode == "copyright" ) {
    showHeader($PHPMAN_TITLE, "", "", $mode);
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

                    // Cascade to pydoc3 and ri — always check FTS5 (fast),
                    // command-line only when man search had no results.
                    $hasManResults = ($inner !== "" && $inner !== "<ul>\n</ul>\n");
                    if ($format === "html") {
                        $inner = "<h2>apropos</h2>\n" . $inner . "\n";
                        $pydocResults = searchFtsBySource($parameter, 'pydoc', 'html');
                        if ($pydocResults === "" && !$hasManResults) {
                            $pydocResults = getPydocSearchPage($parameter, "html");
                        }
                        Profiler::mark('pydoc:done');
                        if ($pydocResults !== "") {
                            $inner .= "<h2>Python 3 (pydoc3)</h2>\n" . $pydocResults . "\n";
                        }
                        $riResults = searchFtsBySource($parameter, 'ri', 'html');
                        if ($riResults === "") {
                            $riResults = getRiSearchPage($parameter, "html");
                        }
                        Profiler::mark('ri:done');
                        if ($riResults !== "") {
                            $inner .= "<h2>Ruby (ri)</h2>\n" . $riResults . "\n";
                        }
                    } elseif ($format === "markdown") {
                        $inner = "## apropos\n\n" . $inner . "\n";
                        $pydocResults = searchFtsBySource($parameter, 'pydoc', 'markdown');
                        if ($pydocResults === "") {
                            $pydocResults = getPydocSearchPage($parameter, "markdown");
                        }
                        Profiler::mark('pydoc:done');
                        if ($pydocResults !== "") {
                            $inner .= "\n\n## Python 3 (pydoc3)\n\n" . $pydocResults;
                        }
                        $riResults = searchFtsBySource($parameter, 'ri', 'markdown');
                        if ($riResults === "") {
                            $riResults = getRiSearchPage($parameter, "markdown");
                        }
                        Profiler::mark('ri:done');
                        if ($riResults !== "") {
                            $inner .= "\n\n## Ruby (ri)\n\n" . $riResults;
                        }
                    } elseif ($format === "json" || $format === "mcp") {
                        $current = json_decode($inner, true);
                        if ($current === null) $current = [];
                        $pydocFts = searchFtsBySource($parameter, 'pydoc', 'json');
                        Profiler::mark('pydoc:done');
                        if (!empty($pydocFts)) {
                            $current["pydoc_results"] = $pydocFts;
                        } else {
                            $pydocJson = getPydocSearchPage($parameter, "json");
                            if ($pydocJson !== "") {
                                $pydocData = json_decode($pydocJson, true);
                                if ($pydocData !== null) $current["pydoc_results"] = $pydocData["results"] ?? [];
                            }
                        }
                        $riFts = searchFtsBySource($parameter, 'ri', 'json');
                        Profiler::mark('ri:done');
                        if (!empty($riFts)) {
                            $current["ri_results"] = $riFts;
                        } else {
                            $riJson = getRiSearchPage($parameter, "json");
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
                    return $inner;                });
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
    // v4.0: enhanced Markdown for /markdown format — prefer emoji_md cache
    if ($parameter !== "" && isset($mode) && in_array($mode, PHPMAN_CONTENT_MODES)) {
        $mdcache = new PageCache();
        $enhancedMd = $mdcache->get($mode, $parameter, '', 'emoji_md');
        if ($enhancedMd !== null && !PageCache::isNotFound($enhancedMd)) {
            $content = $enhancedMd;
        }
    }
    echo "# " . $PHPMAN_TITLE . "\n\n" . $content;
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
    if (strpos($acceptEncoding, "gzip") !== false && function_exists("gzencode") && strlen($content) > PHPMAN_GZIP_MIN_BYTES) {
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
$lineThreshold = PHPMAN_TOC_THRESHOLD;
// Determine if this page has real content (for robots meta)
// Search results and build-index pages should be noindex
$hasRealContent = (trim($content) !== "" && !$isSearchFallback && $mode !== "search");

// Count content lines and set body class for CSS-based show/hide
$showNav = false;
if ($hasRealContent) {
    $showNav = (substr_count($content, "\n") + 1 > $lineThreshold);
}

// ETag already computed before exec() dispatch (#83)
Profiler::mark('render');

showHeader($PHPMAN_TITLE, $parameter, $section, $mode, $hasRealContent, $showNav, $etag);

// H1 breadcrumb: phpman > mode > command(section)
$modes = ["man" => ["label" => "man", "url" => "/man"], "perldoc" => ["label" => "perldoc", "url" => "/search/perl"], "info" => ["label" => "info", "url" => "/info"], "pydoc" => ["label" => "pydoc", "url" => "/pydoc"], "ri" => ["label" => "ri", "url" => "/ri"]];
if ($parameter !== "" && $mode !== "" && $mode !== "search") {
    $bc_parts = [];
    $bc_parts[] = "<a href=\"".h(scriptName())."\">phpman</a>";
    if (isset($modes[$mode])) {
        $bc_parts[] = "<a href=\"".h(scriptName() . $modes[$mode]["url"])."\">".h($modes[$mode]["label"])."</a>";
    }
    $section_label = $section !== "" ? "({$section})" : "";
    $bc_parts[] = h($parameter . $section_label);
    echo "<h1>" . implode(" &gt; ", $bc_parts) . "</h1>\n";
} elseif ($mode !== "" && $mode !== "search" && isset($modes[$mode])) {
    echo "<h1><a href=\"".h(scriptName())."\">phpman</a> &gt; " . h($modes[$mode]["label"]) . "</h1>\n";
} else {
    echo "<h1><a href=\"".h(scriptName())."\">".h($PHPMAN_TITLE)."</a></h1>\n";
}

// Build markdown/JSON URLs for format links (showForm below)
$markdownUrl = "";
$jsonUrl = "";
$script_name_path = baseUrl();

// Detail pages (actual man/perldoc/info/pydoc/ri content): pathinfo URLs
if ($content !== ""
    && in_array($mode, PHPMAN_CONTENT_MODES)
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
elseif ($content !== "" && in_array($mode, PHPMAN_CONTENT_MODES) && $parameter === "") {
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
	// Skip TLDR when enhanced HTML is available (already has Quick Reference).
	// #144: Single cache lookup reused for both TLDR skip and enhanced routing below.
	$enhancedCacheContent = null;
	$hasEnhancedCache = false;
	if (in_array($mode, PHPMAN_CONTENT_MODES)) {
	    $sharedCache = new PageCache();
	    $enhancedCacheContent = $sharedCache->get($mode, $parameter, "", CACHE_FORMAT_EMOJI_HTML);
	    $hasEnhancedCache = ($enhancedCacheContent !== null && !PageCache::isNotFound($enhancedCacheContent));
	}
	if (!$hasEnhancedCache) {
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
	        echo "<div class=\"tldr-block\">\n";
	        echo "<div class=\"tldr-header\">";
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
	}


	// v4.0: enhanced HTML routing — default view uses LLM-enhanced MD if available
	// v4.0: enhanced HTML routing. Skip when format explicitly set.
	$formatExplicit = (requestValue($_GET, "format") !== "") || (serverValue("PATH_INFO") !== "" && preg_match("#/(html|markdown|json|mcp)$#", serverValue("PATH_INFO")));
	$isEnhanced = false;
	// #144: Reuse $enhancedCacheContent from TLDR block above — single cache lookup per request.
	// #145: Replace $GLOBALS with a local variable passed to showFooter().
	$enhancedBy = '';
	if (!$formatExplicit && $parameter !== "" && $hasEnhancedCache && $enhancedCacheContent !== null) {
	    $content = cleanEmojiHtml($enhancedCacheContent);
	    $isEnhanced = true;
	    $enhancedBy = LLM_MODEL;
	}

	// For man page content, add section anchors and floating TOC
	if ($isEnhanced) {
		    // Build TOC from enhanced HTML: <h2> L1, <h3> L2 children
            // #144: single-pass preg_replace_callback instead of O(n×m) repeated scans
            $tocItems = [];
            $currentH2 = null;
            $h2Count = 0;
            $content = preg_replace_callback(
                '#<(h2|h3)\b[^>]*>(.+?)</\1>#i',
                function ($m) use (&$tocItems, &$currentH2, &$h2Count) {
                    $tag = strtolower($m[1]);
                    $label = html_entity_decode(trim(strip_tags($m[2])), ENT_QUOTES, 'UTF-8');
                    if ($tag === 'h2') {
                        $id = "section-" . $h2Count++;
                        $tocItems[] = ["id" => $id, "label" => $label, "children" => []];
                        $currentH2 = count($tocItems) - 1;
                        return '<h2 id="' . $id . '">' . $m[2] . '</h2>';
                    } elseif ($tag === 'h3' && $currentH2 !== null) {
                        $subIdx = count($tocItems[$currentH2]["children"]);
                        $id = "section-" . $currentH2 . "-" . $subIdx;
                        $tocItems[$currentH2]["children"][] = ["id" => $id, "label" => $label];
                        return '<h3 id="' . $id . '">' . $m[2] . '</h3>';
                    }
                    return $m[0];
                },
                $content
            );
            $pageLabel = $parameter . ($section !== "" ? "({$section})" : "");
		    $tocSidebar = renderTocSidebar($tocItems, $pageLabel);
	    echo $content . "\n</div>\n";
	    echo $tocSidebar;
	} else {
    // Sidebars are collected and output AFTER content for better SEO
    $tocSidebar = '';
    if ($mode !== "markdown" && $mode !== "search" && !$isSearchFallback && $parameter !== "" && trim($content) !== "") {
        list($anchoredContent, $tocItems) = addManPageToc($content);

        if ($showNav) {
            $pageLabel = $parameter . ($section !== "" ? "({$section})" : "");
            $tocSidebar = renderTocSidebar($tocItems, $pageLabel);
        }

        echo "<div id=\"man-content\"><pre>" . $anchoredContent . "</pre></div>\n";
    } elseif ($isSearchFallback || $mode === "search" || $isListContent) {
        echo "<div id=\"man-content\">" . $content . "</div>\n";
    } else {
        echo "<pre>" . $content . "</pre>\n";
    }
    echo "</div>";
    echo $tocSidebar;
    }

showFooter($VALIDATOR, $showNav, $mode, $parameter, $section, $enhancedBy);


// +--------------------------------------------------------------------------------+
// | sub functions                                                                  |
// +--------------------------------------------------------------------------------+

//show html header
