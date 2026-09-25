<?php
function getInfoPage (string $parameter, string $format = "html"): string {
    $lines = array();
    $exitCode = 0;
    exec("info ".escapeshellarg($parameter), $lines, $exitCode);  // #45: check return code
    if ($exitCode !== 0 || empty($lines)) {
        return "";
    }
    if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "info");    if ($format === "json" || $format === "mcp") return formatPageOutput($lines, $parameter, "", "info", $format);
    return formatManPerlDoc($lines, "info");
}

/**
 * Build a set of info file basenames that actually exist on disk.
 *
 * The `info` dir menu (from /usr/share/info/dir) lists every package that was
 * ever installed, but on a shared host stale entries survive uninstall — the
 * backing .info file is gone while the menu entry remains. Those produce dead
 * links (empty page → noindex). Filter them out by intersecting the menu with
 * the real files on disk.
 */
function getValidInfoFiles(): array {
    $valid = [];
    foreach (glob('/usr/share/info/*.info*') ?: [] as $file) {
        // "coreutils.info.gz" / "automake-1.16.info-1.gz" → "coreutils" / "automake-1.16"
        $name = preg_replace('/\.info.*$/', '', basename($file));
        if ($name !== '' && $name !== null) {
            $valid[$name] = true;
        }
    }
    return $valid;
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
    $validFiles = getValidInfoFiles();

    if ($format === "markdown") {
        // Hoisted out of the per-line loop: both capture only loop-invariant values.
        $linkFileAndNode = function ($m) use ($validFiles, $script_name) {
            if (!isset($validFiles[$m[1]])) return $m[0];
            return '([' . $m[1] . '](' . $script_name . '/info/' . $m[1] . '/markdown))[' . $m[2] . '](' . $script_name . '/info/' . $m[2] . '/markdown)';
        };
        $linkFileOnly = function ($m) use ($validFiles, $script_name) {
            if (!isset($validFiles[$m[1]])) return $m[0];
            return '([' . $m[1] . '](' . $script_name . '/info/' . $m[1] . '/markdown))';
        };
        $output = "";
        foreach ($lines as $line) {
            // Two-part "(file)node" — link file and node only if file exists
            $line = preg_replace_callback("/\(([a-z0-9_\-]+)\)([a-z0-9_\+]+)/", $linkFileAndNode, $line);
            // One-part "(name)" — link only if the info file exists
            $line = preg_replace_callback("/\(([a-z0-9_\-]+)\)/", $linkFileOnly, $line);
            $output .= $line . "\n";
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
            // Parse "(group)command" or "(command)" format — skip if info file missing
            if (preg_match('/\(([a-z0-9_\-]+)\)([a-z0-9_\+]+)/', $line, $m)) {
                if (!isset($validFiles[$m[1]])) continue;
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
                if (!isset($validFiles[$m[1]])) continue;
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
            // See getManIndex(): the index has no command segment, so the format
            // has to travel as a query param — "/info/json" is a page lookup.
            "url" => $script_name . "?mode=info&format=json",
            "generated" => gmdate("Y-m-d\TH:i:s\Z"),
            "items" => $items,
            "count" => count($items),
        );
        if ($format === "mcp") return formatMcpEnvelope($jsonData);
        return formatForOutput(json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $format);
    }

    $output = "";
    foreach ($lines as $line) {
        // Step 1: Escape all remaining HTML special chars via h()
        $output .= h($line) . " \n";
    }
    // Step 2: Restore escaped &<> and apply info link transformation on escaped text.
    // Skip entries whose info file is missing (stale dir menu → dead link → noindex).
    $output = preg_replace_callback(
        "/\(([a-z0-9_\-]+)\)([a-z0-9_\+]+)|\(([a-z0-9_\-]+)\)/",
        function ($m) use ($validFiles, $script_name) {
            $file = ($m[1] !== '') ? $m[1] : $m[3];
            $node = $m[2] ?? '';
            if (!isset($validFiles[$file])) return $m[0];
            $fileLink = '<a href="' . h($script_name . '/info/' . $file) . '">' . $file . '</a>';
            if ($node !== '') {
                return '(' . $fileLink . ')<a href="' . h($script_name . '/info/' . $node) . '">' . $node . '</a>';
            }
            return '(' . $fileLink . ')';
        },
        $output
    );
    return $output;
}

//convert man perldoc output to html
