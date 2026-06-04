# phpMan v3.0 缓存设计文档

> REVISED — 2026-06-04 补充实测问答数据
> 环境：macOS 15 / Xcode 16 CLI Tools / PHP 8.5.6 + SQLite 3.53.1

---

## 一、背景与目标

phpMan 当前每个请求都经历：
1. `exec()` 调用系统 `man`/`perldoc`/`info`（~0.15–0.25s）
2. `formatManPerlDoc()` 解析 overstrike/ANSI → HTML
3. 可选：`formatToJSON()` / `formatManPerlDocToMarkdown()` 二次解析

缓存目标：**消除系统命令的开销**，对高频请求实现毫秒级响应；同时为 MCP/AI Agent 访问场景预缓存常用格式。

---

## 二、文档量估算（实测数据）

### 2.1 源文件

数据来自 macOS 15 + Xcode 16 CLI Tools:

| 分类 | 文件数 | 源文件大小 | 说明 |
|------|--------|-----------|------|
| man1 (用户命令) | 1,160 | 12.7 MB | ls, bash, curl... |
| man2 (系统调用) | 262 | 1.6 MB | read, write, open... |
| man3 (库函数) | 10,507 | 84.8 MB | 含 Perl 模块文档 |
| man4 (设备) | 49 | 0.3 MB | |
| man5 (文件格式) | 193 | 2.0 MB | |
| man6 (游戏) | 1 | ~0 | |
| man7 (杂项) | 46 | 0.4 MB | |
| man8 (系统管理) | 833 | 4.3 MB | |
| man9 (内核) | 27 | 0.1 MB | |
| mann (Tcl/Tk) | 659 | 7.6 MB | |
| Perldoc 模块 | ~4,300 | — | Perl 5.34 内置模块 |
| Info 页面 | ~10–30 | — | GNU info (brew 包) |
| pydoc3 / ri | 数百 | — | 因系统而异 |
| **总计** | **~18,000** | **~114 MB** | |

**源文件大小分布（man1 样本，n=1,160）：**

| 分位 | 大小 |
|------|------|
| Min | 14 B |
| P10 | 75 B |
| P25 | 1.5 KB |
| P50 | 4.9 KB |
| P75 | 13 KB |
| P90 | 35 KB |
| P99 | 200 KB |
| Max | 1.3 MB |

结论：**大部分文档很小（中位数 ~5 KB），但存在极长尾**（最大 1.3 MB）。

### 2.2 渲染后输出大小

实际通过 phpMan 渲染后的页面大小（`MANROFFOPT=-rLL=100n`）：

| 页面 | 原始 troff 源 | 终端输出 (col -b) | HTML | JSON | Markdown |
|------|:---:|:---:|:---:|:---:|:---:|
| ls(1) | 22.8 KB | 22.7 KB | 45.9 KB | 42.9 KB | 24.5 KB |
| printf(1) | 11.3 KB | 9.6 KB | 22.8 KB | — | — |
| tar(1) | 8 B (stub) | 41.5 KB | 69.2 KB | — | — |
| bash(1) | 249 KB | 252 KB | 335 KB | 259 KB | — |
| curl(1) | 228 KB | 244 KB | 321 KB | 476 KB | — |
| Carp(3pm) | — | — | 21 KB | — | — |
| File::Basename | — | — | 16 KB | — | — |

**输出格式膨胀系数（与终端输出比）：**
- HTML: 1.0–1.3×（内容部分相近，加 ~10 KB 页面框架）
- JSON: 1.6–1.9×（结构化表示 + 字段 key 开销）
- Markdown: 1.0–1.1×（最小标记）

**gzip 压缩效果（实际测试）：**
| 格式 | 原始大小 | gzip 后 | 节省 |
|------|:---:|:---:|:---:|
| HTML | 46 KB | 12 KB | 73% |
| JSON | 43 KB | 8 KB | 81% |
| Markdown | 24 KB | 7 KB | 71% |
| 大页面 HTML (bash) | 335 KB | 82 KB | 75% |

### 2.3 总缓存容量估算

按 8,437 个唯一 `man -k` 条目 + 4,300 perldoc + 其他 = **~13,000 可缓存文档**。

| 方案 | 格式 | 单页平均 | 总计 (raw) | 总计 (gzip) |
|------|------|:---:|:---:|:---:|
| A: 仅 HTML | HTML | ~45 KB | ~560 MB | ~140 MB |
| B: 仅缓存 raw lines | raw text | ~25 KB | ~310 MB | ~80 MB |
| C: 全格式 (HTML+JSON+MD) | 3格式 | ~90 KB | ~1.1 GB | ~280 MB |
| D: 中间格式 + 按格式生成 | IR + 格式按需 | ~30 KB | ~370 MB | ~95 MB |

**推荐：方案 B 或 D**。原始命令输出缓存已经消除了 `exec()` 开销（主要性能瓶颈），且比 HTML 更紧凑。

---

## 三、存储格式对比

| 特性 | 文件系统 (text) | SQLite | 内存 KV (Redis) |
|------|:---:|:---:|:---:|
| PHP 内置支持 | ✅ 原生 | ✅ SQLite3/PDO | ❌ 需扩展 |
| 单文件部署 | ❌ 大量小文件 | ✅ 1 个文件 | ❌ 需外部服务 |
| ~13K 记录性能 | ⚠️ inode 压力 | ✅ 索引查询 <1ms | ✅ ~0.1ms |
| 原子写入 | ❌ 需 tmp+rename | ✅ 事务 | ✅ 事务 |
| 批量过期/清理 | ❌ 需遍历目录 | ✅ SQL DELETE | ✅ TTL |
| 按格式查询 | ❌ 路径约定 | ✅ 索引列 | ✅ 多 key |
| 并发读 | ✅ 无锁 | ✅ WAL 模式 | ✅ 原生 |
| 并发写 | ⚠️ 竞态 | ✅ 行锁/WAL | ✅ |
| PHP 代码复杂度 | 低 (file_put_contents) | 中 (PDO) | 高 (需客户端) |
| gzip 内联 | 需 .gz 后缀 | ✅ BLOB | ✅ BLOB |

### 结论：SQLite 胜出

理由：
1. **零依赖** — PHP 默认编译了 SQLite3 和 PDO_SQLite
2. **~13,000 条记录**在 SQLite 的舒适区（设计容量百万级）
3. **单文件**维护，符合 phpMan 单文件部署哲学（缓存文件仅 1 个 .db）
4. **原子写入** — 避免并发请求下缓存损坏
5. **结构化查询** — 可按 mode/name/section/format 灵活查询
6. **内置 gzip** — PHP 的 `sqlite3_create_function()` 可注册 gzip 压缩函数
7. **预置索引** — (mode, name, section, format) 复合索引覆盖所有读路径

---

## 四、缓存架构

### 4.1 缓存单元（什么被缓存）

**核心缓存**：系统命令的原始输出（`exec()` 返回的 `$lines` 数组，序列化为文本）

```
cache_key = (mode, name, section, format)
cache_value = 渲染后的内容 (gzipped, 格式相关)
```

理由：
- `exec()` 是性能瓶颈（~0.15–0.25s），格式转换很快（<0.01s）
- 原始输出紧凑（同源文件大小 ≈ 5–50 KB）
- **每个格式独立缓存** —— 方便直接响应不同格式的请求

Format 取值：`raw`（原始命令输出）, `html`, `json`, `md`。

### 4.2 缓存粒度与 section 处理

**关键问题：不带 section 参数和带 section 参数的请求是否用同一个缓存？**

#### 实测数据

```
man -w ls          → /usr/share/man/man1/ls.1
man -w 1 ls        → /Library/Developer/.../man1/ls.1
diff (man ls) (man 1 ls) → IDENTICAL ✅（文件不同但内容一样）
```

```
man -w uname       → /usr/share/man/man1/uname.1
man -w 3 uname     → /Library/Developer/.../man3/uname.3
diff (man 1 uname) (man 3 uname) → DIFFERENT ❌（完全不同页面）
```

- `ls` 只存在于 section 1：不带 section 和 `section=1` 内容一致
- `uname` 存在于 section 1 和 section 3：内容完全不同
- **跨 section 同名命令占所有唯一名的 ~31%**（2,583 / 8,300）

#### 结论：section="" 和 section="N" 分开缓存

| 场景 | 缓存 key | 说明 |
|------|----------|------|
| `GET /man/ls` | `(man, ls, "", html)` | 无 section，按 man 默认顺序返回 |
| `GET /man/ls/1` | `(man, ls, 1, html)` | 指定 section 1 |
| `GET /man/uname` | `(man, uname, "", html)` | 返回 section 1（man 默认顺序） |
| `GET /man/uname/3` | `(man, uname, 3, html)` | 返回 section 3 内容 |

**`""` 也是合法 section 值**。两个 key 存储独立的内容：
- 对于单 section 命令（如 `ls`），内容相同但存储两份。代价极小（~46 KB 一份）。
- 对于跨 section 命令（如 `uname`），正确区分不同文档。
- 不引入 alias/redirect 逻辑，最大的简洁性。

**未来优化思路**（如需要节省空间）：
- 在 section="" 命中时，由 `man -w` 解析实际 section，与对应 section 条目合并
- 用 `status` 列标记 "alias_to" 指向真实 section

### 4.3 404 缓存

**问题：不存在的命令是否要缓存？**

#### 实测数据

```
time man -k nonexistent_command  →  ~2.3 sec  (aprops 搜索)
time man nonexistent_command     →  ~0.2 sec  (直接查)
```

当前 phpMan 404 回退到 `getSearchPage()`（apropos），耗时 **~2.3s**。缓存负面结果意义重大。

#### 结论：缓存 404，加 TTL

新增 `status` 列：

```
status = "found"      → 正常缓存，永不过期（文档内容不变）
status = "not_found"  → 缓存 "不存在" 结果，TTL = 24h
```

```
cache entry:
  mode="man", name="nonexistent_cmd", section="", status="not_found",
  content=NULL, created_at=..., ttl=86400
```

**TTL 到期后**：自动重试系统命令。如果包已安装，转为 `found`；否则续期。

**为什么不是永久**：因为系统会安装新包。24h 保证最多一天后自动刷新。

### 4.4 预缓存 vs 被动缓存

**问题：提前预热还是请求触发？**

#### 结论：**被动缓存为主，选择性预热为辅**

**被动缓存（默认）**：
- 页面首次被请求时触发 `exec()`，结果写入缓存
- 后续同页面命中缓存，毫秒级响应
- 自然的"热集"形成机制

**选择性预热（`--warmup`）**：
- 部署后可运行 CLI 预热 top-N 高频页面
- 避免部署后的首次访问延迟

```bash
# 预热 top 100 常用命令（仅 section 1）
php phpMan.php --warmup=100

# 预热全部 section 1（~1,160 页，约 5 分钟）
php phpMan.php --warmup=all

# 只预热特定命令
php phpMan.php --warmup=ls,bash,grep,tar,find
```

**为什么不全量预缓存**：
| 方案 | 耗时 | 存储 | 说明 |
|------|:---:|:---:|------|
| 全量 ~13K 页 × raw | ~50 分钟 | ~310 MB | 太多页面从未被访问 |
| 全量 × 所有格式 | ~3 小时 | ~1+ GB | 浪费 |
| 仅 warmup 100 | ~25 秒 | ~5 MB | 覆盖 80%+ 日常请求 |

### 4.5 缓存层级

```
phpMan.php 所在目录/
├── cache/                        # 缓存根目录
│   ├── phpm_cache.db             # SQLite 数据库文件
│   └── phpm_cache.db-wal        # SQLite WAL（运行时）
│   └── phpm_cache.db-shm        # SQLite SHM（运行时）
│
├── tldr_cache/                   # 已有，TLDR 缓存（保持不变）
│   └── ...                       # 按命令+模型+prompt版本 hash
│
└── phpMan.php                    # 主程序
```

`cache/` 目录需要在部署时创建（或由 phpMan 自动创建），并确保 web 服务器用户可写。

---

## 五、SQLite Schema

```sql
-- 主缓存表
CREATE TABLE IF NOT EXISTS cache (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    mode        TEXT NOT NULL,      -- 'man' | 'perldoc' | 'info' | 'pydoc' | 'ri'
    name        TEXT NOT NULL,      -- 命令/模块名（小写，如 'ls', 'file::basename'）
    section     TEXT NOT NULL DEFAULT '',  -- '1'..'9', '3pm', 'n', 或 ''（无 section 时）
    format      TEXT NOT NULL DEFAULT 'raw',  -- 'raw' | 'html' | 'json' | 'md'
    status      TEXT NOT NULL DEFAULT 'found',  -- 'found' | 'not_found'
    content     BLOB,               -- gzip 压缩后的缓存内容（status='found' 时）
    content_len INTEGER DEFAULT 0,  -- 原始未压缩大小（字节）
    created_at  INTEGER NOT NULL,   -- unix timestamp
    updated_at  INTEGER NOT NULL,   -- unix timestamp
    hits        INTEGER DEFAULT 0,  -- 命中计数（活跃度）
    ttl         INTEGER DEFAULT 0,  -- 0 = 永不过期（found）；>0 = 过期秒数（not_found 默认 86400）

    UNIQUE(mode, name, section, format)
);

CREATE INDEX idx_cache_lookup ON cache(mode, name, section);
CREATE INDEX idx_cache_format ON cache(mode, name, section, format);
CREATE INDEX idx_cache_status  ON cache(status, updated_at);  -- 过期 idle / not_found
CREATE INDEX idx_cache_hits    ON cache(hits DESC);            -- 热门页面排序

-- 元数据表
CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT
);
INSERT OR IGNORE INTO meta (key, value) VALUES ('schema_version', '1');
INSERT OR IGNORE INTO meta (key, value) VALUES ('created', '2026-06-04');

-- 可选：FTS5 索引（用于命令查找/自动补全，非必须）
CREATE VIRTUAL TABLE IF NOT EXISTS cache_fts USING fts5(
    mode, name, section, title,
    tokenize='unicode61',
    content='cache',
    content_rowid='id'
);
```

**关键设计点：**
- `status='not_found'` 对应 404 缓存，此时 `content=NULL`
- `ttl=0` 永不过期（正常文档），`ttl=86400` 负面结果 24h 过期
- `UNIQUE(mode, name, section, format)` 防止重复写入
- `content` 为 gzip BLOB，PHP 侧 `gzcompress()` / `gzuncompress()` 处理

---

## 六、缓存目录 / 文件路径设计

### 6.1 路径规划

缓存根由常量 `CACHE_DIR` 定义，默认值为 phpMan.php 所在目录下的 `cache/`。

```php
define('CACHE_DIR', dirname(__FILE__) . '/cache');
// 可用环境变量覆盖
if (getenv('PHPMAN_CACHE_DIR')) {
    define('CACHE_DIR', getenv('PHPMAN_CACHE_DIR'));
}
```

**为什么用 `cache/` 而不是 `var/cache/`：**
1. phpMan 是单文件部署，`cache/` 同级目录最直观
2. 兼容已有 `tldr_cache/` 目录的命名风格
3. 一个 `scp phpMan.php` + `mkdir cache` 即可部署

**自动创建机制：**

核心设计原则：**`initCache()` 在每个请求的 dispatch 入口调用**，检查 DB 文件是否存在：
- 存在 → 直接连接，不重建 schema（SQLite schema 固化在 .db 文件中）
- 不存在 → 自动创建目录 + .db 文件 + 完整 schema

这意味着：**无论 `cache/` 目录被清空还是 .db 文件被删除，下一个请求就会自动重建一切。**

```php
function cacheDb(): SQLite3 {
    // 同请求内复用连接（静态变量）
    static $db = null;
    if ($db !== null) return $db;
    
    // 检查并创建目录
    $dir = defined('CACHE_DIR') ? CACHE_DIR : dirname(__FILE__) . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        // 放 .gitkeep 防止空目录被 git 忽略
        file_put_contents($dir . '/.gitkeep', '');
    }
    
    $dbPath = $dir . '/phpm_cache.db';
    $isNew = !file_exists($dbPath);
    
    // 连接（自动创建 .db 文件）
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('PRAGMA foreign_keys=ON');
    
    // ★ 只有新 .db 文件才执行 DDL ★
    if ($isNew) {
        $db->exec("CREATE TABLE IF NOT EXISTS cache (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            mode        TEXT NOT NULL,
            name        TEXT NOT NULL,
            section     TEXT NOT NULL DEFAULT '',
            format      TEXT NOT NULL,
            content     BLOB,
            status      TEXT NOT NULL DEFAULT 'found'
                        CHECK(status IN ('found','not_found')),
            ttl         INTEGER NOT NULL DEFAULT 0,
            hits        INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(mode, name, section, format)
        )");
        
        $db->exec("CREATE TABLE IF NOT EXISTS meta (
            key   TEXT PRIMARY KEY,
            value TEXT
        )");
        $db->exec("INSERT OR IGNORE INTO meta (key, value)
                   VALUES ('schema_version', '1')");
        
        $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS cache_fts
                   USING fts5(mode, name, section, title,
                              tokenize='unicode61')");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_lookup
                   ON cache(mode, name, section, format)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_status
                   ON cache(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_hits
                   ON cache(hits DESC)");
        // 过期清理用的部分索引
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_expiry
                   ON cache(updated_at) WHERE ttl > 0");
    }
    
    return $db;
}
```

**关键设计要点：**

| 特性 | 说明 |
|------|------|
| `$isNew` 判断 | 只有新 .db 文件才跑 CREATE TABLE，已有文件直接复用 schema |
| `static $db` | 同请求内所有函数共享一个 SQLite 连接，避免重复打开 |
| `enableExceptions(true)` | 出错立即抛异常，不静默失败 |
| `CHECK(status IN ...)` | 数据库级别约束，防止非法状态值 |
| WAL 模式 | 读不阻塞写，适合 php-fpm 多进程并发 |
| schema 固化在 .db 内 | SQLite 的特性：DDL 执行后永久存储在文件中，无需外部迁移脚本 |

**权限模型：**
- phpMan 启动时自动创建 `cache/` 和 `.db` 文件
- SQLite WAL 模式需要目录可写（`.db-wal` / `.db-shm` 文件自动生成）
- Web 用户（www-data / _www / nobody）对 `cache/` 目录有 `rwx` 权限
- `cache/` 目录本身权限 `755`，`.db` 文件权限 `644`
- 生产环境建议单独配置 `PHPMAN_CACHE_DIR` 到 web 用户可写的位置

### 6.2 部署到生产/测试环境

#### Git 配置

```gitignore
# .gitignore — 已有 tldr_cache/，新增：
cache/
```

缓存目录不应提交到 Git（环境相关、机器相关、可重新生成）。

#### 本地开发

```bash
# 自动创建（phpMan 首次运行时）
# 或手动：
mkdir -p cache/
```

#### `make deploy` 集成

当前 Makefile 的 `deploy` 和 `release` 目标只推送 `phpMan.php`，需要增加缓存目录的创建：

```makefile
# 在 deploy 和 release 目标中加入：
deploy: test
    # ... 现有 scp phpMan.php ...
    ssh -p $(TEST_PORT) $(TEST_USER)@$(TEST_HOST) \
        "mkdir -p $(TEST_PATH)/cache && chmod 755 $(TEST_PATH)/cache"

release: test
    # ... 现有备份 + scp phpMan.php ...
    ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
        "mkdir -p $(DEMO_PATH)/cache && chmod 755 $(DEMO_PATH)/cache"
    # 全量 warmup 可选（部署后预热）：
    # ssh -p $(DEMO_PORT) $(DEMO_USER)@$(DEMO_HOST) \
    #     "cd $(DEMO_PATH) && php phpMan.php --warmup=100"
```

也可在 Makefile 中提取共用变量：

```makefile
CACHE_DIR_REMOTE ?= $(TEST_PATH)/cache   # 可被 .deploy.mk 覆盖
```

#### 生产部署完整流程

```bash
# 1. 推送代码
make release

# 2. 确保缓存目录
ssh user@host "mkdir -p /var/www/phpman/cache && chown www-data:www-data /var/www/phpman/cache && chmod 755 /var/www/phpman/cache"

# 3. 设置环境变量（Nginx + PHP-FPM）
# php-fpm pool.d/www.conf:
env[PHPMAN_CACHE_DIR] = /var/www/phpman/cache
env[PHPMAN_CACHE_DB]  = /var/www/phpman/cache/phpm_cache.db

# 4. 预热（可选）
php phpMan.php --warmup=100

# 5. 验证
curl -s -o /dev/null -w "%{http_code}" "https://example.com/phpMan.php/man/ls"
# 预期: 200（且首次请求后缓存命中）
```

#### 测试环境隔离

单元测试应使用独立的测试数据库，避免污染开发缓存：

```php
// 测试模式：自动切换缓存路径
if (defined('PHPMAN_TEST_MODE')) {
    define('CACHE_DIR', sys_get_temp_dir() . '/phpm_test_cache');
    @mkdir(CACHE_DIR, 0755, true);
}
// 或通过环境变量：
// PHPMAN_CACHE_DIR=/tmp/phpm_test_cache php test/run_all.php
```

#### 清理策略

| 场景 | 命令 |
|------|------|
| 全部重建 | `rm -f cache/phpm_cache.db*` |
| 仅清除过期（404 缓存） | `DELETE FROM cache WHERE ttl > 0 AND updated_at + ttl < UNIXEPOCH()` |
| 仅清除冷门页面 | `DELETE FROM cache WHERE hits < 2 AND updated_at < UNIXEPOCH() - 86400*30` |
| 仅清除特定格式 | `DELETE FROM cache WHERE format = 'json'` |

### 6.3 多实例隔离（可选）

如果同一台机器上部署多个 phpMan 实例（不同虚拟主机），通过环境变量区分：

```bash
# .env 或 httpd.conf
SetEnv PHPMAN_CACHE_DIR /var/www/site1/cache
```

或通过 `PHPMAN_CACHE_DIR` 前缀：

```php
$prefix = getenv('PHPMAN_CACHE_PREFIX') ?: '';
define('CACHE_DB', CACHE_DIR . '/phpm_cache' . $prefix . '.db');
```

---

## 七、缓存策略

### 7.1 写入策略：Write-Through

1. 请求到达 → 查 L1 (APCu) 缓存
2. L1 miss → 查 L2 (SQLite) 缓存
3. L2 miss → 执行系统命令 + 渲染
4. 结果写入 L2 (SQLite) → 可选写入 L1 (APCu)
5. 返回结果

### 7.2 失效策略

| 触发条件 | 操作 |
|----------|------|
| 新版本发布（v3.0 → v3.1） | 删除 `cache/phpm_cache.db`，全部重建 |
| 系统 man-db 更新（mandb） | 手动 `DELETE FROM cache WHERE format='raw'` |
| 特定页面失效 | `DELETE FROM cache WHERE mode=? AND name=? AND section=?` |
| 冷门页面清理（磁盘不足） | `DELETE FROM cache WHERE hits < 5 ORDER BY updated_at ASC LIMIT 1000` |

**版本关联**：`schema_version` 写入 meta 表。phpMan 启动时检查版本号，不一致则清库重建。

### 7.3 预热策略（可选，用于生产部署）

部署后可运行预热脚本：

```bash
# 预热 top 100 命令
php phpMan.php --warmup=100

# 或通过 curl 触发（需 CLI 模式）
curl -s "http://localhost/phpMan.php/man/ls/1/html" > /dev/null
curl -s "http://localhost/phpMan.php/man/bash/1/html" > /dev/null
curl -s "http://localhost/phpMan.php/man/tar/1/html" > /dev/null
# ...等高频页面
```

---

## 八、SQLite 版本与 FTS 支持（实测）

### 8.1 PHP 环境确认

| 检查项 | 状态 |
|--------|------|
| PHP 版本 | 8.5.6（Homebrew） |
| SQLite 版本 | **3.53.1** |
| `ext-sqlite3` | ✅ 已编译 |
| `ext-pdo_sqlite` | ✅ 已编译 |
| FTS3 | ✅ 支持 |
| FTS5 | ✅ **支持** |
| FTS5 unicode61 tokenizer | ✅ 支持 |

### 8.2 FTS5 功能验证

```php
// FTS5 虚拟表创建
$db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS docs_fts USING fts5(
    mode, name, section, content,
    tokenize='unicode61'
)");

// 插入
$db->exec("INSERT INTO docs_fts VALUES ('man', 'ls', '1', 'list directory contents')");

// 全文检索
$result = $db->query(
    "SELECT rank, mode, name, section FROM docs_fts WHERE docs_fts MATCH 'list'"
);

// 带摘要的检索
$result = $db->query(
    "SELECT snippet(docs_fts, '<b>', '</b>', '...', 32) AS snippet
     FROM docs_fts WHERE docs_fts MATCH 'list'"
);
```

**FTS5 特性确认：**
- ✅ `MATCH` 全文搜索
- ✅ `rank` 排序
- ✅ `snippet()` 生成高亮摘要
- ✅ `unicode61` 分词器（支持中文、Unicode）
- ✅ 短语搜索（`"Bourne Again"`）
- ✅ 前缀搜索（`list*`）

### 8.3 FTS5 在缓存中的角色

**FTS5 不用于文档内容搜索**（phpMan 已有 apropos 做搜索）。FTS5 用于：

1. **命令行查找**（可选）— 缓存 man 页标题/描述，支持 `complete` 自动补全
2. **统计查询** — 按 section 统计缓存命中分布
3. **Warmup 决策** — 从 FTS 索引中找出高频/高频匹配的命令

**不创建 FTS 索引的场景**：如果只做 K/V 缓存，FTS5 不是必须的。FTS5 只为可选的搜索/补全功能服务。

### 8.4 数据库估算

| 指标 | 估算值 |
|------|--------|
| 总记录数 | ~13,000（raw）+ ~39,000（3 格式）= ~52,000 |
| 每记录平均大小 | ~15 KB（raw,gz）~25 KB（html,gz） |
| 数据库总大小 | raw 方案: ~200 MB / 全格式: ~500 MB |
| 单行查询时间 | < 1ms（索引命中） |
| 全表扫描 | 不适用（始终索引查询） |
| 并发写压力 | 低（读远多于写） |

### 8.5 SQLite 配置优化

```sql
PRAGMA journal_mode = WAL;           -- 写性能 + 并发读
PRAGMA synchronous = NORMAL;         -- 安全性≈FULL，速度×2
PRAGMA cache_size = -64000;          -- 64 MB 页面缓存
PRAGMA temp_store = MEMORY;          -- 临时表在内存
PRAGMA mmap_size = 134217728;        -- 128 MB 内存映射
PRAGMA page_size = 4096;             -- 4K 页（适合 BLOB 存储）
```

### 8.6 自动 VACUUM

```sql
PRAGMA auto_vacuum = INCREMENTAL;   -- 允许回收空间
-- 每月或更新后：
-- PRAGMA incremental_vacuum(1000);
```

---

## 九、FTS5 索引同步策略（被动缓存时如何更新）

### 9.1 问题

FTS5 是 Virtual Table，其内容**不会随 `cache` 表自动同步**。被动缓存模式下（页面首次请求后才写入），FTS5 索引需要选择一种同步方式。

### 9.2 三种方案对比

| 方案 | 原理 | 优点 | 缺点 |
|------|------|------|------|
| **A: SQLite 触发器** | `AFTER INSERT ON cache` 自动 INSERT cache_fts | 无代码侵入，数据库层保证 | 调试困难，`OR REPLACE` 需要额外处理 |
| **B: PageCache::set() 里手动同步** | `set()` 写完 cache 表后，再写 cache_fts | 清晰可控，可加条件逻辑 | 一处漏写就不同步 |
| **C: 定时重建 / DB 维护** | `INSERT INTO cache_fts(cache_fts) VALUES('rebuild')` | 索引始终一致 | 重建消耗时间，期间 FTS 不可用 |

### 9.3 推荐方案：方案 B（手动同步）+ 可选触发器

**实现方案（PageCache::set 内手动同步）：**

```php
// PageCache::set 尾部 — 写入 cache 后同步 FTS5
private function syncFts(int $cacheId, string $mode, string $name, string $section): void {
    // 从缓存内容中提取首行描述作为 title（用于搜索索引）
    $title = '';
    $row = $this->db->querySingle("SELECT content FROM cache WHERE id = {$cacheId}", true);
    if ($row && $row['content'] !== null) {
        $raw = gzuncompress($row['content']);
        if ($raw !== false) {
            $firstLine = explode("\n", $raw)[0] ?? '';
            // 去掉过长的行，截取前 120 字符
            $title = mb_substr(trim($firstLine), 0, 120);
        }
    }

    $stmt = $this->db->prepare(
        "INSERT OR REPLACE INTO cache_fts (rowid, mode, name, section, title)
         VALUES (:id, :mode, :name, :section, :title)"
    );
    $stmt->bindValue(':id', $cacheId, SQLITE3_INTEGER);
    $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':section', $section, SQLITE3_TEXT);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->execute();
}

// 在 set() 中调用（INSERT 后获取 lastInsertRowID）
public function set(...): bool {
    // ... 写入 cache 表 ...
    $cacheId = $this->db->lastInsertRowID();
    $this->syncFts($cacheId, $mode, $name, $section);
    return true;
}

// 删除时也需要同步 FTS5
public function delete(...): bool {
    // ... DELETE FROM cache ...
    $this->db->exec(
        "DELETE FROM cache_fts WHERE rowid IN (
            SELECT id FROM cache WHERE mode=? AND name=? AND section=?
        )"
    );
}
```

**可选补充：SQLite 触发器（防漏写）**

如果某个路径跳过了 `PageCache::set()` 直接写入了 cache 表，触发器作为保险：

```sql
CREATE TRIGGER IF NOT EXISTS trg_cache_fts_insert AFTER INSERT ON cache
WHEN NEW.status = 'found'
BEGIN
    INSERT OR IGNORE INTO cache_fts (rowid, mode, name, section, title)
    VALUES (NEW.id, NEW.mode, NEW.name, NEW.section, '');
END;

CREATE TRIGGER IF NOT EXISTS trg_cache_fts_delete AFTER DELETE ON cache
BEGIN
    DELETE FROM cache_fts WHERE rowid = OLD.id;
END;
```

触发器方式下 FTS5 的 `title` 列会为空（因为没有内容层面的解析），需要定时用方案 C 的 `rebuild` 来补全。

### 9.4 title 提取策略

FTS5 中的 `title` 列用于搜索/自动补全。来源：

| 来源 | 说明 | 优先级 |
|------|------|--------|
| 从 man 输出的 NAME 段提取 | `ls - list directory contents` | 最高 — 命令行补全的首选 |
| fallback: 文件名的第一行 | 无 NAME 段的页面 | 低 |
| fallback: 空字符串 | 404 缓存不需要建立 FTS 索引 | — |

实际提取时直接解析缓存内容的 NAME 段（`format='raw'` 时）：

```php
private function extractTitle(string $rawContent): string {
    // man 页的 NAME 段通常是 "command - short description"
    if (preg_match('/^([A-Z]+)\\s*\\n\\s*([^\\n]+?)\\s*-\\s*(.+)$/m', $rawContent, $m)) {
        // 匹配："NAME\n       ls - list directory contents"
        return trim($m[2]) . ' - ' . trim($m[3]);
    }
    // fallback: 首行
    $first = explode("\n", $rawContent)[0] ?? '';
    return mb_substr(trim($first), 0, 120);
}
```

### 9.5 完整写入流程

```
请求到达 → cache miss → exec() 系统命令 → 获取 $lines
  ↓
写入 cache 表 (INSERT)
  ↓
获取 lastInsertRowID
  ↓
如果 status='found': 提取 title → 写入 cache_fts (INSERT OR REPLACE)
  ↓
如果 status='not_found': 不写 FTS5（搜索索引不收录 404）
  ↓
返回内容
```

---

## 十、缓存未命中时的 fallback 链

### 10.1 当前逻辑（无缓存时）

```
请求: GET /phpMan.php/man/ls/1/html
  │
  ├─→ switch($mode='man'):
  │     $parameter='ls', $section='1', $format='html'
  │
  ├─→ 检测 perldoc 特征 (:: / 3pm / 3perl) → 否
  │
  ├─→ getManPage('ls', '1', 'html')
  │     │
  │     ├─→ exec("man -Tutf8 1 ls") → $lines, $return_code
  │     │     ├─ 成功 → formatManPerlDoc($lines, 'man') → HTML
  │     │     └─ 失败（macOS BSD man 不识 -Tutf8）
  │     │         └─ exec("man 1 ls") → $lines → formatManPerlDoc()
  │     │               ├─ 成功 → HTML
  │     │               └─ 失败 → return ""
  │     │
  │     └─→ 返回 HTML 或 ""
  │
  ├─→ 如果内容为空 & 全大写 → getManPage(strtolower('LS')) 重试
  │
  ├─→ 如果仍为空 & perldoc 特征 → getPerldocPage() 重试
  │
  ├─→ 如果仍为空 → getSearchPage('ls', '1', 'html')
  │     └─ exec("apropos ls")  → 搜索结果列表
  │     └─ http_response_code(404)
  │
  └─→ showHeader() + HTML 输出
```

**关键观察点：**
1. **getManPage 内就尝试了两种 man 命令**（`-Tutf8` 和裸 `man`），中间无缓存机会
2. **getPerldocPage 内部有 4 级 fallback**（pod2text → perldoc → perldoc -f → perldoc -q）
3. **最终 fallback 是 apropos 搜索**（最慢，~2.3s）
4. **4 个不同的 get*Page 函数**（man/perldoc/info/pydoc/ri）各有一套 fallback 逻辑，需要统一拦截

### 10.2 加上缓存后的 fallback 链

```
请求: GET /phpMan.php/man/ls/1/html
  │
  ├─1─→ PageCache::get('man', 'ls', '1', 'html')
  │      │
  │      ├── 命中 status='found' (ttl=0) → 返回 gzipped HTML → 解压 → 直接输出
  │      ├── 命中 status='not_found' (ttl=86400)
  │      │     └── 检查 updated_at + ttl:
  │      │           ├── 未过期 → 返回 NOT_FOUND 信号 → 走 404
  │      │           └── 已过期 → 视为 MISS，重新执行查询
  │      └── MISS → 继续执行系统命令
  │
  ├─2─→ getManPage('ls', '1', 'html')  [原始逻辑，但结果截获]
  │      ├── exec() + fallback → 返回内容或 ""
  │      │
  │      └── 截获结果（在 getManPage 返回后，dispatch switch 内）:
  │            ├── 内容不为空 → PageCache::set('man','ls','1','html', $content)
  │            │     └── 标记 status='found', ttl=0 → 返回内容
  │            │
  │            └── 内容为空 → 进入下一级 fallback...
  │
  ├─3─→ [可选的 perldoc retry] 逻辑不变
  │
  ├─4─→ 仍为空 → getSearchPage()
  │      └── 截获搜索结果为 NOT_FOUND:
  │            ├── PageCache::set('man','ls','1','html', NULL)
  │            │     └── 标记 status='not_found', ttl=86400
  │            └── http_response_code(404) + 显示搜索结果
  │
  └─→ 输出内容 / 404
```

### 10.3 缓存拦截点设计

**关键原则：不在 get*Page 函数内部缓存，而在 dispatch 层统一拦截。**

```php
// 统一缓存拦截函数
function cacheOrExecute(string $mode, string $name, string $section, string $format, callable $execFn): string {
    $cache = new PageCache();

    // 1) 尝试读缓存
    $cached = $cache->get($mode, $name, $section, $format);
    if ($cached !== null) {
        if ($cached === '###NOT_FOUND###') {
            return '';  // 404 缓存命中，返回空（触发后续 fallback 或 404）
        }
        return $cached;  // 命中 → 直接返回
    }

    // 2) 执行原始逻辑（exec 系统命令）
    $content = $execFn();

    // 3) 写入缓存
    if ($content !== '') {
        $cache->set($mode, $name, $section, $format, $content, 'found');
    } else {
        // 只对完整的 fallback 链尾（所有尝试都失败后）才写 NOT_FOUND
        // 在 dispatch 层标记，不在 get*Page 内
    }

    return $content;
}
```

**实际 dispatch 中的用法（switch man case, line 823–858）：**

```php
case "man":
    if ($parameter !== "") {
        $execManFn = function() use ($parameter, $section, $format) {
            // 原 getManPage 逻辑...
            return getManPage($parameter, $section, $format);
        };

        // 尝试 man 缓存
        $content = cacheOrExecute('man', $parameter, $section, $format, $execManFn);

        // 后续 fallback 链不变（但是对空结果不再重复缓存）
        if (trim($content) === "" && preg_match("/^[A-Z\\._]+$/", $parameter)) {
            $content = cacheOrExecute('man', strtolower($parameter), $section, $format,
                function() use ($parameter, $section, $format) {
                    return getManPage(strtolower($parameter), $section, $format);
                });
        }
        if (trim($content) === "" && (strpos($parameter, "::") !== false || $section === "3pm" ...)) {
            $content = cacheOrExecute('perldoc', $parameter, $section, $format,
                function() use ($parameter, $format) {
                    return getPerldocPage($parameter, $format);
                });
        }
        // 最终 fallback 到搜索 → 这里写入 NOT_FOUND 缓存
        if (trim($content) === "") {
            $cache = new PageCache();
            $cache->set('man', $parameter, $section, $format, null, 'not_found');
            $content = "<ul>" . getSearchPage($parameter, $section, $format) . "</ul>";
            http_response_code(404);
        }
    }
    break;
```

### 10.4 不同 mode 的 fallback 链总结

| mode | fallback 链顺序 | 缓存 key 的 mode 值 |
|------|----------------|--------------------|
| **man** | getManPage → 大写重试 → getPerldocPage → getSearchPage | `'man'` (最终 404 也是 `'man'`) |
| **perldoc** | getPerldocPage (内部 4 级: pod2text→perldoc→-f→-q) → "" → 404 | `'perldoc'` |
| **info** | getInfoPage → "" → 404 | `'info'` |
| **pydoc** | getPydocPage → getPydocSearchPage → 404 | `'pydoc'` |
| **ri** | getRiPage → getRiSearchPage → 404 | `'ri'` |

### 10.5 404 缓存的特殊处理

```
cache 表记录:
  mode='man', name='nonexistent', section='', format='html'
  status='not_found', content=NULL, ttl=86400

读取时:
  PageCache::get() 返回 '###NOT_FOUND###' 特殊标记
  dispatch 层识别该标记 → 走 404 逻辑（不执行系统命令）

TTL 到期后:
  get() 返回 NULL → 重新执行系统命令
  如果包已安装 → status 转为 'found', ttl=0
  如果仍不存在 → 继续 not_found 状态, 续期
```

### 10.6 getSearchPage（apropos）是否缓存？

**结论：不缓存 apropos 搜索结果本身。**

理由：
- apropos 检索的是系统 whatis 数据库（`/var/cache/man/whatis`），macOS 的 whatis 数据库更新不影响 phpMan 缓存
- apropos 结果中命令名到 man 页的映射是稳定的，但强制缓存会引入 stale 结果
- 用户期望搜索看到最新安装的包
- 独立 page 的 404 缓存已经覆盖了高频场景

如果需要提升 apropos 速度，更好的方式是：
- 在本地建一个 whatis 索引的缓存快照
- 或通过 FTS5 搜索命令标题/描述

---

## 十一、接口设计（PHP 类）

```php
class PageCache {
    // 核心方法
    public function get(string $mode, string $name, string $section, string $format): ?string;
    public function set(string $mode, string $name, string $section, string $format, ?string $content, string $status = 'found'): bool;
    public function delete(string $mode, string $name, string $section): bool;
    public function clear(): bool;              // 清空全部
    public function warmup(int $count = 100): array;  // 预热热门页面

    // 统计
    public function stats(): array;             // 总条目、大小、命中率
    public function hitRate(): float;

    // 内部
    private function l1Get(string $key): ?string;
    private function l2Get(string $key): ?string;
    private function compress(string $data): string;    // gzip
    private function decompress(string $data): string;  // gzdecode
}
```

### 集成方式：中间件模式

在 `phpMan.php` 的 dispatch switch 层统一拦截，而不是在每个 get*Page 函数内部：

```php
function cacheOrExecute(string $mode, string $name, string $section, string $format, callable $execFn): string {
    $cache = new PageCache();
    $cached = $cache->get($mode, $name, $section, $format);
    if ($cached !== null) {
        if ($cached === '###NOT_FOUND###') {
            return '';
        }
        return $cached;
    }
    $content = $execFn();
    // 注意：不在此处自动 set — dispatch 层决定 set 时机
    return $content;
}
```

**set 只在 dispatch 层的两处发生：**
1. `getManPage/getPerldocPage/etc` 返回非空 → set with `status='found'`
2. 所有 fallback 链尝试完毕仍为空 → set with `status='not_found'`

最小化代码改动：在 dispatch switch 的每个 case 中插入 `cacheOrExecute()` 包装。

---

## 十二、方案对比总结

| 标准 | 纯文件系统 | SQLite | 内存 KV (APCu) | 混合 (APCu+SQLite) |
|------|:---:|:---:|:---:|:---:|
| 单文件部署 | ❌ | ✅ | ⚠️ 需扩展 | ✅ |
| ~50K 记录 | ⚠️ inode 瓶颈 | ✅ | ✅ (内存) | ✅ |
| 持久化 | ✅ | ✅ | ❌ | ✅ |
| 原子性 | ❌ | ✅ | ✅ | ✅ |
| 代码复杂度 | 低 | 中 | 低 | 中 |
| 查询能力 | 无 | 强 | 无 | 中 |
| 空间效率 | ⚠️ 簇大小浪费 | ✅ 紧凑 | ✅ | ✅ |
| gzip 支持 | ⚠️ .gz 文件 | ✅ BLOB | ✅ | ✅ |
| **推荐** | ❌ | **✅ 主方案** | **✅ L1** | **✅ 推荐架构** |

### 最终推荐

**主存储：SQLite（持久化）**
- 单文件，零依赖
- ~200 MB（raw）~500 MB（全格式）完全在 SQLite 能力范围内
- 复合索引 + WAL 模式 → 单次查询 <1ms

**可选加速：APCu（内存）**
- 高频页面秒级响应
- 5 分钟 TTL，自动老化
- 无 APCu 时降级为直接读 SQLite

**否定的方案：**
- 纯文件系统 — 13,000+ 小文件，inode 压力大，无原子写入
- 纯内存 KV — 丢失持久化，冷启动惩罚大
- Redis/Memcached — 破坏单文件部署原则

---

## 十三、Roadmap 整合

此缓存系统对应 v3.0 路线图中的 "cache" 模块：

```
v3.0 代码拆分计划
├── cache/        ← 本设计
│   └── PageCache.php    ← 缓存类
├── phpMan.php    ← 主入口（调用缓存）
├── config/       ← 配置
└── format/       ← 格式渲染（从 phpMan.php 拆分）
```

CLAUDE.md 规则：单文件入口保留，但 `PageCache.php` 作为可选的 internal include。
