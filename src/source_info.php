<?php
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

    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = $lines[$i];
        // Step 1: Escape all remaining HTML special chars via h()
        $line = h($line);
        $output .= $line . " \n";
    }
    // Step 2: Restore escaped &<> and apply info link transformation on escaped text
    // h() converts & → &amp;, < → &lt;, > → &gt;, " → &quot;
    // After h(), the original link patterns need adjustment:
    // '(' becomes '(' in escaped output, etc. Parentheses are not escaped by h().
    $linkPatterns = array(
        "/\\(([a-z0-9_\\-]+)\\)([a-z0-9_\\+]+)/",
        "/\\(([a-z0-9_\\-]+)\\)/"
    );
    $linkReplace = array(
        '(<a href="'.h($script_name.'/info/$1').'">$1</a>)<a href="'.h($script_name.'/info/$2').'">$2</a>',
        '(<a href="'.h($script_name.'/info/$1').'">$1</a>)'
    );
    $output = preg_replace($linkPatterns, $linkReplace, $output);
    return $output;
}

//convert man perldoc output to html
