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
 *
 * The directory list is discovered, not assumed. Hardcoding /usr/share/info
 * made this return [] wherever info lives elsewhere — MacPorts
 * (/opt/local/share/info) and Homebrew (/usr/local/share/info) relocations, or
 * any host that sets INFOPATH. That was not a graceful degradation: the JSON/MCP
 * index drops every entry it cannot resolve, so an empty set turned the whole
 * /info index into `"items": []`, `"count": 0`. (#234)
 */
function getValidInfoFiles(): array {
    $dirs = [];
    // INFOPATH wins when set — it is the same variable the `info` binary reads,
    // so it is the most authoritative answer, and it is colon-separated.
    $infopath = getenv('INFOPATH');
    if ($infopath !== false && $infopath !== '') {
        foreach (explode(PATH_SEPARATOR, $infopath) as $dir) {
            if ($dir !== '') $dirs[] = rtrim($dir, '/');
        }
    }
    // GNU info's compiled-in defaults (texinfo: infodir + INFOPATH).
    $dirs[] = '/usr/local/share/info';
    $dirs[] = '/usr/share/info';
    // Ask `info` itself where its dir file is — this is what covers relocated
    // installs whose prefix is in neither default above. It reports a *file*
    // (…/dir, or the first .info when there is no dir), hence dirname(); and it
    // is validated with is_file() because some builds decorate the output.
    $dirFile = trim((string)@shell_exec('info --where dir 2>/dev/null'));
    if ($dirFile !== '' && is_file($dirFile)) $dirs[] = dirname($dirFile);

    $valid = [];
    foreach (array_unique($dirs) as $dir) {
        foreach (glob($dir . '/*.info*') ?: [] as $file) {
            // "coreutils.info.gz" / "automake-1.16.info-1.gz" → "coreutils" / "automake-1.16"
            $name = preg_replace('/\.info.*$/', '', basename($file));
            if ($name !== '' && $name !== null) {
                $valid[$name] = true;
            }
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
    // Filter only if discovery found something. An empty set means we failed to
    // locate the info directory, not that every entry is dead — and the JSON/MCP
    // branch drops unresolvable entries outright, so filtering on an empty set
    // empties the whole index. Falling back to the unfiltered behaviour keeps
    // the links, which is what the code did before discovery existed. (#234)
    $filtering = !empty($validFiles);

    if ($format === "markdown") {
        // Hoisted out of the per-line loop: both capture only loop-invariant values.
        $linkFileAndNode = function ($m) use ($filtering, $validFiles, $script_name) {
            if ($filtering && !isset($validFiles[$m[1]])) return $m[0];
            return '([' . $m[1] . '](' . $script_name . '/info/' . $m[1] . '/markdown))[' . $m[2] . '](' . $script_name . '/info/' . $m[2] . '/markdown)';
        };
        $linkFileOnly = function ($m) use ($filtering, $validFiles, $script_name) {
            if ($filtering && !isset($validFiles[$m[1]])) return $m[0];
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
                if ($filtering && !isset($validFiles[$m[1]])) continue;
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
                if ($filtering && !isset($validFiles[$m[1]])) continue;
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
        function ($m) use ($filtering, $validFiles, $script_name) {
            $file = ($m[1] !== '') ? $m[1] : $m[3];
            $node = $m[2] ?? '';
            if ($filtering && !isset($validFiles[$file])) return $m[0];
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
