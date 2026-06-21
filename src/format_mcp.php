<?php
function formatSearchResults(array $results, string $parameter, string $section, string $format): string {
    $scriptName = ($format === 'markdown' || $format === 'json' || $format === 'mcp')
        ? baseUrl() : scriptName();

    // JSON / MCP output
    if ($format === 'json' || $format === 'mcp') {
        $formatted = [];
        foreach ($results as $r) {
            $is_perl = str_contains($r['name'], '::');
            $link_mode = $is_perl ? 'perldoc' : 'man';
            $formatted[] = [
                'name'        => $r['name'],
                'description' => $r['description'] ?? '',
                'section'     => $r['section'],
                'sources'     => $r['sources'] ?? [],
                'link' => $scriptName . '/' . $link_mode . '/' . urlencode($r['name']) . '/' . urlencode($r['section']) . '/json',
            ];
        }
        $jsonData = [
            'name'       => 'search ' . $parameter . ($section !== '' ? " (section {$section})" : ''),
            'mode'       => 'search',
            'parameter'  => $parameter,
            'section'    => $section,
            'url'        => $scriptName . '/search/' . urlencode($parameter) . ($section !== '' ? '/' . urlencode($section) : '') . '/json',
            'generated'  => gmdate('Y-m-d\TH:i:s\Z'),
            'query'      => $parameter,
            'results'    => $formatted,
            'count'      => count($formatted),
            'engine'     => 'fts5',
        ];
        return formatForOutput(json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $format);
    }

    // HTML output
    if ($format === 'html') {
        $rendered = renderGroupedResults($results, $scriptName);
        return $rendered['html'];
    }

    // Markdown output
    $output = '';
    foreach ($results as $r) {
        $is_perl = str_contains($r['name'], '::');
        $link_mode = $is_perl ? 'perldoc' : 'man';
        $desc = $r['description'] ?? '';
        $sources = !empty($r['sources']) ? ' [' . implode(', ', array_map('h', $r['sources'])) . ']' : '';
        $output .= '- [' . $r['name'] . '(' . $r['section'] . ')](' . $scriptName . '/' . $link_mode . '/' . urlencode($r['name']) . '/' . urlencode($r['section']) . '/' . $format . ')';
        if ($desc !== '') {
            $output .= ' — ' . $desc;
        }
        $output .= $sources . "\n";
    }
    return $output;
}

/**
 * Parse an apropos-formatted output line into [name, section, description].
 *
 * Handles both BSD format (name [description] (section)) and
 * Linux format (name (section) — description).
 *
 * @return array|null  [name, section, description] or null if unparseable
 */

function formatForOutput (string $jsonStr, string $format): string {
    if ($format === "mcp") {
        $data = json_decode($jsonStr, true);
        if ($data === null) {
            // Fallback: wrap raw string in text content
            $result = ["content" => [["type" => "text", "text" => $jsonStr]]];
            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $markdown = formatMcpMarkdown($data);
        $structured = formatMcpStructured($data);
        $result = [
            "content" => [["type" => "text", "text" => $markdown]],
            "structuredContent" => $structured
        ];
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    return $jsonStr;
}

/**
 * Convert man/perldoc JSON data to agent-friendly markdown for MCP output.
 * Returns a scannable section outline + flags table + full content.
 */
function formatMcpMarkdown (array $data): string {
    $mode = $data["mode"] ?? "man";

    // ── Search results: markdown list ──
    if ($mode === "search") {
        $query = $data["parameter"] ?? "";
        $count = $data["count"] ?? 0;
        $out = "# Search: {$query} ({$count} results)\n\n";
        foreach ($data["results"] ?? [] as $r) {
            $name = $r["name"] ?? "";
            $desc = $r["description"] ?? "";
            $link = $r["link"] ?? "";
            $out .= "- [{$name}]({$link}) — {$desc}\n";
        }
        if (!empty($data["pydoc_results"])) {
            $out .= "\n## Python 3 (pydoc3)\n\n";
            foreach ($data["pydoc_results"] as $r) {
                $out .= "- [{$r["name"]}]({$r["link"]}) — {$r["description"]}\n";
            }
        }
        if (!empty($data["ri_results"])) {
            $out .= "\n## Ruby (ri)\n\n";
            foreach ($data["ri_results"] as $r) {
                $out .= "- [{$r["name"]}]({$r["link"]}) — {$r["description"]}\n";
            }
        }
        return $out;
    }

    $param = $data["parameter"] ?? "";
    $section = $data["section"] ?? "";
    $label = $param;
    if ($section !== "" && $section !== "-f" && $section !== "-q") {
        $label .= "({$section})";
    }

    $out = "# {$label} ({$mode})\n\n";

    // NAME / Summary
    if (!empty($data["summary"])) {
        $out .= "## NAME\n\n{$data["summary"]}\n\n";
    }

    // SYNOPSIS
    if (!empty($data["synopsis"])) {
        $out .= "## SYNOPSIS\n\n{$data["synopsis"]}\n\n";
    }

    // DESCRIPTION — first paragraph only (#88)
    $sections = $data["sections"] ?? [];
    foreach ($sections as $name => $sec) {
        if (strtoupper($name) === "DESCRIPTION") {
            $content = trim($sec["content"] ?? "");
            // Extract first paragraph (up to first blank line)
            $paragraphs = preg_split('/\n\s*\n/', $content, 2);
            $firstPara = trim($paragraphs[0] ?? "");
            if ($firstPara !== "") {
                $out .= "## DESCRIPTION\n\n{$firstPara}\n\n";
            }
            break;
        }
    }

    // TLDR (only for man section 1)
    $tldr = fetchOfficialTldr($param, $mode, $section);
    if (!empty($tldr)) {
        $out .= "## TLDR\n\n";
        if (!empty($tldr["description"])) {
            $out .= "> {$tldr["description"]}\n\n";
        }
        foreach (array_slice($tldr["examples"] ?? [], 0, 8) as $ex) {
            $out .= "- {$ex["description"]}:\n  `{$ex["command"]}`\n";
        }
        $src = ($tldr["source"] ?? "") === "cheatsh" ? "cheat.sh" : "tldr-pages";
        $out .= "\n*Source: {$src}*\n\n";
    }

    // Section outline — navigation guide for agent
    if (count($sections) > 0) {
        $out .= "## Sections\n\n";
        foreach ($sections as $name => $sec) {
            $subCount = count($sec["subsections"] ?? []);
            $extra = $subCount > 0 ? " ({$subCount} subsections)" : "";
            $out .= "- **{$name}**{$extra}\n";
        }
        $out .= "\nUse structuredContent.sections for detailed options, examples, and full documentation.\n";
    }

    return $out;
}

/**
 * Extract structured data for MCP structuredContent field.
 * Gives agents programmatic access to flags, examples, section outlines.
 */
function formatMcpStructured (array $data): array {
    // ── Search results: return results arrays directly ──
    if (($data["mode"] ?? "") === "search") {
        $out = [
            "mode" => "search",
            "query" => $data["parameter"] ?? "",
            "section" => $data["section"] ?? "",
            "count" => $data["count"] ?? 0,
            "results" => $data["results"] ?? [],
        ];
        if (!empty($data["pydoc_results"])) $out["pydoc_results"] = $data["pydoc_results"];
        if (!empty($data["ri_results"])) $out["ri_results"] = $data["ri_results"];
        return $out;
    }

    $outline = [];
    foreach ($data["sections"] ?? [] as $name => $sec) {
        $item = [
            "name" => $name,
            "lines" => substr_count($sec["content"] ?? "", "\n") + 1,
        ];
        $item["subsections"] = [];
        foreach ($sec["subsections"] ?? [] as $sub) {
            $subItem = [
                "name" => $sub["name"] ?? "",
                "lines" => substr_count($sub["content"] ?? "", "\n") + 1,
            ];
            if (!empty($sub["flag"])) $subItem["flag"] = $sub["flag"];
            if (!empty($sub["long"])) $subItem["long"] = $sub["long"];
            if (!empty($sub["arg"])) $subItem["arg"] = $sub["arg"];
            $item["subsections"][] = $subItem;
        }
        $outline[] = $item;
    }

    // Collect all flags from all sections (not just OPTIONS)
    $allFlags = $data["flags"] ?? [];
    if (empty($allFlags)) {
        // #44: use shared extractFlagsFromSections()
        $allFlags = extractFlagsFromSections($data);
    }

    // v2.2: Fetch TLDR for agent consumption (only for man section 1)
    $param = $data["parameter"] ?? "";
    $tldrMode = $data["mode"] ?? "man";
    $tldrSection = $data["section"] ?? "";
    $tldrData = $param !== "" ? fetchOfficialTldr($param, $tldrMode, $tldrSection) : [];
    $tldrSummary = !empty($tldrData) ? ($tldrData["description"] ?? null) : null;
    $tldrExamples = !empty($tldrData) ? array_slice($tldrData["examples"] ?? [], 0, 12) : [];
    $tldrSource = !empty($tldrData) ? ($tldrData["source"] ?? null) : null;

    return [
        "command" => $data["parameter"] ?? "",
        "section" => $data["section"] ?? "",
        "mode" => $data["mode"] ?? "man",
        "summary" => $data["summary"] ?? null,
        "synopsis" => $data["synopsis"] ?? null,
        "tldr_summary" => $tldrSummary,
        "tldr_examples" => $tldrExamples,
        "tldr_source" => $tldrSource,
        "flags" => $allFlags,
        "examples" => $data["examples"] ?? [],
        "see_also" => $data["see_also"] ?? [],
        "section_outline" => $outline,
        "sections" => $data["sections"] ?? [],  // full section content for agent consumption
    ];
}

/**
 * Convert man page structured JSON to TLDR-style cheatsheet markdown.
 *
 * Auto-generates from man pages: extracts description, key examples,
 * and common flags. Follows tldr-pages format conventions:
 * - Title matches command name
 * - Description in > blockquote
 * - Examples as bullet list with code blocks
 * - Uses {{placeholder}} for user-supplied values
 * - 5-8 examples max
 * - --help and --version at the end
 */
// ────────────────────────────────────────────
//  v2.2: Official tldr-pages + cheat.sh fetcher
// ────────────────────────────────────────────

/**
 * Fetch TLDR from official tldr-pages (primary) or cheat.sh (fallback).
 * Returns structured TLDR data or empty array on failure.
 */
