# phpMan FTS5 全文搜索设计方案

> **状态:** v3 — 全文正文索引 + 命令行连字符/双冒号处理
> **日期:** 2026-06-04
> **环境:** PHP 7.2+ / SQLite 3.53.1 · `PRAGMA compile_options` 含 ENABLE_FTS5
> **关联设计:** [CACHE_DESIGN.md](CACHE_DESIGN.md) — 页面内容缓存架构

---

## 一、现状与问题

### 1.1 当前搜索流程

```
用户输入关键字 → getSearchPage($parameter)
    ↓
exec("apropos " . escapeshellarg($parameter))   ← 每次都调系统命令
    ↓
解析 apropos 输出（4 种正则格式）
    ↓
返回 HTML / JSON / Markdown
```

### 1.2 问题

| 问题 | 影响 |
|------|------|
| 每个搜索请求都调 `apropos` 子进程 | 耗时 ~0.15-0.35s，不支持高并发 |
| 无排序 | apropos 返回顺序不可控，结果质量差 |
| 无搜索结果缓存 | 相同关键词每次都重新执行 |
| 只搜 apropos 摘要（description） | 不覆盖 man 页**正文**内容 |
| 命令行连字符被忽略 | `git-commit` 作为「入口」可查，但正文里的 `--verbose`、`-r` 搜不到 |

### 1.3 已有基础设施

- `cache_fts` FTS5 虚拟表（`mode, name, section, title`）— 用于已缓存页面标题索引/自动补全
- `PageCache` SQLite 缓存 — man/perldoc/info/pydoc/ri 页面渲染结果已可复用
- SQLite 连接共享（`cacheDb()` 静态变量复用）

---

## 二、核心设计决策

### 2.1 搜索范围：索引整个页面正文

FTS5 索引包含文档全文，而不仅是 apropos 摘要。索引内容来自：

| 来源 | 内容 | 正文提取方式 |
|------|------|-------------|
| man HTML 缓存 | 渲染后的纯文本正文 | `strip_tags()` + 提取 `<body>` 内文本 |
| man 命令实时 | 首次索引构建时批量获取 | `man name \| col -b` |
| perldoc / info / pydoc / ri | 同 man，统一处理 | 同体系 |

索引构建时，每条入口包含：
1. `name` — 命令名（如 `git-commit`）
2. `section` — 节号（如 `1`, `3pm`, `n`）
3. `description` — apropos 一句话摘要
4. `body` — 页面正文纯文本（去 HTML / overstrike / ANSI）

### 2.2 命令行连字符和双冒号：`-` `::` 作为 token 字符

**默认 `unicode61` 分词器将 `-` 和 `:` 都视为分隔符**，导致：

| 原文 | 分词结果 | 问题 |
|------|---------|------|
| `git-commit` | token `git` + `commit` | 丢失原始名称 |
| `--verbose` | token `verbose` | 丢失选项标志 |
| `-r` | token `r` | 丢失选项标志 |
| `File::Find` | token `File` + `Find` | 丢失模块名 |
| `Test::More` | token `Test` + `More` | 丢失模块名 |

**解决方案：`unicode61` 的 `tokenchars` 选项，同时保留 `-` 和 `:`**

```sql
CREATE VIRTUAL TABLE search_fts USING fts5(
    name, section, description, body,
    tokenize='unicode61 tokenchars ''-:''',
    prefix='1,2,3'
);
```

**效果：** `-` 和 `:` 都被保留为 token 字符的一部分：

| 原文 | 默认 unicode61 | 加 `tokenchars='-:'` |
|------|---------------|---------------------|
| `git-commit` | `git` `commit` | `git-commit` |
| `--verbose` | `verbose` | `--verbose` |
| `-r` | `r` | `-r` |
| `File::Find` | `File` `Find` | `File::Find` |
| `Test::More` | `Test` `More` | `Test::More` |
| `recursive` | `recursive` | `recursive` |
| `file-system` | `file` `system` | `file-system` |

**关于 `:` 在正文中的影响：** 日常正文里的冒号主要出现在时间格式（`3:00` → 单 token `3:00`）、引用标记等处。这些 token 仍可通过前缀搜索匹配（`"3"*` → 匹配 `3:00`），对实际搜索体验影响极小。

**关于 `::` vs `:` ：** 只有连续两个 `::` 才有模块名匹配意义。但 `tokenchars=':'` 会使单个冒号也被纳入 token。权衡之下利远大于弊：正文中极少需要单独搜前或后字符，而 Perl 模块名 `File::Find` 的一次性正确匹配价值更高。

### 2.3 name 列特殊处理

仅靠 `tokenchars='-:'` 会导致 `git-commit` 和 `File::Find` 成为单一 token，搜索 `git` 无法匹配 `git-commit`，搜 `Find` 无法匹配 `File::Find`。

**解决方案：`name` 列存储展开后的名称**

```
索引时:
  "git-commit"    → 存入 "git-commit git commit"
  "File::Find"    → 存入 "File::Find File Find"
  "DBI::db::commit" → 存入 "DBI::db::commit DBI db commit"

搜索时:
  "git"            → 匹配 "git" (在展开部分的 "git commit" 里)
  "git-commit"     → 精确匹配 token "git-commit"
  "Find"           → 匹配 "Find" (在展开部分的 "File Find" 里)
  "File::Find"     → 精确匹配 token "File::Find"
```

实现代码：

```php
/**
 * 展开带连字符/双冒号的命令名，支持两种方式的搜索
 * "git-commit"   → "git-commit git commit"
 * "File::Find"   → "File::Find File Find"
 * "ls"           → "ls"（无特殊字符的不变）
 */
function expandNameForFts(string $name): string {
    $expanded = $name;

    // 连字符展开: "git-commit" → 追加 "git commit"
    if (str_contains($name, '-')) {
        $expanded .= ' ' . str_replace('-', ' ', $name);
    }

    // 双冒号展开: "File::Find" → 追加 "File Find"
    if (str_contains($name, '::')) {
        $expanded .= ' ' . str_replace('::', ' ', $name);
    }

    return $expanded;
}
```

### 2.4 搜索查询保留连字符

`buildFtsQuery()` 对用户输入的全文搜索词**不再剔除 `-`**：

```php
function buildFtsQuery(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';

    // 检测用户是否已经使用了 FTS5 操作符
    if (preg_match('/\b(AND|OR|NOT|NEAR)\b/i', $raw)) {
        return $raw;
    }

    // 检测精确短语（带引号）
    if (preg_match('/^".*"$/', $raw)) {
        return $raw;
    }

    // 默认：对每个词做前缀搜索
    $terms = preg_split('/\s+/', $raw);
    $parts = [];
    foreach ($terms as $t) {
        $t = trim($t);
        if ($t !== '') {
            // 保留连字符(-)、冒号(:)、下划线(_)、点(.) — 这些在命令行名中至关重要
            // 只剔除不可打印字符和除上述之外的特殊符号
            $t = preg_replace('/[^\p{L}\p{N}\.\-_\:\x{2013}\x{2014}]/u', '', $t);
            if ($t !== '') {
                $parts[] = '"' . $t . '"*';
            }
        }
    }

    return implode(' AND ', $parts);
}
```

**注意 Unicode 破折号：** 用户可能输入 `--`（连字符+连字符）或 `—`（em dash, U+2014），两者在搜索时不应相互转换，保留作为不同 token。

---

## 三、数据模型

### 3.1 search_fts — 独立 FTS5（内部表）

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(
    name,               -- 展开后的命令名: "git-commit git commit"
    section,            -- 节号: "1", "3pm", "n", "perlfunc"
    description,        -- apropos 一句话摘要
    body,               -- 页面正文纯文本（全文）
    tokenize='unicode61 tokenchars ''-''',
    prefix='1,2,3'      -- 前 1/2/3 字符前缀索引，支持短命令名
);
```

**为什么用独立 FTS5 而非外部表：** 写入即查，不需要额外的 rebuild 同步。body 内容随索引构建一起写入，不依赖 cache 表 rowid。

### 3.2 search_index_meta — 索引元数据表

```sql
CREATE TABLE IF NOT EXISTS search_index_meta (
    name        TEXT NOT NULL,           -- 原始命令名
    section     TEXT NOT NULL DEFAULT '',
    source      TEXT NOT NULL DEFAULT 'man',  -- man|perldoc|info|pydoc|ri
    body_len    INTEGER NOT NULL DEFAULT 0,   -- 正文长度（字符数）
    hits        INTEGER NOT NULL DEFAULT 0,   -- 被访问次数
    last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE(name, section, source)
);
```

此表用于：
- 统计：总共索引了多少条目，正文总量
- 排序：hits 值用于搜索结果排序
- 增量追踪：记录每条目最后索引时间

### 3.3 已有表不受影响

| 表 | 角色 | 是否变更 |
|----|------|---------|
| `cache` | 页面内容缓存 | ✅ 扩展支持 `mode='search'` 搜索结果缓存 |
| `cache_fts` | 已缓存页面标题索引 | ✅ 不变（保留用于自动补全） |
| `meta` | 版本/元信息 | ✅ 新增 `search_index_count` 等键 |

---

## 四、索引构建

### 4.1 触发方式

```
CLI:  php phpMan.php --build-index
URL:  GET /phpMan.php/search/--build-index?token=<admin_token>
Cron: 0 3 * * 1 curl -s "https://www.chedong.com/phpMan.php/search/--build-index?token=..."
```

### 4.2 构建步骤

```
1. 清空 search_fts + search_index_meta

2. 遍历 man section 1-9, n:
   a. exec("apropos -s N .") → 获取该节所有命令名
   b. 对每个命令名:
      - exec("man N name 2>/dev/null | col -b")
      - 或者: 从 PageCache 中取已缓存的 raw 内容
      - 提取 name, section, description（第一行 NAME 段）
      - 提取 body（NAME 段之后的内容）
   c. INSERT INTO search_fts VALUES(
        expandNameForFts(name),
        section, description, body
      )
   d. INSERT INTO search_index_meta ... ON CONFLICT IGNORE

3. 收集 perldoc:
   - exec("perldoc -l ModuleName") 或 apropos -s 3pm .
   - 类似步骤 2，但 source='perldoc'

4. 收集 info / pydoc / ri（同体系）
```

### 4.3 正文提取

```
exec("man N command 2>/dev/null | col -b")
```

`col -b` 去掉退格符/overstrike → 纯文本。进一步处理：
- 只保留 `NAME` 段之后的正文（去掉头部版权声明）
- 去掉多余空行
- body 长度截断（最大 ~100KB，防止单页撑爆索引）

### 4.4 增量同步

首次部署后手动 `build-index`。后续维护：

**方案 A：PageCache 触发（推荐）**

```php
// PageCache::set() 中同步
public function set(...): bool {
    // ... 写入 cache 表 ...

    // 同步到 search_fts（仅当该条目尚未索引时）
    if ($ok && $status === 'found' && $mode !== 'search') {
        // 已通过 cache_fts 索引 → 检查 search_fts 是否已有
        // 若无则从 body 提取并插入
    }

    return $ok;
}
```

**方案 B：定时全量重建（更简单）**

```
0 3 * * 1 php /path/to/phpMan.php --build-index
```

周一凌晨低峰期重建，每次 ~3-5 分钟（含正文提取）。

### 4.5 数据量估算

| 指标 | 每页 | 总计 (~13,000 页) |
|------|------|------------------|
| 索引前 body | ~25 KB | ~325 MB |
| FTS5 索引后 | ~8 KB | ~104 MB |
| 构建时间（含 man 调用） | ~20ms | ~4-5 min |
| 构建时间（从缓存读取） | ~0.1ms | ~1-2s |

**构建瓶颈：** 首次全量重建时，`man` 系统命令调用占大头（~0.15-0.25s/页 × 13,000 ≈ 30-55 分钟）。
**优化方案：** 使用已有的 `PageCache` 中缓存的 raw 内容，只有未缓存页面才调用 `man`。首次构建建议用预热模式（`--warmup`）先填充缓存。

---

## 五、搜索结果跨源合并

### 5.1 合并规则

同一 `(name, section)` 出现在多个源中 → 合并为一条结果。合并发生在 PHP 应用层。

```php
function mergeSearchResults(array $rows): array {
    $merged = [];

    foreach ($rows as $row) {
        $key = $row['name'] . "\0" . $row['section'];

        if (!isset($merged[$key])) {
            $merged[$key] = [
                'name'        => $row['name'],
                'section'     => $row['section'],
                'description' => $row['description'],
                'sources'     => [$row['source']],
                'hits'        => (int)$row['hits'],
            ];
        } else {
            if (!in_array($row['source'], $merged[$key]['sources'])) {
                $merged[$key]['sources'][] = $row['source'];
            }
            $merged[$key]['hits'] += (int)$row['hits'];
        }
    }

    return array_values($merged);
}
```

### 5.2 处理矩阵

| 场景 | 合并? | 示例 |
|------|-------|------|
| `man ls` + `info ls` | ✅ 合并 | 同一入口两个源 |
| `man 3 printf` + `man 3pm printf` | ❌ 不合并 | 不同 section |
| `man cron` + `man crontab` | ❌ 不合并 | 不同命令名 |
| `man 1 printf` + `perldoc -f printf` | ❌ 不合并 | 不同 section 不同源 |

---

## 六、搜索排序

### 6.1 5 层排序

| 层级 | 因子 | 权重 | 说明 |
|------|------|------|------|
| **L1** | 精确名称匹配 | 最高 | `name == query`（展开名也计入） |
| **L2** | 名称前缀匹配 | 高 | `name LIKE 'query%'` |
| **L3** | BM25 相关性 | 中 | FTS5 内置 rank（覆盖 name + body） |
| **L4** | Section 优先级 | 中低 | 1 > 8 > 3 > 5 > 7 > 4 > 6 > 9 > perldoc > info > pydoc > ri |
| **L5** | 命中次数(hits) | 低 | 被浏览越多越靠前 |

### 6.2 搜索示例

| 搜索词 | 匹配位置 | 命中结果 |
|--------|---------|---------|
| `git` | 入口名 | `git(1)`→`git-add(1)`→`git-commit(1)`→`git(7)` |
| `git-commit` | 入口名（精确） | `git-commit(1)` 置顶 |
| `--verbose` | **正文全文** | 所有含 `--verbose` 选项的 man 页 |
| `File::Find` | 入口名（精确） | `File::Find(3pm)` 置顶 |
| `Find` | 入口名展开 + 正文 | `File::Find(3pm)` 排前（展开名匹配）|
| `DBI db commit` | 入口名展开 | `DBI::db::commit(3pm)` |
| `perl` | 描述 + 正文 | man 页 + perldoc 混合结果 |
| `rsync -v` | 入口名 `rsync` + 正文 `-v` | `rsync(1)` 置顶 |

---

## 七、搜索结果缓存

### 7.1 存储

搜索结果写入 `cache` 表，`mode='search'`：

| 字段 | 值 |
|------|-----|
| `mode` | `'search'` |
| `name` | 归一化查询 |
| `section` | 可选过滤 |
| `format` | `'json'`（统一中间格式） |
| `content` | gzip(已合并排序后的完整 JSON) |
| `ttl` | `3600`（1h 过期） |
| `status` | `'found'` |

### 7.2 过期策略

| 场景 | 策略 |
|------|------|
| 正常过期 | TTL 3600s |
| 索引重建 | `DELETE FROM cache WHERE mode='search'` |
| 手动跳过 | `?force=1` 参数 |
| Schema 升级 | 全部清除 |

### 7.3 搜索页面不进索引

```
mode='search' 的页面：
  ✅ 缓存到 cache 表
  ❌ 不写入 search_fts 索引
  ❌ 不触发 hits 计数
  ❌ 输出 <meta name="robots" content="noindex, nofollow">
```

实现：

```php
// PageCache::set() — 跳过 search 模式
if ($ok && $status === 'found' && $mode !== 'search') {
    $this->syncFts($cacheId, $mode, $name, $section, $content);
}

// showHeader() — 自动 noindex
if ($mode === 'search' && $parameter !== '') {
    echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
}
```

---

## 八、回退机制

```
搜索请求 → FTS5 可用? → YES → search_fts MATCH + 合并排序
                        NO  → exec("apropos ...") 传统回退
```

FTS5 不可用时直接回退到现有 `apropos` 模式，不做特殊处理。

---

## 九、升级路径

### 9.1 向后兼容

| 场景 | 当前 | 升级后 |
|------|------|--------|
| 已有缓存 DB | cache + cache_fts | 追加 search_fts + search_index_meta |
| 已缓存页面 | PageCache::set | 不变（不影响 cache 表） |
| FTS5 不可用 | 跳过 cache_fts | 跳过 → apropos fallback |
| 搜索 URL | `/search/{query}` | 不变 |
| 搜索结果页 robots | 无 | `noindex, nofollow` |
| 命令行搜索结果 | `gr` 匹配 `grep(1)` | 同，且正文含 `--group` 也能搜到 |

### 9.2 Schema 升级

```php
define('CACHE_SCHEMA_VERSION', '2');

// cacheDb() 版本检查：
if ($row !== CACHE_SCHEMA_VERSION) {
    $db->exec("DELETE FROM cache WHERE mode='search'");
    $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(...)");
    $db->exec("CREATE TABLE IF NOT EXISTS search_index_meta (...)");
    // 不清除已有缓存，只追加新表
}
```

---

## 十、与其他搜索源的关系

```
搜索调度: getSearchPage($parameter, $section, $format)
    ↓
FTS5 搜索 search_fts 覆盖:
  ├── man     pages  — name+section+description+body (全文)
  ├── perldoc        — name+section+description+body (全文)
  ├── info           — name+section+description+body (全文)
  ├── pydoc3         — name+section+description+body (全文)
  └── ri             — name+section+description+body (全文)
```

搜索结果在本级已覆盖所有文档源。现有 pydoc/ri 级联引擎（`getPydocSearchPage`/`getRiSearchPage`）作为增量补充：

```
getSearchPage($parameter)
    ↓
  results = searchFts($parameter)    ← 主结果（FTS5 全文）
    ↓
  results += getPydocSearchPage()     ← 增量补充（系统命令）
  results += getRiSearchPage()        ← 增量补充（系统命令）
```

---

## 十一、文件与部署

### 11.1 文件变更

| 文件 | 改动 |
|------|------|
| `phpMan.php` | 新增 `searchFts()` / `expandNameForFts()` / `buildFtsQuery()` / `mergeSearchResults()` / `renderSearchResults()` / `rebuildSearchIndex()`；修改 `getSearchPage()`；`cacheDb()` schema v2；`showHeader()` noindex；`PageCache::set()` 过滤 search 模式 |
| `docs/SEARCH_FTS5_DESIGN.md` | **新建**（本文） |
| `test/test_search.php` | **新建** — 搜索测试套件 |
| `test/e2e/test_search.php` | **新建** — E2E 搜索测试 |

### 11.2 部署顺序

```
1. 更新 phpMan.php（新代码 + schema v2）
2. scp 到服务器 → 首次请求自动创建 search_fts（空表）
3. 执行 php phpMan.php --build-index（首次全量构建）
4. 验证搜索 → curl /phpMan.php/search/git/json
5. 设置 cron 定时重建（可选）
```

---

## 十二、测试计划

| 测试 | 类型 | 验证内容 |
|------|------|---------|
| expandNameForFts | 单元 | `'git-commit'` → `'git-commit git commit'`，`'File::Find'` → `'File::Find File Find'`，`'ls'` → `'ls'` |
| buildFtsQuery 保留连字符 | 单元 | `'git-commit'` → `'"git-commit"'*`，非直接 strip `-` |
| buildFtsQuery 保留冒号 | 单元 | `'File::Find'` → `'"File::Find"'*` |
| 连字符 token 匹配 | 集成 | `git-commit` 精确匹配 + `git commit` 分词匹配 |
| 双冒号 token 匹配 | 集成 | `File::Find` 精确匹配 + `File Find` 分词匹配 + `Find` 单层匹配 |
| 选项 flag 搜索 | 集成 | `--verbose` 匹配正文含 `--verbose` 的页面（需先有例子页） |
| 跨源合并 | 集成 | `(ls,1,man)` + `(ls,1,info)` → 1 条结果 sources=[man,info] |
| 排序 | 集成 | 精确匹配 > 前缀匹配 > BM25 > Section |
| 搜索结果不索引 | 集成 | `set('search',...)` 不写 search_fts |
| 搜索结果缓存 | 集成 | 相同查询两次 → 第二次不走 FTS5 |
| fallback | 集成 | 模拟 FTS5 异常 → apropos |

---

## 十三、开放问题

1. **首次构建性能** — `man` 命令调用 ~0.15s/页 × 13,000 ≈ 30 分钟。预热缓存后需要再跑一次构建才能从 cache 读取正文。但 PageCache 是按被动缓存设计的（页面被请求后才写入）。是否引入 `--warmup` 预填充缓存功能？

2. **perldoc 正文提取** — perldoc 输出的格式与 man 不同（无 overstrike），可能需要不同的提取路径。

3. **info 正文** — info 页面输出无 overstrike，但可能有终端转义序列。需要 `col -b` 或类似清洗。

4. **body 索引存储量** — FTS5 内部表的 body 列会大幅增加索引体积。FTS5 有 `compress` 和 `uncompress` 选项可自定义压缩，但需要实现 PHP 回调（SQLite FTS5 不支持 PHP 回调）。不过 FTS5 自己的索引压缩已经很好了。

5. **搜索词过长** — 如果用户输入很长的短语，FTS5 的 token 数量限制（默认约 10000 tokens/行）可能触发。需要加入前置截断。

6. **安全考虑** — FTS5 没有 SQL 注入风险（prepared statement），但 `buildFtsQuery` 如果匹配到 `MATCH` 语法错误（如不匹配的引号），FTS5 会抛出异常。需要异常处理。

---

*v3 设计完成。确认后可进入任务拆解阶段。*
