<?php
function renderTocSidebar(array $tocItems, string $pageLabel): string {
    if (!(count($tocItems) > 1 || (count($tocItems) === 1 && !empty($tocItems[0]["children"])))) {
        return '';
    }
    $out = "<div id=\"toc-sidebar\">\n";
    // The click handler lives in phpman.js (#273): an inline onclick is covered
    // by script-src, so keeping it would force 'unsafe-inline' back into the CSP.
    // phpman.js delegates on #toc-toggle and #alpha-toggle by id.
    $out .= "<div class=\"toc-title\" id=\"toc-toggle\">" . h($pageLabel) . " <span class=\"toc-open-icon\">&#9633;</span><span class=\"toc-close-icon\">&#10005;</span></div>\n";
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


/**
 * SGR (Select Graphic Rendition) bold/underline → well-nested <b>/<u> tags.
 *
 * Replaces two non-greedy regexes. Real input (test/corpus/raw/man-tar.txt):
 *
 *   ESC[1m-f ESC[4mESC[22mARCHIVE ESC[24m
 *
 * underline opens exactly where bold closes, so no single-pass regex pairing
 * can produce well-nested tags — the old pair emitted `<b>-f <u></b>ARCHIVE</u>`
 * (interleaved, xmllint-invalid). This state machine:
 *
 * - recognizes ESC[1m (bold on), ESC[22m (bold off), ESC[4m (underline on),
 *   ESC[24m (underline off), ESC[0m (all off); any other ESC[...m is dropped;
 * - always opens in the fixed order <b><u> and closes in reverse;
 * - closes whatever is still open at end of line (tags never span lines);
 * - batches consecutive codes (ESC[4mESC[22m) into a single transition, so no
 *   empty <u></u> is emitted where attributes merely hand over mid-token.
 */
function sgrToHtmlTags (string $line): string {
    static $esc = null;
    if ($esc === null) { $esc = chr(27); }
    if (strpos($line, $esc . '[') === false) {
        return $line;
    }
    $parts = preg_split('/(' . $esc . '\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false || count($parts) === 1) {
        return $line;
    }
    $out = '';
    $stack = [];       // open tags in open order: 'b' always before 'u'
    $bold = false;     // target state accumulated over consecutive codes
    $under = false;
    $pending = false;  // state changed by codes since the last emission

    // Reconcile the open stack with the target state. Close from the top while
    // the top tag must go — unwanted itself, or <u> sitting above a <b> whose
    // state changes (the open order is fixed <b><u>, so <u> closes and reopens
    // whenever <b> underneath it has to appear or disappear) — then reopen the
    // wanted tags in the fixed order.
    $flush = static function (bool $wantBold, bool $wantUnder) use (&$out, &$stack): void {
        $curBold = in_array('b', $stack, true);
        $curUnder = in_array('u', $stack, true);
        if ($curBold === $wantBold && $curUnder === $wantUnder) {
            return;
        }
        while ($stack) {
            $top = end($stack);
            if ($top === 'b') {
                if (!$wantBold) {
                    $out .= '</b>';
                    array_pop($stack);
                    continue;
                }
                break;
            }
            // top === 'u': close when unwanted, or when the <b> beneath it
            // does not match the wanted bold state.
            if (!$wantUnder || $wantBold !== in_array('b', $stack, true)) {
                $out .= '</u>';
                array_pop($stack);
                continue;
            }
            break;
        }
        if ($wantBold && !in_array('b', $stack, true)) { $out .= '<b>'; $stack[] = 'b'; }
        if ($wantUnder && !in_array('u', $stack, true)) { $out .= '<u>'; $stack[] = 'u'; }
    };

    // preg_split with DELIM_CAPTURE: even indices are text, odd are ESC[...m.
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            $params = substr($part, 2, -1);
            foreach (explode(';', $params) as $p) {
                if ($p === '0' || $p === '') {
                    $bold = false; $under = false;
                } elseif ($p === '1') {
                    $bold = true;
                } elseif ($p === '22') {
                    $bold = false;
                } elseif ($p === '4') {
                    $under = true;
                } elseif ($p === '24') {
                    $under = false;
                }
                // anything else (colors etc.) is dropped
            }
            $pending = true;
        } else {
            // Flush only on non-empty text: an empty segment between two
            // consecutive codes (ESC[4mESC[22m) must not split the batch.
            if ($pending && $part !== '') {
                $flush($bold, $under);
                $pending = false;
            }
            $out .= $part;
        }
    }
    if ($pending) {
        $flush($bold, $under);
    }
    // Close whatever is still open at end of line.
    $flush(false, false);
    return $out;
}

function formatManPerlDoc (array $lines, string $mode = "man"): string {
    $script_name = h(scriptName());
    $mode = h($mode);
    // Use global constant RE_ASCII_SAFE for overstrike pattern matching
    $ac = RE_ASCII_SAFE;
    $patterns = array(
                    // Terminal-only OSC sequences go first, ahead of the &<> → \x05\x06\x07
                    // placeholders below. This strip accepts BEL as a terminator, and the
                    // \x07 placeholder standing in for ">" satisfies that test — so a payload
                    // containing ">" would cut the strip short mid-sequence and leak the rest.
                    "/".chr(27)."\][^".chr(27).chr(7)."]*(?:".chr(27)."\\\\|".chr(7).")/",
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
                   '',
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

    // Main text conversions — pass 1, before SGR handling. The SGR state
    // machine (sgrToHtmlTags) runs between pass 1 and pass 2: it must run
    // AFTER the &<> → placeholder escaping (its <b>/<u> output would otherwise
    // be escaped) and BEFORE linkification, so that SGR-split names
    // (e.g. ESC[1mioESC[4m_ESC[24mcancelESC[0m) are rejoined into clean
    // <b>io<u>_</u>cancel</b> before name(section) linking.
    $prePatterns = $patterns;
    $preReplace = $replace;
    // Pass 2 starts fresh at the SGR color strip below — the pass-1 entries
    // (escaping, overstrike, headings) must NOT run again after the machine,
    // or they would re-escape the <b>/<u> tags pass 1 just produced.
    $patterns = array();
    $replace = array();
    // SGR color codes (\e[34m … \e[0m) — groff emits these for man pages that use
    // color escapes (util-linux, systemd). The state machine above already
    // drops every ESC[...m; this stays as a safety net, still ahead of the link
    // patterns below: the URL linkifier matches `[\w]+://`, so a surviving \e[34m
    // had its trailing "34m" swallowed into the href, yielding
    // <a href="34mhttps://…"> — a broken relative link that every crawler then
    // followed into a 403.
    $patterns[] = "/".chr(27)."\[[0-9;]*m/";
    $replace[] = '';
    // OSC 8 hyperlinks — groff emits these for man pages that use .UR/.UE
    // (netpbm and friends): ESC ] 8 ; params ; URI ST  TEXT  ESC ] 8 ; ; ST
    // (ST = ESC \). Turn them into real links instead of dropping the URI: the
    // URL linkifier below matches only absolute "scheme://" URLs, so a relative
    // target such as "index.html#commonoptions" would otherwise be lost.
    // The URI charset excludes quotes (no href attribute breakout) and every
    // whitespace/control byte — browsers strip those before resolving a URL, so
    // " javascript:…" would otherwise slip past the scheme guard.
    // $oscUri is an allowlist: an absolute URL on a known-safe scheme, or a
    // scheme-less relative one. Anything else — javascript:, data:, vbscript: —
    // stays unmatched, so the sequence is dropped and only its text survives.
    // mailto: is listed separately because it carries no "//".
    $oscUri = '(?:(?:https?|ftp)://[^"\'\x00-\x20]+|mailto:[^"\'\x00-\x20]+|(?![\\w+.\\-]*:)[^"\'\x00-\x20]+)';
    // Applied by $oscPattern before the pattern pass — see the loop below.
    $oscPattern = "#".chr(27)."\]8;[^;]*;(".$oscUri.")".chr(27)."\\\\(.*?)".chr(27)."\]8;;".chr(27)."\\\\#i";
    // Cleanup duplicated / orphan tags from combined overstrike + SGR processing
    $patterns[] = "/<\/b><b>/";
    $replace[] = '';
    // Re-added with <u> revert: merge adjacent <u></u> and orphan <u>_</u>
    $patterns[] = "/<\/u><u>/";
    $replace[] = '';
    $patterns[] = "/<u>_<\/u>/";
    $replace[] = '_';

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
    // Scheme allowlist (#275). This used to be `[\w]+://`, which accepts any
    // scheme at all — including `javascript://%0Aalert(1)` (`//` parses as a JS
    // comment, the %0A starts a new line) and `data://…`. The OSC 8 path above
    // already restricts itself to http/https/ftp/mailto; this is the same list,
    // minus mailto, which the email pattern on the previous line already covers
    // and which has no `://` form anyway.
    //
    // The `://` requirement also means the common `javascript:alert(1)` never
    // matched — only the `javascript://` form did — so this narrows a real gap
    // rather than closing a hypothetical one.
    $patterns[] = "/((?:https?|ftp):\/\/[\w%\-\?&;#~=\.\/\@]+[\w\/])/i"; //link to url
    $replace[] = '<a href="$1" rel="noopener noreferrer">$1</a>';
    $patterns[] = "/".chr(7)."/";  //reverse '>'
    $replace[] = "&gt;";
    $seenIds = [];
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        // Pull OSC 8 links out before the pattern pass. The URL linkifier below
        // scans the whole line — inserted markup included — so an <a href="https://…">
        // already in the text would have its URI matched and wrapped a second
        // time, nesting an anchor inside the href. Stash each URI and restore it
        // once the pass is done, so the anchor is never re-processed.
        $oscLinks = array();
        $line = preg_replace_callback($oscPattern, function ($m) use (&$oscLinks) {
            $oscLinks[] = $m[1];
            return "\x0e" . (count($oscLinks) - 1) . "\x0e" . $m[2] . "\x0f\x0f";
        }, $lines[$i]);
        $line = preg_replace($prePatterns, $preReplace, $line);
        $line = sgrToHtmlTags($line);
        $line = preg_replace($patterns, $replace, $line);
        if ($oscLinks) {
            $line = preg_replace_callback("/\x0e(\d+)\x0e/", function ($m) use ($oscLinks) {
                return '<a href="' . h($oscLinks[(int)$m[1]]) . '" rel="noopener noreferrer">';
            }, $line);
            $line = str_replace("\x0f\x0f", '</a>', $line);
        }
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
