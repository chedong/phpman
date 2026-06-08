# pydoc3 / ri 文档格式解析设计

> v2.3 新增：为 pydoc3（Python 3）和 ri（Ruby）命令行文档提供 HTML/Markdown/JSON/MCP 四种输出格式，
> 与已有的 man/perldoc/info 模式使用统一的内容管道。

---

## 一、架构概览

```
pydoc3 <module>  ─┐
                   ├──→ formatManPerlDoc($lines, "pydoc")    → HTML
                   ├──→ formatManPerlDocToMarkdown($lines)   → Markdown
                   ├──→ formatToJSON($lines, "", "pydoc")    → JSON
                   └──→ formatForOutput(json, "mcp")         → MCP

ri <Class#method> ─┐
                   ├──→ formatManPerlDoc($lines, "ri")       → HTML
                   ├──→ formatManPerlDocToMarkdown($lines)   → Markdown
                   ├──→ formatToJSON($lines, "", "ri")       → JSON
                   └──→ formatForOutput(json, "mcp")         → MCP
```

pydoc/ri 复用已有的 `formatManPerlDoc()` / `formatToJSON()` / `formatManPerlDocToMarkdown()` 管道，
通过 `$mode` 参数区分处理逻辑。代码位置：phpMan.php line 1717–1938。

---

## 二、URL 路由

| 路由 | 功能 | 对应函数 |
|------|------|----------|
| `GET /pydoc/{module}/{format}` | Python 模块文档 | `getPydocPage()` |
| `GET /pydoc/{format}` | Python 模块索引 | `getPydocIndex()` |
| `GET /ri/{Class#method}/{format}` | Ruby 类/方法文档 | `getRiPage()` |
| `GET /ri/{format}` | Ruby 类索引 | `getRiIndex()` |

### 搜索级联

v3.6 起，搜索始终聚合三个来源的结果（不再只在 apropos 无结果时才级联）：

```
getSearchPage()                  → FTS5/apropos (man pages)
  + getPydocSearchPage()         → pydoc3 -k 或 FTS5 pydoc 索引
  + getRiSearchPage()            → ri 命令或 FTS5 ri 索引
```

搜索优先级：FTS5 离线索引 > 命令行搜索 > FTS5 按源搜索

详见 [SEARCH_FTS5_DESIGN.md](SEARCH_FTS5_DESIGN.md)。

### MCP auto-detection

`cli_help` 根据命名约定自动选择文档源（line 1520–1559）：

| 输入特征 | 文档源 | 示例 |
|----------|--------|------|
| 包含 `::` | `getPerldocPage()` | `Digest::MD5` |
| 包含 `#` | `getRiPage()` | `Array#map` |
| 包含 `.`（无 `::`） | `getPydocPage()` | `json.loads`, `os.path` |
| 其他 | `getManPage()` → pydoc fallback → ri fallback | `ls` |

---

## 三、pydoc3 格式解析

### 3.1 原始输出格式

`pydoc3 <module>` 输出纯文本（无 overstrike/ANSI），典型结构：

```
Help on package json:

NAME
    json

DESCRIPTION
    JSON (JavaScript Object Notation) ...

CLASSES
    builtins.OSError(builtins.Exception)
        JSONDecodeError

    class JSONDecoder(builtins.object)
     |  Simple JSON decoder
     ...

    class JSONEncoder(builtins.object)
     ...

FUNCTIONS
    dump(obj, fp, ...)
        Serialize obj as JSON ...

    dumps(obj, ...)
        Serialize obj to JSON string ...
```

### 3.2 章节标题检测

pydoc 输出使用 **ALL CAPS 行** 作为 L1 章节标题（`NAME`, `DESCRIPTION`, `CLASSES`, `FUNCTIONS`, `DATA` 等），
这些被现有的 `detectL1Heading()` 函数通过 ALL CAPS 正则匹配（line 386）。

pydoc 模式 **不** 走 ri 专用的 RDoc 标记检测（line 433–444），而是走标准 man/perldoc L1/L2 检测流程。

### 3.3 类/函数定义检测（L2 子章节）

pydoc 有两个专用 L2 模式，在 `detectL2IndentedPatterns()` 中处理（line 363–373）：

**类定义** — `detectL2IndentedPatterns()` line 363：
```
    class Name(ParentClass)
```
- 4 空格缩进 + `class` + 类名 + `(`
- 括号内可能包含 HTML `<a>` 链接（父类被链接后），先用 `preg_replace` 剥离
- 提取为：`['level' => 2, 'text' => 'class Name']`

**函数/方法定义** — line 369：
```
    funcName(args)
```
- 4 空格缩进 + 小写开头标识符 + `(`
- 排除 Python 关键字（`class/def/if/for/while/with/try/import/from/return/yield/raise/print/assert/del/global/nonlocal/lambda/pass/break/continue/except/finally/elif/else/and/or/not/in/is`）
- 提取为：`['level' => 2, 'text' => 'funcName']`

### 3.4 HTML 链接处理（mode="pydoc"）

在 `formatManPerlDoc()` 中，pydoc 模式使用特定的链接模式（line 2332–2335）：

```
pattern: /class (\w+)\((\w+(?:\.\w+)*)\)/
replace: class $1(<a href="/pydoc/$2">$2</a>)
```

效果：父类引用自动变成可点击链接，如 `class JSONDecodeError(ValueError)` → `class JSONDecodeError(<a href="/pydoc/ValueError">ValueError</a>)`。

### 3.5 模块索引格式

`getPydocIndex()` 执行 `pydoc3 modules`，输出是多列文本格式：

```
Please wait ... (calculating module list)

BaseHTTPServer      email               json                ...
Bastion             encodings           keyword             ...
...
Enter any module name ...
```

解析策略（line 1742–1808）：
1. 跳过空白行之前的头部
2. 遇到 `Enter any module name` 停止
3. 用 `preg_split('/\s{2,}/', ...)` 按 2+ 空格拆分多列
4. 去重、排序
5. 按请求的 format（HTML/Markdown/JSON/MCP）输出

### 3.6 搜索格式

`getPydocSearchPage()` 执行 `pydoc3 -k <keyword>`，输出格式：

```
module_name - Description of the module
another_module - Another description
```

解析策略（line 1863–1922）：
- 正则 `^(\S+)\s*-\s*(.+)` 拆分模块名和描述
- 无描述的条目作为纯模块名列出
- 搜索结果包含 `name`, `description`, `link` 字段

---

## 四、ri（Ruby RDoc）格式解析

### 4.1 原始输出格式

`ri <Class#method>` 输出 **overstrike 格式**（字符^H字符，与 man 相同），典型结构：

```
= A\bAr\brr\bra\bay\by \b <\b< \b O\bOb\bbj\bje\bec\bct\bt

------------------------------------------------------------------------
= I\bIn\bnc\bcl\blu\bud\bde\bes\bs:\b:
Enumerable (from ruby core)

(from ruby core)
------------------------------------------------------------------------
Array indexing starts at 0...

= C\bCl\bla\bas\bss \b m\bme\bet\bth\bho\bod\bds\bs:\b:

  new
  try_convert

= I\bIn\bns\bst\bta\ban\bnc\bce\be \b m\bme\bet\bth\bho\bod\bds\bs:\b:

  %
  *
  []
  map
  ...
```

### 4.2 RDoc 标记章节检测（ri 专用）

ri 模式使用 **完全独立** 的章节检测逻辑，不走标准 L1/L2 检测。在 `detectHeadingType()` 中，
当 `$mode === "ri"` 时直接返回 RDoc 标记结果（line 433–444）：

| 标记 | 含义 | 检测结果 |
|------|------|----------|
| `= Heading` | L1 章节 | `['level' => 1, 'text' => 'Heading']` |
| `== Subheading` | L2 子章节 | `['level' => 2, 'text' => 'Subheading']` |

处理流程：
1. 先剥离 HTML 标签和 markdown 格式标记（`**`, `_`）
2. 正则 `/^= (.+)/` 匹配 L1，`/^== (.+)/` 匹配 L2
3. 过滤掉内容仅为 `=` 或 `==` 的行（分隔线误判）

**设计决策**：ri 完全不走标准检测，因为：
- ri 的 overstrike 清理后会产生 `= **Section** **Name**` 格式
- 标准 `detectL2BoldSubheading` 可能误判为 L2
- RDoc 标记 `= / ==` 简单可靠，不需要复用复杂逻辑

### 4.3 TOC 标签清理

ri 的 RDoc 标记 `=` 和 `==` 前缀需要在 TOC 中剥离。在 `buildToc()` 中：

```php
// L1 TOC: strip "= Heading" → "Heading" (line 1652-1653)
$label = preg_replace('/^=\s*/', '', $label);

// L2 TOC: strip "== Heading" → "Heading" (line 1663-1664)
$label = preg_replace('/^==\s*/', '', $label);
```

### 4.4 HTML 链接处理（mode="ri"）

在 `formatManPerlDoc()` 中，ri 模式使用两套链接模式（line 2336–2342）：

**父类链接**（与 pydoc 共用模式）：
```
pattern: /class (\w+)\((\w+(?:\.\w+)*)\)/
replace: class $1(<a href="/ri/$2">$2</a>)
```

**Ruby 常量/模块引用**（`::` 表示法）：
```
pattern: /((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/
replace: $3<a href="/ri/$4">$4</a>$6
```

效果：
- `SomeModule::SubClass` → `<a href="/ri/SomeModule::SubClass">SomeModule::SubClass</a>`
- 仅替换 white-space/comma/HTML-tag 限定的上下文，避免误匹配非模块名

### 4.5 索引格式

`getRiIndex()` 执行 `ri -l`，输出每行一个类/模块名（纯文本），无特殊解析。

### 4.6 搜索策略

ri **没有** 原生的 `ri -k`（关键字搜索），所以 `getRiSearchPage()` 直接执行 `ri <query>`。
ri 内置模糊匹配：如果找不到精确匹配，会尝试部分匹配。

首行过滤规则（无结果判断）：
- `Nothing known about` — 标准无结果响应
- `.xxx not found` — ri 对小写名称的特殊响应（如 `ri json` 返回 `.json not found`）

当 `ri` 命令无结果时，搜索级联回退到 `searchFtsBySource('ri')` 从 FTS5 索引中搜索 ri 条目。
详见 [SEARCH_FTS5_DESIGN.md](SEARCH_FTS5_DESIGN.md)。

---

## 五、内容管道共享机制

pydoc 和 ri 的内容处理管道与 man/perldoc/info **完全共用**：

| 处理阶段 | pydoc 特殊性 | ri 特殊性 |
|----------|-------------|-----------|
| `cleanTerminalOutput()` | 无 overstrike，直接通过 | 有 overstrike，与 man 相同处理 |
| `detectHeadingType()` | 标准 L1/L2 + pydoc 类/函数模式 | 专用 RDoc 标记模式（`=`/`==`） |
| `formatManPerlDoc()` | pydoc 父类链接模式 | ri 父类 + `::` 常量链接 |
| `formatToJSON()` | 标准 JSON 结构化 | 标准 JSON 结构化 |
| `formatForOutput()` | 标准 MCP 包装 | 标准 MCP 包装 |
| `buildToc()` | `=`/`==` 前缀剥离 | `=`/`==` 前缀剥离 |
| `fetchOfficialTldr()` | 无 TLDR（pydoc/ri 不触发） | 无 TLDR |

### cleanTerminalOutput()

位置：phpMan.php line 146–172。将 raw 终端输出中的 overstrike 和 ANSI 序列转换为 markdown 风格标记：

- `X^HX` → `**X**`（粗体）
- `_^HX` → `_X_`（下划线）
- `ESC[1m...ESC[0/22m` → `**...**`（ANSI 粗体）
- `ESC[4m...ESC[0/24m` → `_..._`（ANSI 下划线）

pydoc 输出不含这些序列，所以函数直接透传。

---

## 六、"Not Found" 处理

当 pydoc/ri 在服务器上找不到文档时，显示外部搜索链接：

| 模式 | 外链 | URL |
|------|------|-----|
| man | cheat.sh | `https://cheat.sh/{command}` |
| perldoc | MetaCPAN | `https://metacpan.org/pod/{module}` |
| **pydoc** | Python Docs | `https://docs.python.org/3/search.html?q={module}` |
| **ri** | Ruby-Doc | `https://ruby-doc.org/search.html?q={class}` |
| info | Google | `https://www.google.com/search?q={topic}` |

代码：phpMan.php line 1269–1288。

---

## 七、关键设计决策

### 7.1 为什么 ri 用独立章节检测

- ri 的 RDoc 标记（`= Section`, `== Subsection`）简单明确，不需要复用 man 的复杂 L1/L2 启发式
- overstrike 清理后产生的 `= **Bold** **Text**` 格式可能被标准 L2 粗体检测误判
- 隔离逻辑：未来 RDoc 格式变化只影响 ri 模式，不污染其他模式

### 7.2 为什么 pydoc 复用标准检测

- pydoc 输出是纯文本 ALL CAPS 章节，与 perldoc 的 `=head1` 格式兼容
- 类/函数定义作为 L2 子章节通过扩展 `detectL2IndentedPatterns` 处理
- 不需要独立检测路径，减少维护成本

### 7.3 没有 overstrike 处理不等于不需要 cleanTerminalOutput

pydoc 输出不含 overstrike/ANSI，`cleanTerminalOutput()` 对其是透传。
但统一管道保证了未来 pydoc 输出格式变化时不会出现格式不一致。

---

## 八、代码位置索引

| 功能 | 文件 | 行号 |
|------|------|------|
| URL 路由 dispatch | phpMan.php | 843–866 |
| MCP auto-detection | phpMan.php | 1520–1559 |
| `getPydocPage()` | phpMan.php | 1717–1727 |
| `getRiPage()` | phpMan.php | 1729–1739 |
| `getPydocIndex()` | phpMan.php | 1741–1808 |
| `getRiIndex()` | phpMan.php | 1810–1860 |
| `getPydocSearchPage()` | phpMan.php | 1862–1922 |
| `getRiSearchPage()` | phpMan.php | 1924–1938 |
| ri heading detection | phpMan.php | 433–444 |
| pydoc class/func detection | phpMan.php | 363–373 |
| mode-specific link patterns | phpMan.php | 2331–2353 |
| TOC label stripping (=/==) | phpMan.php | 1652, 1663 |
| Not found external links | phpMan.php | 1272–1288 |
| `cleanTerminalOutput()` | phpMan.php | 146–172 |
| `detectHeadingType()` | phpMan.php | 429–461 |
| `formatManPerlDoc()` | phpMan.php | 2285–2393 |
| `formatToJSON()` | phpMan.php | 3100–3338 |
| `formatForOutput()` | phpMan.php | 2398–2415 |
