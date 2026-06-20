<?php
function getPerldocPage (string $parameter, string $format = "html"): string {
    $lines = array();
    $width = intval(PHPMAN_WIDTH);
    // pod2text -w controls output width at the POD formatter level (replaces MANWIDTH
    // Pipeline: perldoc -l locates source → head -1 picks first file → pod2text formats.
    // head -1 prevents multi-file concatenation when perldoc -l returns multiple paths.
    // Falls back to raw perldoc if pod2text pipeline fails (e.g. source not found).
    $cmd = "perldoc -l ".escapeshellarg($parameter)." 2>/dev/null | head -1 | tr '\\n' '\\0' | xargs -0 pod2text -w " . escapeshellarg((string)$width) . " 2>/dev/null";  // #24: xargs -0 for space-safe paths
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

function getPerldocIndex (string $format = "html"): string {
    return getSearchPage("perl", "", $format);
}

//get info page index page
