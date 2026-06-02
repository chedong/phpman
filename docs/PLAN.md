# phpMan 项目计划

## 版本号规范

- 格式: `v{MAJOR}.{MINOR}` → git tag `v2.1`, `v3.0`
- 补丁版本: `v2.1.1` — 仅安全修复/bugfix，不新增功能
- MAJOR 递增: 架构变更或不向后兼容的 API 改动
- MINOR 递增: 新增功能，保持向后兼容
- Tag 规则: 每个 release 对应一个 annotated tag

```bash
git tag -a v2.1 -m "v2.1: cross-platform width control, TLDR endpoint"
git push origin v2.1
```

---

## 版本路线图

```
v2.1 (2026.05 已发布)   →   v2.2 (即时)            →   v3.0 (2026.Q3+)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
man / perldoc / info        TLDR 嵌入全格式              代码拆分
MCP Server (JSON-RPC)       man+TLDR 实时聚合            配置文件 (phpman.config.php)
Markdown 输出               官方 tldr-pages 数据源        缓存基础设施 (man/tldr/missing)
JSON API (语义化)           零配置 零依赖                  LLM 智能生成
TLDR 端点 (LLM)             保持单文件                   离线数据采集与改写
跨平台宽度控制              去掉 LLM 密钥依赖             pydoc3 / ri 扩展
                                                         搜索增强 安全加固
                                                         本地全文搜索 国际化

v2.1 = 当前生产 | v2.2 = 轻量 TLDR 聚合 | v3.0 = 架构升级 + 缓存 + AI
```

---

## Phase 1: v2.2 — 零配置 TLDR 聚合（即时）

### 设计原则

- **单文件部署**，scp 即用
- **不需要本地设置**：无缓存目录、无 LLM 密钥、无配置文件
- **实时聚合**：man page（本地 shell）+ TLDR（官方 tldr-pages）→ 同一页面呈现
- **全格式嵌入**：HTML / JSON / Markdown / MCP 四种输出都包含 TLDR 段落

### 1.1 TLDR 数据源：官方 tldr-pages

直接消费 [tldr-pages/tldr](https://github.com/tldr-pages/tldr) 仓库的原始 markdown 文件。

**获取方式**：运行时从 GitHub Raw 拉取单页

```
https://raw.githubusercontent.com/tldr-pages/tldr/main/pages/common/curl.md
https://raw.githubusercontent.com/tldr-pages/tldr/main/pages/linux/curl.md
```

**查找顺序**：`common/` → `linux/` → `osx/` → 无（降级到规则提取）

**降级路径**：
```
官方 tldr 页面存在 → 解析展示
         │
         不存在
         │
         ▼
formatTldr() 规则提取（已有，从 man page EXAMPLES + FLAGS 生成）
```

### 1.2 页面嵌入方式

在 man page 顶部插入 TLDR block，不是另一个页面：

```
┌─────────────────────────────────────────────┐
│ ⚡ TLDR                                       │
│                                               │
│ Download a file:                              │
│   curl -O {{url}}                             │
│ Send JSON POST:                               │
│   curl -X POST -d '{}' {{url}}                │
│ Follow redirects:                             │
│   curl -L {{url}}                              │
│ [View full TLDR page]                         │
├─────────────────────────────────────────────┤
│ NAME                                          │
│   curl - transfer a URL                       │
│ SYNOPSIS                                      │
│   curl [options...] <url>                     │
│ ...                                           │
└─────────────────────────────────────────────┘
```

**交互逻辑**：
- 大文档（man page > 200 行）：TLDR 默认展开
- 小文档：TLDR 默认折叠，点击展开
- 无 TLDR 时（命令不在官方 tldr-pages 中）：不显示 TLDR block

### 1.3 JSON API 增强

当前 `/man/curl/1/json` 返回：

```json
{
  "name": "curl",
  "sections": {...},
  "flags": [...]
}
```

v2.2 增加 `tldr` 字段：

```json
{
  "name": "curl",
  "tldr": {
    "source": "official",
    "description": "Transfer data from or to a server",
    "examples": [
      {"description": "Download a file", "command": "curl -O {{url}}"},
      {"description": "Send a POST request", "command": "curl -X POST -d '{}' {{url}}"}
    ]
  },
  "sections": {...},
  "flags": [...]
}
```

`source` 字段取值：
- `"official"` — 来自 tldr-pages 官方仓库
- `"extracted"` — 规则提取降级
- `null` — 无 TLDR 可用

### 1.4 MCP 输出增强

```
当前（structuredContent）:
  {sections, flags, examples}

v2.2:
  {tldr_summary, tldr_examples, sections, flags, examples}
```

Agent 优先读 `tldr_examples`，需要完整文档时才展开 `sections`。

### 1.5 Markdown 输出增强

```
# curl

> **TLDR:** Transfer data from or to a server

- Download a file:
  `curl -O {{url}}`
- Send POST:
  `curl -X POST -d '{}' {{url}}`

---

## NAME
curl - transfer a URL
...
```

### 1.6 实现要点

- **无需缓存**：GitHub Raw 请求，HTTP 天然有 CDN 缓存
- **无新配置**：不引入任何 env var 或 config 文件
- **单文件**：改动集中在 `formatTldr()` 增强和一个 `fetchOfficialTldr()` 函数
- **超时控制**：GitHub 请求设 5s 超时，超时直接降级到规则提取
- **保持兼容**：现有 LLM 生成路径保留（有 `LLM_API_KEY` 时继续使用），官方 tldr 优先于 LLM

### 1.7 改动范围

| 改动 | 位置 | 说明 |
|------|------|------|
| `fetchOfficialTldr($cmd)` | 新增函数 | 从 GitHub Raw 拉 tldr 页面 |
| `parseTldrMarkdown($md)` | 新增函数 | 解析 tldr markdown → 结构化数据 |
| `formatTldr()` | 增强 | 官方数据优先，提取降级兜底 |
| JSON 输出 | `formatToJSON()` | 注入 `tldr` 字段 |
| MCP 输出 | `formatMcpStructured()` | 注入 `tldr_summary` + `tldr_examples` |
| HTML 输出 | `formatManPerlDoc()` | 页面顶部插入 TLDR block |
| Markdown 输出 | `formatManPerlDocToMarkdown()` | 文首插入 TLDR 段落 |

---

## Phase 2: v3.0 — 架构升级 + 缓存 + 配置（2026.Q3+）

v2.2 的功能逻辑全部保留，在现有基础上做架构性升级。

### 2.1 配置文件

引入 `phpman.config.php`（详见 `docs/CACHE_DESIGN.md` 第七节），统一管理：

- 缓存目录（`PHP_MAN_CACHE_ROOT`）
- LLM API 密钥（`LLM_API_KEY` 等）
- 缓存 TTL 覆盖

### 2.2 缓存基础设施

详见 `docs/CACHE_DESIGN.md`，核心：

```
/var/cache/phpman/
├── man/          ← man raw text，7 天 TTL，hash bucket
├── tldr/         ← TLDR 结果，7 天 TTL
└── missing/      ← 负缓存，1 天 TTL
```

- 缓存 raw text，不缓存 HTML/JSON/MCP（实时渲染）
- md5 hash 做文件名，杜绝路径遍历
- 256 hash bucket 避免单目录文件数爆炸

### 2.3 LLM 智能生成

- 配置 `LLM_API_KEY` 后，TLDR 可以为官方未覆盖的命令生成内容
- 优先级：官方 tldr > LLM 生成 > 规则提取
- 结果写入 tldr 缓存，复用缓存基础设施

### 2.4 代码拆分

```
phpMan.php (入口 + 路由)
src/
├── Source/     ← getManPage, getPerldocPage, fetchOfficialTldr
├── Formatter/  ← HTML, JSON, Markdown, MCP, TLDR
├── Cache/      ← cacheGet, cacheSet, cachePath
└── Config/     ← config 加载
```

保留单文件入口向后兼容。

### 2.5 多语言工具扩展

- pydoc3 支持
- ri (Ruby) 支持
- 模糊搜索、索引侧栏导航

### 2.6 离线数据

- 官方 tldr-pages 仓库本地 clone + cron 更新（替代实时 GitHub Raw 请求）
- 热门命令 Top 100 预热
- 本地全文搜索 (SQLite FTS5)

---

## v2.1 → v2.2 → v3.0 对比

```
                v2.1              v2.2               v3.0
─────────────────────────────────────────────────────────────
文件数          1                 1                  1 + src/
配置文件        无                无                  phpman.config.php
缓存目录        tldr_cache/(LLM)  无                  /var/cache/phpman/
TLDR 来源       LLM API           官方 tldr-pages     官方 > LLM > 提取
TLDR 覆盖格式   /tldr 端点        全部 4 种格式        全部 4 种格式
LLM 密钥        可选              不需要              可选（增强）
部署            scp 1 文件        scp 1 文件          scp + config 一次
```
