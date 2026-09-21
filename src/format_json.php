<?php
/**
 * Build the JSON intermediate representation for a page as an array.
 *
 * $lines is taken by reference and *released* as soon as it has been folded
 * into $sections. That matters for very large pages: `info py` is ~430k lines,
 * and keeping the line buffer alive through the build pushed the request past
 * PHP's 128MB memory_limit.
 */
function buildJsonData (array &$lines, string $parameter, string $section = "", string $mode = "man"): array {
    // #44: use shared cleanTerminalOutput() instead of inline patterns
    $lines = cleanTerminalOutput($lines);

    $section_label = "";
    if ($section !== "" && $section !== "-f" && $section !== "-q") {
        $section_label = "({$section})";
    } elseif ($section !== "") {
        $section_label = " (-{$section})";
    }

    $script_name = baseUrl();
    $canonical_url = $script_name . "/" . $mode . "/" . urlencode($parameter);
    if ($section !== "" && $section !== "-f" && $section !== "-q") {
        $canonical_url .= "/" . urlencode($section);
    }
    $canonical_url .= "/json";

    // Detect sections and subsections — uses shared detectHeadingType()
    // to stay consistent with HTML and Markdown output.
    //
    // Retained section text is capped (PHPMAN_JSON_MAX_CONTENT_BYTES overall,
    // PHPMAN_JSON_MAX_SECTION_BYTES per section) so that a huge page does not
    // have to be held in memory just to be serialised — `info py` is ~19.5MB of
    // text. Lines past the cap are dropped rather than retained, which is why
    // the semantic fields (summary/synopsis/flags/examples/see_also) are derived
    // from the full line stream while it is folded instead of from the retained
    // sections afterwards: those sections no longer hold the whole page.
    $sections = array();
    $currentSection = null;   // reference to current section or subsection (for content accumulation)
    $currentL1 = null;        // reference to current L1 section (for adding L2 subsections)
    $currentL1Index = -1;     // index into $sections of the L1 the content accrues to
    $inSubsection = false;    // content is accruing to an L2 subsection of that L1
    $subIsFlagEntry = false;  // current L2 subsection is a flag definition
    $flagEntryKey = null;     // which flag source that subsection came from

    $budget = PHPMAN_JSON_MAX_CONTENT_BYTES;
    $sectionUsed = 0;
    $truncatedAnywhere = false;

    // First L1 of each kind, by index — the extractors below mirror the old
    // "first matching section wins" scans.
    $summaryL1 = null;
    $synopsisL1 = null;
    $examplesL1 = null;
    $seeAlsoL1 = null;
    $flagL1 = array("OPTIONS" => null, "DESCRIPTION" => null);

    $summaryParts = array();
    $synopsisParts = array();
    $examples = array();
    $seeAlso = array();
    $flagEntries = array("OPTIONS" => null, "DESCRIPTION" => null);

    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        $rawLine = $lines[$i];
        $plainLine = trim(str_replace(array("**", "_"), "", $rawLine));

        if ($plainLine !== "") {
            // Use shared heading detection (same as HTML/Markdown paths)
            $nextRawLine = ($i + 1 < $count) ? $lines[$i + 1] : null;
            $heading = detectHeadingType($rawLine, $mode, $nextRawLine);

            if ($heading !== null) {
                if ($heading['level'] === 1) {
                    // Break previous references before creating new section
                    unset($currentSection);
                    $currentSection = array(
                        "name" => $heading['text'],
                        "level" => 1,
                        "content" => array(),
                        "subsections" => array(),
                    );
                    $sections[] = &$currentSection;
                    // L1 is also the current L1 for subsection attachment
                    unset($currentL1);
                    $currentL1 = &$currentSection;
                    $currentL1Index = count($sections) - 1;
                    $sectionUsed = 0;
                    $inSubsection = false;
                    $subIsFlagEntry = false;
                    $flagEntryKey = null;

                    $l1Name = $heading['text'];
                    if ($l1Name === "NAME" && $summaryL1 === null) {
                        $summaryL1 = $currentL1Index;
                    } elseif ($l1Name === "SYNOPSIS" && $synopsisL1 === null) {
                        $synopsisL1 = $currentL1Index;
                    } elseif (array_key_exists($l1Name, $flagL1) && $flagL1[$l1Name] === null) {
                        $flagL1[$l1Name] = $currentL1Index;
                        $flagEntries[$l1Name] = array();
                    } elseif (($l1Name === "EXAMPLES" || $l1Name === "EXAMPLE") && $examplesL1 === null) {
                        $examplesL1 = $currentL1Index;
                    } elseif ($l1Name === "SEE ALSO" && $seeAlsoL1 === null) {
                        $seeAlsoL1 = $currentL1Index;
                    }
                } elseif ($heading['level'] === 2 && $currentL1 !== null) {
                    // L2 subsections always attach to the current L1 section
                    unset($subsection);
                    $subsection = array(
                        "name" => $heading['text'],
                        "level" => 2,
                        "content" => array(),
                    );
                    $currentL1["subsections"][] = &$subsection;
                    // Switch content accumulation to the new subsection
                    unset($currentSection);
                    $currentSection = &$subsection;
                    $sectionUsed = 0;
                    $inSubsection = true;
                    $subIsFlagEntry = false;
                    $flagEntryKey = null;

                    if ($currentL1Index === $flagL1["OPTIONS"] || $currentL1Index === $flagL1["DESCRIPTION"]) {
                        $cleanFlagName = trim($heading['text'], "[] ");
                        // Flag subsections start with "-"
                        if ($cleanFlagName !== "" && $cleanFlagName[0] === "-") {
                            $flagEntryKey = $currentL1Index === $flagL1["OPTIONS"] ? "OPTIONS" : "DESCRIPTION";
                            $flagEntries[$flagEntryKey][] = array("name" => $cleanFlagName, "lines" => array());
                            $subIsFlagEntry = true;
                        }
                    }
                }
                // Skip the underline line in info mode (Setext-style heading)
                if (!empty($heading['skipNext'])) {
                    $i++;
                }
                continue;
            }
        }

        // Regular content line — blank lines fold as "" like before
        if ($currentSection === null) {
            continue;
        }

        $bytes = strlen($plainLine) + 1;
        $currentSection["content_bytes"] = ($currentSection["content_bytes"] ?? 0) + $bytes;
        $currentSection["content_lines"] = ($currentSection["content_lines"] ?? 0) + 1;
        if ($budget <= 0 || $sectionUsed >= PHPMAN_JSON_MAX_SECTION_BYTES) {
            $currentSection["truncated"] = true;
            $truncatedAnywhere = true;
        } else {
            $currentSection["content"][] = $plainLine;
            $budget -= $bytes;
            $sectionUsed += $bytes;
        }

        if ($plainLine === "") {
            continue;
        }

        // Semantic fields read the full stream, so the cap cannot lose them.
        // NAME/SYNOPSIS take the section's own lines; EXAMPLES/SEE ALSO also
        // fold in their subsections, matching the original extractors.
        if ($currentL1Index === $summaryL1 && !$inSubsection) {
            $summaryParts[] = $plainLine;
        } elseif ($currentL1Index === $synopsisL1 && !$inSubsection) {
            $synopsisParts[] = $plainLine;
        } elseif ($currentL1Index === $examplesL1) {
            if (strlen($plainLine) > 1) {
                $examples[] = $plainLine;
            }
        } elseif ($currentL1Index === $seeAlsoL1) {
            // Strip man page footer lines (e.g. "curl 7.81.0  ...  curl(1)")
            // from SEE ALSO content before extracting references
            $stripped = preg_replace('/^\S.{2,}\S[ ]{3,}.*[ ]{3,}\w+\(\w+\)\s*$/', '', $plainLine);
            if (preg_match_all('/([a-zA-Z0-9_.-]+)\((\w+)\)/', $stripped, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    // Filter out self-references (man page footer lines bleed into content)
                    if ($m[1] === $parameter) {
                        continue;
                    }
                    $seeAlso[] = array(
                        "name" => $m[1],
                        "section" => $m[2],
                        "url" => baseUrl() . "/man/" . urlencode($m[1]) . "/" . urlencode($m[2]) . "/json"  // #46: dynamic URL
                    );
                }
            }
        } elseif ($subIsFlagEntry) {
            $flagEntries[$flagEntryKey][count($flagEntries[$flagEntryKey]) - 1]["lines"][] = $plainLine;
        }
    }

    // Every line has been folded into $sections, so the line buffer is dead
    // weight from here on. Assigning through the by-reference parameter drops
    // the caller's reference too — unset() would only drop this local alias and
    // leave the buffer alive for the whole caller frame.
    $lines = array();

    // Build JSON structure
    $jsonData = array(
        "mode" => $mode,
        "parameter" => $parameter,
        "section" => $section,
        "url" => $canonical_url,
        "generated" => gmdate("Y-m-d\TH:i:s\Z"),
    );

    // Extract SYNOPSIS section as top-level field
    if ($synopsisL1 !== null) {
        $jsonData["synopsis"] = trim(implode("\n", $synopsisParts));
    }

    // Add structured sections
    $jsonSections = array();
    foreach ($sections as $sec) {
        $cleanSec = array();
        $textParts = array();
        foreach ($sec["content"] as $cl) {
            if ($cl !== "" || !empty($textParts)) {
                $textParts[] = $cl;
            }
        }
        $cleanSec["content"] = implode("\n", $textParts);

        $subsections = array();
        foreach ($sec["subsections"] as $sub) {
            $subText = array();
            foreach ($sub["content"] as $cl) {
                if ($cl !== "" || !empty($subText)) {
                    $subText[] = $cl;
                }
            }
            $cleanName = trim($sub["name"]);
            $entry = array(
                "name" => $cleanName,
                "content" => implode("\n", $subText),
            );
            if (!empty($sub["truncated"])) {
                $entry["truncated"] = true;
                $entry["content_bytes"] = $sub["content_bytes"] ?? 0;
                $entry["content_lines"] = $sub["content_lines"] ?? 0;
            }
            // Add semantic fields for flag-like subsections
            if (strlen($cleanName) > 0 && $cleanName[0] === "-") {
                $parsed = parseFlagJSON($cleanName);
                if ($parsed["flag"] !== "") {
                    $entry["flag"] = $parsed["flag"];
                }
                if ($parsed["long"] !== null) {
                    $entry["long"] = $parsed["long"];
                }
                if ($parsed["arg"] !== null) {
                    $entry["arg"] = $parsed["arg"];
                }
            }
            $subsections[] = $entry;
        }
        if (!empty($sec["truncated"])) {
            $cleanSec["truncated"] = true;
            $cleanSec["content_bytes"] = $sec["content_bytes"] ?? 0;
            $cleanSec["content_lines"] = $sec["content_lines"] ?? 0;
        }
        $cleanSec["subsections"] = $subsections;
        // Use L1 heading name as the key
        $jsonSections[$sec["name"]] = $cleanSec;
    }
    $jsonData["sections"] = $jsonSections;

    // === Semantic fields, accumulated while folding the line stream ===

    // 1. Summary from NAME section
    if ($summaryL1 !== null) {
        $nameText = trim(implode(" ", $summaryParts));
        if ($nameText !== "") {
            $jsonData["summary"] = $nameText;
        }
    }

    // 2. Flags from OPTIONS section (or DESCRIPTION as fallback)
    $flags = array();
    $flagSource = $flagEntries["OPTIONS"];
    if ($flagSource === null) {
        $flagSource = $flagEntries["DESCRIPTION"] ?? array();
    }
    foreach ($flagSource as $flagEntry) {
        $flag = parseFlagJSON($flagEntry["name"]);
        $flag["description"] = trim(preg_replace('/\s+/', ' ', implode(" ", $flagEntry["lines"])));
        $flags[] = $flag;
    }
    $jsonData["flags"] = $flags;

    // 3. Examples from EXAMPLES / EXAMPLE section
    $jsonData["examples"] = $examples;

    // 4. See Also from SEE ALSO section
    $jsonData["see_also"] = $seeAlso;

    // Text past the cap was dropped, so say so: the payload is the outline plus
    // as much text as the budget allowed, not the whole page.
    if ($truncatedAnywhere) {
        $jsonData["content_truncated"] = true;
        $jsonData["content_budget_bytes"] = PHPMAN_JSON_MAX_CONTENT_BYTES;
        $jsonData["content_retained_bytes"] = PHPMAN_JSON_MAX_CONTENT_BYTES - max(0, $budget);
    }

    // $sections (raw per-line arrays, one zval per line) has been fully folded
    // into $jsonData by now — $jsonData["sections"] holds freshly imploded
    // strings. Releasing the raw structure before encoding avoids holding both
    // representations at once.
    unset($sections);

    // v2.2: Inject TLDR from official sources (only for man section 1)
    $tldr = fetchOfficialTldr($parameter, $mode, $section);
    if (!empty($tldr)) {
        $jsonData["tldr"] = $tldr;
    }

    return $jsonData;
}

/**
 * Render a page as the JSON IR string (format=json).
 *
 * format=mcp goes through buildJsonData() + formatMcpEnvelope() instead: that
 * skips this encode and the matching decode in the envelope builder, which for
 * a huge page is a ~15MB string the MCP path would only throw away.
 */
function formatToJSON (array &$lines, string $parameter, string $section = "", string $mode = "man"): string {
    $result = json_encode(buildJsonData($lines, $parameter, $section, $mode), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return $result !== false ? $result : '{}';
}

// Parse a flag name like "-K, --config <file>" into structured {flag, long, arg}
function parseFlagJSON(string $name): array {
    $result = array("flag" => "", "long" => null, "arg" => null);
    $parts = preg_split('/\s+/', trim($name));
    $hasFlag = false;  // track if we've already captured a flag name

    foreach ($parts as $part) {
        // Strip trailing comma from short flags like "-K," → "-K"
        $part = rtrim($part, ',');

        // Standalone argument placeholder: <file>, ARCHIVE, [=name], etc.
        if ($hasFlag && preg_match('/^(<[^>]+>|\[[^\]]+\])$/', $part)) {
            // Angle-bracket or square-bracket placeholder: <file>, [=password]
            $result["arg"] = $part;
            continue;
        }
        if ($hasFlag && preg_match('/^[A-Z][A-Z0-9_]{1,}$/', $part)) {
            // ALL CAPS placeholder after a flag: ARCHIVE, FILE, COMMAND
            $result["arg"] = $part;
            continue;
        }

        if (preg_match('/^-[a-zA-Z0-9?]$/', $part)) {
            // Short flag: -X
            $result["flag"] = $part;
            $hasFlag = true;
        } elseif (preg_match('/^--[a-zA-Z0-9][a-zA-Z0-9._-]*=(.+)$/', $part, $m)) {
            // Long flag with embedded arg: --option=VAL
            $result["long"] = explode("=", $part)[0];
            $result["arg"] = $m[1];
            $hasFlag = true;
        } elseif (preg_match('/^--[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $part)) {
            // Long flag: --option
            $result["long"] = $part;
            $hasFlag = true;
        }
    }

    return $result;
}

//convert man perldoc output to markdown
