<?php
/**
 * Golden-corpus normalization library (#255, Task 1).
 *
 * No Composer, no framework, no runtime dependencies: plain PHP functions,
 * included with require_once. CLI 与 Web 共用同一实现。
 *
 * 归一化规则（注释即文档）：
 * - corpusStripFormatting：先去 ANSI SGR 转义（\x1b[4m…\x1b[24m，正则
 *   /\x1b\[[0-9;]*m/），再去 overstrike 退格序列（正则 /(.)\x08\1/ → '$1'，
 *   即 man 粗体打印的 b\x08b → b）。
 * - corpusNormalizeText：strip → 逐行 trim（rtrim 去行尾空白，行首缩进一并
 *   抹平——man/perldoc 缩进风格不同，归一化正文要可比）→ 丢页眉页脚 →
 *   连续空行压成一个 → trim。页眉/页脚判定：一条
 *   preg_match('/^[A-Z0-9][A-Z0-9_.:\-]*\(\d[a-z]*\)\s/', $line)；只有当
 *   同一行文本在首、末各出现一次时才丢弃（array_search 比较首末非空行），
 *   避免误删 perldoc 正文。
 * - corpusReadManifest：制表符分隔 pages.txt，`#` 开头为注释行，空 section
 *   字段解析为空串。
 * - corpusTokens：preg_split('/\s+/', trim($s))，过滤空串。
 * - corpusMissingTokens：对 raw 做计数多重集，逐 token 消耗 rendered 的计数，
 *   返回仍未满足的 token（保持 raw 中的出现顺序）。
 * - corpusTextFromHtml：标签替换为空格（strip_tags 会把 "<p>a</p><p>b</p>"
 *   变成 "ab"，丢失词边界）→ html_entity_decode(…, ENT_QUOTES|ENT_HTML5,
 *   'UTF-8') → preg_replace('/\s+/', ' ', …) → trim。
 */

declare(strict_types=1);

function corpusStripFormatting(string $text): string {
    // 去 ANSI SGR 转义（粗体/下划线等 \x1b[..m）
    $text = preg_replace('/\x1b\[[0-9;]*m/', '', $text);
    // 去 overstrike：字符 \x08 同字符（man 粗体 b\x08b）
    $text = preg_replace('/(.)\x08\1/', '$1', $text);
    return $text;
}

function corpusNormalizeText(string $text): string {
    $text = corpusStripFormatting($text);
    // 逐行 trim：rtrim 去行尾空白与 \r；行首缩进抹平（man 用 4 空格缩进、
    // perldoc 用 7 空格，归一化后的正文要逐词可比）
    $lines = array_map(static fn(string $l): string => trim($l), explode("\n", $text));

    // man 页眉页脚：形如 "LS(1)   User Commands   LS(1)" 的行，只有当同一行
    // 文本在首、末各出现一次时才丢弃；末行不同（perldoc 没有页眉）或全篇
    // 只出现一次则保留，避免误删唯一的首行正文。
    $headerRe = '/^[A-Z0-9][A-Z0-9_.:\-]*\(\d[a-z]*\)\s/';
    $firstKey = null;
    $lastKey = null;
    foreach ($lines as $i => $l) {
        if ($l !== '') {
            if ($firstKey === null) { $firstKey = $i; }
            $lastKey = $i;
        }
    }
    if ($firstKey !== null && $lastKey > $firstKey
        && $lines[$firstKey] === $lines[$lastKey]
        && preg_match($headerRe, $lines[$firstKey])) {
        // 该文本全篇恰好出现两次、且首次出现在开头（array_search 首键核对）
        $count = 0;
        foreach ($lines as $l) {
            if ($l === $lines[$firstKey]) { $count++; }
        }
        if ($count === 2 && array_search($lines[$firstKey], $lines, true) === $firstKey) {
            unset($lines[$firstKey], $lines[$lastKey]);
        }
    }

    // 连续空行压成一个
    $out = [];
    $prevBlank = false;
    foreach ($lines as $line) {
        if ($line === '') {
            if ($prevBlank) { continue; }
            $prevBlank = true;
        } else {
            $prevBlank = false;
        }
        $out[] = $line;
    }
    return trim(implode("\n", $out));
}

/**
 * 读取制表符分隔的语料清单。`#` 开头为注释行；字段：id, tool, name, section；
 * 空 section（如 perldoc 页没有节）解析为空串。
 *
 * @return array<int, array{id: string, tool: string, name: string, section: string}>
 */
function corpusReadManifest(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException("manifest not found: {$path}");
    }
    $rows = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') { continue; }
        $f = explode("\t", $line);
        if (count($f) < 3) { continue; }
        $rows[] = [
            'id' => $f[0],
            'tool' => $f[1],
            'name' => $f[2],
            'section' => isset($f[3]) ? $f[3] : '',
        ];
    }
    return $rows;
}

/**
 * 按空白切词：返回所有非空 token（保留原文顺序）。
 *
 * @return string[]
 */
function corpusTokens(string $text): array {
    $pieces = preg_split('/\s+/', trim($text));
    return array_values(array_filter($pieces, static fn($p) => $p !== ''));
}

/**
 * 缺失词多重集：对 raw 逐 token 消耗 rendered 的计数，
 * 返回仍未满足的 token，保持 raw 中的出现顺序。
 *
 * @param string[] $rawTokens
 * @param string[] $renderedTokens
 * @return string[]
 */
function corpusMissingTokens(array $rawTokens, array $renderedTokens): array {
    $renderedCount = [];
    foreach ($renderedTokens as $t) {
        $renderedCount[$t] = ($renderedCount[$t] ?? 0) + 1;
    }
    $missing = [];
    foreach ($rawTokens as $t) {
        if (isset($renderedCount[$t]) && $renderedCount[$t] > 0) {
            $renderedCount[$t]--;
        } else {
            $missing[] = $t;
        }
    }
    return $missing;
}

/**
 * HTML 抽正文：标签替换为空格（strip_tags 会把 "<p>a</p><p>b</p>" 折成 "ab"，
 * 丢掉词边界）→ 实体解码（ENT_QUOTES|ENT_HTML5, UTF-8）→ 连续空白折成一个
 * 空格 → trim。
 */
function corpusTextFromHtml(string $html): string {
    $text = preg_replace('/<[^>]*>/', ' ', $html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}
