<?php
function fetchOfficialTldr(string $command, string $mode = "man", string $section = ""): array {
    // Lowercase for cache key and tldr-pages / cheat.sh lookup
    $command = strtolower($command);
    $cacheKey = $command;

    // Static cache first — avoids repeated lookups within a single request (#87)
    static $cache = [];
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    // Only fetch TLDR for man section 1 commands — tldr-pages only covers
    // common CLI tools, not Perl modules (perldoc), info nodes, or man pages
    // in sections 2-8 (syscalls, library functions, file formats, etc.)
    if ($mode !== "man") return [];
    if ($section !== "" && !preg_match('/^1[a-z]*$/', $section)) return [];
    // Skip commands with :: (Perl/Ruby module names) — tldr-pages never covers these
    if (strpos($command, '::') !== false) return [];
    // Skip commands with non-simple names (dots, special chars beyond [-_.])
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $command)) return [];

    // SQLite persistent cache — 7-day TTL (#80)
    try {
        $db = cacheDb();
        $stmt = $db->prepare(
            "SELECT content FROM tldr_cache
             WHERE command = :cmd
               AND (strftime('%s','now') - fetched_at) < 604800"
        );
        $stmt->bindValue(':cmd', $command, SQLITE3_TEXT);
        $cached = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($cached) {
            $result = json_decode($cached['content'], true);
            if (is_array($result)) {
                // Negative cache: return empty array for previously-missing entries
                if (($result['source'] ?? '') === 'not_found') {
                    $cache[$cacheKey] = [];
                    return [];
                }
                $cache[$cacheKey] = $result;
                return $result;
            }
        }
    } catch (\Throwable $e) {
        phpManLog("TLDR cache read: " . $e->getMessage());
    }

    $result = fetchTldrPages($command);
    if (empty($result)) $result = fetchCheatShTldr($command);
    $cache[$cacheKey] = $result;

    // Persist to SQLite cache — 7-day TTL for future requests
    // Cache both successful and empty/missing results (negative cache, #80)
    try {
        $db = cacheDb();
        if (empty($result)) {
            $result = ['source' => 'not_found', 'examples' => []];
        }
        // INSERT OR REPLACE is DELETE+INSERT: refreshes cached TLDR for this command
        $stmt = $db->prepare(
            "INSERT OR REPLACE INTO tldr_cache (command, source, content, fetched_at)
             VALUES (:cmd, :source, :content, strftime('%s','now'))"
        );
        $stmt->bindValue(':cmd', $command, SQLITE3_TEXT);
        $stmt->bindValue(':source', $result['source'] ?? 'unknown', SQLITE3_TEXT);
        $stmt->bindValue(':content', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
        $stmt->execute();
    } catch (\Throwable $e) {
        phpManLog("TLDR cache write: " . $e->getMessage());
    }

    return $result;
}

/**
 * Fetch from tldr-pages GitHub raw.
 * Lookup order: common/ → linux/ → osx/
 */
function fetchTldrPages(string $command): array {
    $pages = ["common", "linux", "osx"];
    foreach ($pages as $page) {
        $url = "https://raw.githubusercontent.com/tldr-pages/tldr/main/pages/{$page}/" . urlencode($command) . ".md";
        $ctx = stream_context_create([
            "http" => [
                "timeout" => 5,
                "header" => "User-Agent: phpMan/" . GIT_DESCRIBE . "\r\n",
            ],
        ]);
        $md = @file_get_contents($url, false, $ctx);
        if ($md === false) {
            // 404 is normal — most man pages don't have tldr-pages entries.
            // No need to log; the static cache above prevents repeated lookups.
            continue;
        }
        if (strlen($md) > 20) {
            return parseTldrMarkdown($md, $command, "official");
        }
    }
    return [];
}

/**
 * Fetch from cheat.sh as fallback.
 */
function fetchCheatShTldr(string $command): array {
    $url = "https://cheat.sh/" . urlencode($command) . "?T";
    $ctx = stream_context_create([
        "http" => [
            "timeout" => 5,
            "header" => "User-Agent: phpMan/" . (defined('PHPMAN_VERSION') ? PHPMAN_VERSION : (defined('GIT_DESCRIBE') ? ltrim(GIT_DESCRIBE, 'v') : 'unknown')) . "\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        // cheat.sh miss is normal — no need to log.
        return [];
    }
    if (strlen($raw) < 20) return [];
    return parseCheatShOutput($raw, $command);
}

/**
 * Parse tldr-pages markdown format to structured array.
 */
function parseTldrMarkdown(string $md, string $command, string $source): array {
    $lines = explode("\n", $md);
    $description = "";
    $examples = [];
    $currentDesc = "";
    $inDescription = true;
    $collectingExample = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip title line (# command) and empty lines
        if ($trimmed === "" || $trimmed === "# " . $command || preg_match('/^# /', $trimmed)) continue;

        // Description: lines starting with >
        if (preg_match('/^>\s*(.*)/', $trimmed, $m)) {
            $text = trim($m[1]);
            if (stripos($text, "More information") === 0) continue;
            if ($text === "") continue;
            if ($description === "") {
                $description = $text;
            }
            $inDescription = true;
            continue;
        }

        // Example: "- Description:" followed by `command`
        if (preg_match('/^-\s*(.+):\s*$/', $trimmed, $m)) {
            $currentDesc = trim($m[1]);
            $collectingExample = true;
            $inDescription = false;
            continue;
        }

        // Backtick-wrapped command
        if ($collectingExample && preg_match('/^`(.+)`$/', $trimmed, $m)) {
            $cmd = trim($m[1]);
            // Clean up tldr-pages syntax: {{...}} → keep, [-X|--long] → --long
            $cmd = preg_replace('/\{\{[-\[\]\|]/', '{{', $cmd);
            $cmd = preg_replace('/[-\[\]\|]\}\}/', '}}', $cmd);
            $examples[] = [
                "description" => $currentDesc,
                "command" => $cmd,
            ];
            $collectingExample = false;
            $currentDesc = "";
            continue;
        }

        // Bare command line (non-backtick example)
        if ($collectingExample && strlen($trimmed) > 1 && $trimmed[0] !== "#" && $trimmed[0] !== ">") {
            $cmd = preg_replace('/^`|`$/', '', $trimmed);
            $examples[] = [
                "description" => $currentDesc,
                "command" => $cmd,
            ];
            $collectingExample = false;
            $currentDesc = "";
            continue;
        }
    }

    if (empty($examples)) return [];

    return [
        "source" => $source,
        "description" => $description,
        "examples" => array_slice($examples, 0, 16),
    ];
}

/**
 * Parse cheat.sh plain-text output (?T flag).
 */
function parseCheatShOutput(string $raw, string $command): array {
    $lines = explode("\n", $raw);
    $description = "";
    $examples = [];
    $currentDesc = "";

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip source header, blank lines
        if ($trimmed === "" || preg_match('/^#\[.+\]/', $trimmed)) continue;

        // Description line: # text (but not just #)
        if (preg_match('/^#\s+(.+)\.?\s*$/', $trimmed, $m)) {
            $text = trim($m[1]);
            if ($text === "" || stripos($text, "see also") === 0) continue;
            // First non-empty # line is the description
            if ($description === "" && !preg_match('/^[a-z]/i', $text)) {
                $description = rtrim($text, ".");
                continue;
            }
            // Subsequent # lines are example descriptions
            $currentDesc = rtrim($text, ".");
            continue;
        }

        // Command line
        if ($currentDesc !== "" && strlen($trimmed) > 2) {
            // Replace concrete args with placeholders
            $cmd = preg_replace('/ (\/[\w\/.-]+)/', ' {{path}}', $trimmed);
            $cmd = preg_replace('/ ([\w.-]+\.(txt|gz|tgz|tar|zip|json|xml|pem))/i', ' {{file}}', $cmd);
            $examples[] = [
                "description" => $currentDesc,
                "command" => $cmd,
            ];
            $currentDesc = "";
        }
    }

    if (empty($examples)) return [];

    return [
        "source" => "cheatsh",
        "description" => $description,
        "examples" => array_slice($examples, 0, 16),
    ];
}


/**
 * Format structured TLDR data (from official tldr-pages or cheat.sh) to markdown.
 * v2.2: Used when official data sources are available.
 */
function formatTldrFromStructured(array $tldr, string $command): string {
    $base = baseUrl();
    $canonical = "{$base}/man/" . urlencode($command);
    $out = "# {$command}\n\n";
    if (!empty($tldr["description"])) {
        $out .= "> {$tldr["description"]}.\n";
    }
    $source = $tldr["source"] ?? "";
    $sourceLabel = $source === "cheatsh" ? "cheat.sh" : "tldr-pages";
    $out .= "> More information: {$canonical}  \n";
    $out .= "> Source: {$sourceLabel}\n\n";
    foreach ($tldr["examples"] as $ex) {
        $desc = $ex["description"] ?? "";
        $cmd = $ex["command"] ?? "";
        if ($desc !== "" && $cmd !== "") {
            $out .= "- {$desc}:\n  `{$cmd}`\n";
        }
    }
    return $out;
}

function formatTldr (?array $data): string {
    if ($data === null) return "";

    $command = $data["parameter"] ?? "";
    $summary = $data["summary"] ?? "";
    $synopsis = $data["synopsis"] ?? "";
    $flags = $data["flags"] ?? [];
    $examples = $data["examples"] ?? [];

    // Fallback: extract flags from all sections if top-level is empty
    // #44: use shared extractFlagsFromSections()
    if (empty($flags)) {
        $flags = extractFlagsFromSections($data);
    }

    $mode = $data["mode"] ?? "man";
    $section = $data["section"] ?? "";
    $base = baseUrl();
    $canonical = "{$base}/{$mode}/" . urlencode($command);
    if ($section !== "" && $section !== "-f" && $section !== "-q") {
        $canonical .= "/" . urlencode($section);
    }

    // Title
    $out = "# {$command}\n\n";

    // Description from NAME section
    if ($summary !== "") {
        $out .= "> {$summary}.\n";
    } elseif ($synopsis !== "") {
        $out .= "> {$synopsis}\n";
    }
    $out .= "> More information: {$canonical}.\n\n";

    $exampleCount = 0;
    $maxExamples = PHPMAN_TLDR_MAX_EXAMPLES;

    // If man page has explicit EXAMPLES section, use those first
    if (!empty($examples)) {
        foreach ($examples as $ex) {
            if ($exampleCount >= $maxExamples - 2) break;
            $ex = trim($ex);
            if ($ex === "" || strlen($ex) < 3) continue;
            // Skip lines that are section headers or descriptions
            if (preg_match('/^[A-Z][A-Z\s]{5,}$/', $ex)) continue;
            // Wrap in backticks if it looks like a command
            $cleaned = preg_replace('/\s+/', ' ', $ex);
            $out .= "- Example:\n  `{$cleaned}`\n";
            $exampleCount++;
        }
    }

    // Generate examples from flag descriptions

    $usedFlags = []; // track which flags we've already generated examples for
    foreach ($flags as $f) {
        if ($exampleCount >= $maxExamples - 2) break;
        $shortFlag = $f["flag"] ?? "";
        $longFlag = $f["long"] ?? "";
        $desc = $f["description"] ?? "";

        // Skip flags we've already shown
        $flagKey = $shortFlag ?: $longFlag;
        if ($flagKey === "" || isset($usedFlags[$flagKey])) continue;
        $usedFlags[$flagKey] = true;

        // Skip help/version flags (we handle them at the end)
        if ($shortFlag === "-h" && !$longFlag) continue;
        if ($longFlag === "--help") continue;
        if ($shortFlag === "-V" && !$longFlag) continue;
        if ($longFlag === "--version") continue;

        // Build the TLDR example
        $flagStr = $longFlag ?: $shortFlag;
        $argStr = "";
        if (!empty($f["arg"])) {
            $argStr = " " . str_replace(["<", ">", "[", "]"], ["{{", "}}", "{{", "}}"], $f["arg"]);
        }

        // Determine description quality — use actual man page descriptions only
        $shortDesc = "";
        if ($desc !== "") {
            // Filter out low-quality descriptions (sentence fragments, too long)
            if (strlen($desc) > 80) continue; // skip multi-line prose
            if (preg_match('/^[a-z].*\.\.\.$/', $desc)) continue; // skip fragments ending with ...
            if (preg_match('/^[);,.]/', $desc)) continue; // skip continuation fragments
            $shortDesc = $desc;
        } else {
            continue; // no description at all
        }

        $out .= "- {$shortDesc}:\n  `{$command} {$flagStr}{$argStr}`\n";
        $exampleCount++;
    }

    // Always add help and version as last two
    $hasHelpFlag = false;
    $hasVersionFlag = false;
    foreach ($flags as $f) {
        if (($f["flag"] ?? "") === "-h" || ($f["long"] ?? "") === "--help") $hasHelpFlag = true;
        if (($f["flag"] ?? "") === "-V" || ($f["long"] ?? "") === "--version") $hasVersionFlag = true;
    }

    if ($exampleCount < $maxExamples) {
        $helpFlag = $hasHelpFlag ? "{{[-h|--help]}}" : "--help";
        $out .= "- Display help:\n  `{$command} {$helpFlag}`\n";
        $exampleCount++;
    }

    if ($exampleCount < $maxExamples) {
        $versionFlag = $hasVersionFlag ? "{{[-V|--version]}}" : "--version";
        $out .= "- Display version:\n  `{$command} {$versionFlag}`\n";
    }

    return $out;
}
