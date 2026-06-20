<?php
function formatToJSON (array $lines, string $parameter, string $section = "", string $mode = "man"): string {
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
    // to stay consistent with HTML and Markdown output
    $sections = array();
    $currentSection = null;   // reference to current section or subsection (for content accumulation)
    $currentL1 = null;        // reference to current L1 section (for adding L2 subsections)

    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        $rawLine = $lines[$i];
        $plainLine = trim(str_replace(array("**", "_"), "", $rawLine));

        if ($plainLine === "") {
            if ($currentSection !== null) {
                $currentSection["content"][] = "";
            }
            continue;
        }

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
            }
            // Skip the underline line in info mode (Setext-style heading)
            if (!empty($heading['skipNext'])) {
                $i++;
            }
            continue;
        }

        // Regular content line
        if ($currentSection !== null) {
            $clean = trim($plainLine);
            if ($clean !== "") {
                $currentSection["content"][] = $clean;
            }
        }
    }

    // Build JSON structure
    $jsonData = array(
        "mode" => $mode,
        "parameter" => $parameter,
        "section" => $section,
        "url" => $canonical_url,
        "generated" => gmdate("Y-m-d\TH:i:s\Z"),
    );

    // Extract SYNOPSIS section as top-level field
    foreach ($sections as $sec) {
        if ($sec["name"] === "SYNOPSIS") {
            $synopsisText = implode("\n", array_filter($sec["content"], function($line) {
                return trim($line) !== "";
            }));
            $jsonData["synopsis"] = trim($synopsisText);
            break;
        }
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
        $cleanSec["subsections"] = $subsections;
        // Use L1 heading name as the key
        $jsonSections[$sec["name"]] = $cleanSec;
    }
    $jsonData["sections"] = $jsonSections;

    // === Semantic extraction for agent consumption ===

    // 1. Summary from NAME section
    foreach ($sections as $sec) {
        if ($sec["name"] === "NAME") {
            $contentStr = is_array($sec["content"]) ? implode(" ", $sec["content"]) : $sec["content"];
            $nameText = trim($contentStr);
            if ($nameText !== "") {
                $jsonData["summary"] = $nameText;
            }
            break;
        }
    }

    // 2. Flags from OPTIONS section (or DESCRIPTION as fallback)
    $flags = array();
    $flagSections = array("OPTIONS", "DESCRIPTION");
    foreach ($flagSections as $targetSection) {
        foreach ($sections as $sec) {
            if ($sec["name"] === $targetSection) {
                if (count($sec["subsections"]) > 0) {
                    foreach ($sec["subsections"] as $sub) {
                        $cleanName = trim($sub["name"], "[] ");
                        // Flag subsections start with "-"
                        if (strlen($cleanName) > 0 && $cleanName[0] === "-") {
                            $flag = parseFlagJSON($cleanName);
                            $descStr = is_array($sub["content"]) ? implode(" ", $sub["content"]) : $sub["content"];
                            $flag["description"] = trim(preg_replace('/\s+/', ' ', $descStr));
                            $flags[] = $flag;
                        }
                    }
                }
                break 2; // Stop after first section with flags
            }
        }
    }
    $jsonData["flags"] = $flags;

    // 3. Examples from EXAMPLES / EXAMPLE section
    $examples = array();
    foreach ($sections as $sec) {
        if ($sec["name"] === "EXAMPLES" || $sec["name"] === "EXAMPLE") {
            $allContent = is_array($sec["content"]) ? implode("\n", $sec["content"]) : $sec["content"];
            foreach ($sec["subsections"] as $sub) {
                $allContent .= "\n" . (is_array($sub["content"]) ? implode("\n", $sub["content"]) : $sub["content"]);
            }
            $lines = explode("\n", $allContent);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== "" && strlen($line) > 1) {
                    $examples[] = $line;
                }
            }
            break;
        }
    }
    $jsonData["examples"] = $examples;

    // 4. See Also from SEE ALSO section
    $seeAlso = array();
    foreach ($sections as $sec) {
        if ($sec["name"] === "SEE ALSO") {
            $allContent = is_array($sec["content"]) ? implode("\n", $sec["content"]) : $sec["content"];
            foreach ($sec["subsections"] as $sub) {
                $allContent .= "\n" . (is_array($sub["content"]) ? implode("\n", $sub["content"]) : $sub["content"]);
            }
            // Strip man page footer lines (e.g. "curl 7.81.0  ...  curl(1)")
            // from SEE ALSO content before extracting references
            $allContent = preg_replace('/^\S.{2,}\S[ ]{3,}.*[ ]{3,}\w+\(\w+\)\s*$/m', '', $allContent);
            preg_match_all('/([a-zA-Z0-9_.-]+)\((\w+)\)/', $allContent, $matches, PREG_SET_ORDER);
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
            break;
        }
    }
    $jsonData["see_also"] = $seeAlso;

    // v2.2: Inject TLDR from official sources (only for man section 1)
    $tldr = fetchOfficialTldr($parameter, $mode, $section);
    if (!empty($tldr)) {
        $jsonData["tldr"] = $tldr;
    }

    $result = json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
