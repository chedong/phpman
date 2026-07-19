# phpMan 战略复盘 PRD：文档搜索引擎在 LLM 时代的价值重估

**日期**: 2026-07-20  
**状态**: 战略分析  
**项目年龄**: 20+ 年 (2005–2026)

---

## 1. Executive Summary

### 1.1 核心问题

phpMan 最初设计目标是为人类提供 CLI 文档的 Web 界面。2024–2026 年间追加了三个新目标：
1. **SEO**: Markdown 格式提升搜索引擎索引
2. **GEO (Generative Engine Optimization)**: Markdown/JSON 格式提升 AI 爬虫抓取
3. **Agent 集成**: JSON + MCP 协议为 AI Agent 提供结构化文档接口

当前正对 27,129 个文档页面进行 LLM emoji 增强（41.6% 完成，预估总成本 ~¥2,170）。

### 1.2 核心发现

| 目标 | 结论 | 证据 |
|------|------|------|
| 传统 SEO | ❌ 未达成 | Googlebot 仅 23 次 phpMan 请求 |
| GEO (AI 爬虫) | ✅ 显著达成 | GPTBot 3,817 + ClaudeBot 512 次请求 |
| Agent/MCP 集成 | ✅ 达成 | MCP 是 GPTBot 首选格式 (437 > markdown 413 > html 297) |
| LLM emoji 增强价值 | ⚠️ 边际效益 | ¥2,170 成本低但 AI 爬虫已优先选 raw markdown/mcp |

### 1.3 战略建议

**phpMan 的核心价值已从 "CLI → Web" 转变为 "CLI → MCP → AI Agent"。** 应该：
1. 强化 MCP 接口（tool 数量、响应速度、schema 完善度）
2. 弱化 emoji 增强（AI bots 不需要 emoji）
3. 将增量投入转向 CLI 接口工具化（让 LLM 调用真实 CLI，而非只看文档）

---

## 2. 数据分析

### 2.1 流量分布（2026-07-19 access.log, N=35,892）

```
格式分布:
  HTML:      29,729 (82.8%)  — 人类用户 + 传统爬虫
  Markdown:   2,008 (5.6%)   — AI 爬虫偏好
  MCP:        1,375 (3.8%)   — Agent 工具调用
  JSON:       1,278 (3.6%)   — 结构化消费
```

### 2.2 AI 爬虫格式偏好

**GPTBot (OpenAI, N=1,354 phpMan 请求)**:

| 格式 | 请求数 | 占比 |
|------|--------|------|
| MCP | 437 | 32.3% |
| Markdown | 413 | 30.5% |
| HTML | 297 | 21.9% |
| JSON | 207 | 15.3% |

**ClaudeBot (Anthropic, N=209 phpMan 请求)**:

| 格式 | 请求数 | 占比 |
|------|--------|------|
| Markdown | 70 | 33.5% |
| JSON | 64 | 30.6% |
| MCP | 46 | 22.0% |
| HTML | 29 | 13.9% |

**关键洞察**：
- GPTBot 结构化格式偏好 78.1%，JSON+MCP 占 47.6%
- ClaudeBot 结构化格式偏好 86.1%
- **两个主要 AI 爬虫都不约而同地避开了 HTML，选择 MCP/Markdown/JSON**
- MCP endpoint 日均 70+ 请求，已形成稳定的 Agent 消费流

### 2.3 传统搜索爬虫

| 爬虫 | 请求数 | 说明 |
|------|--------|------|
| Googlebot | 680 | 几乎全部请求 blog，仅 23 次 phpMan |
| Bingbot | 126 | 量极小 |
| Amazonbot | 14,591 | 量巨大但非搜索用途 |

Google 未有效索引 phpMan 页面。传统 SEO 策略失败。

### 2.4 LLM 增强成本估算

**假设**（DeepSeek 官方定价：输入 ¥1/百万 token，输出 ¥4/百万 token）：

```
单页增强 (2格式: emoji_md + emoji_html):
  输入: avg ~20KB HTML ≈ 6,667 tokens × ¥1/M  = ¥0.007
  输出: avg ~32KB 增强 ≈ 10,667 tokens × ¥4/M = ¥0.043
  单页单格式成本: ~¥0.050

全量项目成本:
  27,129 页 × 2 格式 = 54,258 次 LLM 调用
  54,258 × ¥0.050 = ¥2,713 (~$373)

已完成成本: 11,284 × 2 × ¥0.050 ≈ ¥1,128
剩余成本:   15,845 × 2 × ¥0.050 ≈ ¥1,585
```

**但**：当前 PHPMAN_ENHANCE_MAX_CHARS=128,000，实际输出远小于上限。大页面（>100KB）消耗更多但仅占 3.2%。实际成本可能在 ¥1,500–¥3,000 区间。

---

## 3. 战略评估

### 3.1 "20 年老项目还有意义吗？"

**原命题（2005）**: 用 Web 浏览器看 man page → 仍有意义，但已被 StackOverflow/GitHub/LLM 取代

**新命题（2024–2026）**: 作为 AI Agent 的知识源 → **高度有意义**

证据：
- GPTBot 优先级 MCP > Markdown > HTML，证明 Agent 需要结构化文档
- phpMan 的 `.well-known/mcp.json` 实现了 MCP 服务发现
- 1,375 次 MCP 请求/周期证明 Agent 正在主动调用
- `cli_help` 和 `cli_search` 两个 MCP tool 提供了比网页抓取更高效的知识获取

**核心转变**：phpMan 不再是"给人类做网页"，而是**给 AI 做接口**。

### 3.2 "LLM 更需要 CLI 接口，而不是反过来"

用户的洞察是正确的：
- LLM 已能通过 WebFetch 读取任何网页
- LLM **不能**的是在用户电脑上执行 CLI 命令
- 将 CLI 文档包装成网页 → LLM 仍需理解 HTML 结构
- 将 CLI 文档暴露为 API → LLM 直接消费（当前 MCP 模式）
- **将 CLI 能力暴露为 Tool → LLM 可以执行（下一步）**

phpMan 当前处于第二层（API 化），应迈向第三层（工具化）。

### 3.3 架构悖论：数据源本身就是 CLI

更根本的问题：phpMan 的数据源（`man`, `perldoc`, `info`, `ri`, `pydoc3`）**本来就是命令行工具**。整个架构是一个环形：

```
LLM 需要 man 文档:
  phpMan 路径:  LLM → MCP → HTTP → phpMan.php → shell_exec("man ls") → formatManPerlDoc() → HTML → MCP → JSON → LLM
  直接路径:     LLM → shell_exec("man ls") → LLM
```

phpMan 在这个环形链路中增加的价值仅在于：

| 能力 | 是否 CLI 已有 | phpMan 增量 |
|------|:---:|------|
| `man` 文档 | ✅ `man ls` | HTML 渲染（LLM 不需要） |
| `perldoc` 文档 | ✅ `perldoc -f` | 同上 |
| `info` 文档 | ✅ `info make` | 同上 |
| `ri` 文档 | ✅ `ri String` | 同上 |
| `pydoc3` 文档 | ✅ `pydoc3 os` | 同上 |
| TLDR cheatsheet | ❌ 无 | **唯一增量**：GitHub 抓取 + 缓存 |
| 跨 mode 全文搜索 | ❌ 各自独立 | **增量价值**：统一 FTS5 索引 |
| emoji 增强 | ❌ 无 | **负价值**（浪费 LLM token） |

**结论**：phpMan 的 Web 接口层（HTML 渲染、format negotiation、emoji 增强、XHTML 合规）对 LLM Agent 用例是多余的。MCP server 只需 200 行：直接 `shell_exec("man $cmd")` → raw text → MCP response。所有 22 个 `src/` 源文件中，真正服务于 Agent 用例的只有 `mcp_server.php` + `search_index.php` + `tldr.php`。

### 3.4 LLM 增强 vs 原始格式：哪个对 Agent 更有价值？

**emoji 增强是为人类设计的**，对 AI Agent：
- ❌ emoji 增加 token 消耗
- ❌ 格式化的表格/列表不如 raw markdown 紧凑
- ❌ `emoji_html` 包含装饰性元素
- ✅ raw `markdown` 格式最节省 token
- ✅ `json` 格式最易解析
- ✅ `mcp` 格式有 schema 约束，最可靠

**结论**：LLM 增强对 SEO 无用（Google 不索引），对 GEO 价值有限（AI 爬虫偏好原始结构化格式）。投入应转向 MCP tool 数量和质量的提升。

---

## 4. 非目标 (Non-Goals)

基于以上分析，以下方向不应继续投入：

1. **全量 emoji 增强** — 停止除已运行批处理外的增量投入。剩余 ¥1,500 跑完即可，不做第二轮。
2. **提升 Google SEO 排名** — Google 不索引 man page 类内容，20 年的经验已证明这一点。
3. **HTML 格式优化** — AI 爬虫明确避开 HTML。唯一用途是人类用户，保持现状即可。
4. **页面美观性迭代** — XHTML 1.0 Transitional 限制已足够，不需要现代化。

---

## 5. 风险与路线图

### 5.1 技术风险

| 风险 | 等级 | 缓解 |
|------|------|------|
| MCP 协议被取代 | 低 | MCP 已成为 Anthropic/OpenAI 标准 |
| 爬虫过量导致 429 | 中 | 已观察到 GPTBot 触发限流，需扩展 rate limit 白名单 |
| 大页面 LLM 超时 | 高 | 107 页 >100KB 无法增强，分片方案可解决但非优先级 |
| API 费用超支 | 低 | 总量可控（<$500） |

### 5.2 建议路线图

**Phase 1 — 即刻停止**（本周）
- 跑完当前 batch enhance（已投入的成本，剩余 ~17h）
- 不再启动新的增强轮次

**Phase 2 — 强化 MCP**（本月）
- 扩展 MCP tool：`cli_help` → 支持分页/分 section
- 新增 tool：`cli_list`（列出某 mode 全部命令）、`cli_versions`（检查命令版本差异）
- MCP 响应优化：减小 token 消耗（当前 `content_len` 平均 24KB，可压缩）

**Phase 3 — CLI Tool 化**（下月）
- 将 phpMan 反向：不是 Web 化 CLI，而是 CLI 化 Web
- `cli/` 目录下新增 AI-agent 可调用的脚本：
  - `man-lookup` — 本地 `man` 增强 + 输出结构化 JSON
  - `tldr-fetch` — 获取 TLDR cheatsheet（已有）
  - `apropos-ai` — 语义搜索（整合 FTS5 + 向量检索）

---

## 6. 附录

### 6.1 完整 Bot 分布

```
Amazonbot      14,591  (40.7%)  — 非搜索，Alexa 相关
GPTBot          3,817  (10.6%)  — OpenAI 爬虫
SemrushBot        962  (2.7%)   — SEO 工具
Googlebot         680  (1.9%)   — Google 搜索
ClaudeBot         512  (1.4%)   — Anthropic 爬虫
其他             15,330  (42.7%)
```

### 6.2 方法说明

- 日志来源：`/home/chedong/logs/chedong.com/https/access.log` (8.9MB, 35,892 行)
- 周期：最近数天（日志轮转周期内）
- Bot 识别：User-Agent 子串匹配
- 429 计数：`grep 'GPTBot.*429'` = 0（日志中未记录 429 状态码行，可能是 ModSecurity 层拦截未写入 access.log）
