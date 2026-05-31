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

## 版本路线图

```
v2.1 (2026.05 已发布)    v3.0 MVP (2026.Q3)        v3.1 (2026.Q3末)      v4.0 (2026.Q4+)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
man / perldoc / info  →  pydoc3 支持          →  ri (Ruby)         →    AI 智能文档生成
MCP Server (JSON-RPC)     代码拆分 Phase 1        模糊搜索               智能翻译 (保留标识符)
Markdown 输出             MCP 错误标准化           索引侧栏导航           Cheat Sheet 模式
JSON API (语义化)         安全加固                 Go doc               参数样例自动生成
TLDR 端点                搜索增强                                      本地缓存 (永久)
跨平台宽度控制                                                         本地全文搜索
                                                                       国际化 (LANG+AI)

v2.1 = 当前生产 | v3.0 = 核心扩展 | v3.1 = 搜索与导航 | v4.0 = AI 重塑文档体验
```

---

## 一、多语言参考工具支持 `v3.0`

将 phpMan 从 Unix man/perldoc/info 扩展到覆盖主流编程语言的本地文档工具。

### 1.1 新增模式

| 模式 | 工具 | 命令 | URL 路由 | 输出格式 |
|------|------|------|----------|----------|
| pydoc | `pydoc3` | `pydoc3 os.path` | `/pydoc/os.path` | 纯文本，结构化 |
| ri | `ri` | `ri Array#map` | `/ri/Array/map` | 带 overstrike（同 man） |

### 1.2 入口索引

```
pydoc3 modules    → 列出所有 Python 模块（~345 个）
ri -l             → 列出所有 Ruby 文档条目（~1989 个）
```

- **>150 条自动启用字母索引侧栏**（按首字母分组，快速跳转）
- 搜索：`pydoc3 -k keyword` / `ri -i keyword`

### 1.3 远期扩展 (v3.0+)

| 语言 | 工具 | 优先级 | 备注 |
|------|------|--------|------|
| Go | `go doc` | 中 | 需在 GOPATH/GOROOT 环境运行 |
| Rust | `rustdoc` | 低 | 通常预生成 HTML |
| Node.js | Node.js 内置 `help` | 低 | 格式不统一 |
| C/C++ | man 已覆盖 | — | — |

### 1.4 实现要点

- 输出走现有 `formatManPerlDoc()` 管道（HTML/Markdown/JSON/MCP 四种格式复用）
- `ri` 输出和 man page 一样使用 `X^HX` overstrike 格式，直接兼容
- `pydoc3` 输出是纯文本，处理更简单
- URL 路由继承现有模式：`/pydoc/module.name` `/ri/Class/method`

### 1.5 宽度控制（跨平台）

三种模式的输出宽度统一为 `$PHP_MAN_WIDTH`（默认 100），但控制机制不同：

| 模式 | 机制 | 层级 | Linux | macOS/BSD |
|------|------|------|-------|-----------|
| man | `MANROFFOPT=-rLL=100n` + `man -Tutf8` | groff 排版引擎 | ✅ SGR 编码 | ❌ 无 groff |
| man fallback | `MANWIDTH=100 man` (bare) | BSD man 内置格式 | — | ✅ overstrike 编码 |
| perldoc | `pod2text -w 100` | POD 文本格式化器 | ✅ | ✅ |
| pydoc | 待定 | 待定 | — | — |

- **man (GNU/Linux)**: groff 的 `-rLL` 寄存器控制宽度，`-Tutf8` 输出 SGR escape 序列
- **man (BSD/macOS)**: `MANWIDTH=100` 环境变量控制宽度，默认输出 overstrike（`X^HX`）格式
- **perldoc**: `MANWIDTH` 对现代 perldoc 无效。改用 `pod2text -w 100` 在 POD 格式化层控制宽度 —— 跨平台通用
- **pydoc**: 不能用 groff（无 roff 格式内容），也不能用 pod2text（无 POD 格式）。需在 Python `pydoc.TextDoc` / `textwrap` 层控制
- `formatManPerlDoc()` 同时处理 SGR escape 和 overstrike 两种编码，fallback 透明

---

## 二、LLM 智能格式化 `v4.0`

利用免费/本地 LLM 对 man page 内容进行智能处理，输出工程友好的参考文档。

### 2.1 智能翻译 (`?lang=zh`)

**核心约束：保留所有代码标识符不翻译**

```
规则（写入 System Prompt）:
- 保留: 命令名、函数名、flag、参数、环境变量、文件路径、代码示例
- 仅翻译: 描述性文字和说明段落
- 使用工程术语，非通用翻译风格
```

对比效果：
```
Google 翻译:  chmod 实用程序修改文件模式位。
AI 翻译:     chmod 工具修改文件的 mode bits（权限位）。

                     ↑ 保留英文术语         ↑ 补充原词
```

### 2.2 Cheat Sheet 模式 (`?mode=cheat`)

```markdown
## curl — Quick Reference

| 场景 | 命令 |
|------|------|
| GET 请求 | `curl https://api.example.com` |
| POST JSON | `curl -X POST -H "Content-Type: application/json" -d '{}' URL` |
| 下载文件 | `curl -O https://example.com/file.tar.gz` |
| 查看头信息 | `curl -I https://example.com` |
| 跟随重定向 | `curl -L https://example.com` |

> 每个命令提取 5–10 个最常见用法
```

### 2.3 参数样例生成 (`?examples=1`)

```json
{
  "flag": "-r",
  "long": "--recursive",
  "arg": null,
  "description": "operate recursively on directories",
  "examples": [
    "chmod -R 755 /var/www",
    "chmod -R u+w,g+w ~/project"
  ]
}
```

### 2.4 TLDR 摘要 (已有基础)

- 当前 `/tldr/{command}` 已实现
- 强化：自动检测命令最常用 3 个子场景，分别给一行示例

### 2.5 LLM 接入方案

| 模型 | 免费额度 | 延迟 | 适用场景 |
|------|---------|------|---------|
| Gemini 2.5 Flash | 1500 req/day | ~1s | 翻译、TLDR 首选 |
| Groq (Llama-4) | 免费 tier | ~0.5s | 样例生成、cheat sheet |
| Ollama 本地 | 无限制 | 取决于硬件 | 隐私敏感、批量预处理 |
| DeepSeek V4 | 已有 API | ~2s | 已有配置零成本接入 |

### 2.6 缓存策略

man page 内容不变 → LLM 结果可**永久缓存**：

```
tldr_cache/
├── ls.1/
│   ├── cheat.zh.md           # 中文 Cheat Sheet
│   ├── examples.zh.json      # 中文参数样例
│   ├── lang.zh.json          # 全页中文翻译
│   └── summary.json          # TLDR 摘要
├── git-commit.1/
│   └── ...
```

- 按 `命令 + 语言 + 功能` 维度缓存
- 热门命令（Top 100）预热，首次访问即秒回
- 新增 `?refresh=1` 强制重新生成

---

## 三、搜索增强 `v3.0 → v4.0`

### 3.1 索引/搜索结果页字母导航 `v3.0`

**触发条件：条目数 > 150**

```
┌──────────────────────────────────────┐
│ A B C D E F G H I J K L M ...        │  ← 字母索引侧栏
├──────────────────────────────────────┤
│ A                                    │
│   ACL   ACL::ACLEntry   ARGF         │
│   Abbrev   Addrinfo   Array          │
│                                      │
│ B                                    │
│   Base64   BasicObject   Binding     │
│   BigDecimal   Bundler               │
│ ...                                  │
└──────────────────────────────────────┘
```

- 首字母侧栏固定在页面顶部/侧边
- 点击字母平滑滚动/跳转到对应分组
- 小屏（<768px）改为下拉选择器

### 3.2 模糊搜索 `v3.0`

```
输入: "git cmomit" → 建议: "git commit"
输入: "nginx"      → 建议: "nginx" (section 8), "nginx.conf" (section 5)
```

- 基于 Levenshtein 距离 + 音节匹配
- 优先返回结果，无结果时自动 Fallback 到模糊建议

### 3.3 本地全文搜索 `v4.0`

- 本地索引 man page 正文内容（不仅限于 apropos 的 NAME 行）
- SQLite FTS5 全文搜索引擎，无需外部依赖
- 支持 `?q=compress+files&fulltext=1` 全文匹配
- 搜索结果高亮显示匹配片段

---

## 四、MCP & Agent 协议优化 `v3.0`

### 4.1 流式/分页输出

大 man page（`bash` 351KB）JSON 一次性返回开销大：

```
GET /man/bash/1/json?page=1&limit=50       # Agent 按需翻页
GET /man/bash/1/json?section=OPTIONS       # 按 section 分段取
```

### 4.2 错误响应标准化

```json
{
  "error": {
    "code": "CMD_NOT_FOUND",
    "message": "No manual entry for foobar",
    "fallback": {
      "action": "search",
      "url": "/search/foobar/json"
    }
  }
}
```

`fallback.action` 让 Agent 能自动决策下一步。

### 4.3 MCP 动态工具发现

- `/mcp/tools/list` 返回所有可用命令的模式列表
- Agent 无需硬编码 phpMan URL 即可发现和调用

### 4.4 远期：Agent-to-Agent 协议

- 通过 `see_also` 字段构建命令关系图
- MCP `resources/list` 暴露命令间引用关系

---

## 五、代码架构拆分 `v3.0`

### 5.1 当前状态

- 单文件 `phpMan.php` ~129KB，混合所有功能
- 函数 ~80 个，全局变量 ~10 个

### 5.2 目标结构

```
phpMan/
├── public/
│   └── index.php              # 入口 + 路由 (~30 行)
├── src/
│   ├── Core/
│   │   ├── Router.php          # URL 解析 + 模式分发
│   │   └── Security.php        # h(), getSafeHost(), scriptName()
│   ├── Source/
│   │   ├── Man.php             # getManPage()
│   │   ├── Perldoc.php         # getPerldocPage()
│   │   ├── Info.php            # getInfoPage()
│   │   ├── Search.php          # getSearchPage()
│   │   ├── Pydoc.php           # getPydocPage() (新)
│   │   └── Ri.php              # getRiPage() (新)
│   ├── Formatter/
│   │   ├── Html.php            # formatManPerlDoc → HTML
│   │   ├── Markdown.php        # Markdown 输出
│   │   ├── Json.php            # JSON/MCP 输出
│   │   └── TOC.php             # TOC 侧边栏生成
│   ├── Renderer/
│   │   ├── Heading.php         # detectHeadingType()
│   │   ├── Overstrike.php      # overstrike → <b>/<u>
│   │   └── Flags.php           # parseFlagJSON()
│   ├── LLM/
│   │   ├── Translator.php      # 翻译
│   │   ├── ExampleGen.php      # 样例生成
│   │   └── CheatSheet.php      # Cheat Sheet
│   └── Cache/
│       └── CacheManager.php    # ETag + 过期管理
├── tests/                      # 已有
├── docs/                       # 已有
└── phpMan.php                  # 传统入口（向后兼容）
```

### 5.3 迁移策略

- 渐进式：新增模块先放到 `src/`，旧函数保留到下一大版本
- 测试先行：每拆分一个模块，先补测试覆盖
- 向后兼容：保留单文件入口至少一个大版本周期

---

## 六、国际化 (I18N) `v4.0`

### 6.1 LANG 环境变量支持

```php
$locale = $_GET['lang'] ?? $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
putenv("LANG={$locale}");
```

- `man -L zh_CN ls` — 中文 man page
- `man -L ja_JP find` — 日文 man page
- 无对应语言版本时 fallback 到英文 + AI 翻译

### 6.2 RTL 语言支持

- 检测阿拉伯语/希伯来语 → CSS `direction: rtl`
- TOC 侧栏镜像（右侧 → 左侧）

---

## 七、可观测性 `v3.0`

### 7.1 轻量访问统计

- 热门命令 Top 100（无需外部依赖，SQLite 本地存储）
- 格式偏好分布（HTML vs JSON vs Markdown）
- Agent vs 人类用户比例（User-Agent 分析）

### 7.2 调试模式

```
?debug=1 → 输出渲染管线耗时拆解：
  来源获取: 45ms
  解析:     12ms
  格式化:   8ms
  输出:     3ms
  ─────────────
  总计:    68ms
```

---

## 版本规划总结

```
┌──────────────────────────────────────────────────────────────┐
│ v3.0 MVP — 核心扩展 (2026.Q3)                                │
├──────────────────────────────────────────────────────────────┤
│ pydoc3 支持              代码架构拆分 Phase 1 (Source/ 模块化)│
│ MCP 错误响应标准化        安全加固 (input validation)         │
│ 搜索结果页增强            perldoc/man 跨平台宽度控制完善      │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│ v3.1 — 搜索与导航 (2026.Q3 末)                               │
├──────────────────────────────────────────────────────────────┤
│ ri (Ruby) 支持            索引侧栏导航 (>150 条)              │
│ 模糊搜索                  Go doc 支持                         │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│ v3.2 — 性能与可观测性 (2026.Q4)                              │
├──────────────────────────────────────────────────────────────┤
│ MCP 流式/分页输出         可观测性 (SQLite 统计)              │
│ 代码架构拆分 Phase 2      代码架构拆分 Phase 2 (Formatter/)   │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│ v4.0 — AI 智能文档 (2026.Q4+)                                │
├──────────────────────────────────────────────────────────────┤
│ AI 智能翻译 (保留标识符)   Cheat Sheet 模式                   │
│ 参数样例自动生成            TLDR 摘要增强                     │
│ 本地 LLM 缓存 (永久)        本地全文搜索 (SQLite FTS5)        │
│ 国际化 LANG + AI fallback     RTL 语言支持                   │
└──────────────────────────────────────────────────────────────┘
```

## 优先级矩阵

```
                    影响范围
                高          低
        ┌──────────────┬──────────────┐
  高    │ pydoc/ri 扩展 │ 模糊搜索      │  ← v3.0
        │ 索引侧栏导航  │ MCP 流式输出  │
  优    │ 代码拆分      │ 可观测性      │
  先    ├──────────────┼──────────────┤
  级    │ AI 智能翻译   │ Agent-to-Agent│  ← v4.0
  低    │ 本地缓存      │ MCP 动态发现  │
        │ Cheat Sheet  │ 国际化        │
        │ 本地全文搜索  │ RTL 支持      │
        └──────────────┴──────────────┘
```

**v3.0 MVP (2026.Q3):** pydoc3 + 代码拆分 Phase 1 + MCP 错误标准化 + 安全加固
**v3.1 (2026.Q3 末):** ri + 模糊搜索 + 索引侧栏导航 + Go doc
**v3.2 (2026.Q4):** MCP 流式/分页 + 可观测性 + 代码拆分 Phase 2
**v4.0 (2026.Q4+):** AI 翻译 + Cheat Sheet + 参数样例 + 本地缓存 + 本地全文搜索 + 国际化
