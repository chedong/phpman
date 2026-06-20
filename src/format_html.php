<?php
function renderTocSidebar(array $tocItems, string $pageLabel): string {
    if (!(count($tocItems) > 1 || (count($tocItems) === 1 && !empty($tocItems[0]["children"])))) {
        return '';
    }
    $out = "<div id=\"toc-sidebar\">\n";
    $out .= "<div class=\"toc-title\" id=\"toc-toggle\" onclick=\"document.body.classList.toggle('toc-open');\">" . h($pageLabel) . " <span class=\"toc-open-icon\">&#9633;</span><span class=\"toc-close-icon\">&#10005;</span></div>\n";
    foreach ($tocItems as $l1) {
        $out .= "<a href=\"#" . h($l1["id"]) . "\">" . h($l1["label"]) . "</a>\n";
        if (!empty($l1["children"])) {
            $out .= "<div class=\"toc-subs\">\n";
            foreach ($l1["children"] as $l2) {
                $out .= "<a href=\"#" . h($l2["id"]) . "\" class=\"toc-sub\">" . h($l2["label"]) . "</a>\n";
            }
            $out .= "</div>\n";
        }
    }
    $out .= "</div>\n";
    return $out;
}

// CLI Mode: command-line tools have been split into standalone scripts.
// Use these instead of the old phpMan.php -- flags:
//   php cli/build-index.php           Search index rebuild
//   php cli/build-index.php --cron    Index rebuild with timestamp
//   php cli/enhance.php man:ls        LLM emoji enhancement
//   php cli/batch-enhance.php        Full batch enhance (rate-limited, resumable)


function formatManPerlDoc (array $lines, string $mode = "man"): string {
    $script_name = h(scriptName());
    $mode = h($mode);
    // Use global constant RE_ASCII_SAFE for overstrike pattern matching
    $ac = RE_ASCII_SAFE;
    $patterns = array(
                    "/&/",  //html special char: '&' => chr(5) => '&gt;';
                    "/</",  //html special char: '>' => chr(6) => '&lt;';
                    "/>/",  //html special char: '<' => chr(7) => '&gt;';
                    //man page special chars
                    // CRITICAL: Use $ac instead of . to avoid splitting multibyte UTF-8
                    "/{$ac}".chr(8)."{$ac}".chr(8)."({$ac})".chr(8)."{$ac}/",  // ?^H?^H?^H? => <b>?</b>
                    "/_".chr(8)."({$ac})".chr(8)."{$ac}/",  //_^H?^H? => <u>?</u>
                    "/_".chr(8)."({$ac})/",  //_^H? => <u>?</u>
                    "/_".chr(8)."/",  // Cleanup: strip orphan _^H (e.g. before non-ASCII UTF-8)
                    "/{$ac}".chr(8)."({$ac})/",  //?^H? => <b>?</b>
                    //reverse html special chars
                    "/".chr(5)."/",  //reverse '&'
                    "/".chr(6)."/",  //reverse '<'
                    //perldoc specific: plain text headings (no overstrike sequences in perldoc output)
                    "/^([A-Z][A-Z0-9][A-Z0-9\/\s]{1,50})\s*$/",
                    "/^ {2}([A-Z][a-z][\w\s:\x27;,-]+)\s*$/",
                );

    $replace = array(
                   chr(5),
                   chr(6),
                   chr(7),
                   '<b>$1</b>',
                    '<u>$1</u>',
                    '<u>$1</u>',
                    '',  // strip orphan _^H
                    '<b>$1</b>',
                   "&amp;",
                   "&lt;",
                   '<b>$1</b>',
                   '  <u>$1</u>',
               );

    // SGR escape sequences — must process BEFORE linkification so that
    // SGR-split names (e.g. ESC[1mioESC[4m_ESC[24mcancelESC[0m) are rejoined
    // into clean <b>io<u>_</u>cancel</b> before name(section) linking.
    $patterns[] = "/".chr(27)."\[1m(.*?)".chr(27)."\[(?:0|22)m/";
    $replace[] = '<b>$1</b>';
    $patterns[] = "/".chr(27)."\[4m(.*?)".chr(27)."\[(?:0|24)m/";
    $replace[] = '<u>$1</u>';
    // Cleanup duplicated / orphan tags from combined overstrike + SGR processing
    $patterns[] = "/<\/u><u>/";
    $replace[] = '';
    $patterns[] = "/<u>_<\/u>/";
    $replace[] = '_';
    $patterns[] = "/<\/b><b>/";
    $replace[] = '';

    // Mode-specific link patterns
    if ($mode === "pydoc") {
        // pydoc: class ParentClass links
        $patterns[] = "/class (\w+)\((\w+(?:\.\w+)*)\)/";
        $replace[] = 'class $1(<a href="'.$script_name.'/pydoc/$2">$2</a>)';
    } elseif ($mode === "ri") {
        // ri: Class or Class#method references
        $patterns[] = "/class (\w+)\((\w+(?:\.\w+)*)\)/";
        $replace[] = 'class $1(<a href="'.$script_name.'/ri/$2">$2</a>)';
        // Ruby constant/module refs with :: notation
        $patterns[] = "/((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/";
        $replace[] = '$3<a href="'.$script_name.'/ri/$4">$4</a>$6';
    } else {
        // man and perldoc: standard command(section) and module linking
        $patterns[] = "/((<.>)|([\s,]))([\w\-\.\+]+)(<\/.>)?\((<.>)?(\d\w*|n)(<\/.>)?\)(,)?(<\/.>)?/";
        $replace[] = '$3$4($7)$9';
        // Handle names split by a single inline tag boundary:
        //   <b>io</b>_cancel(2) → io_cancel(2)
        // (the _ was wrapped in <u> by SGR/overstrike, leaving a </b>.. boundary)
        $patterns[] = "/((<.>)|([\s,]))([\w\-\.\+]+)<\/[bu]>([\w\-\.\+]+)\((<.>)?(\d\w*|n)(<\/.>)?\)/";
        $replace[] = '$3$4$5($7)';
        $patterns[] = "/([\s,])([\w\-\.\+]+)\((\d\w*|n)\)/";
        $replace[] = '$1<a href="'.$script_name.'/'.$mode.'/$2/$3">$2($3)</a>';
        //translate link to related perl modules, but $obj->Module::Name-> will not be translate
        //'<u>Module::Name</u>' => ' Module::Name'
        $patterns[] = "/((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/";
        $replace[] = '$3<a href="'.$script_name.'/'.$mode.'/$4">$4</a>$6';
    }

    // Common patterns: email, URL, closing >
    $patterns[] = "/(([\w\-\.]+)@([\w\-]+)(\.[\w\-]+)+)/";  //link to email
    $replace[] = '<a href="mailto:$2 AT $3$4">$2<u> AT </u>$3$4</a>';
    $patterns[] = "/([\w]+:\/\/[\w%\-\?&;#~=\.\/\@]+[\w\/])/i"; //link to url
    $replace[] = '<a href="$1" rel="noopener noreferrer">$1</a>';
    $patterns[] = "/".chr(7)."/";  //reverse '>'
    $replace[] = "&gt;";
    $seenIds = [];
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = preg_replace($patterns, $replace, $lines[$i]);
        $nextLine = ($i + 1 < $count) ? $lines[$i + 1] : null;
        $heading = detectHeadingType($line, $mode, $nextLine);
        if ($heading) {
            $id = ($heading['level'] === 1 ? 'section-' : 'sub-')
                . strtolower(preg_replace('/[^A-Z0-9]+/i', '-', $heading['text']));
            $id = trim($id, '-');
            // Ensure unique id for XHTML validation
            if (isset($seenIds[$id])) {
                $seenIds[$id]++;
                $id = $id . '-' . $seenIds[$id];
            } else {
                $seenIds[$id] = 0;
            }
            $line = '<a id="' . h($id) . '"></a>' . $line;
            // Skip the underline line in info mode (Setext-style heading)
            if (!empty($heading['skipNext'])) {
                $output .= $line . "\n";
                $i++;  // skip the underline
                continue;
            }
        }
        $output .= $line . "\n";
    }
    // Safety net: strip any remaining invalid UTF-8 bytes
    // mb_convert_encoding with substitute removes lone surrogates and bad sequences
    $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
    return $output;
}

// Wraps JSON string in MCP content format if needed.
// $format === "mcp" → {"content":[{"type":"text","text":"<json>"}]}
// $format === "json" → raw JSON as-is

function addManPageToc (string $html): array {
    $lines = explode("\n", $html);

    // Hierarchical TOC: [ ['id'=>, 'label'=>, 'children'=>[...]], ... ]
    $tocItems = array();
    $currentL1Idx = null;

    foreach ($lines as $i => $line) {
        // Level 1 anchor already placed by formatManPerlDoc
        if (preg_match('/<a id="(section-[^"]+)"><\/a>(.*)/', $line, $m)) {
            // Decode entities first (e.g. &lt;strong&gt; → <strong>), then strip system
            // formatting tags (<b>, <u>) but NOT <strong> (it's original content that
            // should render as bold in the TOC). Avoid strip_tags() which would treat
            // <sys/socket.h> as an HTML tag and remove it.
            $label = trim(strip_tags(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')));
            // Strip RDoc/ri heading prefixes: "= Heading" → "Heading"
            $label = preg_replace('/^=\s*/', '', $label);
            $tocItems[] = array('id' => $m[1], 'label' => $label, 'children' => array());
            $currentL1Idx = count($tocItems) - 1;
            continue;
        }

        // Level 2 anchor already placed by formatManPerlDoc
        if (preg_match('/<a id="(sub-[^"]+)"><\/a>(.*)/', $line, $m)) {
            if ($currentL1Idx !== null) {
                $label = trim(strip_tags(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')));
                // Strip RDoc/ri heading prefixes: "== Heading" → "Heading"
                $label = preg_replace('/^==\s*/', '', $label);
                $tocItems[$currentL1Idx]['children'][] = array('id' => $m[1], 'label' => $label);
            }
            continue;
        }
    }

    return array($html, $tocItems);
}

//get specified perl module's man page and convert to html format
