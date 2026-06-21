<?php
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
                $sourceTag = !empty($sources) ? ' <span class="sources">[' . implode(', ', array_map('h', $sources)) . ']</span>' : '';
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
            $sourceTag = !empty($sources) ? ' <span class="sources">[' . implode(', ', array_map('h', $sources)) . ']</span>' : '';
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
                if ($db === null) {
                    // Cache DB unavailable — skip FTS5, fall through to apropos
                    throw new \RuntimeException("FTS5 unavailable: cache DB not writable");
                }
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
            } catch (\Throwable $e) {
                phpManLog("FTS5 search fallback: " . $e->getMessage());
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
        // macOS may return duplicates from multiple man databases (system + Homebrew)
        $lines = array_unique($lines);

        // Warm up FTS5 cache from apropos results (keyword searches only)
        if (!empty($lines) && !$sectionOnly) {
            indexAproposLines($lines);
        }
    }

    // json / mcp output
    if ($format === "json" || $format === "mcp") {
        // Collect pydoc/ri results from FTS5 (same for json and mcp)
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
        // Return plain JSON for both — MCP wrapping done ONCE by caller (web dispatch / executeCliSearch)
        $jsonStr = json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($format === "mcp") {
            return $jsonStr;  // plain JSON, caller wraps with formatForOutput
        }
        return $jsonStr;  // json format: plain JSON
    }

    // determine link mode: perl modules (section 3pm or name with ::) use perldoc, others use man
    // HTML: parse apropos lines into structured array for renderGroupedResults()
    if ($format === "html") {
        $parsed = [];
        foreach ($lines as $line) {
            $entries = parseAproposLines($line);
            foreach ($entries as [$name, $section_num, $description]) {
            $is_perl = str_contains($name, '::');
            $parsed[] = [
                'name'        => $name,
                'section'     => $section_num,
                'description' => $description,
                'sources'     => $is_perl ? ['perldoc'] : ['man'],
            ];
            }
        }
        $rendered = renderGroupedResults($parsed, $script_name);
        if ($rendered['sidebar'] !== '') {
        }
        return $rendered['html'];
    }

    // Markdown: pure markdown list (no HTML wrappers) — each entry as "- [name(section)](url) — description"
    $output = "";
    foreach ($lines as $line) {
        $entries = parseAproposLines($line);
        foreach ($entries as [$name, $section_num, $description]) {
            $is_perl = str_contains($name, '::');
            $link_mode = $is_perl ? "perldoc" : "man";
            $link = "{$script_name}/{$link_mode}/" . urlencode($name) . "/" . urlencode($section_num) . "/markdown";
            $output .= "- [{$name}({$section_num})]({$link})";
            if ($description !== '') {
                $descText = trim(html_entity_decode(strip_tags($description), ENT_QUOTES, 'UTF-8'));
                if ($descText !== '') $output .= " — {$descText}";
            }
            $output .= "\n";
        }
    }
    return $output;
}

//link to man page list by searching section tag
