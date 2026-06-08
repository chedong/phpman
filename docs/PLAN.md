# phpMan 项目计划

## 版本号规范

- 格式: `v{MAJOR}.{MINOR}` → git tag `v2.1`, `v3.0`
- 补丁版本: `v3.6.1` — bugfix 或小改进，不新增功能
- MAJOR 递增: 架构变更或不向后兼容的 API 改动
- MINOR 递增: 新增功能，保持向后兼容
- Tag 规则: 每个 release 对应一个 annotated tag

```bash
git tag -a v3.6.1 -m "v3.6.1: single FTS5 query, pydoc/ri search"
git push origin v3.6.1
```

---

## 版本路线图

```
v2.1 (已发布)   →   v2.2 (已发布)   →   v2.3 (已发布)   →   v3.4–3.6 (已发布)     →   v3.7/v4.0
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
man/perldoc/info       TLDR 聚合          pydoc3/ri 模式     FTS5 搜索 + 缓存        代码拆分
MCP Server             官方 tldr-pages     结构化输出          单次查询三源聚合         搜索增强
Markdown 输出          零配置              搜索级联            大小写不敏感匹配         安全加固
JSON API               保持单文件          自动文档源检测       pydoc/ri FTS5 索引      国际化
TLDR 端点              去掉 LLM 密钥依赖                       SQLite 缓存架构         LLM 智能生成
跨平台宽度控制                                                  配置文件 phpman.config   离线数据

v3.6.1 = 当前生产 (FTS5 三源搜索) | v4.0 = 架构升级 + 搜索增强 + AI
```

---

## 已完成版本

### v3.4–3.6.1 (2026-06-05 → 2026-06-08)

- **FTS5 全文搜索**：离线索引 man/pydoc/ri 三源，单次查询覆盖全部，大小写不敏感
- **SQLite 缓存架构**：`cacheDb()` + `PageCache` + `search_fts` + `search_index_meta`
- **配置文件**：`phpman.config.php` 支持 CACHE_DIR / LLM / DEBUG
- **pydoc3 / ri 模式**：HTML/Markdown/JSON/MCP 四格式输出
- **搜索级联**：FTS5 优先 → 命令行 fallback (apropos / pydoc3 -k / ri)
- **deploy 系统**：`.deploy.mk` + Makefile (staging/production/rollback/cache-flush)

### v2.3 (已发布)

- pydoc3 / ri 模式
- 搜索级联 (apropos → pydoc3 → ri)
- ri RDoc 章节检测 (`=` / `==`)
- 自动文档源检测 (`::` → perldoc, `.` → pydoc, `#` → ri)

### v2.2 (已发布)

- TLDR 聚合：官方 tldr-pages (GitHub Raw) 优先 → cheat.sh 降级 → 规则提取兜底
- 全格式嵌入 (HTML/JSON/Markdown/MCP)
- 零配置，不需要 LLM 密钥

### v2.1 (已发布)

- man / perldoc / info 模式
- MCP Server (Streamable HTTP)
- Markdown / JSON / MCP 输出
- 跨平台宽度控制

---

## 待完成

### v3.7 — 搜索增强 + 性能优化

- **#94 Section 列表走 FTS5**：`?parameter=(1)` 目前仍走 `apropos -s 1 .`（30s+），应改为 FTS5 `SELECT ... WHERE section='1'` 查询
- **TLDR 持久缓存**：当前只有 `static $cache`（请求内复用），每次新请求都重新从 GitHub Raw / cheat.sh 拉取
- **#80 TLDR 404 洪灾**：不存在的命令触发外部请求导致 FPM 耗尽
- **#79 TLDR 熔断机制**：外部请求失败时拖慢所有请求

### v4.0 — 架构升级

- **代码拆分**：`src/Source/` + `src/Formatter/` + `src/Cache/` + `src/Config/`，保留单文件入口
- **LLM 智能生成**：AI 翻译、多语言摘要
- **离线数据**：tldr-pages 本地 clone + cron 更新
- **国际化**：LANG-based locale + AI fallback 翻译
