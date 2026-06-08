# phpMan FTS5 全文搜索设计方案

> **状态:** v4 — FTS5 离线索引优先 + 命令行级联 fallback + 单次查询三源聚合
> **日期:** 2026-06-08 (v3.6.1)
> **环境:** PHP 7.2+ / SQLite 3.53.1 · `PRAGMA compile_options` 含 ENABLE_FTS5
> **关联设计:** [CACHE_DESIGN.md](CACHE_DESIGN.md) — 页面内容缓存架构

---

## 一、搜索架构总览

### 1.1 两级搜索策略：FTS5 优先，命令行 fallback

```
用户搜索请求
    ↓
┌──────────────────────────────────────────────────────┐
│ 第一级：FTS5 离线索引（快、有缓存）                    │
│                                                        │
│  getSearchPage() 一次 FTS5 查询覆盖全部三个来源:        │
│    search_fts MATCH 'json*'                            │
│      → section='3pm'  → $lines (man/perldoc)           │
│      → section='pydoc' → $pydocFtsLines                │
│      → section='ri'    → $riFtsLines                   │
│                                                        │
│  只索引标题(name) + 简介(description)                   │
│  不索引正文(body) — 避免索引膨胀                        │
└───────────────┬──────────────────────────────────────┘
                │ FTS5 某源无结果?
                ├── man 无结果 ↓
                │   exec("apropos ...") + indexAproposLines()
                ├── pydoc 无结果 ↓
                │   getPydocSearchPage() → pydoc3 -k
                └── ri 无结果 ↓
                    getRiSearchPage() → ri $parameter
```

### 1.2 单次 FTS5 查询三源聚合

v3.6.1 起，`getSearchPage()` 的 FTS5 查询**不再排除** pydoc/ri section，
一次查询即获取全部三个来源的结果，按 section 路由到不同数组：

```
getSearchPage($parameter)
    ↓ FTS5 单次查询
    $lines         ← section IN ('1','2','3','4','5','6','7','8','9','n','3pm','3perl'...)
    $pydocFtsLines ← section = 'pydoc'
    $riFtsLines    ← section = 'ri'
    ↓ FTS5 某源无结果时 fallback
    man 空   → apropos + indexAproposLines()
    pydoc 空 → getPydocSearchPage() (pydoc3 -k)
    ri 空    → getRiSearchPage() (ri command)
```

不再需要 `searchFtsBySource()` — FTS5 查询在 `getSearchPage()` 内一步完成。

### 1.3 搜索结果缓存

整个搜索过程通过 `cacheOrExecute('search', ...)` 包裹，结果写入 `cache` 表：

| 字段 | 值 |
|------|-----|
| `mode` | `'search'` |
| `name` | 搜索关键词 |
| `section` | 可选过滤 |
| `format` | 请求的输出格式 |
| `content` | gzip(聚合后的完整结果) |
| `ttl` | `3600`（1h 过期） |

相同关键词的重复搜索直接命中缓存，不走 FTS5 也不调命令行。

---

## 二、FTS5 离线索引构建

### 2.1 索引来源

FTS5 索引通过 `--build-index` 离线构建，来自三个系统命令的输出：

| 来源 | 命令 | 数据 | section 值 | source 值 |
|------|------|------|-----------|-----------|
| man pages | `apropos -s N .` | 命令名 + 一句话摘要 | `1`-`9`, `n` | `man` |
| Python 3 模块 | `pydoc3 modules` | 模块名 | `pydoc` | `pydoc` |
| Ruby 类/模块 | `ri -l` | 类名 | `ri` | `ri` |

### 2.2 索引内容：只索引标题和简介

```sql
CREATE VIRTUAL TABLE search_fts USING fts5(
    name,               -- 展开后的命令名: "JSON::Ext::Parser JSON Ext Parser json ext parser json::ext::parser"
    section,            -- 节号: "1", "3pm", "pydoc", "ri"
    description,        -- 一句话摘要: "Python 3 module", "Ruby class/module"
    body,               -- 空（不索引正文，避免索引膨胀）
    tokenize='unicode61 tokenchars ''-:''',
    prefix='1,2,3'
);
```

**为什么只索引标题+简介而不索引正文：**

- `apropos -s N .` 遍历 9,000+ 条目只需 ~3 秒；索引正文需要 `man` 逐条调用，~0.15s/页 × 13,000 ≈ 30 分钟
- 共享主机环境 fork 大量 `man` 进程会触发 `fork: retry: Resource temporarily unavailable`
- 标题+简介已足够覆盖绝大多数搜索场景

### 2.3 构建步骤

```
1. DROP + CREATE search_fts（比 DELETE FROM 快得多）
2. DELETE FROM search_index_meta

3. 遍历 man section 1-9, n:
   exec("apropos -s N .") → 解析每行 "name (section) — description"
   INSERT INTO search_fts (expandNameForFts(name), section, description, '')
   INSERT INTO search_index_meta (name, section, 'man')

4. pydoc3 模块:
   exec("pydoc3 modules") → 按 2+ 空格拆分多列
   INSERT INTO search_fts (expandNameForFts(module), 'pydoc', 'Python 3 module', '')
   INSERT INTO search_index_meta (module, 'pydoc', 'pydoc')

5. ri 类/模块:
   exec("ri -l") → 每行一个类名
   INSERT INTO search_fts (expandNameForFts(class), 'ri', 'Ruby class/module', '')
   INSERT INTO search_index_meta (class, 'ri', 'ri')
```

### 2.4 触发方式

```
CLI:  php phpMan.php --build-index
Cron: 0 3 * * * php /path/to/phpMan.php --build-index-cron
URL:  GET /phpMan.php?build-index (仅限本地请求)
```

### 2.5 数据量

| 来源 | 条目数（production） | 索引时间 |
|------|---------------------|---------|
| man pages | 9,630 | ~5s |
| pydoc3 modules | 341 | ~1s |
| ri classes | 3,878 | ~2s |
| **合计** | **13,849** | **~10s** |

---

## 三、name 列分词策略

### 3.1 expandNameForFts() — 索引时展开

为了让 FTS5 的 `unicode61` 分词器能同时匹配精确名称和各组成部分，`name` 列存储展开后的多 token 字符串：

```
原始名称              → 展开后存储到 search_fts.name
─────────────────────────────────────────────────────────────────────
git-commit            → git-commit git commit git-commit
File::Find            → File::Find File Find file find file::find
JSON::Ext::Parser     → JSON::Ext::Parser JSON Ext Parser json ext parser json::ext::parser
json.decoder          → json.decoder json decoder json.decoder
ls                    → ls ls
```

展开规则：
1. **原始名称**：始终保留
2. **连字符展开**：`git-commit` → 追加 `git commit`
3. **双冒号展开**：`File::Find` → 追加 `File Find` + `file find`（小写）+ `file::find`（小写原始）
4. **点号展开**：`json.decoder` → 追加 `json decoder`
5. **小写副本**：始终追加 `mb_strtolower(name)`，确保大小写不敏感匹配

### 3.2 FTS5 tokenizer 配置

```sql
tokenchars '-:'
```

使 `-` 和 `:` 成为 token 字符的一部分，而非分隔符：

| 原文 | 默认 unicode61 | 加 `tokenchars='-:'` |
|------|---------------|---------------------|
| `git-commit` | `git` `commit` | `git-commit` |
| `File::Find` | `File` `Find` | `File::Find` |
| `--verbose` | `verbose` | `--verbose` |

### 3.3 buildFtsQuery() — 搜索时保留特殊字符

用户搜索 `git-commit` 或 `File::Find` 时，`buildFtsQuery()` 保留 `-`、`:`、`.`、`_`：

```php
// 默认：对每个词做前缀搜索
$parts[] = '"' . $t . '"*';
// "json" → '"json"*' — 匹配 json, JSON::Ext, json.decoder 等
// "git-commit" → '"git-commit"*' — 匹配 git-commit 精确名
// "Parser" → '"Parser"*' — 匹配 JSON::Ext::Parser 的展开部分
```

### 3.4 大小写不敏感

FTS5 `unicode61` 分词器默认将 token 转为小写存储。配合 `expandNameForFts()` 追加的小写副本：

- 搜索 `json` → 匹配 `json`、`JSON::PP`（展开含 `json`）、`Psych::JSON`（展开含 `json`）
- 搜索 `JSON` → FTS5 自动转小写 `json` → 同上
- 搜索 `Parser` → FTS5 自动转小写 `parser` → 匹配 `JSON::Ext::Parser`（展开含 `parser`）

---

## 四、搜索执行流程（详细）

### 4.1 getSearchPage() — 单次 FTS5 查询覆盖三源

```
getSearchPage($parameter, $section, $format)
    ↓
1. 构建 FTS5 查询: buildFtsQuery($parameter)
    ↓
2. FTS5 可用? (search_index_meta 有数据)
    ├── YES → SELECT FROM search_fts WHERE MATCH :q
    │         ORDER BY rank LIMIT 300    ← 包含 pydoc/ri section
    │         按 section 路由:
    │           section='pydoc' → $pydocFtsLines
    │           section='ri'    → $riFtsLines
    │           其他             → $lines (man pages)
    └── NO  → 空结果，进入 fallback
    ↓
3. $lines 为空? → exec("apropos ...") + indexAproposLines()
    ↓
4. 输出格式处理:
    json/mcp → $lines→results, $pydocFtsLines→pydoc_results, $riFtsLines→ri_results
    html     → <h2>apropos</h2> + $lines
              + <h2>Python 3</h2> + $pydocFtsLines (或 getPydocSearchPage)
              + <h2>Ruby (ri)</h2> + $riFtsLines (或 getRiSearchPage)
```

### 4.2 搜索级联 — pydoc3 和 ri 命令行 fallback

FTS5 查询已在 `getSearchPage()` 内完成三源路由。级联只在 FTS5 某源无结果时触发：

```
search case (cacheOrExecute 包裹):
    ↓
getSearchPage()       → 一次 FTS5 查询
    → $lines (man), $pydocFtsLines, $riFtsLines
    ↓
$pydocFtsLines 非空? → 用 FTS5 pydoc 结果
    └── 空 → getPydocSearchPage() → pydoc3 -k
    ↓
$riFtsLines 非空?    → 用 FTS5 ri 结果
    └── 空 → getRiSearchPage() → ri command
```

### 4.3 getRiSearchPage() — "not found" 过滤

ri 命令的模糊匹配结果有时不是真正的搜索结果，需要过滤：

```php
// 以下首行表示无结果，返回空
if (preg_match('/^Nothing known about/i', $first_line)) return "";
if (preg_match('/^\.json not found/i', $first_line)) return "";  // ri 对小写名的特殊响应
```

### 4.4 getSearchPage() JSON 输出 — pydoc/ri 从 FTS5 合并

`getSearchPage()` 的 FTS5 查询按 section 路由后，JSON 输出直接从 `$pydocFtsLines` / `$riFtsLines` 合并：

```json
{
  "results": [...],          // man/perldoc 条目
  "pydoc_results": [...],   // Python 3 模块条目
  "ri_results": [...]       // Ruby 类/模块条目
}
```

搜索级联在 `cacheOrExecute` 闭包内完成聚合，确保缓存结果也包含三源数据。

### 4.5 Section 列表模式 `(1)` `(3pm)` `(n)` — 必须使用 apropos

当搜索参数为 section-only 格式（如 `(1)`、`(3pm)`、`(n)`）时，`getSearchPage()` 检测到 `$sectionOnly = true`，**直接跳过 FTS5，使用 `apropos -s <section> .` 进行全量枚举**。

**为什么必须用 apropos 而非 FTS5：**

1. **FTS5 覆盖不全**：`apropos -s 1 .` 返回 2872 个唯一名，FTS5 section=`1` 仅 2659 个。少了的 213 个是 1p/1ssl/1mh 等子 section 条目，FTS5 将它们存在对应 sub-section（如 section=`1p`）中
2. **不应混入 pydoc/ri**：Section 列表请求的是特定 man 节的全部命令，pydoc 模块（section=`pydoc`）和 ri 类（section=`ri`）不应出现
3. **FTS5 设计为全文搜索**：按 section 枚举不是 FTS5 的设计目标；FTS5 的 rank 排序对全量列出无意义

**性能**：`apropos` 依赖 whatis/mandb 数据库，首次可能需 15-30s。结果通过 `cacheOrExecute` 缓存到 SQLite（~500KB），后续请求 ~4s。性能瓶颈在 apropos 数据库质量，不在 phpMan 缓存层。

---

## 五、数据模型

### 5.1 search_fts — FTS5 全文索引表

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(
    name,               -- 展开后的命令名
    section,            -- "1"-"9","n","pydoc","ri"
    description,        -- 一句话摘要
    body,               -- 空（不索引正文）
    tokenize='unicode61 tokenchars ''-:''',
    prefix='1,2,3'
);
```

### 5.2 search_index_meta — 索引元数据

```sql
CREATE TABLE IF NOT EXISTS search_index_meta (
    name        TEXT NOT NULL,
    section     TEXT NOT NULL DEFAULT '',
    source      TEXT NOT NULL DEFAULT 'man',  -- man|pydoc|ri
    body_len    INTEGER NOT NULL DEFAULT 0,
    hits        INTEGER NOT NULL DEFAULT 0,
    last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE(name, section, source)
);
```

### 5.3 FTS5 可用性检测

`getSearchPage()` 检查 `search_index_meta` 总行数（不限 source），确认至少有数据时才走 FTS5：

```php
$totalIndexed = $db->querySingle("SELECT COUNT(*) FROM search_index_meta");
if ($totalIndexed > 0) { /* 使用 FTS5 */ }
```

---

## 六、搜索排序

### 6.1 5 层排序

| 层级 | 因子 | 权重 | 说明 |
|------|------|------|------|
| **L1** | 精确名称匹配 | 最高 | `name == query` |
| **L2** | 名称前缀匹配 | 高 | `name LIKE 'query%'` |
| **L3** | BM25 相关性 | 中 | FTS5 内置 rank |
| **L4** | Section 优先级 | 中低 | 1 > 8 > 3 > 5 > 7 > 4 > 6 > 9 |
| **L5** | 命中次数(hits) | 低 | 被浏览越多越靠前 |

---

## 七、搜索结果缓存

### 7.1 缓存策略

| 场景 | 策略 |
|------|------|
| 正常搜索 | `cacheOrExecute('search', ...)` 缓存 1h |
| FTS5 命中 | 缓存聚合结果（含 pydoc_results / ri_results） |
| FTS5 未命中 → apropos | 缓存 apropos 结果；apropos 结果回填 FTS5 |
| 索引重建 | `DELETE FROM cache WHERE mode='search'` |
| Schema 升级 | 全部清除 |

### 7.2 搜索页面不进 FTS5 索引

```
mode='search' 的页面：
  ✅ 缓存到 cache 表
  ❌ 不写入 search_fts 索引
  ❌ 不触发 hits 计数
  ❌ 输出 <meta name="robots" content="noindex, follow">
```

### 7.3 增量索引

apropos fallback 的结果会通过 `indexAproposLines()` 回填到 FTS5：

```php
// getSearchPage() 中，apropos fallback 之后
if (!empty($lines) && !$sectionOnly) {
    indexAproposLines($lines);  // 回填 FTS5，下次搜索可直接命中
}
```

---

## 八、回退机制总览

```
搜索请求
    ↓
cache 命中? → YES → 直接返回缓存结果
    ↓ NO
FTS5 一次查询 search_fts (man + pydoc + ri)
    → $lines (man), $pydocFtsLines, $riFtsLines
    ↓
$lines 为空? → exec("apropos ...") + indexAproposLines()
$pydocFtsLines 为空? → getPydocSearchPage() (pydoc3 -k)
$riFtsLines 为空?    → getRiSearchPage() (ri command)
    ↓
聚合三源结果 → 缓存 → 返回
```

---

## 九、与其他搜索源的关系

### 9.1 FTS5 三源索引覆盖

```
search_fts 索引:
  ├── man pages    — name + section + description  (via apropos -s N .)
  ├── pydoc3       — name + 'pydoc' + description  (via pydoc3 modules)
  └── ri           — name + 'ri' + description     (via ri -l)
```

### 9.2 搜索优先级

| 优先级 | 数据源 | 触发条件 |
|--------|--------|---------|
| 1 | FTS5 离线索引（三源单次查询） | 索引有数据时优先使用 |
| 2 | apropos 命令 | FTS5 无 man 数据或未命中 |
| 3 | pydoc3 -k 命令 | FTS5 无 pydoc 数据时 fallback |
| 4 | ri 命令 | FTS5 无 ri 数据时 fallback |

### 9.3 为什么单次 FTS5 查询优于分源查询

- **性能**：一次 SQL 查询 vs 三次 SQL 查询 + PHP 序列化/反序列化
- **简洁**：`getSearchPage()` 内完成路由，级联层无需再调 `searchFtsBySource()`
- **一致性**：FTS5 的 rank 排序跨源统一，不会因分源查询而丢失相关性信息

---

## 十、重建索引脚本

### 10.1 重建方式

```bash
# CLI 全量重建
php phpMan.php --build-index

# Cron 模式（带时间戳）
php phpMan.php --build-index-cron

# 查看帮助
php phpMan.php --help
```

Cron 示例（每日凌晨 3 点）：
```
0 3 * * * /usr/bin/php /path/to/phpMan.php --build-index-cron
```

索引重建通过 `CACHE_DIR` 配置定位数据库文件，不需要额外参数。

---

## 十一、文件与部署

### 11.1 部署顺序

```
1. 更新 phpMan.php
2. scp 到服务器
3. 执行 php phpMan.php --build-index（重建含 pydoc/ri 的 FTS5 索引）
4. 验证: curl /phpMan.php/search/json/json | python3 -m json.tool
5. 检查 ri_results / pydoc_results 是否有数据
```

### 11.2 相关文件

| 文件 | 改动 |
|------|------|
| `phpMan.php` | `getSearchPage()` 单次 FTS5 查询覆盖三源；`rebuildSearchIndex()` 含 man+pydoc+ri 索引 + meta-guard 去重；`expandNameForFts()` 大小写+点号+冒号展开；`getRiSearchPage()` 过滤 `.xxx not found`；`--help` CLI 帮助 |
| `docs/SEARCH_FTS5_DESIGN.md` | 本文档 |
| `test/unit/test_search_fts.php` | 更新 expandNameForFts 期望值 |
