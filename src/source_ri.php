<?php
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
