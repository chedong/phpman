<?php
function expandNameForFts(string $name): string {
    $expanded = $name;

    // Hyphen expansion: "git-commit" → append "git commit"
    if (str_contains($name, '-')) {
        $expanded .= ' ' . str_replace('-', ' ', $name);
    }

    // Double-colon expansion: "File::Find" → append "File Find"
    // Also lowercase for case-insensitive matching: "File Find" → "file find"
    if (str_contains($name, '::')) {
        $parts = str_replace('::', ' ', $name);
        $expanded .= ' ' . $parts . ' ' . mb_strtolower($parts);
    }

    // Dot expansion: "json.decoder" → append "json decoder"
    if (str_contains($name, '.')) {
        $expanded .= ' ' . str_replace('.', ' ', $name);
    }

    // Append lowercase version for case-insensitive FTS5 prefix matching
    $expanded .= ' ' . mb_strtolower($name);

    return $expanded;
}

/**
 * Build an FTS5 MATCH query from raw user input.
 * Preserves hyphens (-) and colons (:) — critical for command names and Perl modules.
 * Returns empty string if query is empty/invalid.
 */
function buildFtsQuery(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';

    // Replace Chinese/Unicode separator punctuation with spaces so terms split correctly.
    // Handles: enumeration comma (、), Chinese comma (，), Chinese semicolon (；).
    // Only Unicoded-specific punctuation is handled here; ASCII , and ; are
    // already stripped by the [^\p{L}\p{N}\.\-_:] regex below.
    $raw = preg_replace('/[、，；]/u', ' ', $raw);

    // Detect explicit FTS5 operators — validate and pass through
    if (preg_match('/\b(AND|OR|NOT|NEAR)\b/i', $raw)) {
        // Strip dangerous FTS5 syntax: column filters, special commands
        $sanitized = preg_replace('/[{}^!@#]/', '', $raw);
        return $sanitized;
    }

    // Exact phrase (quoted) — sanitize and pass through
    if (preg_match('/^".*"$/', $raw)) {
        $sanitized = preg_replace('/[{}^!@#]/', '', $raw);
        return $sanitized;
    }

    // Default: prefix-match each term with AND
    $terms = preg_split('/\s+/', $raw);
    $parts = [];
    foreach ($terms as $t) {
        $t = trim($t);
        if ($t !== '') {
            // Preserve hyphens, underscores, dots, internal colons — critical for commands.
            // Strip leading/trailing colons to prevent FTS5 column-filter misinterpretation.
            // E.g. "SQL:" → FTS5 reads "SQL" as column name; "Apache::Session" stays intact.
            $t = preg_replace('/[^\p{L}\p{N}\.\-_:]/u', '', $t);
            $t = preg_replace('/^:+|:+$/', '', $t);
            if ($t !== '') {
                $parts[] = '"' . $t . '"*';
            }
        }
    }

    return $parts === [] ? '' : implode(' AND ', $parts);
}

/**
 * Merge search results from multiple sources by (name, section).
 * Same (name, section) from different sources are combined into one entry.
 */
function mergeSearchResults(array $rows): array {
    $merged = [];

    foreach ($rows as $row) {
        $displayName = $row['display_name'] ?? $row['name'];
        $key = $displayName . "\0" . $row['section'];

        if (!isset($merged[$key])) {
            $merged[$key] = [
                'name'        => $displayName,
                'section'     => $row['section'],
                'description' => $row['description'],
                'sources'     => [$row['source']],
                'hits'        => (int)($row['hits'] ?? 0),
            ];
        } else {
            if (!in_array($row['source'], $merged[$key]['sources'])) {
                $merged[$key]['sources'][] = $row['source'];
            }
            $merged[$key]['hits'] += (int)($row['hits'] ?? 0);
        }
    }

    return array_values($merged);
}

/**
 * Search FTS5 index for pydoc or ri entries matching a query.
 * Returns results in the format expected by the search cascade:
 *   - 'json': array of ['name' => ..., 'description' => ..., 'link' => ...]
 *   - 'html': <ul><li>...</li></ul>
 *   - 'markdown': markdown list
 *
 * @param string $parameter  Search query
 * @param string $source     'pydoc' or 'ri'
 * @param string $format     'json', 'html', or 'markdown'
 * @return array|string      Formatted results (array for json, string for html/markdown)
 */
function searchFtsBySource(string $parameter, string $source, string $format) {
    try {
        $db = cacheDb();
        $ftsQuery = buildFtsQuery($parameter);
        if ($ftsQuery === '') return $format === 'json' ? [] : '';

        $stmt = $db->prepare(
            "SELECT s.name, s.section, s.description
             FROM search_fts s
             LEFT JOIN search_index_meta m ON m.section = s.section
                 AND (s.name = m.name OR s.name LIKE m.name || ' %')
             WHERE search_fts MATCH :q AND s.section = :source
             ORDER BY rank LIMIT 100"
        );
        $stmt->bindValue(':q', $ftsQuery, SQLITE3_TEXT);
        $stmt->bindValue(':source', $source, SQLITE3_TEXT);
        $result = $stmt->execute();

        $script_name = ($format === 'markdown' || $format === 'json') ? baseUrl() : scriptName();
        $entries = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $origName = trim(explode(' ', $row['name'])[0]);
            $entries[] = ['name' => $origName, 'description' => $row['description']];
        }

        if (empty($entries)) return $format === 'json' ? [] : '';

        if ($format === 'json') {
            $out = [];
            foreach ($entries as $e) {
                $out[] = [
                    'name' => $e['name'],
                    'description' => $e['description'],
                    'link' => $script_name . '/' . $source . '/' . urlencode($e['name']) . '/json',
                ];
            }
            return $out;
        }

        if ($format === 'html') {
            $out = "<ul>\n";
            foreach ($entries as $e) {
                $out .= '<li><a href="' . $script_name . '/' . $source . '/' . urlencode($e['name']) . '">'
                     . h($e['name']) . '</a>';
                if ($e['description'] !== '') {
                    $out .= ' — ' . h($e['description']);
                }
                $out .= "</li>\n";
            }
            $out .= "</ul>\n";
            return $out;
        }

        // markdown
        $out = '';
        foreach ($entries as $e) {
            $out .= '- [' . h($e['name']) . '](' . $script_name . '/' . $source . '/' . urlencode($e['name']) . '/markdown)';
            if ($e['description'] !== '') {
                $out .= ' — ' . h($e['description']);
            }
            $out .= "\n";
        }
        return $out;
    } catch (\Throwable $e) {
        phpManLog("formatForOutput: " . $e->getMessage());
        return $format === 'json' ? [] : '';
    }
}

/**
 * Execute a single FTS5 query and return merged results.
 *
 * Used by the FTS5 search path (getSearchPage) for AND/OR query strategies.
 */
function searchFtsQuery(SQLite3 $db, string $ftsQuery, string $parameter, string $section, int $limit): array {
    // COALESCE(m.name, s.name) AS display_name: prefer original name from meta
    // (unexpanded), fall back to FTS expanded name. JOIN on section + name
    // prefix match because search_fts.name stores expandNameForFts() output.
    $baseFrom = "FROM search_fts s
             LEFT JOIN search_index_meta m ON m.section = s.section
                 AND (s.name = m.name OR s.name LIKE m.name || ' %')";
    $baseSelect = "SELECT COALESCE(m.name, s.name) AS display_name, s.name, s.section, s.description, m.hits, m.source";
    $baseOrder = "ORDER BY
                 CASE WHEN COALESCE(m.name, s.name) = :exact THEN 0 ELSE 1 END,
                 CASE WHEN COALESCE(m.name, s.name) LIKE :prefix THEN 0 ELSE 1 END,
                 rank,
                 CASE s.section
                     WHEN '1' THEN 1 WHEN '8' THEN 2 WHEN '3' THEN 3
                     WHEN '5' THEN 4 WHEN '7' THEN 5 WHEN '4' THEN 6
                     WHEN '6' THEN 7 WHEN '9' THEN 8 ELSE 9
                 END,
                 m.hits DESC";

    if ($section !== '') {
        $stmt = $db->prepare(
            "$baseSelect
             $baseFrom
             WHERE search_fts MATCH :query AND s.section = :section
             $baseOrder
             LIMIT :limit"
        );
        $stmt->bindValue(':section', $section, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare(
            "$baseSelect
             $baseFrom
             WHERE search_fts MATCH :query
             $baseOrder
             LIMIT :limit"
        );
    }

    $stmt->bindValue(':query', $ftsQuery, SQLITE3_TEXT);
    $stmt->bindValue(':exact', $parameter, SQLITE3_TEXT);
    $stmt->bindValue(':prefix', $parameter . '%', SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $result->finalize();

    return mergeSearchResults($rows);
}

/**
 * Group search results by first character and render HTML with optional
 * alphabet sidebar when result count exceeds threshold.
 *
 * @return array ['html' => string, 'sidebar' => string (may be empty)]
 */

function parseAproposLine (string $line): ?array {
    // BSD: "name [description] (section)"
    if (preg_match('/^(.+)\s+\[\s*(.+?)\s*\]\s+\(((?:\d\w*|n)\w*)\)\s*$/', $line, $m)) {
        $name = trim($m[1]);
        if (!isValidManPageName($name)) return null;
        return [$name, trim($m[3]), trim($m[2])];
    }
    // Linux em-dash: "name (section) — description" (also macOS: "name(section)")
    if (preg_match('/^(.+)\s*\(((?:\d\w*|n)\w*)\)\s+—\s+(.+)$/', $line, $m)) {
        $name = trim($m[1]);
        if (!isValidManPageName($name)) return null;
        return [$name, trim($m[2]), trim($m[3])];
    }
    // Linux dash: "name (section) - description" (also macOS: "name(section)")
    if (preg_match('/^(.+)\s*\(((?:\d\w*|n)\w*)\)\s+-\s+(.+)$/', $line, $m)) {
        $name = trim($m[1]);
        if (!isValidManPageName($name)) return null;
        return [$name, trim($m[2]), trim($m[3])];
    }
    return null;
}

/**
 * Reject single punctuation characters (shell builtins like !, %, ., :, etc.)
 * and troff formatting leakage. Keep '[' (the shell test(1) command alias).
 */
function isValidManPageName(string $name): bool {
    if ($name === '[') return true;
    return (bool)preg_match('/[A-Za-z0-9]/', $name);
}

/**
 * Parse an apropos line that may contain multiple comma-separated commands
 * sharing a single description. BSD section 9 kernel man pages commonly
 * use this format: "name1(9), name2(9), name3 (9) — description".
 *
 * Falls back to parseAproposLine() for standard single-entry lines.
 *
 * @return array<int, array{string, string, string}>  [name, section, description]
 */
function parseAproposLines(string $line): array {
    // Quick check: does this line have comma-separated entries?
    if (!preg_match('/\)\s*,\s*\S/', $line)) {
        $parsed = parseAproposLine($line);
        return $parsed !== null ? [$parsed] : [];
    }

    // Extract shared description from the end: match " — " (em-dash) or " - " (hyphen)
    $desc = '';
    $namePart = $line;
    if (preg_match('/^(.*?)\s+—\s+(.+)$/', $line, $m) ||
        preg_match('/^(.*?)\s+-\s+(.+)$/', $line, $m)) {
        $namePart = rtrim($m[1]);
        $desc = $m[2];
    }

    // Split on "), " or ")," to get individual name(section) parts
    $parts = preg_split('/\),\s*/', $namePart);
    $entries = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        // preg_split consumed the closing paren — add it back
        if (!str_ends_with($part, ')')) {
            $part .= ')';
        }
        // Parse "name (section)" or "name(section)"
        if (preg_match('/^(.+?)\s*\(((?:\d\w*|n)\w*)\)\s*$/', $part, $m)) {
            $name = trim($m[1]);
            if (isValidManPageName($name)) {
                $entries[] = [$name, trim($m[2]), $desc];
            }
        }
    }
    if (empty($entries)) {
        $parsed = parseAproposLine($line);
        return $parsed !== null ? [$parsed] : [];
    }
    return $entries;
}

/**
 * Dynamically index apropos results into the FTS5 search index.
 *
 * Called after every apropos fallback in getSearchPage(). Inserted entries
 * make future searches for the same term fast without a full pre-build.
 * Uses INSERT OR IGNORE so already-indexed entries are safe to re-visit.
 *
 * @param array $lines  apropos output lines
 * @return int          number of newly indexed entries
 */
function indexAproposLines (array $lines): int {
    if (empty($lines)) return 0;

    try {
        $db = cacheDb();

        // Verify FTS5 table exists (SQLite may lack FTS5 support)
        $check = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='search_fts'");
        if (!$check) return 0;

        $count = 0;
        $insertFts = $db->prepare(
            "INSERT OR IGNORE INTO search_fts(name, section, description, body) VALUES(:name, :section, :description, '')"
        );
        $insertMeta = $db->prepare(
            "INSERT OR IGNORE INTO search_index_meta(name, section, source, body_len, hits, last_indexed) VALUES(:name, :section, 'man', 0, 0, datetime('now'))"
        );
        $updateHits = $db->prepare(
            "UPDATE search_index_meta SET hits = hits + 1, last_indexed = datetime('now') WHERE name = :name AND section = :section AND source = 'man'"
        );

        foreach ($lines as $line) {
            $entries = parseAproposLines($line);
            foreach ($entries as [$name, $section, $description]) {

            // Only insert FTS5 entry when meta entry is new — FTS5 lacks UNIQUE
            // so INSERT OR IGNORE always succeeds, causing duplicates. Dedup via meta.
            $insertMeta->bindValue(':name', $name, SQLITE3_TEXT);
            $insertMeta->bindValue(':section', $section, SQLITE3_TEXT);
            $insertMeta->execute();
            $isNew = ($db->changes() === 1);
            $insertMeta->reset();

            if ($isNew) {
                $expandedName = expandNameForFts($name);
                $insertFts->bindValue(':name', $expandedName, SQLITE3_TEXT);
                $insertFts->bindValue(':section', $section, SQLITE3_TEXT);
                $insertFts->bindValue(':description', $description, SQLITE3_TEXT);
                $insertFts->execute();
                $insertFts->reset();
            } else {
                $updateHits->bindValue(':name', $name, SQLITE3_TEXT);
                $updateHits->bindValue(':section', $section, SQLITE3_TEXT);
                $updateHits->execute();
                $updateHits->reset();
            }

            $count++;
            }
        }

        return $count;
    } catch (\Throwable $e) {
        phpManLog("cacheDb stats: " . $e->getMessage());
        return 0;
    }
}

/**
 * Rebuild the FTS5 search index from system man pages.
 * Traverses all man sections, extracts names/descriptions via apropos,
 * and populates search_fts + search_index_meta.
 *
 * Returns a summary string for CLI/HTTP response.
 */

function rebuildSearchIndex(): string {
    $startTime = microtime(true);
    $sections = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'n'];
    $total = 0;
    $errors = 0;
    $output = [];

    try {
        $db = cacheDb();

        // Check if search_fts exists
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='search_fts'");
        if (!$tables->fetchArray()) {
            return "ERROR: FTS5 not available (search_fts table does not exist).\n";
        }

        // v4.9.25: DELETE FROM search_fts instead of DROP+CREATE or RENAME — FTS5
        // shadow tables survive DROP TABLE in some SQLite versions (ghost rows persist
        // across DROP/CREATE cycles). DELETE FROM properly clears all indexed content
        // while keeping the virtual table and its shadow tables intact.

        // 1. Clear all existing FTS content (DELETE works on FTS5 virtual tables)
        try { $db->exec("DELETE FROM search_fts"); }
        catch (\Throwable $ignored) {}
        // Also clean up leftover from a previous interrupted run (if any)
        try { $db->exec("DROP TABLE IF EXISTS search_fts_old"); }
        catch (\Throwable $ignored) {}

        // 3. Create fresh table
        try {
            $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts
                       USING fts5(
                           name, section, description, body,
                           tokenize='unicode61 tokenchars ''-:''',
                           prefix='1,2,3'
                       )");
        } catch (\Throwable $e) {
            // FTS5 not available — nothing to restore since we DROP+CREATE
            phpManLog("rebuildSearchIndex: FTS5 creation failed: " . $e->getMessage());
            return "ERROR: FTS5 not available, cannot create search_fts.\n";
        }

        // 4. Clear meta + page cache (these changes stay even on rollback;
        //    the INSERT transaction below only covers FTS data)
        $db->exec("DELETE FROM search_index_meta");
        $output[] = "Cleared existing search index.\n";

        // Invalidate search result caches only — FTS5 index rebuild makes
        // cached search results stale. Search results are stored with
        // mode='search' (format can be 'html', 'json', 'markdown', etc.).
        // Individual page caches (man/perldoc/info/pydoc/ri) use their own
        // mode values and are NOT search-dependent — must be preserved.
        // Preserve emoji_md/emoji_html — LLM-enhanced content is expensive
        // to regenerate (48+ days) and isn't search-index-dependent.
        $db->exec("DELETE FROM cache WHERE mode = 'search'");
        $output[] = "Cleared search result cache (page caches preserved).\n";

        // 5. Wrap INSERTs in a transaction to prevent WAL bloat
        $db->exec("BEGIN IMMEDIATE");

        $logInterval = 500; // Report progress every N entries
        $lastLogTime = microtime(true);
        $entriesSinceLastLog = 0;

        foreach ($sections as $sec) {
            $cmd = 'apropos -s ' . escapeshellarg($sec) . ' . 2>/dev/null';
            $lines = [];
            exec($cmd, $lines, $exitCode);

            if ($exitCode !== 0 || empty($lines)) {
                continue;
            }
            // macOS may have duplicate man pages from multiple databases (system + Homebrew)
            $lines = array_unique($lines);

            $sectionCount = 0;
            foreach ($lines as $line) {
                $entries = parseAproposLines($line);
                foreach ($entries as [$name, $sectionNum, $description]) {

                // Skip entries with invalid characters
                if ($name === '' || $description === '') {
                    continue;
                }

                $name = substr($name, 0, 200);

                // Body text is intentionally skipped for performance on shared hosts.
                // Extracting body text requires forking `man` per entry (9,000+ processes)
                // which causes `fork: retry: Resource temporarily unavailable`.
                // Name + section + description from apropos is sufficient for search matching.
                $body = '';

                $expandedName = expandNameForFts($name);

                try {
                    // Dedup via search_index_meta before FTS INSERT
                    // (FTS5 lacks UNIQUE constraints; plain INSERT always adds rows)
                    $stmt2 = $db->prepare(
                        "INSERT OR IGNORE INTO search_index_meta (name, section, source, body_len)
                         VALUES (:name, :section, 'man', :body_len)"
                    );
                    $stmt2->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt2->bindValue(':section', $sectionNum, SQLITE3_TEXT);
                    $stmt2->bindValue(':body_len', strlen($body), SQLITE3_INTEGER);
                    $stmt2->execute();
                    $isNew = ($db->changes() === 1);

                    if ($isNew) {
                        $stmt = $db->prepare(
                            "INSERT INTO search_fts (name, section, description, body)
                             VALUES (:name, :section, :description, :body)"
                        );
                        $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
                        $stmt->bindValue(':section', $sectionNum, SQLITE3_TEXT);
                        $stmt->bindValue(':description', $description, SQLITE3_TEXT);
                        $stmt->bindValue(':body', $body, SQLITE3_TEXT);
                        $stmt->execute();
                    }

                    $sectionCount++;
                    $total++;
                    $entriesSinceLastLog++;

                    // Log progress periodically
                    if ($entriesSinceLastLog >= $logInterval) {
                        $now = microtime(true);
                        $batchTime = round(($now - $lastLogTime) * 1000, 0);
                        $rate = $entriesSinceLastLog > 0
                            ? round($entriesSinceLastLog / ($now - $lastLogTime), 0)
                            : 0;
                        $output[] = "  [{$total}] Section {$sec}: +{$entriesSinceLastLog} entries in {$batchTime}ms ({$rate} ent/s)\n";
                        $entriesSinceLastLog = 0;
                        $lastLogTime = $now;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    phpManLog("rebuildFts man: " . $e->getMessage());
                }
                }  // inner foreach (parseAproposLines entries)
            }

            $output[] = "  Section {$sec}: {$sectionCount} entries indexed.\n";

            // Flush remaining progress for this section
            if ($entriesSinceLastLog > 0) {
                $now = microtime(true);
                $batchTime = round(($now - $lastLogTime) * 1000, 0);
                $rate = $entriesSinceLastLog > 0
                    ? round($entriesSinceLastLog / ($now - $lastLogTime), 0)
                    : 0;
                $output[] = "  [{$total}] Section {$sec}: +{$entriesSinceLastLog} entries in {$batchTime}ms ({$rate} ent/s)\n";
                $entriesSinceLastLog = 0;
                $lastLogTime = $now;
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $output[] = "\nMan pages: {$total} entries indexed.\n";
        $output[] = "Man page errors: {$errors}.\n";

        // ================================================================
        // 2. Python 3 modules via pydoc3
        // ================================================================
        $pydocLines = [];
        exec('pydoc3 modules 2>/dev/null', $pydocLines, $pydocExit);
        if ($pydocExit === 0 && !empty($pydocLines)) {
            $pydocCount = 0;
            $pydocErrors = 0;
            $inBody = false;
            foreach ($pydocLines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    $inBody = true;
                    continue;
                }
                if (!$inBody) continue;
                if (preg_match('/^Enter any module name/i', $trimmed)) break;
                $parts = preg_split('/\s{2,}/', $trimmed);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '' || preg_match('/^\s*$/', $part)) continue;
                    if (preg_match('/^(Enter any module|Or, type|modules spam)/i', $part)) continue;
                    if (strpos($part, '_') === 0 && !preg_match('/^__[a-z]+__$/', $part)) continue;
                    $expandedName = expandNameForFts($part);
                    try {
                        // Dedup via meta before FTS INSERT
                        $stmt2 = $db->prepare(
                            "INSERT OR IGNORE INTO search_index_meta (name, section, source, body_len)
                             VALUES (:name, :section, 'pydoc', 0)"
                        );
                        $stmt2->bindValue(':name', $part, SQLITE3_TEXT);
                        $stmt2->bindValue(':section', 'pydoc', SQLITE3_TEXT);
                        $stmt2->execute();
                        $isNew = ($db->changes() === 1);

                        if ($isNew) {
                            $stmt = $db->prepare(
                                "INSERT INTO search_fts (name, section, description, body)
                                 VALUES (:name, :section, :description, '')"
                            );
                            $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
                            $stmt->bindValue(':section', 'pydoc', SQLITE3_TEXT);
                            $stmt->bindValue(':description', 'Python 3 module', SQLITE3_TEXT);
                            $stmt->execute();
                        }

                        $pydocCount++;
                        $total++;
                    } catch (\Throwable $e) {
                        $pydocErrors++;
                        $errors++;
                        phpManLog("rebuildFts pydoc: " . $e->getMessage());
                    }
                }
            }
            $output[] = "  pydoc3: {$pydocCount} modules indexed.\n";
        }

        // ================================================================
        // 3. Ruby classes via ri
        // ================================================================
        $riLines = [];
        exec('ri -l 2>/dev/null', $riLines, $riExit);
        if ($riExit === 0 && !empty($riLines)) {
            $riCount = 0;
            $riErrors = 0;
            foreach ($riLines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') continue;
                $expandedName = expandNameForFts($trimmed);
                try {
                    // Dedup via meta before FTS INSERT
                    $stmt2 = $db->prepare(
                        "INSERT OR IGNORE INTO search_index_meta (name, section, source, body_len)
                         VALUES (:name, :section, 'ri', 0)"
                    );
                    $stmt2->bindValue(':name', $trimmed, SQLITE3_TEXT);
                    $stmt2->bindValue(':section', 'ri', SQLITE3_TEXT);
                    $stmt2->execute();
                    $isNew = ($db->changes() === 1);

                    if ($isNew) {
                        $stmt = $db->prepare(
                            "INSERT INTO search_fts (name, section, description, body)
                             VALUES (:name, :section, :description, '')"
                        );
                        $stmt->bindValue(':name', $expandedName, SQLITE3_TEXT);
                        $stmt->bindValue(':section', 'ri', SQLITE3_TEXT);
                        $stmt->bindValue(':description', 'Ruby class/module', SQLITE3_TEXT);
                        $stmt->execute();
                    }

                    $riCount++;
                    $total++;
                } catch (\Throwable $e) {
                    $riErrors++;
                    $errors++;
                    phpManLog("rebuildFts ri: " . $e->getMessage());
                }
            }
            $output[] = "  ri: {$riCount} classes indexed.\n";
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $output[] = "\nDone. {$total} total entries indexed, {$errors} errors in {$elapsed}s.\n";

        // COMMIT the transaction
        $db->exec("COMMIT");

        // Drop the old table — rebuild succeeded, old data is no longer needed
        try { $db->exec("DROP TABLE IF EXISTS search_fts_old"); }
        catch (\Throwable $ignored) {}

        // Update meta
        $stmtMeta = $db->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES (:key, :value)");
        $stmtMeta->bindValue(':key', 'search_index_count', SQLITE3_TEXT);
        $stmtMeta->bindValue(':value', (string)$total, SQLITE3_TEXT);
        $stmtMeta->execute();
        $stmtMeta->bindValue(':key', 'search_index_updated', SQLITE3_TEXT);
        $stmtMeta->bindValue(':value', gmdate('Y-m-d\TH:i:s\Z'), SQLITE3_TEXT);
        $stmtMeta->execute();

        return implode('', $output);
    } catch (\Throwable $e) {
        phpManLog("rebuildSearchIndex: " . $e->getMessage());
        try { $db->exec("ROLLBACK"); } catch (\Throwable $ignored) {}
        // #180: Restore old table if rebuild failed — searches keep working
        try {
            $hasOld = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='search_fts_old'");
            if ($hasOld) {
                $db->exec("DROP TABLE IF EXISTS search_fts");
                $db->exec("ALTER TABLE search_fts_old RENAME TO search_fts");
            }
        } catch (\Throwable $ignored) {}
        return "ERROR: " . $e->getMessage() . "\n";
    }
}

// +--------------------------------------------------------------------------------+
// | parameter checking and format page output                                      |
// +--------------------------------------------------------------------------------+

// Test mode: define functions only, skip execution
if (defined('PHPMAN_TEST_MODE')) {
    return;
}

// +--------------------------------------------------------------------------------+
// | LLM Enhancement Engine (v4.0 Phase 3)                                           |
// +--------------------------------------------------------------------------------+

/**
 * Call OpenAI-compatible chat completions API.
 * Returns response text or empty string on failure.
 */
