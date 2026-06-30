<?php
function getManPage (string $parameter, string $section = "", string $format = "html"): string {
    $lines = array();
    // Save and restore env vars to prevent leaks across requests (PHP-FPM/mod_php)
    $oldManroffopt = getenv('MANROFFOPT');
    $oldManwidth = getenv('MANWIDTH');
    try {
        $width = intval(PHPMAN_WIDTH);
        putenv("MANROFFOPT=-rLL=" . $width . "n");
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
            putenv("MANWIDTH=" . $width);
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
