# phpMan Documentation

> **Scope** (as of 2026-07-14): phpMan is a **pure documentation server** —
> man/perldoc/info/pydoc/ri → raw HTML, Markdown, JSON, MCP output.
> **0 LLM calls** in phpMan's request path. All enhancement (emoji, OKF,
> etc.) lives in external projects. See [External Projects](#external-projects-references) below.

## Product
- **[01-PRODUCT.md](01-PRODUCT.md)** — 产品定义、设计决策、安全边界、pydoc3/ri 解析设计

## Design
- **[02-UI-DESIGN.md](02-UI-DESIGN.md)** — 设计系统 tokens & 规范（Tokyo Night 配色、字体、布局、组件）

## Systems
- **[03-CACHE.md](03-CACHE.md)** — SQLite 缓存 & 数据库 schema
- **[04-SEARCH.md](04-SEARCH.md)** — FTS5 全文搜索设计

## Planning
- **[05-PLAN.md](05-PLAN.md)** — 项目路线图 & 版本规划

## External Projects (References)
- **[06-ANALYTICS.md](06-ANALYTICS.md)** — `site-stats` 独立项目设计：站点流量统计 (GA4) 暴露为 MCP + HTTP。**不是 phpMan 功能** — phpMan 是其中一个消费者。

## What phpMan Does NOT Do (Moved Out 2026-07-14)

| Capability | Where it lives now |
|---|---|
| LLM emoji/OKF enhancement | `doc-enhance` project (external) |
| Site analytics (GA4 MCP tool) | `site-stats` project (external) |
| `cli/batch-enhance.php` | `doc-enhance` project (moved as-is) |
| `enhanceManPage()`, `callLLM()` | `doc-enhance` project (extracted) |
| `formatMarkdownToHTML()` | Removed (only existed for LLM `emoji_md` path) |

The `## External Projects` section in `05-PLAN.md` has the full design for both.

## Archive
- **[archive/](archive/)** — 历史研究笔记
- Git history (v4.0..v4.9) — historical LLM-enhancement design, preserved for reference
