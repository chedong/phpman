# phpMan 缓存设计文档

> 版本: 2.0 | 日期: 2026-06-02 | 适用于 v3.0
>
> **注意**：本文档描述的是 v3.0 的缓存架构设计。v2.2 不包含任何本地缓存 ——
> TLDR 从官方 tldr-pages 实时获取，man page 每次 shell 执行。详见 `docs/PLAN.md`。

---

## 一、核心原则

```
缓存什么                  不缓存什么
─────────────────────────────────────────
✅ man raw text           ❌ HTML（从 raw 实时渲染）
✅ tldr markdown          ❌ JSON（从 raw 实时生成）
✅ missing lookup         ❌ MCP（从 JSON 实时包装）
✅ perldoc raw text
```

**为什么只缓存 raw text**：man → HTML、man → JSON、man → MCP 都是从同一份 raw text 派生。缓存 raw text 一处命中，四种输出格式全部受益。缓存 HTML 则 JSON/TLDR/MCP 还要再执行一次 man。

---

## 二、目录布局

```
/var/cache/phpman/          ← CACHE_ROOT，必须在 webroot 外
├── man/                    ← man raw text 缓存
│   ├── a1/
│   ├── b4/
│   ├── ...
│   └── ff/                 ← 最多 256 个 bucket
│
├── perldoc/                ← perldoc raw text 缓存
│   └── ...
│
├── tldr/                   ← TLDR 生成结果缓存
│   └── ...
│
└── missing/                ← 负缓存（不存在的命令）
    ├── man/
    │   └── ...
    └── tldr/
        └── ...
```

### 为什么放在 webroot 外

- Apache/Nginx/Caddy 无法直接访问，不需要 `.htaccess` deny
- 不会被误当成静态文件 serve
- 备份、清理、监控与业务代码隔离

### 为什么用 hash bucket

单目录文件数过大时，`ls`、`find`、`rsync`、ext4/xfs 目录遍历都会变慢。256 个 bucket（取 md5 前 2 位 hex）确保每个目录均匀分布，即使缓存 30000+ man page 也不会有单个目录超过 ~120 个文件。

---

## 三、缓存 Key 与路径构造

### 3.1 核心安全规则

**永远不要用用户输入直接拼路径。**

```php
// 错误 — 路径遍历风险
$cacheFile = CACHE_ROOT . "/man/" . $name . "." . $section;

// 正确 — md5 单向映射，输入不可逆
$key  = strtolower($name) . ":" . $section;
$hash = md5($key);
$file = CACHE_ROOT . "/man/" . substr($hash, 0, 2) . "/" . $hash;
```

即使攻击者传入 `name=../../etc/passwd`，最终的路径也只是 `/var/cache/phpman/man/ab/ab8f2d3c...` — 路径遍历攻击直接失效。

### 3.2 Key 构造规则

```
man:     "{name}:{section}"     → curl:1,    open:2,   git:1
perldoc: "perldoc:{module}"     → perldoc:File::Basename
tldr:    "tldr:{name}:{section}"→ tldr:curl:1
missing: "missing:{type}:{name}:{section}" → missing:man:foobar123456:
```

**Key 必须包含 section**。`open(1)`、`open(2)`、`open(3)` 是不同的文档，不能共享缓存。

### 3.3 路径构造函数

```php
function cachePath(string $ns, string $key): string {
    $hash = md5(strtolower($key));
    $dir  = CACHE_ROOT . "/" . $ns . "/" . substr($hash, 0, 2);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir . "/" . $hash;
}

// 使用示例
$path = cachePath("man", "curl:1");       // → /var/cache/phpman/man/a1/a1b2c3d4...
$path = cachePath("tldr", "curl:1");      // → /var/cache/phpman/tldr/e5/e5f6a7b8...
$path = cachePath("missing", "man:foobar123456:"); // → /var/cache/phpman/missing/man/9c/9c3d4e5f...
```

---

## 四、缓存 TTL

| 类型 | TTL | 理由 |
|------|-----|------|
| man raw text | 7 天 | man-pages 包更新频率低，系统升级才变 |
| perldoc raw text | 7 天 | 同上 |
| tldr | 7 天 | man page 不变 → LLM 输出确定 |
| missing（负缓存） | 1 天 | 防止 Googlebot/LLM crawler/恶意扫描反复打不存在的命令 |

### 判断逻辑

```php
function cacheGet(string $path, int $ttl): ?string {
    if (!file_exists($path)) return null;
    if (time() - filemtime($path) >= $ttl) return null;  // 过期
    $content = file_get_contents($path);
    if ($content === false || strlen($content) < 10) return null;  // 损坏
    return $content;
}
```

---

## 五、请求流向

### 5.1 man 页面

```
GET /man/curl/1
      │
      ├─ check missing/man/ → hit → 返回 404
      │
      ├─ cacheGet(man, "curl:1")
      │   ├─ hit  → raw text
      │   └─ miss → shell_exec("man curl 1")
      │              ├─ 成功 → cacheSet(man, "curl:1", raw, 7d)
      │              │         → 进入渲染管线
      │              └─ 失败 → cacheSet(missing/man, "curl:1", 1d)
      │                        → 返回 404
      │
      └─ 渲染管线（从 raw text 出发）
           ├─ format=html     → formatManPerlDoc(raw)
           ├─ format=json     → formatToJSON(parseMan(raw))
           ├─ format=markdown → formatManPerlDocToMarkdown(raw)
           └─ format=mcp      → formatForOutput(json, "mcp")
```

**关键收益**：man raw 命中后，HTML/JSON/Markdown/MCP 四种格式都不再执行 `man` 命令。

### 5.2 TLDR 页面

```
GET /tldr/curl
      │
      ├─ check missing/tldr/ → hit → 降级到 formatTldr(raw)
      │
      ├─ getTldrFromCache("curl:1")
      │   ├─ hit  → 返回 markdown
      │   └─ miss →
      │        ├─ getManRaw("curl:1")  ← 复用 man 缓存层
      │        └─ generateTldrWithLLM(data)
      │             ├─ 成功 → cacheSet(tldr, "curl:1", md, 7d)
      │             └─ 失败 → formatTldr(data) 降级
      │                        → cacheSet(missing/tldr, "curl:1", 1d)
```

### 5.3 JSON API

```
GET /man/curl/1/json
      │
      ├─ getManRaw("curl:1")  ← 复用 man 缓存
      │   └─ hit → parseMan(raw) → formatToJSON() → 返回
      │
      └─ ETag 304 仍然生效（HTTP 传输层优化）
```

---

## 六、缓存操作接口

```php
/**
 * 读取缓存。
 * 返回内容字符串或 null（miss/过期/损坏）。
 */
function cacheGet(string $ns, string $key, int $ttl): ?string;

/**
 * 写入缓存。
 * 写入失败记录 error_log，不抛异常。
 */
function cacheSet(string $ns, string $key, string $content): void;

/**
 * 检查并写入负缓存（不存在的命令）。
 */
function cacheSetMissing(string $ns, string $key): void;

/**
 * 检查是否为已知不存在的命令。
 */
function cacheIsMissing(string $ns, string $key): bool;

/**
 * 获取缓存文件路径（不检查存在性，不创建目录）。
 * 仅用于调试/清理工具。
 */
function cachePath(string $ns, string $key): string;
```

### 使用示例

```php
// man page 获取
$raw = cacheGet("man", "curl:1", CACHE_TTL_MAN);
if ($raw !== null) return $raw;

$raw = shell_exec("man curl 1 2>/dev/null");
if ($raw !== "" && $raw !== null) {
    cacheSet("man", "curl:1", $raw);
    return $raw;
}
cacheSetMissing("man", "curl:1");
return null;
```

---

## 七、配置管理

### 7.1 配置文件：`phpman.config.php`

所有配置集中在一个文件，放在 `phpMan.php` 同级目录。`phpMan.php` 只需在顶部加一行 `@include`，后续运维只改 config 文件，不动主程序。

```php
<?php
// phpman.config.php — phpMan 运行时配置
// 放在 phpMan.php 同级目录，无需 .htaccess 保护
//
// 新部署：复制此文件到同级目录，填写配置项
// 已有部署：环境变量继续生效（服务器 env 优先于本文件）

// 自检：直接 URL 访问时返回 403，被 include 时正常执行
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

// ────────────────────────────────────────────
//  缓存
// ────────────────────────────────────────────

// 缓存根目录（建议 webroot 外，如 /home/user/phpman-cache）
// 留空：自动探测（XDG → webroot 父目录 → .cache → /tmp）
putenv('PHP_MAN_CACHE_ROOT=/home/user/phpman-cache');

// 缓存 TTL（秒），留空使用默认值
// putenv('CACHE_TTL_MAN=604800');       // man raw text，默认 7 天
// putenv('CACHE_TTL_TLDR=604800');      // TLDR 生成结果，默认 7 天
// putenv('CACHE_TTL_MISSING=86400');    // 负缓存，默认 1 天

// ────────────────────────────────────────────
//  LLM API（TLDR 功能需要）
// ────────────────────────────────────────────

putenv('LLM_API_URL=https://api.openai.com/v1/chat/completions');
putenv('LLM_API_KEY=sk-ant-xxxxx');
putenv('LLM_MODEL=gpt-4o-mini');
// putenv('LLM_TIMEOUT=15');             // API 超时秒数，默认 15

// ────────────────────────────────────────────
//  TLDR 旧缓存（迁移过渡期兼容）
//  新部署无需设置
// ────────────────────────────────────────────
// putenv('TLDR_CACHE_DIR=/path/to/old/tldr_cache');
// putenv('TLDR_CACHE_TTL=604800');
```

### 7.2 phpMan.php 改动

在现有所有 `define` 之前加一行 `@include`：

```php
// === phpMan.php 顶部，所有 define 之前 ===
@include __DIR__ . '/phpman.config.php';

// 现有代码不变
define('LLM_API_URL',  getenv('LLM_API_URL')  ?: '');
define('LLM_API_KEY',  getenv('LLM_API_KEY')  ?: '');
define('LLM_MODEL',    getenv('LLM_MODEL')    ?: 'gpt-4o-mini');
define('LLM_TIMEOUT',  (int)(getenv('LLM_TIMEOUT') ?: 15));
```

### 7.3 配置优先级

```
Apache SetEnv / Nginx fastcgi_param / php-fpm pool env[]
        │  最高优先，覆盖一切
        ▼
phpman.config.php  putenv()
        │  补 config 文件中设置的值
        ▼
phpMan.php 内置默认值
        │  兜底
```

`putenv()` 不覆盖已存在的同名环境变量。生产环境通过服务器配置注入的密钥（如 `SetEnv LLM_API_KEY`）不会被 config 文件覆盖。

### 7.4 自适应缓存目录探测

`PHP_MAN_CACHE_ROOT` 未设置时自动探测：

```php
function detectCacheRoot(): string {
    // 1. 显式配置
    $env = getenv('PHP_MAN_CACHE_ROOT');
    if ($env) return rtrim($env, '/');

    // 2. XDG cache 目录（systemd 服务、Linux 标准）
    $xdg = getenv('XDG_CACHE_HOME') ?: (getenv('HOME') ?: '/tmp') . '/.cache';
    $path = $xdg . '/phpman';
    if (@mkdir($path, 0700, true) || (is_dir($path) && is_writable($path))) return $path;

    // 3. webroot 父目录（虚拟主机最常见）
    $parent = dirname(__DIR__);
    $path = $parent . '/phpman-cache';
    if ($parent !== __DIR__
        && is_dir($parent) && is_writable($parent)
        && strpos($parent, '/home/') === 0) {
        @mkdir($path, 0700, true);
        return $path;
    }

    // 4. webroot 内隐藏目录（最后手段）
    $path = __DIR__ . '/.cache';
    @mkdir($path, 0700, true);
    return $path;
}
```

### 7.5 各部署场景

| 场景 | config 文件 | 缓存目录 |
|------|-----------|----------|
| VPS root | 不需要（系统 env 设好） | `/var/cache/phpman` |
| cPanel | FTP 上传 `phpman.config.php` | `/home/user/phpman-cache/`（自动） |
| Plesk Nginx | FTP 上传 `phpman.config.php` | 设 `PHP_MAN_CACHE_ROOT` |
| Docker | env 注入或 volume mount | `PHP_MAN_CACHE_ROOT=/data/cache` |
| 本地开发 | 不需要 | `./.cache/`（自动） |
| 零配置极限 | 无 config 文件，无 env | `./.cache/`，TLDR 禁用 |

---

## 八、负缓存（Missing Cache）

### 为什么需要

不存在的命令（如 `foobar123456`、扫描器随机字符串）每次请求都会执行 `man foobar123456`。Googlebot、GPTBot、恶意扫描器每天可能产生成千上万次无效查询。

### 实现

```php
// missing 缓存文件内容
// 只需要一个 checked_at 时间戳，极小
{"checked_at": 1717286400}

// TTL: 1 天。即使真的有人后来安装了该命令的 man page，
// 最多等 1 天就会重新检查。
```

### 流程

```
请求 /man/foobar123456
  │
  ├─ cacheIsMissing("man", "foobar123456:") → true
  │   └─ 直接返回 404，不执行 man 命令
  │
  └─ 首次 miss → shell_exec("man foobar123456")
       ├─ 成功（有输出）→ cacheSet("man", ...)
       └─ 失败（空输出）→ cacheSetMissing("man", ...)
                          → 返回 404
```

---

## 九、数据流总览

```
                    ┌──────────────────────┐
                    │     HTTP Request      │
                    └──────────┬───────────┘
                               │
                    ┌──────────▼───────────┐
                    │    路由 & 格式协商     │
                    └──────────┬───────────┘
                               │
            ┌──────────────────┼──────────────────┐
            │                  │                  │
     ┌──────▼──────┐   ┌──────▼──────┐   ┌──────▼──────┐
     │   man 请求   │   │  tldr 请求   │   │  其他请求    │
     └──────┬──────┘   └──────┬──────┘   └─────────────┘
            │                  │
     ┌──────▼──────┐   ┌──────▼──────┐
     │ missing 检查 │   │ missing 检查 │
     └──────┬──────┘   └──────┬──────┘
            │                  │
     ┌──────▼──────┐   ┌──────▼──────┐
     │ man 缓存层   │   │ man 缓存层   │  ← 复用同一缓存层
     │ raw text    │   │ raw text    │
     └──────┬──────┘   └──────┬──────┘
            │                  │
     ┌──────▼──────┐   ┌──────▼──────┐
     │  渲染管线    │   │ tldr 缓存层  │
     │ html|json   │   │ markdown    │
     │ md|mcp      │   └──────┬──────┘
     └──────┬──────┘          │
            │          ┌──────▼──────┐
     ┌──────▼──────┐   │ LLM API     │
     │ HTTP 响应    │   │ (miss 时)   │
     │ + ETag/gzip │   └─────────────┘
     └─────────────┘
```

---

## 十、运维

### 缓存统计

```bash
CACHE_ROOT="${PHP_MAN_CACHE_ROOT:-/var/cache/phpman}"

# 各类缓存条目数
find "$CACHE_ROOT/man" -type f | wc -l
find "$CACHE_ROOT/tldr" -type f | wc -l
find "$CACHE_ROOT/missing" -type f | wc -l

# 磁盘占用
du -sh "$CACHE_ROOT"/*

# 每个 bucket 的文件数分布
for d in "$CACHE_ROOT/man"/*/; do
    echo "$(find "$d" -type f | wc -l) $d"
done | sort -rn | head
```

### 清理

```bash
CACHE_ROOT="${PHP_MAN_CACHE_ROOT:-/var/cache/phpman}"

# 清理所有过期缓存（>7 天 man，>1 天 missing）
find "$CACHE_ROOT/man" -type f -mtime +7 -delete
find "$CACHE_ROOT/missing" -type f -mtime +1 -delete

# 清理空 bucket 目录
find "$CACHE_ROOT" -type d -empty -delete

# 全量清空（紧急情况）
rm -rf "$CACHE_ROOT/man"/*/*
rm -rf "$CACHE_ROOT/tldr"/*/*
rm -rf "$CACHE_ROOT/missing"/*/*
```

### 预热（可选，v4.0）

```bash
# 对 Top 100 热命令预生成 man + tldr 缓存
for cmd in curl wget git ffmpeg rsync openssl find grep sed awk tar gzip ssh scp ...; do
    curl -s "http://localhost/man/$cmd/json" > /dev/null
    curl -s "http://localhost/tldr/$cmd" > /dev/null
done
```

### 监控指标

| 指标 | 获取方式 | 目标 |
|------|----------|------|
| man 缓存命中率 | access log + cache hit log | >90% |
| missing 拦截率 | `find missing -type f \| wc -l` | 持续增长 = 正常 |
| 缓存磁盘占用 | `du -sh /var/cache/phpman` | <200MB |
| man 命令延迟 p99 | `?debug=1` | <50ms (缓存命中时) |

---

## 十一、迁移路径（从当前 v2.1）

### 阶段 1：建立配置与缓存基础设施

1. 新增 `@include __DIR__ . '/phpman.config.php'`（phpMan.php 一行改动）
2. 创建 `phpman.config.php` 模板文件
3. 实现 `cacheGet()` / `cacheSet()` / `cacheSetMissing()` / `cacheIsMissing()`
4. 实现 `cachePath()` 的 hash bucket 逻辑
5. 实现 `detectCacheRoot()` 自适应探测
6. 单元测试覆盖路径安全（`../../etc/passwd` 不逃逸）

### 阶段 2：迁移 man page 获取

1. `getManPage()` 内嵌 `cacheGet("man", ...)` 逻辑
2. 缓存 raw text（`man` 命令原始输出，未经过 overstrike 清理）
3. 渲染管线不变（继续消费 raw text）
4. HTML/JSON/Markdown/MCP 全部自动受益

### 阶段 3：迁移 TLDR

1. 将现有 `tldr_cache/` 目录迁移到 `CACHE_ROOT/tldr/`
2. 复用 hash bucket 路径结构
3. TLDR 获取优先查 man 缓存（省一次 shell exec）

### 阶段 4：清理

1. 删除旧的 `tldr_cache/` 目录和 `.htaccess`
2. 更新 `.gitignore`
3. 更新 README 部署文档
