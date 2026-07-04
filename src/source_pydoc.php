<?php
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
    return $rendered['html'];
}

//get ri class index (ri -l)

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
