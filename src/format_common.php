<?php
/**
 * Strip terminal control sequences from raw command output, in place.
 *
 * Rewrites $lines by reference instead of returning a second array. On a
 * 430k-line page (`info py`) the old form kept two full copies of the buffer
 * alive at once — ~34MB of a 76MB peak. Note a by-value foreach writing
 * $lines[$i] would NOT help: that write forces PHP to separate (fully copy)
 * the array. Only a for loop over a packed list rewrites without copying,
 * hence the normalisation below.
 *
 * $linkStyle decides what an OSC 8 hyperlink becomes: 'markdown' (the default)
 * keeps the target as `[text](uri)`, 'plain' keeps only the text. JSON and MCP
 * are not markdown documents — buildJsonData() already strips the `**`/`_`
 * emphasis from section text, so an OSC 8 link was the one markdown construct
 * still reaching a JSON `content` string. (#236)
 */
function cleanTerminalOutput (array &$lines, string $linkStyle = 'markdown'): void {
    // Uses RE_ASCII (plain printable) — raw terminal output has no \x05\x06\x07 placeholders
    $ac = RE_ASCII;
    // OSC 8 link target — an absolute URL on a known-safe scheme, or a scheme-less
    // relative one. See the note on the OSC pattern below. mailto: is listed
    // separately because it carries no "//".
    $oscUri = "(?:(?:https?|ftp)://[^\"'\x00-\x20]+|mailto:[^\"'\x00-\x20]+|(?![\\w+.\\-]*:)[^\"'\x00-\x20]+)";
    $patterns = array(
        "/{$ac}".chr(8)."{$ac}".chr(8)."({$ac})".chr(8)."{$ac}/",  // ?^H?^H?^H? => bold
        "/_".chr(8)."({$ac})/",  // _^H? => underline
        "/{$ac}".chr(8)."({$ac})/",  // ?^H? => bold
        "/".chr(27)."\[1m(.*?)".chr(27)."\[(?:0|22)m/",  // ANSI bold
        "/".chr(27)."\[4m(.*?)".chr(27)."\[(?:0|24)m/",  // ANSI underline
        // SGR color codes carry no meaning in text/markdown output, so drop the
        // whole sequence. groff emits them for man pages that use color escapes
        // (util-linux, systemd); left in place they leaked through as literal
        // "[34m" / "[0m" in the body text. Must stay last: the bold/underline
        // patterns above need to see their own ESC[0m terminator first.
        "/".chr(27)."\[[0-9;]*m/",  // ANSI color / reset
        // OSC 8 hyperlinks — groff emits these for man pages that use .UR/.UE
        // (netpbm and friends): ESC ] 8 ; params ; URI ST  TEXT  ESC ] 8 ; ; ST
        // (ST = ESC \). Kept as a markdown link so the target survives; the bold
        // and underline patterns above have already marked up the link text.
        // $oscUri allowlists the scheme and excludes quotes and whitespace, so a
        // javascript:/data: target is left unmatched and the sequence dropped.
        "#".chr(27)."\]8;[^;]*;(".$oscUri.")".chr(27)."\\\\(.*?)".chr(27)."\]8;;".chr(27)."\\\\#i",
        // Any leftover OSC (window titles, an unclosed 8) is terminal-only.
        "/".chr(27)."\][^".chr(27).chr(7)."]*(?:".chr(27)."\\\\|".chr(7).")/",
    );
    $replace = array(
        "\x01$1\x02",
        "\x03$1\x04",
        "\x01$1\x02",
        "\x01$1\x02",
        "\x03$1\x04",
        "",
        ($linkStyle === 'markdown') ? '[$2]($1)' : '$2',
        "",
    );
    if (!array_is_list($lines)) {
        // Every caller passes exec() output, which is always a packed list; a
        // keyed array is normalised here so the loop below can index by
        // position. This path copies, but it is not the one that runs.
        $lines = array_values($lines);
    }
    $count = count($lines);
    for ($i = 0; $i < $count; $i ++) {
        $line = preg_replace($patterns, $replace, $lines[$i]);
        $line = str_replace("\x08", "", $line);  // strip remaining backspaces
        $line = str_replace(array("\x02\x01", "\x04\x03"), "", $line);
        $lines[$i] = str_replace(array("\x01", "\x02", "\x03", "\x04"), array("**", "**", "_", "_"), $line);
    }
}

/**
 * Extract flags from subsections when top-level flags array is empty.
 * Shared by formatMcpStructured() and formatTldr().
 */
function extractFlagsFromSections (array $data): array {
    $flags = [];
    foreach ($data["sections"] ?? [] as $sec) {
        foreach ($sec["subsections"] ?? [] as $sub) {
            if (!empty($sub["flag"]) || !empty($sub["long"])) {
                $flags[] = [
                    "flag" => $sub["flag"] ?? "",
                    "long" => $sub["long"] ?? null,
                    "arg" => $sub["arg"] ?? null,
                    "description" => trim(preg_replace('/\s+/', ' ', $sub["content"] ?? "")),
                ];
            }
        }
    }
    return $flags;
}

/**
 * phpMan is a web interface of Unix command 'man', 'perldoc', 'info' and 'apropos'.
 * This script makes it easier to read man pages which is lengthy and require you
 * to use 'more' or 'pg' filters. Just try it if you feel hard to remember the command
 * for page back or need to dump man page into text/html format.
 * Compatible with GNU/Linux and FreeBSD under PHP 8.1+.
 *
 * !!! Note: on Apache 2.0.x need configure: AcceptPathInfo On !!!
 *
 * You can also find other web interface:
 *   shell-sed-awk based script at:
 *     http://www.softlab.ntua.gr/~christia/man-cgi.html
 *   perl based script at:
 *     http://www.freebsd.org/cgi/man.cgi
 *     http://www.freebsd.org/cgi/man.cgi/source
 *   dwww of debian:
 *     http://packages.debian.org/stable/doc/dwww
 *
 * Sub function list:
 *     showHeader ( $title, $parameter, $section, $mode, $hasRealContent, $showNav )  //show html header with css style
 *     showForm ($parameter, $check)         //show input form and recursive call
 *     showFooter ( $validate )              //show html footer
 *     getManPage ($parameter, $mode)        //get html format man page
 *     getInfoPage ($parameter)              //get html format info page
 *     getPerldocPage ($parameter)           //get html format perldoc page
 *     getSearchPage ($parameter)            //get html format apropos page
 *     getManIndex ()                        //get man page index
 *     getPerldocIndex ()                    //get perldoc page index
 *     getInfoIndex ()                       //get info page index
 *     formatManPerlDoc ($lines)             //formate man, perldoc and info output
 *
 */

// +--------------------------------------------------------------------------------+
// | global configures: output html style and whether show xhtml validators         |
// +--------------------------------------------------------------------------------+

//app title
$PHPMAN_TITLE = PHPMAN_HOME_TITLE;

// TOC entries for floating right sidebar (populated when rendering man page content)
$TOC_ITEMS = array();

//use colored man page - merged into showHeader()

$VALIDATOR = "";

// Unmask comments to show XHTML 1.0 Transitional + CSS validators
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $scheme . '://' . getSafeHost() . serverValue("REQUEST_URI", scriptName());
$VALIDATOR = "<a href=\"https://validator.w3.org/check?uri=" . urlencode($currentUrl) . "\">".
"<img style=\"border:0;width:88px;height:31px\"".
" src=\"https://www.w3.org/Icons/valid-xhtml10\"".
" alt=\"Valid XHTML 1.0 Transitional!\" /></a>".
"<a href=\"https://jigsaw.w3.org/css-validator/validator?uri=" . urlencode($currentUrl) . "\">".
"<img style=\"border:0;width:88px;height:31px\"".
" src=\"https://jigsaw.w3.org/css-validator/images/vcss-blue\"".
" alt=\"Valid CSS!\" /></a>";

ini_set("default_charset", "UTF-8");


function detectL2ItalicSubheading (string $line): ?array {
    if (preg_match('/^_([A-Z][a-z][\w\s:\x27;\-,]+)_$/', $line, $m)) {
        return ['level' => 2, 'text' => trim($m[1])];
    }
    return null;
}

/**
 * L2: man .SS bold — "   **Packages**" or "   **Symbol** **Tables**"
 * Also matches at column 0 for e.g. "**Line** **Buffering**"
 * Multi-segment ALL CAPS bold (e.g. "**SEE** **ALSO**") is NOT L2 — falls through to L1.
 */
function detectL2BoldSubheading (string $line): ?array {
    if (preg_match('/^ {0,8}((?:\*\*[^*]+\*\*\s*)+)$/', $line, $m)) {
        $text = str_replace('**', '', trim($m[1]));
        $isAllCapsSection = preg_match('/^[A-Z][A-Z0-9_ \/\-]{2,50}$/', $text);
        // Single bold word at column 0 (e.g. "**Overview**") is L1, not L2
        if (!$isAllCapsSection
            && !(strpos($line, '**') === 0 && substr_count($line, '**') === 2)) {
            if (strlen($text) >= 3) {
                return ['level' => 2, 'text' => $text];
            }
        }
    }
    return null;
}

/**
 * L2: man .TP tagged paragraphs / option definition lines / perldoc =head2 /
 *     indented plain-text option flags.
 * Handles: bold+type pairs, bold option flags, perldoc head2, plain-text options.
 */
function detectL2IndentedPatterns (string $line): ?array {
    // man .TP tagged paragraphs with bold variable names + optional type
    // e.g. "       **CREATE**_**HOME** (boolean)"
    if (preg_match('/^ {7,}((?:(?:\*\*[^*]+\*\*[_\s]*)+\([a-z]+\)[,\s]*)+)/', $line, $m)) {
        $text = str_replace('**', '', trim($m[1]));
        if (strlen($text) >= 3) {
            return ['level' => 2, 'text' => $text];
        }
    }

    // man option definition lines — e.g. "       **-R**, **--root** _CHROOT_DIR_"
    if (preg_match('/^ {3,7}(\*\*-\w[\w\-]*)/', $line, $m)) {
        if (preg_match_all('/\*\*([^*]+)\*\*/', $line, $allMatches)) {
            $flags = [];
            foreach ($allMatches[1] as $seg) {
                if (preg_match('/^-/', $seg)) {
                    $flags[] = $seg;
                }
            }
            if (!empty($flags)) {
                $text = implode(' ', $flags);
                if (strlen($text) <= 80) {
                    return ['level' => 2, 'text' => $text];
                }
            }
        }
    }

    // perldoc =head2 — "  Methods you should implement" (2-space indent)
    $testLine = preg_replace('/_/', '', $line);
    if (preg_match('/^ {2}([A-Z][a-z][\w\s:\x27;\-,\\.]+)$/', $testLine, $m)) {
        $text = trim($m[1]);
        if (!preg_match('/^(This|That|These|Those|It|There)\s+(is|was|has|have|had|are|were)\b/i', $text)) {
            return ['level' => 2, 'text' => $text];
        }
    }

    // indented plain-text option flag (no bold markers)
    // e.g. "       -K, --config <file>"
    if (preg_match('/^ {4,8}(-{1,2}[a-zA-Z][\w\-]*'
            . '(?:\s*[<\[]\s*[^>\]]*[>\]])?'
            . '(?:\s*,\s*-{1,2}[a-zA-Z][\w\-]*(?:\s*[<\[]\s*[^>\]]*[>\]])?)*)'
            . '\s*$/',
            $line, $m)
        && strlen(trim($m[1])) <= 80) {
        return ['level' => 2, 'text' => trim($m[1])];
    }

    // pydoc class definitions: "    class Name(Parent)" (Parent may contain HTML link)
    $testLine = preg_replace('#<a[^>]*>|</a>#', '', $line);
    if (preg_match('/^ {4}class (\w+)\(/', $testLine, $m)) {
        return ['level' => 2, 'text' => 'class ' . $m[1]];
    }

    // pydoc function definitions: "    funcName(args)"
    if (preg_match('/^ {4}([a-z]\w*)\(/', $testLine, $m)
        && !preg_match('/^(class|def|if|for|while|with|try|import|from|return|yield|raise|print|assert|del|global|nonlocal|lambda|pass|break|continue|except|finally|elif|else|and|or|not|in|is)\b/', $m[1])) {
        return ['level' => 2, 'text' => $m[1]];
    }

    return null;
}

/**
 * L1: ALL CAPS section headings / perldoc =head1 / man .SH mixed case at column 0.
 * Includes header/footer rejection logic to avoid false positives.
 */
function detectL1Heading (string $line): ?array {
    // ALL CAPS — strip formatting markers first
    $plain = trim(str_replace(['**', '_'], '', $line));
    if (isset($line[0]) && $line[0] !== ' ' && $line[0] !== "\t"
        && preg_match('/^[A-Z][A-Z0-9_ \/\-]{2,50}$/', $plain)) {
        return ['level' => 1, 'text' => $plain];
    }

    // perldoc =head1 / man .SH at column 0, mixed case
    // Single-word titles: 3+ chars. Two-word: 10+. Three+: 16+.
    $noBold = str_replace(['**', '_'], '', $line);
    if (isset($line[0]) && $line[0] !== ' ' && $line[0] !== "\t"
        && preg_match('/^[A-Z][a-z][\w\s:\x27;\-,\.\(\)\/]+$/D', $noBold)
        && !preg_match('/[.!?:]\s*$/', $noBold)) {

        // Reject man page header/footer lines
        $noBoldTrimmed = trim($noBold);
        if (preg_match('/^(\w[\w\s.-]*?)\s{3,}.*\s{3,}\1\(\w+\)\s*$/', $noBoldTrimmed)) {
            return null;
        }
        if (preg_match('/^(\w+)\(\w+\)\s{3,}.*\s{3,}\1\(\w+\)\s*$/', $noBoldTrimmed)) {
            return null;
        }
        if (preg_match('/\w+\(\w+\)\s*$/', $noBoldTrimmed)
            && substr_count($noBoldTrimmed, '  ') >= 4) {
            return null;
        }
        $text = trim($noBold);
        $wordCount = substr_count($text, ' ') + 1;
        if ($wordCount === 1 && strlen($text) >= 3) {
            return ['level' => 1, 'text' => $text];
        }
        if ($wordCount === 2 && strlen($text) >= 10) {
            return ['level' => 1, 'text' => $text];
        }
        if ($wordCount >= 3 && strlen($text) >= 16) {
            return ['level' => 1, 'text' => $text];
        }
    }
    return null;
}

/**
 * Detect heading level and text from a man/perldoc line.
 * Returns ['level' => 1|2, 'text' => string] or null.
 * Dispatches to strategy functions in priority order: L2 patterns first, then L1.
 */
function detectHeadingType (string $line, string $mode = "man", ?string $nextLine = null): ?array {
    // Normalize: convert HTML bold/underline to markdown-style markers
    $line = preg_replace(['#</?b>#', '#</?u>#'], ['**', '_'], $line);

    // ri mode: only RDoc markup headings ("= L1", "== L2")
    if ($mode === "ri") {
        if (preg_match('/^= (.+)/', $line, $m)) {
            $text = trim(strip_tags(str_replace(['**', '_'], '', $m[1])));
            if ($text !== '' && $text !== '=') return ['level' => 1, 'text' => $text];
        }
        if (preg_match('/^== (.+)/', $line, $m)) {
            $text = trim(strip_tags(str_replace(['**', '_'], '', $m[1])));
            if ($text !== '' && $text !== '==') return ['level' => 2, 'text' => $text];
        }
        return null;
    }

    // info mode: Setext-style underline headings (text on current line, underline on next)
    // H1: *****  H2: =====  H3: -----
    // NOTE: falls through to generic L1/L2 detection below if no underline match.
    if ($mode === "info" && $nextLine !== null) {
        $trimmedNext = trim($nextLine);
        $len = strlen($trimmedNext);
        if ($len >= 3) {
            $char = $trimmedNext[0];
            if (in_array($char, ['*', '=', '-'], true) && $trimmedNext === str_repeat($char, $len)) {
                $text = trim(strip_tags(str_replace(['**', '_'], '', $line)));
                if ($text !== '') {
                    $level = ($char === '*') ? 1 : (($char === '=') ? 2 : 3);
                    return ['level' => $level, 'text' => $text, 'skipNext' => true];
                }
            }
        }
        // Fall through to generic detection below — not all info pages use Setext.
    }

    // L2 strategies (checked first — more specific patterns take priority)
    $result = detectL2ItalicSubheading($line);
    if ($result !== null) return $result;

    $result = detectL2BoldSubheading($line);
    if ($result !== null) return $result;

    $result = detectL2IndentedPatterns($line);
    if ($result !== null) return $result;

    // L1 strategies (checked last — broader patterns)
    $result = detectL1Heading($line);
    if ($result !== null) return $result;

    return null;
}

