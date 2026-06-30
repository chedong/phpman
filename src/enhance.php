<?php
function callLLM(string $systemPrompt, string $userMessage, string $context = ''): string {

    // Build ordered list of endpoints: primary + fallbacks
    $endpoints = [[
        'url' => LLM_API_URL,
        'key' => LLM_API_KEY,
        'model' => LLM_MODEL,
        'label' => 'primary',
    ]];
    if (defined('LLM_FALLBACKS') && is_array(LLM_FALLBACKS)) {
        foreach (LLM_FALLBACKS as $i => $fb) {
            $fbUrl  = $fb['url'] ?? '';
            $fbKey  = $fb['key'] ?? '';
            $fbModel = $fb['model'] ?? '';
            if ($fbUrl === '' || $fbKey === '') continue;
            $endpoints[] = [
                'url' => $fbUrl,
                'key' => $fbKey,
                'model' => $fbModel,
                'label' => 'fallback-' . ($i + 1),
            ];
        }
    }

    if (empty($endpoints[0]['url']) || empty($endpoints[0]['key'])) return '';

    $lastError = '';

    foreach ($endpoints as $ep) {
        $result = callLLMEndpoint($systemPrompt, $userMessage, $ep, $context);
        if ($result !== null) return $result;
        // null = retryable failure (5xx, timeout, connection error) — try next
        // '' = non-retryable failure (4xx, invalid response) — stop
        if ($result === '' && $ep['label'] !== 'primary') break;
    }

    return '';
}

/**
 * Call a single LLM endpoint. Returns:
 *   string       — success (may be empty for reasoning_content)
 *   null         — retryable failure (try next fallback)
 *   '' (empty)   — non-retryable failure (stop)
 */
function callLLMEndpoint(string $systemPrompt, string $userMessage, array $ep, string $context = ''): ?string {
    $payload = json_encode([
        'model' => $ep['model'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ],
        'max_tokens' => (int)LLM_MAX_TOKENS,
        'temperature' => 0.3,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($ep['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $ep['key'],
            'User-Agent: phpMan/' . (defined('GIT_DESCRIBE') ? GIT_DESCRIBE : 'unknown'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $label = $ep['label'];

    // Connection / timeout errors — retryable
    if ($response === false || $response === '') {
        $msg = "LLM [{$label}] {$context}: API call failed [HTTP " . ($httpCode ?: '0') . "]: " . ($error ?: 'empty response');
        phpManLog($msg);
        return ($httpCode >= 500 || $httpCode === 0 || $httpCode === 429) ? null : '';
    }

    $data = json_decode($response, true);
    if ($data === null) {
        // 502/503 with non-JSON body — retryable
        $retryable = ($httpCode >= 500 || $httpCode === 429);
        phpManLog("LLM [{$label}] {$context}: JSON decode failed [HTTP {$httpCode}] (" . strlen($response) . " bytes)" .
            ($retryable ? ' — retrying with fallback' : ''));
        return $retryable ? null : '';
    }

    if (!empty($data['error'])) {
        $errType = $data['error']['type'] ?? 'unknown';
        $errMsg  = $data['error']['message'] ?? 'no message';
        $errCode = $data['error']['code'] ?? '';
        // 4xx errors (auth, bad request) — NOT retryable
        $retryable = ($httpCode >= 500 || $httpCode === 429);
        phpManLog("LLM [{$label}] {$context}: API error [HTTP {$httpCode}] [{$errType}] {$errMsg}" .
            ($errCode ? " (code: {$errCode})" : "") .
            ($retryable ? ' — retrying with fallback' : ''));
        return $retryable ? null : '';
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    if ($content === '') {
        $content = $data['choices'][0]['message']['reasoning_content'] ?? '';
    }

    $finishReason = $data['choices'][0]['finish_reason'] ?? '';
    if ($finishReason === 'length') {
        phpManLog("LLM [{$label}] {$context}: output truncated (finish_reason=length), tokens_used=" .
            json_encode($data['usage'] ?? []));
    }

    if ($label !== 'primary') {
        phpManLog("LLM [{$label}] {$context}: fallback succeeded after primary failure");
    }

    return trim($content);
}

/**
 * Shared OKF enhancement system prompt.
 * Transforms plain man page Markdown into Open Knowledge Format (OKF) — agent-optimized,
 * token-efficient Markdown with YAML frontmatter. No emoji, no visual decoration.
 *
 * Used by both enhanceManPage() (web-triggered) and batch_enhance.php (CLI batch).
 */
function getMdEnhancePrompt(): string {
    return "You are a documentation formatting assistant. Transform plain man page Markdown into Open Knowledge Format (OKF) — a clean, agent-optimized, token-efficient Markdown variant.\n\n" .
        "Output rules:\n" .
        "1. Output MUST start with YAML frontmatter delimited by --- lines. Required fields: type: CommandReference, command, mode, section, source. See template below.\n" .
        "2. Output ONLY valid Markdown — no HTML tags, no code fences around the entire output, no preamble outside frontmatter\n" .
        "3. Preserve ALL original technical information (options, flags, syntax, descriptions)\n" .
        "4. Do NOT invent new content — only reorganize and condense existing content\n" .
        "5. Use ONLY Markdown formatting: `backticks` for code, **double stars** for bold, [text](url) for links. NEVER use <code>, <b>, <i>, <a> or any HTML tags.\n" .
        "6. CRITICAL: NO emoji anywhere. Zero. No section prefixes, no inline emoji, no decorative symbols. Emoji waste tokens for AI agents.\n" .
        "7. Code blocks MUST have language tags: ```shell, ```perl, ```python, ```ruby, ```c, ```text\n" .
        "8. Function names, method signatures, and class names MUST be wrapped in `backticks`: `search()`, `match(pattern, string)`, `re.compile()`\n" .
        "9. Cross-references use standard Markdown links to the canonical source, not bare URLs\n\n" .
        "YAML frontmatter template:\n" .
        "```\n" .
        "---\n" .
        "type: CommandReference\n" .
        "command: <name>\n" .
        "mode: <man|perldoc|info|pydoc|ri>\n" .
        "section: <section number or empty>\n" .
        "source: <man-pages|perldoc|info|pydoc3|ri>\n" .
        "---\n" .
        "```\n\n" .
        "Section structure (in order):\n" .
        "- YAML frontmatter\n" .
        "- ## Quick Reference — ALWAYS the first section. If input contains a TLDR/Quick Reference block, condense to ≤8 most useful examples. If not, extract the most common use cases from the content. Format: \"- `command` — one-line description\"\n" .
        "- ## Name — one-line description (extract from original NAME section, no emoji)\n" .
        "- ## Synopsis — command syntax, condensed\n" .
        "- ## Options — grouped logically. Each option: \"- `-f, --flag` — description\". Skip rarely-used options; prioritize the most important ones.\n" .
        "- ## Examples — if the original has examples, preserve them. Code blocks with language tags.\n" .
        "- ## See Also — related commands with standard links\n" .
        "- ## Exit Codes — ONLY if explicitly documented in the original\n\n" .
        "Condensation rules:\n" .
        "- For function/class reference sections (pydoc, ri, perldoc): list as \"- `name` — description\" without decorative prefixes\n" .
        "- Merge repetitive descriptions; prefer tight, information-dense formatting\n" .
        "- Condense output to under " . number_format(PHPMAN_ENHANCE_MAX_CHARS) . " characters\n" .
        "- Every character counts — this output is consumed by AI agents, not humans";
}

/**
 * Shared HTML enhancement system prompt.
 * Used by both enhanceManPage() (web-triggered) and batch_enhance.php (CLI batch).
 */
function getHtmlEnhancePrompt(): string {
    return "You are a Linux documentation emoji-enhancement assistant. Transform man page HTML into an emoji-rich, visually scannable version optimized for both human developers and AI agents.\n\n" .
        "CRITICAL: Structure preservation — your PRIMARY goal is to keep the original document's heading hierarchy and content structure intact. Only ADD emoji and visual polish; do NOT restructure.\n\n" .
        "CRITICAL heading rules:\n" .
        "1. NEVER use <h1> — the page already has an H1 title. Start from <h2>\n" .
        "2. Section titles (NAME, SYNOPSIS, DESCRIPTION, OPTIONS, EXAMPLES, SEE ALSO) → <h2> with ONE emoji prefix\n" .
        "3. Sub-sections within a section → <h3> with emoji prefix\n" .
        "4. Comments in code examples are NOT headings — do NOT wrap them in <h1>/<h2>/<h3>\n\n" .
        "CRITICAL code block rules:\n" .
        "5. ALL code MUST be wrapped in <pre><code>...</code></pre>\n" .
        "6. Code includes anything with: \$variable, ->method, use Module;, function(), flags like -f --long\n" .
        "7. Even single-line code statements need <pre><code> — never leave code as bare text with <br>\n" .
        "8. Code blocks MUST contain EXACT original code — NO changes, NO added links, NO emoji inside <pre><code>. NEVER put <a href> inside a <pre><code> block. The copy-button will break and the code becomes invalid.\n" .
        "9. Only add emoji comments AFTER the closing </pre> — never inside code blocks\n\n" .
        "CRITICAL list formatting:\n" .
        "10. Use standard <ul><li> or <ol><li> for ALL lists. Emoji may appear inside <li> text content, but NEVER replace the list structure with emoji-only lines.\n" .
        "11. NEVER use emoji characters (🔹, 🔸, ▪️, ▫️, ➡️, 📌, 🟢) as visual bullet replacements at the start of paragraphs — always use proper HTML list tags.\n\n" .
        "CRITICAL function/class reference formatting:\n" .
        "12. For function, method, and class reference sections (pydoc modules, ruby ri classes, perldoc function lists): use <li><code>name(args)</code> — description</li> format WITHOUT per-item emoji. Function names MUST be in <code> tags for copyability. Emoji-per-item in long lists hurts scannability — only the section heading needs an emoji.\n\n" .
        "CRITICAL XSS prevention:\n" .
        "13. ANY < or > NOT part of an allowed HTML tag (<h2>, <h3>, <p>, <br>,\n" .
        "    <b>, <u>, <a>, <pre>, <code>, <table>, <tr>, <td>, <th>, <ul>, <ol>,\n" .
        "    <li>, <div>, <span>, <em>, <strong>, <hr>, <blockquote>) MUST be escaped\n" .
        "    as &lt; and &gt;. Example: print qq(<input>) → print qq(&lt;input&gt;)\n" .
        "14. Before output, scan your entire response. Fix any bare < or > outside\n" .
        "    allowed tags. This is a SECURITY requirement.\n\n" .
        "Output rules:\n" .
        "15. Output ONLY valid HTML — no code fences, no JSON wrapper, no preamble\n" .
        "16. Preserve ALL original technical information — do NOT create new sections or content\n" .
        "17. Preserve <b>, <u>, <a href> tags from the original — they carry semantic meaning. But do NOT add new <a> links inside code blocks.\n" .
        "18. Add descriptive emoji to option descriptions and list item text\n" .
        "19. Keep original HTML structure and section ordering intact\n" .
        "20. Emoji should be standard Unicode, widely supported\n" .
        "21. 🚀 Quick Reference: ALWAYS create a <h2>🚀 Quick Reference</h2> section as the second section (right after NAME). Use a <table> with columns Use Case | Command | Description. Command cells use <code> (NOT <pre><code> — pre blocks break table layout). Keep descriptions concise. If the input contains a TLDR block, preserve and emoji-enhance it. If not, generate common use cases from the content — this is critical for AI agents and quick human lookup.\n" .
        "22. Exit Codes: add an <h2>🚪 Exit Codes</h2> section ONLY if the original document explicitly lists exit codes\n" .
        "23. IMPORTANT: Condense your output to under " . number_format(PHPMAN_ENHANCE_MAX_CHARS) . " characters. Preserve key sections but summarize/combine verbatim repetition. Prefer tight formatting over verbosity.";
}

/**
 * Full LLM HTML enhancement — sends rendered man page HTML to LLM
 * and caches the emoji-enhanced HTML output. Used offline via --enhance CLI.
 *
 * @return string Enhanced HTML, or empty string on failure
 */
function enhanceManPage(string $mode, string $name): string {
    $cache = new PageCache();

    // ── Phase 1: emoji_md (Markdown, for /markdown view) ──
    $enhancedMd = $cache->get($mode, $name, '', 'emoji_md');
    if ($enhancedMd === null || PageCache::isNotFound($enhancedMd)) {
        $plainMd = '';
        switch ($mode) {
            case 'man':    $plainMd = getManPage($name, '', 'markdown'); break;
            case 'perldoc': $plainMd = getPerldocPage($name, 'markdown'); break;
            case 'info':   $plainMd = getInfoPage($name, 'markdown'); break;
            case 'pydoc':  $plainMd = getPydocPage($name, 'markdown'); break;
            case 'ri':     $plainMd = getRiPage($name, 'markdown'); break;
        }
        if (trim($plainMd) !== '') {

            // Fetch TLDR for man pages — inject as Quick Reference seed
            $mdPrompt = getMdEnhancePrompt();
            $mdUserMessage = "Transform this man page Markdown into an emoji-enhanced version:\n\n";
            if ($mode === 'man') {
                $tldrData = fetchOfficialTldr($name, $mode, '');
                if (!empty($tldrData['examples'])) {
                    $mdUserMessage .= "## TLDR / Quick Reference (from tldr-pages)\n";
                    foreach (array_slice($tldrData['examples'], 0, 8) as $ex) {
                        $mdUserMessage .= "- `{$ex['command']}` — {$ex['description']}\n";
                    }
                    $mdUserMessage .= "\n";
                }
            }
            $mdUserMessage .= $plainMd;

            $enhancedMd = callLLM($mdPrompt, $mdUserMessage, "{$mode}/{$name} emoji_md");
            if ($enhancedMd !== '') {
                $enhancedMd = preg_replace('/^```(?:markdown|md)?\s*\n?/m', '', $enhancedMd);
                $enhancedMd = preg_replace('/\n?```\s*$/m', '', $enhancedMd);
                $enhancedMd = preg_replace('/^\[?[\w.-]+\]?\(\d+\w*\)\s+.*\s+\[?[\w.-]+\]?\(\d+\w*\)\s*\n/', '', $enhancedMd);
                // Fix internal links broken by CLI context: replace localhost absolute paths
                // with deployment-agnostic relative links (e.g. man/tar/markdown)
                $enhancedMd = preg_replace(
                    '#https?://localhost\S*?/(man|perldoc|info|pydoc|ri)/(\S+?)/markdown#',
                    '$1/$2/markdown',
                    $enhancedMd
                );
                $enhancedMd = trim($enhancedMd);
                if ($enhancedMd !== '') {
                    $cache->set($mode, $name, '', 'emoji_md', $enhancedMd, 'found');
                    echo "  [md] {$mode}/{$name}: enhanced (" . strlen($enhancedMd) . " chars)\n";
                }
            }
        }
    } else {
        echo "  [md] {$mode}/{$name}: already enhanced\n";
    }

    // ── Phase 2: emoji_html (HTML, for default view) ──
    $enhancedHtml = $cache->get($mode, $name, '', 'emoji_html');
    if ($enhancedHtml === null || PageCache::isNotFound($enhancedHtml)) {
        $rawHtml = '';
        switch ($mode) {
            case 'man':    $rawHtml = getManPage($name, '', 'html'); break;
            case 'perldoc': $rawHtml = getPerldocPage($name, 'html'); break;
            case 'info':   $rawHtml = getInfoPage($name, 'html'); break;
            case 'pydoc':  $rawHtml = getPydocPage($name, 'html'); break;
            case 'ri':     $rawHtml = getRiPage($name, 'html'); break;
        }
        if (trim($rawHtml) !== '') {
            // Extract just the man-content block
            if (preg_match('#<div id="man-content">(.+?)</div>#s', $rawHtml, $m)) {
                $rawHtml = $m[1];
            } elseif (preg_match('#<pre>(.+?)</pre>#s', $rawHtml, $m)) {
                $rawHtml = $m[1];
            }
            // DO NOT truncate input — LLM needs full content for proper structure.
            // Instead, prompt instructs LLM to keep output under limit.
            // (SCRIPT_NAME already fixed at function entry — links are correct)

            $htmlPrompt = getHtmlEnhancePrompt();
            $htmlUserMessage = "Transform this man page HTML into an emoji-enhanced version:\n\n";
            if ($mode === 'man') {
                $tldrData = fetchOfficialTldr($name, $mode, '');
                if (!empty($tldrData['examples'])) {
                    $htmlUserMessage .= "<h2>TLDR / Quick Reference (from tldr-pages)</h2>\n<table>\n";
                    foreach (array_slice($tldrData['examples'], 0, 8) as $ex) {
                        $desc = h($ex['description'] ?? '');
                        $cmd = h($ex['command'] ?? '');
                        $htmlUserMessage .= "<tr><td>{$cmd}</td><td>{$desc}</td></tr>\n";
                    }
                    $htmlUserMessage .= "</table>\n\n";
                }
            }
            $htmlUserMessage .= $rawHtml;

            $enhancedHtml = callLLM($htmlPrompt, $htmlUserMessage, "{$mode}/{$name} emoji_html");
            if ($enhancedHtml !== '') {
                $enhancedHtml = preg_replace('/^```(?:html)?\s*\n?/m', '', $enhancedHtml);
                $enhancedHtml = preg_replace('/\n?```\s*$/m', '', $enhancedHtml);
                // cleanEmojiHtml() handles DOCTYPE/html/head/body stripping + XSS defense
                $enhancedHtml = cleanEmojiHtml($enhancedHtml);
                // SCRIPT_NAME was fixed at function entry — links are already correct
                $enhancedHtml = trim($enhancedHtml);
                if ($enhancedHtml !== '') {
                    $cache->set($mode, $name, '', 'emoji_html', $enhancedHtml, 'found');
                    echo "  [html] {$mode}/{$name}: enhanced (" . strlen($enhancedHtml) . " chars)\n";
                }
            }
        }
    } else {
        echo "  [html] {$mode}/{$name}: already enhanced\n";
    }

    return $enhancedHtml ?? '';
}

/**
 * Post-process LLM-generated emoji HTML to fix common mistakes:
 * - Stray <h1> tags → <h2> (page already has H1 title from breadcrumb)
 * - Strip dangerous HTML tags as XSS defense-in-depth
 */
function cleanEmojiHtml(string $html): string {
    // Fix: <h1> → <h2> — LLM sometimes ignores the "never use h1" rule
    $html = preg_replace('#<(/?)h1\b([^>]*)>#i', '<$1h2$2>', $html);

    // Strip full-document wrappers: LLM output or old cache may contain
    // <!DOCTYPE html><html><head>...<body> which nests inside phpMan's own HTML
    $html = preg_replace('#^<!DOCTYPE[^>]*>\s*#i', '', $html);
    $html = preg_replace('#</?html[^>]*>#i', '', $html);
    $html = preg_replace('#</?head[^>]*>.*?</head>#is', '', $html);
    $html = preg_replace('#</?body[^>]*>#i', '', $html);

    // Remove <script>/<style>/<meta>/<link>/<title> with content.
    // strip_tags() removes tags but LEAVES text, leaking JSON-LD/CSS.
    $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html);
    $html = preg_replace('#<meta[^>]*>#i', '', $html);
    $html = preg_replace('#<link[^>]*>#i', '', $html);
    $html = preg_replace('#<title[^>]*>.*?</title>#is', '', $html);

    // XSS defense: strip any tag not in the safe allowlist.
    // LLM output may contain unescaped code like <input>, <form>, <script>
    // that browsers would interpret as real HTML.
    $safeTags = '<h2><h3><h4><h5><h6><p><br><b><u><i><em><strong><a>'
              . '<pre><code><table><thead><tbody><tr><td><th><ul><ol><li>'
              . '<div><span><hr><blockquote><sup><sub><small><del>';
    $html = strip_tags($html, $safeTags);

    // #155: XSS defense-in-depth — strip_tags preserves event handlers on allowed tags.
    // Post-process: remove on* attributes (double-quoted, single-quoted, unquoted)
    // and neutralize javascript: URIs in all quote variants.
    $html = preg_replace(
        [
            '/\bon\w+\s*=\s*"[^"]*"/i',     // onclick="..."
            '/\bon\w+\s*=\s*\'[^\']*\'/i',   // onclick='...'
            '/\bon\w+\s*=\s*[^\s>]+/i',      // onclick=... (unquoted, runs to space or >)
            '/href\s*=\s*"javascript:/i',     // href="javascript:..."
            '/href\s*=\s*\'javascript:/i',    // href='javascript:...'
            '/href\s*=\s*"data:text/i',       // href="data:text/html,..."
            '/href\s*=\s*\'data:text/i',      // href='data:text/html,...'
            '/href\s*=\s*"vbscript:/i',       // href="vbscript:..."
            '/href\s*=\s*\'vbscript:/i',      // href='vbscript:...'
            '/\bstyle\s*=\s*"[^"]*\b(?:expression|javascript|vbscript|url\s*\()/i',  // style="expression(...)"
            '/\bstyle\s*=\s*\'[^\']*\b(?:expression|javascript|vbscript|url\s*\()/i', // style='expression(...)'
        ],
        ['', '', '', 'href="#"', 'href="#"', 'href="#"', 'href="#"', 'href="#"', 'href="#"', '', ''],
        $html
    );

    return $html;
}

/**
 * Render floating TOC sidebar from tocItems structure.
 * Each item: {"id": "...", "label": "...", "children": [{"id": "...", "label": "..."}]}
 * Returns '' when not enough items to warrant a sidebar.
 */
