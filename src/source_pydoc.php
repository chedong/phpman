<?php
function getPydocPage (string $parameter, string $format = "html"): string {
    $lines = array();
    phpManExec("pydoc3 ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code !== 0 || count($lines) === 0) {
        return "";
    }
    if ($format === "markdown") return formatManPerlDocToMarkdown($lines, $parameter, "pydoc");
    if ($format === "json" || $format === "mcp") return formatPageOutput($lines, $parameter, "", "pydoc", $format);
    return formatManPerlDoc($lines, "pydoc");
}

//get specified class/method's ri page

function getPydocIndex (string $format = "html"): string {
    $lines = array();
    phpManExec("pydoc3 modules", $lines, $return_code);
    if ($return_code !== 0 || empty($lines)) {
        return "";
    }
    // pydoc3 modules outputs multi-column format. Split each line on whitespace
    // to extract individual module names, skipping header/footer lines.
    $modules = array();
    $in_body = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === "") {
            $in_body = true;  // blank line separates header from module list
            continue;
        }
        if (!$in_body) continue;
        // Stop at the footer line
        if (preg_match('/^Enter any module name/i', $trimmed)) break;
        // Split on 2+ spaces (multi-column layout)
        $parts = preg_split('/\s{2,}/', $trimmed);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== "" && !preg_match('/^\s*$/', $part)) {
                $modules[] = $part;
            }
        }
    }
    if (empty($modules)) return "";
    sort($modules);

    $script_name = ($format === "markdown" || $format === "json" || $format === "mcp") ? baseUrl() : scriptName();
    if ($format === "markdown") {
        $output = "# Python 3 Module Index (pydoc)\n\n";
        foreach ($modules as $mod) {
            $output .= "- [{$mod}]({$script_name}/pydoc/".urlencode($mod)."/markdown)\n";
        }
        return $output;
    }
    if ($format === "json" || $format === "mcp") {
        $items = array();
        foreach ($modules as $mod) {
            $items[] = array(
                "name" => $mod,
                "link" => $script_name . "/pydoc/" . urlencode($mod) . "/json"
            );
        }
        $result = json_encode(array(
            "mode" => "pydoc",
            "parameter" => "modules",
            "count" => count($items),
            "entries" => $items
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($format === "mcp") {
            $result = json_encode(array(
                "content" => array(array("type" => "text", "text" => $result)),
                "structuredContent" => json_decode($result, true)
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return $result;
    }
    $output = "";
    $results = [];
    foreach ($modules as $mod) {
        $results[] = ['name' => $mod, 'section' => '', 'description' => '', 'sources' => ['pydoc']];
    }
    $rendered = renderGroupedResults($results, $script_name);
    return $rendered['html'];
}

//get ri class index (ri -l)

/**
 * 用 FTS 索引给 pydoc 搜索兜底，返回与 `pydoc3 -k` 同样形态的 "name - description" 行。
 *
 * 复用 searchFtsBySource() 的 json 形态再映射回行：它已经有 100 条上限、rank 排序、
 * 去重和 expandNameForFts() 的展开名处理，重写一遍查询只会多一处要维护的东西。
 */
function pydocSearchLinesFromFts (string $parameter): array {
    if (!function_exists('searchFtsBySource')) return array();
    $items = searchFtsBySource($parameter, 'pydoc', 'json');
    if (!is_array($items)) return array();
    $lines = array();
    foreach ($items as $it) {
        $name = (string)($it['name'] ?? '');
        if ($name === '') continue;
        $desc = (string)($it['description'] ?? '');
        $lines[] = ($desc !== '') ? "{$name} - {$desc}" : $name;
    }
    return $lines;
}

function getPydocSearchPage (string $parameter, string $format = "html"): string {
    $lines = array();
    $ok = phpManExec("pydoc3 -k ".escapeshellarg($parameter), $lines, $return_code);
    if (!$ok || empty($lines)) {
        // #247/#248: pydoc3 -k 会 import 整个 stdlib —— 实测生产上 9.5 秒、病态时 30 秒，
        // 而这是每次「未命中的 pydoc 名」的首次访问都要付的。超时（或真的没有结果）时
        // 退到 FTS：索引里已经有 pydoc 的模块名，查询是毫秒级的。
        //
        // 代价是描述退化成索引里的统一常量（src/search_index.php:655 写死 'Python 3
        // module'）。**有名字好过挂 20 秒然后报错**，而且这是 404 兜底页，语境已经说明
        // 「没找到，这是相近的」。
        //
        // 这是**降级路径，不是替代**：正常路径仍然用 pydoc3 -k 的真 docstring 描述
        // （`json - JSON (JavaScript Object Notation) <https://json.org> is a subset of`）。
        $lines = pydocSearchLinesFromFts($parameter);
        if (empty($lines)) {
            return "";
        }
    }
    $script_name = ($format === "markdown" || $format === "json" || $format === "mcp") ? baseUrl() : scriptName();
    if ($format === "markdown") {
        $output = "# pydoc3 search: {$parameter}\n\n";
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(\S+)\s*-\s*(.+)/', $trimmed, $m)) {
                $output .= "- [{$m[1]}]({$script_name}/pydoc/".urlencode($m[1])."/markdown) — {$m[2]}\n";
            } elseif ($trimmed !== "") {
                $output .= "- [{$trimmed}]({$script_name}/pydoc/".urlencode($trimmed)."/markdown)\n";
            }
        }
        return $output;
    }
    if ($format === "json" || $format === "mcp") {
        $items = array();
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(\S+)\s*-\s*(.+)/', $trimmed, $m)) {
                $items[] = array(
                    "name" => $m[1],
                    "description" => $m[2],
                    "link" => $script_name . "/pydoc/" . urlencode($m[1]) . "/json"
                );
            } elseif ($trimmed !== "") {
                $items[] = array(
                    "name" => $trimmed,
                    "link" => $script_name . "/pydoc/" . urlencode($trimmed) . "/json"
                );
            }
        }
        $result = json_encode(array(
            "mode" => "pydoc",
            "parameter" => $parameter,
            "results" => $items
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($format === "mcp") {
            $result = json_encode(array(
                "content" => array(array("type" => "text", "text" => $result)),
                "structuredContent" => json_decode($result, true)
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return $result;
    }
    $output = "<ul>\n";
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^(\S+)\s*-\s*(.+)/', $trimmed, $m)) {
            $output .= '<li><a href="'.$script_name.'/pydoc/'.urlencode($m[1]).'">'.h($m[1]).'</a> — '.h($m[2]).'</li>'."\n";
        } elseif ($trimmed !== "") {
            $output .= '<li><a href="'.$script_name.'/pydoc/'.urlencode($trimmed).'">'.h($trimmed).'</a></li>'."\n";
        }
    }
    $output .= "</ul>\n";
    return $output;
}

//get ri search results (ri already does fuzzy matching, try as direct lookup)
