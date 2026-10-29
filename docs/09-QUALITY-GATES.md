# 09 — 质量门与外部验证

> 状态：设计（2026-10-30）
> 相关 issue：[#253](https://github.com/chedong/phpman/issues/253)（部署后验证 + CI）、[#254](https://github.com/chedong/phpman/issues/254)（结果语义）、[#255](https://github.com/chedong/phpman/issues/255)（golden corpus + 差分）、[#256](https://github.com/chedong/phpman/issues/256)（指标 + SLO）、[#257](https://github.com/chedong/phpman/issues/257)（静态分析 + fuzz）；背景：[#248](https://github.com/chedong/phpman/issues/248)、[#249](https://github.com/chedong/phpman/issues/249)

## 1. 为什么需要它

这个项目已经有 714 条断言、原子发布 + 三道 precheck、以及叙事非常详尽的 CHANGELOG。缺的不是努力，是**独立判据**：过去三周的误判都不是"没查"，而是"查了，但结论错"。

| 误判 | 事后证据 | 说明 |
|---|---|---|
| 2026-10-13 "部署没生效" | `current` 已切到 v4.12.1，站点仍跑 v4.11.2 20+ 分钟 | 靠人读 ETag 才发现 `opcache.revalidate_path=0` |
| deploy 注释里的 "realpath 300s" 理论 | `php -i` 一查即知 opcache 不重新解析路径 | 错误解释写进了脚本注释 |
| #249 的「慢渲染」前提 | 该族渲染 570/847/2205ms、queue=0ms | 串错了样本族，被复查纠正 |
| #251（mf 淹没错误日志） | `logcheck` 连续多天 "0 fatal"，实际 3,071 条 fatal | 工具的输出本身会撒谎 |

结论：**自证不够**。需要"不是我们写的判据"——参考实现、规范、第三方测量、以及会在退化时自动变红的门。

## 2. 四层验证模型

| 层 | 判据 | 当前状态 | 缺口 | 载体 |
|---|---|---|---|---|
| L0 语法/单测 | `php -l`、714 条断言 | 已有 | 无 | `make test` / `make test-unit` |
| L1 行为契约 | 结果状态不变量、格式纯度、缓存不变量 | 部分（零散断言） | 语义未类型化 | #254 |
| L2 参考实现差分 | groff/pydoc/perldoc/ri 的输出当 oracle | **无** | 无语料、无差分 | #255 |
| L3 外部标准/第三方 | W3C/WCAG/RFC/MCP spec、外部监控与扫描 | 很少（W3C 只测 1 个 URL，手工跑） | 无 CI、无外部扫描 | #253 + 本文 G4–G9 |

## 3. 原始材料（从哪开始）

原则：**从工具/协议的原始输出开始，不从摘要或文档开始。**

| 材料 | 为什么二手信息不够 | 采集方式 |
|---|---|---|
| 生产 OS 上各工具的原始输出 | 渲染 bug 在字节里（overstrike/ANSI/OSC-8/宽字符/groff 版本） | `man -Tutf8`、`groff -Thtml`、`perldoc -T`、`pydoc3 -w`、`ri`、`info --output`，连版本号一起存档 |
| HTTP 原始交换 | 0 字节/616 字节两族 500 只有帧层面说得清 | `curl --raw -v`、`nghttp -v`、`h2spec` |
| 服务器真实状态 | access log 是摘要，不是证据 | `php -i`、`apachectl -M`、mod_fcgid 配置、`/proc/<pid>`、`dmesg`（OOM）、`ss`/`lsof` |
| SQLite 真实字节 | `cache-stats` 是汇总 | `PRAGMA integrity_check`、`EXPLAIN QUERY PLAN`、`.dump`、`dbstat` |
| 上游实现与规范 | 要知道"可能输出什么"，不能从样例归纳 | man-db/groff、perldoc、pydoc、ri 源码与 changelog；RFC 9110/9309；JSON-RPC 2.0；MCP spec |
| 爬虫的第一手身份 | UA 只是声明 | `openai.com/gptbot.json`、Anthropic IP 列表、Google/Bing verified-bot 文档 + 自己的 FCrDNS 校验 |
| 真实调用方轨迹 | "agent 会怎么用"不能靠猜 | MCP 客户端 trace、`search` 查询日志、完整爬取序列 |

## 4. 方法与工具

| 领域 | 方法 | 工具 |
|---|---|---|
| 容量与排队 | USE + Little's law（并发 = 到达率 × 延迟）；按**突发**而非均值定池 | `vegeta` / `k6` / access-log 重放 |
| 剖析 | 分离"渲染"与"子进程/IO"成本 | `php-spx` / Xdebug、`strace -c`、`perf`、SQLite `.timer on` |
| 静态分析 | 类型与污点传播 | PHPStan L9、Psalm taint（#257） |
| 测试 | golden corpus、属性测试、模糊测试 | 自写 runner + `php-fuzzer`/AFL++（#255、#257） |
| 可观测 | 日志 → 时间序列 → SLO burn | `cli/metrics.php` → Prometheus textfile（#256） |
| 部署 | 不可变产物 + 原子切换 + **自动验证** | `deploy/atomic-release.sh` + `verify-release.sh`（#253） |
| 安全 | 威胁建模 + 外部扫描 | STRIDE、ZAP baseline、SSL Labs、securityheaders |
| 可访问性 | 自动化 + 真实屏幕阅读器抽查 | axe-core / pa11y / Lighthouse CI |
| 爬虫治理 | 身份验证 + 策略矩阵 | FCrDNS、RFC 9309 测试、官方 IP 列表 |

## 5. 质量门（G1–G9）

| 门 | 命令/判据 | 通过标准 | 失败动作 | 状态 |
|---|---|---|---|---|
| G1 单测 | `make test-unit` | 全绿 | block | 已有 |
| G2 发布前置 | `deploy/release-precheck.sh` | 三守卫全过 | block | 已有 |
| G3 部署后验证 | `deploy/verify-release.sh <tag>` | 版本/ETag/行为/缓存/日志分离全过 | block + 打印回滚命令 | 待建（#253） |
| G4 XHTML corpus | 语料 × 4 格式 | `xmllint --valid` 0 errors | block | 待建（#255） |
| G5 JSON 契约 | `jq` / JSON Schema | 全部可解析、字段齐全 | block | 待建（#253） |
| G6 MCP 一致性 | MCP Inspector | initialize/tools/list/tools/call 全过 | block | 待建（#253） |
| G7 前端质量 | Lighthouse + axe | 约定阈值 | warn → block | 待建 |
| G8 安全 | ZAP baseline / SSL Labs / headers | 0 high；A+；无缺失头 | warn | 待建 |
| G9 参考实现差分 | 归一化文本 diff | 0 diff（或已审阅的差异清单） | block | 待建（#255） |

## 6. 现状与差距

**已有**：714 条断言、原子发布 + 三道 precheck、`logcheck` 的分族/窗口计数、`RENDERER_VERSION` 失效机制、事故叙事详尽的 CHANGELOG、`phpman-regression.sh` 的 8 类检查。

**缺**：CI（0 个 workflow）、部署后自动验证（0）、真实语料（0）、指标时间序列（0）、静态分析（0）、外部安全/可访问性扫描（0）。

## 7. 路线图

1. **阶段 1（1–2 天）**：#253 的 G3 + CI `unit`；补 `log-rotate` 的 cron（#248 ④）。这一步直接覆盖"部署没生效/日志撒谎"。
2. **阶段 2（1 周）**：#255 先做 `man` + `perldoc` 各 3 页的 corpus 与差分；#254 先迁 `pydoc`/`ri` 两个 miss 分支。这一步覆盖"渲染形状变了没人发现"。
3. **阶段 3（持续）**：#256 指标 + SLO + 缓存抽样；#257 PHPStan L9 + fuzz；G6–G8 接入 CI。每阶段结束的标准是"门红了会拦"，不是"做完了"。

## 8. 非目标

- 不引入 Composer / 框架 / 运行时依赖；保持单文件部署与无 autoload。
- 不改 XHTML 1.0 Transitional 约束。
- 不为过门而改语义：门是发现器，不是目标。
- 不为了"让测试通过"缩短 210 天缓存 TTL——缓存策略是设计决策，应由数据驱动。

## 9. 参考标准与工具

- **规范**：W3C XHTML 1.0、WCAG 2.2 AA、RFC 9110（HTTP 语义）、RFC 9309（robots）、RFC 8259（JSON）、JSON-RPC 2.0、MCP spec、Keep a Changelog 1.1.0、SemVer 2.0。
- **工具**：PHPStan / Psalm、`php-spx`、`vegeta`/`k6`、`h2spec`/`nghttp`、MCP Inspector、axe-core/pa11y/Lighthouse CI、ZAP、SSL Labs、securityheaders.com。
