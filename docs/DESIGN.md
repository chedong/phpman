# phpMan 产品定义与设计文档

> 代码 review 前必读：本文档定义了 phpMan 的产品定位、核心设计决策和有意为之的行为。
> 避免将功能性设计误判为安全缺陷或技术债。

---

## 一、产品定位

phpMan 是一个单文件 PHP Web 应用，将 Unix `man`/`perldoc`/`info`/`apropos`/`pydoc3`/`ri` 命令的输出以 HTML、Markdown、JSON、MCP 四种格式呈现，同时作为 MCP Server 供 AI Agent 调用。

**核心价值**：让人类和 AI 都能高效查阅 Unix 文档。

---

## 二、有意为之的设计决策

### 2.1 页脚显示访客 IP 和 User-Agent ✅

**Issue 参考**：#27（已关闭，非缺陷）

`showFooter()` 输出访客 IP 和 User-Agent 是**有意的产品功能**，用于：

1. **跟踪搜索引擎蜘蛛** — 通过 User-Agent 识别 Googlebot、Bingbot、Baiduspider 等爬虫的抓取行为
2. **跟踪大模型抓取器** — 识别 GPTBot、ClaudeBot、Bytespider、Applebot-Extended 等 AI 爬虫
3. **访问分析** — IP 帮助区分真实用户与自动化请求，辅助运维决策

**设计原则**：
- 这些信息仅出现在 HTML 页面源码中（已通过 `h()` 防 XSS）
- JSON/MCP/Markdown 等非 HTML 格式不包含访客信息
- 这是 phpMan 作为**公开文档站**的核心可观测性功能，不是隐私泄露

**相关代码位置**：`showFooter()` ~line 964-965

### 2.2 单文件架构 ✅

phpMan 以单文件 `phpMan.php` 部署，这是有意的设计：

- **零依赖部署**：scp 一个文件即可运行，无需 Composer/autoload
- **向后兼容**：SourceForge 时代就存在的用户可能在旧 PHP 版本上运行
- 代码拆分（v3.0 路线图）将渐进式进行，保留单文件入口

### 2.3 XHTML 1.0 Transitional ✅

phpMan 保持 XHTML 1.0 Transitional 合规，不升级到 HTML5：

- 不使用 `og:` meta 标签（`property` 属性不兼容 XHTML）
- 不使用 HTML5 语义标签（`<nav>`、`<section>` 等）
- 外部链接保持最小 URL 参数

### 2.4 TLDR 缓存策略 ✅

TLDR 缓存文件存储在 webroot 内的 `tldr_cache/` 目录：

- man page 内容不变 → LLM 生成结果可永久缓存
- 缓存按 `命令+模型+prompt版本+上下文hash` 唯一标识（#23 修复后）
- 这是性能与成本的平衡：避免每次请求都调用 LLM API

### 2.5 Info 模式 Setext 标题检测 ✅

GNU info 页面使用 Setext 风格的下划线标题：

| 级别 | 模式 | 示例 |
|------|------|------|
| H1 | 下一行为 `*****` | `1 Introduction\n**************` |
| H2 | 下一行为 `=====` | `2.1 Invoking shar\n=================` |
| H3 | 下一行为 `-----` | `2.1.1 shar help\n----------------` |

`detectHeadingType($line, $mode, $nextLine)` 接受可选 `$nextLine` 参数。info 模式下检测到标题时返回 `skipNext: true`，调用方跳过下划线行。其他模式忽略此参数。

**相关代码位置**：`detectHeadingType()` ~line 420，`formatManPerlDoc()` ~line 2374

### 2.6 Tokyo Night 暗色主题 ✅

v2.3 采用 Tokyo Night 配色，统一所有模块（man/perldoc/info/pydoc/ri）视觉风格：

| 元素 | 色值 | 用途 |
|------|------|------|
| `#1a1b26` | 深蓝黑 | 主背景 |
| `#c0caf5` | 浅蓝灰 | 正文 |
| `#e0af68` | 暖金 | 粗体 |
| `#9ece6a` | 绿色 | 下划线 |
| `#7aa2f7` | 蓝色 | 链接、按钮 |
| `#24283b` | 深蓝灰 | 侧栏/TLDR 背景 |
| `#3b4261` | 中灰蓝 | 边框 |

CSS 全局统一：`body`/`pre` 共享字体和字号，`<b>`/`<u>` 颜色不再区分模块。

### 2.7 格式链接仅详情页显示 ✅

Markdown | JSON | MCP 格式链接仅在详情页（有实际内容）显示在搜索框同行。首页和无结果页不显示格式链接。

**相关代码位置**：`showForm()` ~line 1252

### 2.8 H1 面包屑 + 标题格式 ✅

详情页 H1 和 `<title>` 统一为面包屑格式：

```
phpMan > man > ls(1)
```

- `phpMan` 链接到首页，中间元素链接到模式索引页（`/man`、`/pydoc` 等），当前页纯文本
- perldoc 无独立索引，中间链接指向 `/search/perl`
- 首页/search 模式保持原有单标题样式

**相关 Issue**：#65

### 2.9 搜索结果统一列表格式 ✅

搜索（apropos）和 pydoc3 关键词搜索结果统一用 `<ul><li>` 列表格式，替换 `<pre>` + `<br />` 分行：

| 模块 | 格式 | 容器 |
|------|------|------|
| apropos | `<li><a>` 链接列表 | `<h2>apropos</h2>` + `<ul>` |
| pydoc3 | `<li><a>mod — desc</a></li>` | `<h2>Python 3 (pydoc3)</h2>` + `<ul>` |
| ri | 完整文档内容 | `<h2>Ruby (ri)</h2>` + `<pre>` |

ri index（`/ri`）同步改为 `<ul>` 列表。搜索/搜回退页面用 `<div id="man-content">` 而非 `<pre>` 包裹。

### 2.10 Footer Git 版本号 ✅

### 2.12 命令名大小写与平台差异（Linux vs BSD）✅

phpMan 的路由 `normalizeParameter()` 保留路径中命令名的原始大小写（不做 `strtolower`），依赖下游系统各自处理。

**系统 man 命令对大小写的处理因平台而异（实测确认）：**

| 平台 | `man RUBY` | 原因 |
|------|-----------|------|
| Linux (GNU man-db) | ✅ 找到 `ruby(1)` | mandb 数据库 + 文件系统 glob 双路归一化 |
| macOS (BSD man) | ❌ No manual entry | 直接 `stat()` 文件，大小写敏感 |

**GNU man 的归一化过程**（通过 `man -d RUBY` 确认）：
1. 打开 `/var/cache/man/index.db`
2. `multi key lookup (Ruby\t1)` 和 `multi key lookup (ruby\t1)` — 同时查询 title-case 和 lowercase
3. `globbing pattern RUBY.1*` 也能匹配到 `ruby.1.gz`
4. 最终找到物理文件 `ruby3.0.1.gz`

**BSD man**：直接按文件名查找，`man RUBY` 失败。

**对 phpMan 路由设计的影响：**

```
phpMan.php/man/RUBY/1
  → exec("man -Tutf8 1 'RUBY'")
    → GNU man 通过 mandb 找到 ruby(1) ✅（Linux）
    → BSD man 找不到 ❌（macOS，除非文件系统大小写不敏感）

  → fetchOfficialTldr("RUBY")
    → GitHub RAW: RUBY.md 404 ❌（URL 大小写敏感）
    → cheat.sh/RUBY: Unknown topic ❌
```

**核心不对称**：系统命令（man/perldoc/info）依赖系统自身行为，而外部 API（GitHub RAW / cheat.sh / LLM API）是大小写敏感的 URL。这意味着：

- man 命令找到页面 ≠ fetchOfficialTldr 能找到 TLDR（修复前）
- TLDR 缓存 key 必须归一化（`strtolower`）后再用（已在 #XX 修复）
- 其他外部 API 调用（LLM `/tldr` 端点）同样需要在入口层处理大小写

**设计原则**：phpMan 的"系统调用信任，外部 API 防御"不对称是路由设计的核心理解点。系统命令的兼容性由各平台保证；外部 API 调用必须由 phpMan 做显式归一化。

**TOC（目录）在移动端的显示策略**：

- **宽屏（>1024px）**：TOC 侧栏固定右侧，默认展开，不显示切换按钮
- **窄屏（≤1024px）**：TOC 默认收起，仅显示标题行（如 `tar(1) □`），点击标题行展开/收起下方链接
- **切换按钮**：`□`（展开）/ `✕`（收起）图标 `float:right` 与标题同行，整个标题行可点击
- **back-to-top**：移动端 z-index 高于 TOC sidebar，展开时不会被遮挡
- **实现**：`body.toc-open` class 切换，纯 CSS 控制，onclick 内联 JS 无外部依赖

**相关代码位置**：`$MOBILE_CSS` heredoc（~line 37）、`showHeader()` 内 `global $MOBILE_CSS`（~line 1163）、`$hasTocContent` 块（~line 1128）

`make deploy`/`make release` 通过 `sed` + ssh pipe 将 `git describe --tags --always --dirty` 注入 `GIT_DESCRIBE` 常量，footer 显示 `phpMan v2.3-5-g1cea00a`。本地开发默认为 `local`。

---

## 三、安全边界定义

以下行为属于**安全缺陷**，需要修复：

| 类别 | 示例 | 处理方式 |
|------|------|----------|
| 注入漏洞 | CRLF 注入、命令注入、XSS | 立即修复 |
| 信息泄漏（非设计意图的） | MCP 错误暴露内部路径、异常详情 | 修复 |

以下行为属于**防御纵深**，应由服务器层（Nginx/Cloudflare/CDN）统一处理，PHP 层代码待清理：

| 防御项 | 服务器层方案 | PHP 层当前状态（待清理） | Issue | 说明 |
|--------|-------------|------------------------|-------|------|
| 速率限制 | Nginx `limit_req` / Cloudflare WAF | `checkRateLimit()` 文件锁方案 | #84 | PHP 限流在代理场景下无效（`REMOTE_ADDR` 是代理 IP），文件锁高并发下竞争严重（#76 已关闭） |
| Gzip 压缩 | Nginx `gzip on` / Cloudflare 自动压缩 | `ob_gzhandler` | #84 | 可能与服务器 gzip 双重压缩；阻塞 PHP 进程 |
| 安全响应头（HSTS） | Nginx `add_header Strict-Transport-Security ... always;` | `showHeader()` 内 `if (!isLocalRequest())` 条件输出 | #89 | 代理后 `REMOTE_ADDR` 是内网 IP → 生产环境反而不发 HSTS；本地开发用 HTTP 不存在 HSTS 问题 |

**设计原则**：phpMan 是单文件应用，限流/压缩/安全头等基础设施应交给部署层（Nginx/Apache/Cloudflare/CDN）处理，PHP 层不做过度工程。phpMan 不一定部署在网站根目录下，因此不生成 robots.txt、sitemap.xml、llms.txt 等根路径文件，这些应由站点管理员在服务器层统一配置。

### 3.1 `isLocalRequest()` 废弃

`isLocalRequest()` 通过 `$_SERVER['REMOTE_ADDR']` 判断请求来源，在反向代理后 `REMOTE_ADDR` 是代理 IP 而非客户端 IP，导致判断失效。该函数将被整体移除，3 处调用分别由正确方案替代：

| 调用处 | 当前行为 | 问题 | 替代方案 | Issue |
|--------|----------|------|----------|-------|
| line 1172: HSTS header | `if (!isLocalRequest())` → 发送 HSTS | 代理后 `REMOTE_ADDR` 是内网 IP → 生产环境反而不发 HSTS | **Nginx 配置**：生产 HTTPS 虚拟主机 `add_header Strict-Transport-Security ... always;`；本地开发用 HTTP 不存在 HSTS | #89 |
| line 1423: 服务器版本 | `if (isLocalRequest())` → 显示 `SERVER_SOFTWARE` | 代理后所有请求来自内网 IP → 任何人都能看到版本信息 | **Nginx `server_tokens off`** + **php.ini `expose_php=Off`**；PHP 代码中删除版本显示 | #89 |
| `?debug=1` 调试模式 | `isLocalRequest()` → 允许查看敏感细节 | 同上，代理后所有人都能触发调试 | **PHP 环境变量** `PHPMAN_DEBUG=true`，显式配置而非 IP 推断 | #89 |

**设计原则**：安全策略（HSTS、版本隐藏）属于传输层/基础设施层，应由 Web 服务器在 TLS 终端处理，不应由 PHP 应用逻辑决定。应用层功能（调试模式）用显式环境变量控制，而非运行时 IP 推断——`REMOTE_ADDR` 在代理架构下不可信。

以下行为属于**v2.3 已完成的安全加固**：

| 加固项 | Issue | 实现 | 状态 |
|--------|-------|------|------|
| 非 HTML 响应安全头 | #63 | JSON/Markdown/MCP 响应添加 `X-Content-Type-Options: nosniff` + `X-Frame-Options: DENY` | ✅ 保留 |
| HSTS 强制 HTTPS | #70 → #89 | `Strict-Transport-Security` 头，原 `if (!isLocalRequest())` 条件输出 | 🔄 待清理：改由 Nginx 配置，删除 PHP 层 `isLocalRequest()` 判断（#89） |
| IP 级速率限制 | #69 → #84 | `checkRateLimit()` 基于文件锁 + JSON 存储，默认 30 req/60s | 🔄 待清理：改由 Nginx `limit_req`，删除 PHP 层实现（#84） |
| MCP 错误信息泛化 | #71 | `sendMcpError()` 返回 `Method not found` 不暴露内部方法名 | ✅ 保留 |
| Shell 参数防御 | #62 | `$width` 已 `intval()` 后再拼入 shell 命令 | ✅ 保留 |

以下行为属于**产品功能**，不应删除：

| 功能 | 位置 | 理由 |
|------|------|------|
| 页脚 IP + UA 显示 | `showFooter()` | 蜘蛛/爬虫跟踪 |
| `?debug=1` 诊断信息 | 开发辅助 | 改用 `PHPMAN_DEBUG=true` 环境变量控制，替代 `isLocalRequest()` IP 推断（#89） |

---

## 四、Review 流程

代码 review 时遵循以下顺序：

1. **先读本文档** — 理解产品定义和有意的设计决策
2. **查阅 `docs/PLAN.md`** — 了解版本路线图和架构方向
3. **查阅 `docs/PYDOC_RI_DESIGN.md`** — pydoc3/ri 格式解析与内容管道设计
4. **查阅 GitHub Issues** — 了解已知问题和修复优先级
5. **再审查代码** — 避免将产品设计误判为缺陷

---

## 五、修订记录

| 日期 | 修订内容 |
|------|----------|
| 2026-06-04 | `isLocalRequest()` 废弃：HSTS/版本隐藏改由 Nginx 配置，debug 改用 `PHPMAN_DEBUG` 环境变量；ob_gzhandler + checkRateLimit() 标记为待清理（#84 #89）；安全加固表增加状态列 |
| 2026-06-03 | 安全边界定义更新：限流/压缩/安全头定位为服务器层职责，PHP 层仅兜底；不生成根路径文件（robots.txt/sitemap/llms.txt）；关闭 #66 #72 #76 #77 |
| 2026-06-03 | v2.3 移动端 TOC 折叠：窄屏默认收起，标题行可点击展开/收起，修复 `$MOBILE_CSS` global 声明缺失 |
| 2026-06-03 | v2.3 搜索结果统一列表格式、ri index 列表化、footer git 版本号（`git describe`）、移除独立 `/tldr` 路由 |
| 2026-06-03 | v2.3 安全加固：速率限制（#69）、HSTS（#70）、nosniff 头（#63）、MCP 错误泛化（#71）、H1 面包屑（#65）、JSON-LD 修正（#64），关闭 #62 |
| 2026-06-03 | v2.3：Tokyo Night 暗色主题、info 模式 Setext 标题检测、CSS 全局统一、格式链接仅详情页显示、footer 优化（#55 #60 #61 #67 #73 #74 #75 关闭） |
| 2026-06-02 | v2.3：新增 pydoc3 / ri 模式，详见 `docs/PYDOC_RI_DESIGN.md` |
| 2026-06-01 | 初始版本：记录页脚 IP/UA 显示为有意设计（关闭 #27） |
