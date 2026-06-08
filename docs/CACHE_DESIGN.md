# phpMan SQLite 数据库结构文档

> 状态：v3.6.2 生产环境
> 环境：PHP 8.x + SQLite 3.x / WAL 模式 / FTS5 已启用

---

## 一、数据库概述

单一 `phpm_cache.db` 文件，包含页面缓存 + FTS5 全文搜索索引 + TLDR 缓存。

| 表 | 类型 | 行数 (production) | 用途 |
|---|------|:---:|------|
| `cache` | 常规表 | ~38K | 页面内容缓存（man/perldoc/pydoc/ri 渲染结果） |
| `cache_fts` | FTS5 虚拟表 (外部内容) | — | 已缓存页面的标题索引（可选，linked to cache.id） |
| `search_fts` | FTS5 虚拟表 (独立) | 13,835 | 离线全文搜索索引（man+pydoc+ri 三源） |
| `search_index_meta` | 常规表 | 13,835 | 索引条目的元数据（用于去重、排序、统计） |
| `tldr_cache` | 常规表 | 按需 | TLDR cheatsheet 缓存（7 天 TTL） |
| `meta` | 常规表 | 3 | schema 版本、索引计数、更新时间 |

---

## 二、cache — 页面内容缓存

### 2.1 表结构

```sql
CREATE TABLE IF NOT EXISTS cache (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    mode        TEXT NOT NULL,              -- 'man'|'perldoc'|'info'|'pydoc'|'ri'|'search'
    name        TEXT NOT NULL,              -- 命令/模块名（如 'ls', 'File::Basename'）
    section     TEXT NOT NULL DEFAULT '',   -- '1'~'9','3pm','n','pydoc','ri' 或 ''
    format      TEXT NOT NULL,              -- 'html'|'markdown'|'json'|'mcp'
    content     BLOB,                       -- gzcompress() 压缩后的渲染输出
    content_len INTEGER NOT NULL DEFAULT 0, -- 压缩前原始字节数
    status      TEXT NOT NULL DEFAULT 'found'
                    CHECK(status IN ('found','not_found')),
    ttl         INTEGER NOT NULL DEFAULT 0, -- 0=永久(7天), 86400=404(1天)
    hits        INTEGER NOT NULL DEFAULT 0, -- 缓存命中次数
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE(mode, name, section, format)
);

CREATE INDEX idx_cache_lookup ON cache(mode, name, section, format);
CREATE INDEX idx_cache_status  ON cache(status, updated_at);
CREATE INDEX idx_cache_hits    ON cache(hits DESC);
CREATE INDEX idx_cache_expiry  ON cache(updated_at) WHERE ttl > 0;
```

### 2.2 说明

- **缓存键**：(mode, name, section, format) 唯一确定一条缓存
- **压缩**：PHP `gzcompress()`，SQLite BLOB 存储，平均压缩比 ~70%
- **TTL**：found 条目 604800s (7天)，not_found 条目 86400s (1天)。过期后通过 `get()` 访问时自动删除
- **自动清理**：`cacheOrExecute()` 有 1% 概率触发 `DELETE FROM cache WHERE expired`
- **search 模式**：不写入 `cache_fts` 索引，不触发 hits 计数，输出 `<meta name="robots" content="noindex">`

### 2.3 典型数据量

| mode | 平均缓存大小 | 示例 |
|------|:---:|------|
| man | ~11 KB (gz) | 45 KB HTML → 12 KB gz |
| perldoc | ~9 KB | 21 KB HTML → 6 KB gz |
| pydoc | ~13 KB | 35 KB HTML → 10 KB gz |
| ri | ~1.4 KB | 小页面 |
| search | ~110 KB | 搜索结果列表 |

---

## 三、search_fts — FTS5 离线全文搜索索引

### 3.1 表结构

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(
    name,           -- 展开后的命令名: "git-commit git commit git-commit"
    section,        -- '1'~'9','n','3pm','pydoc','ri' 等
    description,    -- apropos 一句话摘要 / 'Python 3 module' / 'Ruby class/module'
    body,           -- 空（不索引正文，避免膨胀）
    tokenize='unicode61 tokenchars ''-:''',
    prefix='1,2,3'
);
```

### 3.2 说明

- **独立内容表**：`search_fts` 是独立 FTS5 表（非外部内容），通过 `INSERT` 添加条目
- **name 列**：存储 `expandNameForFts()` 展开后的多 token 字符串，实现大小写不敏感 + 分词匹配
- **body 列**：始终为空。不索引页面正文 — 共享主机 fork `man` 逐条提取会导致 `fork: retry: Resource temporarily unavailable`
- **tokenchars**：`-` 和 `:` 作为 token 字符的一部分（不拆分），`git-commit` 和 `File::Find` 保持完整
- **去重**：插入前通过 `search_index_meta` 检查 `(name, section, source)` 是否已存在

### 3.3 重建方式

```bash
# CLI
php phpMan.php --build-index

# Cron (daily)
0 3 * * * /usr/bin/php /path/to/phpMan.php --build-index-cron

# 或使用独立脚本
php rebuild-index.php /path/to/phpman_cache/production --cron
```

重建流程：`DROP TABLE search_fts` → `CREATE` → `DELETE FROM search_index_meta` → `INSERT` man pages (apropos) → `INSERT` pydoc3 modules → `INSERT` ri classes。

---

## 四、search_index_meta — 索引元数据

### 4.1 表结构

```sql
CREATE TABLE IF NOT EXISTS search_index_meta (
    name         TEXT NOT NULL,              -- 原始名称（未展开）
    section      TEXT NOT NULL DEFAULT '',   -- 节号
    source       TEXT NOT NULL DEFAULT 'man',-- 'man'|'pydoc'|'ri'
    body_len     INTEGER NOT NULL DEFAULT 0, -- 正文长度（当前为 0）
    hits         INTEGER NOT NULL DEFAULT 0, -- 命中计数
    last_indexed INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE(name, section, source)
);
```

### 4.2 说明

- **去重**：UNIQUE(name, section, source) 保证同一名称+节号+来源只出现一次
- **增量索引**：`indexAproposLines()` 通过 INSERT OR IGNORE → changes() 检查是否为新条目，避免重复 INSERT FTS5
- **排序信号**：hits 值用于搜索结果排序（高命中条目靠前）
- **数据量**：生产环境 ~13,835 行（man 9,630 + pydoc 341 + ri 3,878）

---

## 五、cache_fts — 已缓存页面标题索引

### 5.1 表结构

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS cache_fts USING fts5(
    mode, name, section, title,
    tokenize='unicode61',
    content='cache',        -- 外部内容表
    content_rowid='id'      -- 指向 cache.id
);
```

### 5.2 说明

- **外部内容 FTS5**：链接到 `cache` 表，通过 `cache.id` 取 title
- **用途**：已缓存页面的标题索引，可用于自动补全/命令查找
- **同步**：`PageCache::set()` 内 `syncFts()` 方法写入
- **注意**：搜索命令实际使用 `search_fts`，非 `cache_fts`

---

## 六、tldr_cache — TLDR 持久缓存

### 6.1 表结构

```sql
CREATE TABLE IF NOT EXISTS tldr_cache (
    command    TEXT UNIQUE NOT NULL,         -- 命令名（小写）
    source     TEXT NOT NULL,                -- 'official'|'cheatsh'|'not_found'
    content    TEXT NOT NULL,                -- JSON 序列化的 TLDR 数据
    fetched_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
);
```

### 6.2 说明

- **TTL**：读取时通过 `(strftime('%s','now') - fetched_at) < 604800` 判断 7 天有效期
- **负缓存**：`source='not_found'` 标记无 TLDR 的命令，避免重复请求 GitHub
- **数据流**：`fetchOfficialTldr()` → 查 SQLite → 未命中 → tldr-pages/cheat.sh → 写入 SQLite
- **来源优先级**：tldr-pages (common/ → linux/ → osx/) → cheat.sh fallback

---

## 七、meta — 元信息

```sql
CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- 当前条目：
-- schema_version     = '3'
-- search_index_count = '13849'
-- search_index_updated = '2026-06-08T...'
```

Schema 版本用于检测升级（版本号变更时清理旧缓存）。

---

## 八、expandedNameForFts() 展开规则

索引时将名称展开为多个 token，实现灵活匹配：

| 原名 | 展开后 (search_fts.name) |
|------|--------------------------|
| `ls` | `ls ls` |
| `git-commit` | `git-commit git commit git-commit` |
| `File::Find` | `File::Find File Find file find file::find` |
| `json.decoder` | `json.decoder json decoder json.decoder` |
| `JSON::Ext::Parser` | `JSON::Ext::Parser JSON Ext Parser json ext parser json::ext::parser` |

规则：原始名 + 去分隔符版 + 小写版 + 小写去分隔符版。

搜索 `json` → 匹配 `JSON::Ext::Parser`（展开含 `json`）、`Psych::JSON`（展开含 `json`）、`json.decoder`（原始含 `json`）。

---

## 九、PRAGMA 配置

```sql
PRAGMA journal_mode = WAL;        -- 写不阻塞读
PRAGMA synchronous  = NORMAL;     -- 性能与安全平衡
PRAGMA foreign_keys = ON;         -- 外键约束
```

---

## 十、维护与清理

### 10.1 FTS5 索引重建

```bash
# CLI 全量重建（生产环境部署后执行）
php phpMan.php --build-index

# Cron 每日重建
0 3 * * * /usr/bin/php /path/to/phpMan.php --build-index-cron
```

### 10.2 缓存清理

```bash
# 全部重建（删除 DB 文件，下个请求自动创建）
rm -f /path/to/phpman_cache/production/phpm_cache.db*

# 仅清搜索缓存（强制下次搜索走全新 FTS5 查询）
sqlite3 phpm_cache.db "DELETE FROM cache WHERE mode='search'"

# 清除过期条目（通常自动触发，也可手动）
sqlite3 phpm_cache.db "DELETE FROM cache WHERE ttl > 0 AND (strftime('%s','now') - updated_at) > ttl"
```

### 10.3 数据库碎片整理

长时间运行后，大量 INSERT/DELETE 可能产生碎片。VACUUM 重写整个数据库文件：

```bash
sqlite3 /path/to/phpm_cache.db "PRAGMA journal_mode=DELETE; VACUUM;"
```

只在重建 FTS5 索引后执行 VACUUM 效果最佳（先 DROP+CREATE 清理旧索引，再 VACUUM 回收空间）。

### 10.4 关联文档

- [SEARCH_FTS5_DESIGN.md](SEARCH_FTS5_DESIGN.md) — FTS5 全文搜索设计（v4/v3.6.2）
- [PYDOC_RI_DESIGN.md](PYDOC_RI_DESIGN.md) — pydoc3 / ri 文档格式解析设计
- [DESIGN.md](DESIGN.md) — phpMan 产品定义与核心设计决策
