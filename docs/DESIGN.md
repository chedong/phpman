# phpMan 产品定义与设计文档

> 代码 review 前必读：本文档定义了 phpMan 的产品定位、核心设计决策和有意为之的行为。
> 避免将功能性设计误判为安全缺陷或技术债。

---

## 一、产品定位

phpMan 是一个单文件 PHP Web 应用，将 Unix `man`/`perldoc`/`info`/`apropos` 命令的输出以 HTML、Markdown、JSON、MCP 四种格式呈现，同时作为 MCP Server 供 AI Agent 调用。

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

---

## 三、安全边界定义

以下行为属于**安全缺陷**，需要修复：

| 类别 | 示例 | 处理方式 |
|------|------|----------|
| 注入漏洞 | CRLF 注入、命令注入、XSS | 立即修复 |
| 信息泄漏（非设计意图的） | MCP 错误暴露内部路径、异常详情 | 修复 |
| 缺少防御纵深 | 无安全响应头、无速率限制 | 渐进加固 |
| 权限过宽 | 缓存目录 0755、缓存无 TTL | 渐进加固 |

以下行为属于**产品功能**，不应删除：

| 功能 | 位置 | 理由 |
|------|------|------|
| 页脚 IP + UA 显示 | `showFooter()` | 蜘蛛/爬虫跟踪 |
| `?debug=1` 诊断信息 | 开发辅助 | 仅在 `isLocalRequest()` 时显示敏感细节 |

---

## 四、Review 流程

代码 review 时遵循以下顺序：

1. **先读本文档** — 理解产品定义和有意的设计决策
2. **查阅 `docs/PLAN.md`** — 了解版本路线图和架构方向
3. **查阅 GitHub Issues** — 了解已知问题和修复优先级
4. **再审查代码** — 避免将产品设计误判为缺陷

---

## 五、修订记录

| 日期 | 修订内容 |
|------|----------|
| 2026-06-01 | 初始版本：记录页脚 IP/UA 显示为有意设计（关闭 #27） |
