# 10 — Open Issues 解决方案设计

> 状态：设计（2026-11-03）
> 基线：`master` @ `v4.12.13-9-g32db740`
> 覆盖：9 个 open issue —— [#265](https://github.com/chedong/phpman/issues/265)、[#259](https://github.com/chedong/phpman/issues/259)、[#258](https://github.com/chedong/phpman/issues/258)、[#257](https://github.com/chedong/phpman/issues/257)、[#256](https://github.com/chedong/phpman/issues/256)、[#255](https://github.com/chedong/phpman/issues/255)、[#254](https://github.com/chedong/phpman/issues/254)、[#253](https://github.com/chedong/phpman/issues/253)、[#249](https://github.com/chedong/phpman/issues/249)
> 前置阅读：[09-QUALITY-GATES.md](09-QUALITY-GATES.md)（四层验证模型、G1–G9）、[08-RELEASE-VERSION-INTEGRITY.md](08-RELEASE-VERSION-INTEGRITY.md)（发布版本号）

## 0. 一页结论

9 个 issue 不是 9 件独立的事，而是**三条链 + 一件已完成**：

| 链 | issue | 目标 | 先做哪条 |
|---|---|---|---|
| **A. 可观测性与发布安全** | #253、#256 | 让"部署没生效 / 池饱和 / 500 分族"变成机器判定 | **最先**：没有判据，后面的改动无法验收 |
| **B. 渲染语义与资源占用** | #254 → #258 → #259（+ #249） | 把"空串/超时/不存在"类型化，再给请求与并发封顶 | 第二步：#254 是 #258/#259 的地基 |
| **C. 质量基础设施** | #255、#257 | 用真实语料与不变量抓住"输出形状"和"解析器"回归 | 可并行，但依赖 #253 的 CI 落点 |
| **已完成** | #265 | install.sh 参数解析 | 已在 PR #266 修复，只差验证与关闭 |

**一句话顺序**：先装仪表（#253/#256），再改语义（#254/#258），最后限流（#259）；质量门（#255/#257）全程并行。

## 1. 依赖图与阶段

```
P0  #265 验证+关闭        #253 verify-release + CI unit
                              │
P1  #256 metrics + SLO ───────┼──────────────┐
        │                     │              │
        │                     │              └─> #249 delivery-shortfall 指标
        └─> #259 gate 的 N、404 成本
                              │
P2  #254 RenderResult ──> #258 RequestBudget ──> #259 INDETERMINATE / 快速失败
                              │
P3  #255 golden corpus    #257 PHPStan + property/fuzz
```

| 阶段 | 内容 | 预计 | 退出标准 |
|---|---|---|---|
| **P0** | #265 验证关闭；#253 的 `verify-release.sh` + CI unit | 1–2 天 | 版本写错时验证器非 0；`make release` 末尾有逐项 PASS |
| **P1** | #256 最小可用（HTML 采样 + `metrics.php` + SLO）；修复 log-rotate cron | 2–3 天 | 能回答"p95 多少 / 5xx 哪族 / slot 几次" |
| **P2** | #254（pydoc/ri/man/search）+ #258 RequestBudget | 4–6 天 | 超时/预算耗尽不写最终负缓存；`/man/snice/1/json` ≤ 20s |
| **P3** | #259 并发闸 + 廉价 404；#255/#257 质量门 | 5–8 天 | 下一次尖峰：显式 503 + Retry-After，slot 消息为 0 |

## 2. 逐 issue 设计

### #265 — install.sh 参数解析（已完成，补验证）

**现状**：PR #266（merge `32db740`，提交 `5bf79de`）已把解析改成 `while [ $# -gt 0 ]` + 显式消费值：`--webroot=` / `--data-owner=` / 未知选项报错 / 端口范围校验 / webroot 必须绝对路径。issue 仍 open，只差验证。

**设计**

1. 在 `test/` 增加一个**只测解析、不跑安装**的入口：给 `install.sh` 加 `--check-args`（解析完参数立即 `exit 0`，在任何 `do_install` 之前），避免测试真的写 `~/.phpman`。
2. `test/unit/test_install_args.php` 用 `proc_open` 跑参数矩阵：

| 输入 | 期望 |
|---|---|
| `--webroot`（末尾无值） | 退出 1，含 `--webroot needs a path` |
| `--webroot=/srv/www` | 退出 0 |
| `--webroot /srv/www --no-start` | 退出 0 |
| `--cache-dir cache` | 退出 1，含 `must be an absolute path` |
| `--unknown` | 退出 1，含 `unknown option` |
| `abc` / `--data-owner` 后缺值 | 退出 1 |
| `PHPMAN_PORT=70000` | 退出 1，含 `1-65535` |

3. 验证后在 issue 里贴一次矩阵结果并关闭。

**验收**：矩阵全绿；`bash -n install.sh` 通过；`git grep -n 'for arg in'` 不再出现旧解析。

---

### #253 — 部署后自动验证 + CI

**现状**：`make verify` 只 `curl -o /dev/null -w %{http_code}`；`test/phpman-regression.sh` 有 8 类检查但手工跑；仓库无 `.github/workflows`。

**设计**

#### A. `deploy/verify-release.sh <expected-tag> [base-url]`

只读 HTTP，失败即非 0。依赖只有 `curl` + `php`（JSON 用 `php -r` 解析，不假设 `jq`）：

| 检查 | 请求 | 通过标准 |
|---|---|---|
| 版本（页脚） | `GET /phpMan.php/man/ls/1` | 页脚 `GIT_DESCRIBE` == 期望 tag（如 `v4.12.13`） |
| 版本（MCP） | `POST /mcp` `initialize` | `result.serverInfo.version` == `PHPMAN_VERSION`（无 `v`） |
| 静态资源版本 | `GET /phpMan.php/man/ls/1` + CSS/JS | `?v=` == `PHPMAN_VERSION` |
| 已知不存在 | `GET /phpMan.php/man/zzz_phpman_verify/1/markdown` | 404 且 body 含 "Not found" notice（#252） |
| 缓存命中 | 同一已知页面连打两次 | 第二次 < 1s（`curl -w %{time_total}`） |
| ETag 形态 | `HEAD` 一个 HTML 页面 | `ETag` 匹配 `^"[0-9a-f]{32}"$`（不校验 cacheAge，见下） |

**ETag 不做公式校验**：HTML ETag 含 `cacheAge`（`phpMan.php:238`），验证器无法在不知道 DB 状态的前提下复算；强行复算只会引入伪失败。第一版只校验形态 + 版本串 + 行为。

**日志分离**（可选 `--check-logs`，需要 SSH）：部署后触发一次 `/json`，断言 `phpman_error.log` **没有**新增 `mf ` 行、`phpman_mf.log` **有**新增行。默认关闭，避免验证器依赖 SSH。

输出格式：

```
[PASS] version footer      v4.12.13
[PASS] version MCP         4.12.13
[PASS] assets              ?v=4.12.13
[PASS] not-found page      404 + notice
[PASS] cache second hit    0.08s
[FAIL] ...                 expected ..., got ...
```

失败时打印 `make rollback STEP=1`，第一版**不自动回滚**（避免验证器自身故障导致生产回滚）。

#### B. Makefile 接线

```make
release: test test-unit _release-code _verify-release
	@$(MAKE) _release-tag
release-reindex: test test-unit _release-code _verify-release
	@$(MAKE) _release-tag
_verify-release:
	@deploy/verify-release.sh "$$(cat .release-version)" "$(DEMO_URL)"
```

放在 `_release-tag` **之前**：验证不过就不打 tag，避免把坏版本号推出去。

#### C. GitHub Actions

`.github/workflows/ci.yml`：

| job | 触发 | 内容 |
|---|---|---|
| `unit` | push/PR | PHP 8.1/8.2/8.3 矩阵：`make test` + `php test/run_all.php`；`fetch-depth: 0`（#250 的 git 测试要 tag/历史） |
| `shellcheck` | push/PR | `shellcheck cli/*.sh deploy/*.sh install.sh`（warn，不阻塞） |
| `staging-smoke` | 每日 | 对 `test.chedong.com` 跑 `test/phpman-regression.sh`，失败标红不阻塞 |

**验收**：把期望 tag 改错 → 非 0 且输出 actual/expected；一次真实 release 的尾部有逐项 PASS；master push 的 unit job 全绿。

**风险**：GitHub Runner 上 `pydoc3`/`man` 的版本与生产不同。`unit` job 只跑不依赖真实工具的测试；依赖工具的进 `staging-smoke`。

---

### #256 — mf 日志 → 指标 + SLO；缓存抽样复算

> **实现状态（2026-11-05，`658a706`）**：**A / B / C 已实现**，CI 在 PHP 8.1–8.3 全绿；
> **D（缓存抽样复算）未做**。B 里「5xx 分族复用 logcheck 的配对」这一条**没有照做**，
> 理由见 B 末尾。`.prom` 的落盘与消费见新增的 E。

**现状**：`phpman_mf.log` 只能 grep；HTML 完全不在遥测里（31-Oct 故障的 7,805 个 200 绝大多数是 HTML）；缓存没有一致性校验。

**设计**

#### A. 先补 HTML 采样（否则 p95 无意义）

在 `phpMan.php` 内容生成完成处（`phpManLogMachineRender()` 调用点）扩展：

- `PHPMAN_HTML_LOG_MS`（默认 500）：HTML 渲染 ≥ 阈值必记；
- `PHPMAN_HTML_SAMPLE`（默认 `0.01`）：未达阈值时按 URL 的稳定哈希采样 1%；
- `PHPMAN_MF_LOG_BYTES`（已有，默认 64KB）：HTML 同样适用（大 body 即使很快也记）。

mf 行增加 `fmt=html` 与 `sampled=1`。量级估算：28k HTML/天 × 1% ≈ 280 行/天，可接受。

#### B. `cli/metrics.php`

```
php cli/metrics.php --since=1h --out=~/.phpman/metrics/phpman.prom
php cli/metrics.php --since=24h --json          # 人读快照
php cli/metrics.php --check-slo                 # 违例非 0，给 cron/告警用
```

输入：`phpman_mf.log`、`phpman_error.log`、`access.log`、`server error.log`、缓存 DB。

输出（Prometheus textfile，原子写 tmp+rename）：

```
phpman_render_count{mode="man",format="json"} 123
phpman_render_duration_ms{mode="man",format="json",quantile="0.95"} 312
phpman_render_bytes{mode="man",format="markdown",quantile="0.95"} 88213
phpman_http_5xx_total{family="epipe"} 3
phpman_http_5xx_total{family="zero_byte"} 1
phpman_slot_exhausted_total 0
phpman_budget_exhausted_total{mode="man"} 0
phpman_cache_rows{mode="man",status="found"} 4211
phpman_cache_rows{mode="man",status="not_found"} 903
phpman_5xx_ratio 0.0023
```

**5xx 分族复用 #249 的配对逻辑**：把 `cli/logcheck.sh` 那段 awk 的核心抽成 `cli/lib/log_pair.awk`（或被 `metrics.php` 以 `proc_open` 调用），保证两边口径一致——不要写第二套实现。

> **实施时这条没做成，改成了「不做」。** `logcheck.sh` 是通过 `ssh host bash -s < logcheck.sh`
> 送到服务器上的 —— 脚本从 **stdin** 来，`$0` 不是路径，**无法 include 同目录的 awk 文件**。
> 抽成文件就意味着 logcheck 必须内嵌一份、metrics 再引用一份，还是两套。
> 所以 `metrics.php` 只出 SLO 需要的**总数**（`phpman_http_5xx_total` / `_zero_byte_` / `_ratio_`），
> 分族归属继续由 `make logcheck` 回答。两边口径的**一致性**靠 SLO 只依赖总数来保证。

#### C. SLO（写进本文件，`--check-slo` 执行）

| SLI | 目标 | 窗口 |
|---|---|---|
| 机器格式 p95 | < 3s | 1h |
| HTML p95 | < 2s | 1h（采样） |
| 5xx 比例 | < 1% | 1h |
| `can't apply process slot` | = 0 | 5m |
| 预算耗尽 | < 0.1% | 1h |
| 投递短差（`%b` < `bytes=`） | 观察值，不设阈值 | 1h |

#### D. 缓存抽样复算（第二期，**未做**）

`cli/cache-verify.php --sample=0.001`：

- `SELECT ... FROM cache WHERE renderer_version = ? ORDER BY RANDOM() LIMIT N`；
- 用**当前代码**重新渲染（CLI 下把 `PHPMAN_BASE_URL` 指到生产 URL，保证 `baseUrl()` 与 Web 一致）；
- 归一化：去掉 `generated` 时间戳、ETag、profiling 注释；
- 不一致 → 写 `cache_mismatch` 指标 + 报告文件（shard/name/format + 第一处 diff），**不自动删**；
- 只验机器格式（HTML 有随机 token/时间，先不做）。

**风险**：在共享主机上 `metrics.php` 读大日志会占 CPU。已限制 `--since` 与行数（默认只读最后 200k 行）。cron 跑增量这条**暂不做**，见 E。

#### E. `.prom` 的落盘与消费（**决定暂不加 cron**，2026-11-05）

**先不加定时任务。** 理由：

- 这台机器上已有「手工轮转」的先例（`phpman_error.log` 是站主特意定为手工的），再自动加一个每 5 分钟读 20 万行日志的任务，与那个决定方向相反；
- 指标的价值在**问问题时**，不在**没人看时**。P1 阶段的用途是回答「31-Oct 那类事故的 p95 是多少 / 5xx 是哪族」，那是人驱动的，不是告警驱动的；
- 真要做告警，应该先有基线，否则阈值只能拍脑袋。

**落盘**：`php cli/metrics.php --since=1h --out=$PHPMAN_HOME/metrics/phpman.prom`

原子写（先 `.tmp` 再 `rename`）：抓取方永远读不到半个文件；中断留下的 `.tmp` 下次覆盖，不累积。

**三种消费方式，都只读文件、不触发计算**

| 方式 | 命令 | 用途 |
|---|---|---|
| 快照 | `--since=1h --json` | 人看：分格式 p50/p95、5xx 比例、槽耗尽、缓存行数 |
| 落盘 | `--since=1h --out=…/phpman.prom` | 给 textfile collector 或任何抓取方 |
| 闸门 | `--check-slo` | 非 0 即违反；可接进发布前检查或手工跑 |

**没有 Prometheus 时**：`.prom` 是纯文本，`grep`/`awk` 就能读。缺的是**随时间变化的序列** —— 那要求有人按周期落盘。**在加 cron 之前，落盘是一次手工动作，两次之间没有序列**；这是「先不加 cron」的直接代价。

**将来若要加**：必须先改成「从上次 offset 读增量」，否则每次读 20 万行在共享主机上是纯浪费。当前实现每次读尾部 20 万行 —— 够手工用，不适合高频。

---

**验收（现状）**

| 项 | 状态 |
|---|---|
| 人为制造 3s 渲染 → p95 可见 | ✅ 断言层面已验（夹具 p50=500 / p95=950 精确） |
| 篡改一行缓存 → `cache-verify` 报出 | ⬜ 属 D，未做 |
| 连续 24h 的 `.prom` 有序列 | ⬜ 需要周期落盘，而按 E 暂不加 cron |

---

### #254 — RenderResult：found / not-found / indeterminate / fallback

**现状**：`string` 同时表示 found 正文、确认无匹配、临时失败、没缓存；`''` / `null` / `CACHE_SENTINEL_NOT_FOUND` / HTTP 404 四种表达混用。已经造成 #247（负行空正文）、#248（超时写 24h 负行）、#252（空页）、#254 自己（FTS 正文绕过守卫）四次同类事故。

**设计**

#### A. 值对象 `src/render_result.php`

```php
final class RenderResult {
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const INDETERMINATE = 'indeterminate';

    private function __construct(
        public readonly string $status,
        public readonly string $body,
        public readonly string $source,   // page | fallback | notice
        public readonly bool $retryable
    ) {}

    public static function found(string $body, string $source = 'page'): self;
    public static function notFound(string $body, string $source = 'fallback'): self;      // body 必须非空
    public static function indeterminate(string $body, string $source = 'fallback'): self; // 永不写缓存

    public function httpStatus(): int;      // 200 / 404 / 503
    public function cacheable(): bool;      // FOUND | NOT_FOUND
    public function cacheStatus(): ?string; // found | not_found
    public function ttl(): ?int;            // TIMEOUT / NOT_FOUND / FOUND
}
```

**不变量（构造函数/工厂里 assert）**

1. `NOT_FOUND` 的 body 非空；JSON/MCP 是合法空信封，不是 0 字节。
2. `INDETERMINATE` 永远 `cacheable() === false`；HTTP 503 + `Retry-After`，不是 404。
3. `NOT_FOUND` 只能由"命令成功返回、确认无结果"产生；`rc=124` / 非零退出 ≠ not found。
4. HTTP 状态只由 `httpStatus()` 映射，调用点不再各自 `http_response_code()`。

#### B. 迁移顺序（一次只动一组）

1. `cacheOrSearchFallback()` → 新增 `resolvePageWithFallback(): RenderResult`；旧函数保留为**适配器**（返回 `$r->body` 并回填旧 out-params），保证现有 751 条断言先绿。
2. `pydoc`、`ri`、`man` 三个 miss 分支改用新函数。
3. `search` 级联最后改（它现在把空串当"无该来源"信号）。
4. 输出边界（`phpMan.php` markdown/json/html 三个分支）统一读 `$result->httpStatus()`。

#### C. 缓存行加 `reason`（可选但推荐）

```sql
ALTER TABLE cache ADD COLUMN reason TEXT;  -- absent | timeout | budget | empty
```

SQLite 不支持 `ADD COLUMN IF NOT EXISTS`，用 `PRAGMA table_info(cache)` 判断后再加（与现有 `CREATE ... IF NOT EXISTS` 的自愈风格一致）。`getWithStatus()` 一并返回 `reason`，供 #256 区分"超时负行"与"真的不存在"。

**验收**：表驱动 9 种 (status × cached/uncached) 组合，断言 HTTP 码、是否写缓存、body 非空；注入超时后 DB 里**没有**最终负行（只有 600s 短 TTL 行）；现有断言全绿。

**风险**：返回类型改动面大。用适配器 + 分模式迁移，不一次性重写。

---

### #258 — RequestBudget：请求级 deadline（20s）

**现状**：上界是"每条命令"（全局 15s、`pydoc3 -k` 20s），不是"每个请求"。`/man` 未命中可串 `man -Tutf8` → BSD 回退 → `apropos`（大写还有小写重试），实测 `/man/snice/1/json` **41.46s**；mf 里 24 条 ≥20s。

**设计**

#### A. `src/budget.php`

```php
final class RequestBudget {
    public function __construct(int $limitMs, ?float $startMs = null);
    public static function current(): self;   // 单例；PHPMAN_REQUEST_TIMEOUT_MS 默认 20000，0=不限
    public function remainingMs(): int;       // max(0, deadline - now)
    public function exhausted(): bool;
    public function slice(int $requestedMs): int; // min(requested, remaining)
}
```

#### B. `phpManExec()` 集成（零调用点改动）

- 默认取 `RequestBudget::current()`；
- `$effectiveMs = min($timeoutMs ?? PHPMAN_CMD_TIMEOUT*1000, $budget->remainingMs())`；
- `$effectiveMs <= 0` → **不 spawn**：`$rc = 124; $lines = [];`，记一行 `budget exhausted: mode=… stage=… remaining=0ms`，返回 false；
- `timeout(1)` 只接受秒：`ceil($effectiveMs / 1000)`，最小 1；因此预算会有 ≤1s 的粒度误差，配置上留 1s 余量。
- 新增可选 `$stage` 参数（`man.primary` / `man.bsd` / `man.search` / `pydoc.page` / `pydoc.search` / `ri.page` / `perldoc.page` / `info.page` / `search.apropos`），用于日志与指标。

#### C. 调用点的短路

预算一旦耗尽，**不要进入下一步回退**：

```php
if (!phpManExec($cmd, $lines, $rc, null, 'man.primary') && RequestBudget::current()->exhausted()) {
    return RenderResult::indeterminate(...);   // #254
}
```

没有 #254 时，临时用 `null` 返回值 + `$indeterminate` 标记过渡；不建议长期保留。

#### D. 降级页

- HTTP 503 + `Retry-After: 30` + `Cache-Control: no-store`；
- body：HTML/markdown 一句"暂时无法完整生成，请稍后重试或使用搜索"；JSON 为合法信封 `{mode,parameter,status:"indeterminate",retryable:true}`；
- 不写最终负缓存；超时短 TTL 行（600s）保留，避免同名被反复打。

**验收**：`/man/snice/1/json` ≤ 20s；mf 里 `mode=man` 的 >20s 行归零；注入 `sleep` 的单测断言"只 spawn 到预算耗尽、不写最终负行、返回 503"。

---

### #259 — 31-Oct 503：并发闸 + 廉价 404

**现状**：31-Oct 20:00–20:59 PDT，17,455 请求里 3,491 个 503、1,223 个 500；`can't apply process slot` 1:1；UA 轮换的分布式爬虫；4,217 个 404 仍在 fork 子进程。**关键数据**：mf p50 = 16ms / p99 = 801ms —— 那一小时不是慢渲染。

**设计（先测再改）**

#### A. 先拿到两个未知数（#256）

1. **池槽数**：DreamHost 面板 / `phpinfo()` 的 `FcgidMaxProcesses` / `MaxProcessCount`；
2. **HTML 单次耗时**：靠 #256 的 HTML 采样；
3. **500 是否占槽**：把 `access.log` 的 500 时间分布与 `can't apply process slot` 对齐，验证"EPIPE 大响应把槽占住"的假设。

没有 1、2、3，`N` 只能拍脑袋。

#### B. 并发闸（`src/inflight.php`）

只在**冷渲染**（`cacheOrExecute()` 即将调用 `$generator()`）取一个槽；缓存命中不过闸。**一个请求最多一个 token**（静态 re-entrant），由 `register_shutdown_function` 释放。

```sql
CREATE TABLE IF NOT EXISTS inflight (
    token      TEXT PRIMARY KEY,
    started_at INTEGER NOT NULL,
    mode       TEXT
);
CREATE INDEX IF NOT EXISTS idx_inflight_started ON inflight(started_at);
```

获取（`BEGIN IMMEDIATE`，微秒级临界区）：

1. `DELETE FROM inflight WHERE started_at < now - 120`（回收崩溃请求）；
2. `SELECT COUNT(*)`；
3. `< PHPMAN_MAX_CONCURRENT_RENDERS` 才 `INSERT`；
4. `busy_timeout(250)`；`SQLITE_BUSY` → **fail open**（继续渲染）+ `gate_error` 指标（坏掉的闸不能把站点打死）。

`PHPMAN_MAX_CONCURRENT_RENDERS` 默认 `0`（关闭），等测到槽数后设为 `池槽数 - 2`（给缓存命中留余量）。**先观察、再启用**。

闸满时：立即 503 + `Retry-After: 2` + 明确 body（不是让 mod_fcgid 被动拒绝）。

#### C. 廉价 404（可能比闸更值钱）

31-Oct 的数据（p50=16ms）说明真正的成本很可能是 **4,217 个 404 × 每次 fork**，而不是慢渲染。做法：

1. `build-index.php` 产一张 `known_names(mode, name, section)`（来自 man-db/perldoc/pydoc/ri/info 的完整清单）；
2. 每个 mode 一个 `complete` 标志：**只有 complete=1 才允许用"不在表里"直接 404**；
3. pydoc 先做（`pydoc3 modules` 本身就是完整清单）；man/perldoc 先只做**测量**，不启用；
4. 上线前用 access.log 回放：对过去 7 天的 200 请求检查是否会被误判 404，误杀率必须为 0。

#### D. 不做的事

- **不靠 UA/IP 封禁**：UA 在轮换、IP 分散，`.htaccess` 规则基本无效；
- **不提高池容量**：共享主机侧改不了；
- **不为了省槽而缩短 210 天缓存 TTL**：那是设计决策，交给 #256 的数据。

**验收**：下一次尖峰：显式 503 + Retry-After；slot 消息 = 0；404 的 `man`/`perldoc` fork 数显著下降（metrics 可见）。

---

### #249 — 500 / Broken pipe：把客户端放弃变成一等信号

**现状**：工具已上线（`73e93ee` 分族、`e2c313b` 大响应 bytes 触发）；剩下的是**用**而不是建。

**设计**

1. **指标化**（并入 #256）：`phpman_delivery_shortfall_total{mode,format}` = 同一 URL/分钟内 `access %b < mf bytes=` 的次数；同时保留 `family="epipe"` / `family="zero_byte"` 分族。
2. **口径边界写进指标注释**：gzip 响应合法地更小；`bytes=` 不含输出前的 wrapper；只对 `mf` 行存在且 `bytes>0` 的请求计算。
3. **可选**（需 DH 支持）：请 DreamHost 在 vhost `LogFormat` 加 `%X`（连接结束状态）与 `%D`（耗时），这样零字节族不必靠推断。
4. **不把 500 改成 499**：Apache 记录的是真实结果；先修来源，别改信号语义。

**关闭条件**：`delivery_shortfall` 指标连续 7 天有数据，且两族的比例能被 #253 的 verify 报告引用；否则保持 open。

---

### #255 — golden corpus + 参考实现差分

**现状**：751 条断言验函数行为，但没有真实语料的逐字节基准；`phpman-regression.sh` 的 W3C 只测 `man/gzip/1`。

**设计**

#### A. 语料布局

```
test/corpus/
  meta.json                       # man-db/groff/perl/python/ruby 版本、locale、生成日期
  cases/
    man/ls/1/{raw.txt,html,json,md,sha256}
    man/gcc/1/...
    perldoc/File::Find/{...}
    info/coreutils/...
    pydoc/json/...
    ri/FTP/...
```

选页：每工具 3–5 页，覆盖短/长/宽字符/overstrike/ANSI/OSC-8/表格；总体积 < 2MB。

#### B. `cli/build-corpus.php`（在目标 OS 上跑）

- 记录 `meta.json`（版本 + locale + 生成命令）；
- 对每个 case 生成 4 种格式 + `sha256`；
- `--update` 覆盖，`--check` 只比较（CI 用）。

#### C. `test/corpus/run.php`（无需网络）

1. **格式纯度**：markdown 无 HTML 标签；JSON `json_decode` 成功；HTML `xmllint --noout --valid`（有 DTD 时）。
2. **golden 字节**：sha256 不等 → 打印 diff 的页面与第一处差异。
3. **参考实现差分**（可选 `--oracle`）：`groff -Thtml` / `pydoc3 -w` / `Pod::Html` / `ri` 的纯文本归一化后 diff。
4. **跨格式不变量**：同一页面的章节标题序列在 html/json/md 中一致；`see_also` 链接不丢失。
5. **与 `RENDERER_VERSION` 联动**：corpus 的 `meta.json` 记录生成时的 `RENDERER_VERSION`；如果代码 bump 了而 corpus 没更新，gate 直接失败并提示"要么解释输出变化，要么更新语料"。

**验收**：≥ 5 工具 × 3 页；故意去掉一个转义 → 至少 1 个 diff；`php test/corpus/run.php` 在无网络环境全绿。

**风险**：不同 OS 的工具版本会产生不同 raw 输出。语料在**生产同款 OS** 生成；CI 只跑纯格式断言，sha256 断言在 staging-smoke 跑。

---

### #257 — PHPStan L9 + 属性/模糊测试

**现状**：无 Composer / 无 CI / 无静态分析；解析器与转义只有样例断言。

**设计**

#### A. PHPStan（先测再定级）

- 用 **PHAR**（CI 下载并校验 sha256），不进运行时、不加 `composer.json`；
- `phpstan.neon.dist`：`level: 9`，paths = `phpMan.php, src/, cli/, test/`；
- **决策门**：先跑一次 L9 记录总数。> 2,000 条 → 退到 L7 + baseline；≤ 500 条 → L9 + baseline；
- `phpstan-baseline.neon` 入库；CI 用 `--error-format=github`，**只允许不新增**。

#### B. 属性测试（优先级高于 PHPStan）

`test/property/run.php`（自写最小 runner，无依赖）：

| 性质 | 生成器 |
|---|---|
| `h()` 输出可被 `html_entity_decode` 还原 | 任意字节串（含非法 UTF-8） |
| `phpManLogSafeField()` 永远单行、≤ 120 字符、无控制字符 | 含 `\n`/`\0`/超长串 |
| `normalizeParameter()` 幂等 | 随机路径片段 |
| `formatManPerlDocToMarkdown()` 不含 HTML 标签 | 随机 overstrike/ANSI 行 |
| JSON round-trip | 嵌套数组/Unicode/控制字符 |

每次 500 个种子，固定 seed 可复现；失败打印最小化输入。

#### C. 模糊测试（夜间）

- `test/fuzz/run.php`：基于 corpus 的字节变异（不需要 php-fuzzer）；
- 目标：`formatManPerlDoc`、`detectHeadingType`、`normalizeSection`、malformed segment 解析；
- CI nightly 30 分钟；崩溃/未捕获异常 = 非 0；失败用例最小化后存 `test/fuzz/regressions/`。

**验收**：PHPStan baseline 稳定不增长；≥ 5 组属性测试；30 分钟 fuzz 无 crash。

## 3. 共享契约

### 3.1 配置项（全部可 `defined()` 覆盖）

| 常量 | 默认 | 引入 |
|---|---|---|
| `PHPMAN_REQUEST_TIMEOUT_MS` | 20000 | #258 |
| `PHPMAN_MAX_CONCURRENT_RENDERS` | 0（关） | #259 |
| `PHPMAN_HTML_LOG_MS` | 500 | #256 |
| `PHPMAN_HTML_SAMPLE` | 0.01 | #256 |
| `PHPMAN_METRICS_DIR` | `~/.phpman/metrics` | #256 |
| `PHPMAN_CACHE_TTL_TIMEOUT` | 600 | 已有 |
| `PHPMAN_PYDOC_K_TIMEOUT` | 20 | 已有 |

### 3.2 日志行格式

```
mf mode=man name=snice sec=1 fmt=json elapsed=41462ms peak=2.0MB bytes=9236 sampled=0
budget exhausted: mode=man stage=man.search remaining=0ms command=apropos ...
gate shed: mode=man limit=8 inflight=8 wait=0ms
```

机器可解析、单行、字段有界（复用 `phpManLogSafeField()`）。

### 3.3 指标命名

统一 `phpman_` 前缀；5xx 分族 `family="epipe|zero_byte|error_page"`；不把 `name`（高基数）放进标签。

## 4. 落地切片（每个切片可独立 review/部署）

| 切片 | 内容 | 关闭 |
|---|---|---|
| **PR1** | #265 验证入口 + `deploy/verify-release.sh` + Makefile + CI unit | #265、#253 的 G3 |
| **PR2** | HTML 采样 + `cli/metrics.php` + SLO + log-rotate cron | #256 MVP |
| **PR3** | `RenderResult`（pydoc/ri/man/search）+ `reason` 列 | #254 |
| **PR4** | `RequestBudget` + exec 集成 + 503 降级页 | #258 |
| **PR5** | `inflight` 闸 + `known_names` 廉价 404 | #259 |
| **PR6** | corpus + PHPStan + 属性/fuzz | #255、#257 |

每个 PR 都必须：`make test` + `php test/run_all.php` 全绿；改渲染输出时 bump `RENDERER_VERSION`；不引入 Composer/运行时依赖。

## 5. 待确认的阻塞未知数

1. **FastCGI 池槽数**（DreamHost 面板 / `phpinfo()`）——决定 #259 的 `N`。
2. **access.log 是否有 `%D` / `%X`**——决定 #249 的定性精度。
3. **FTS `known_names` 的覆盖率**——决定廉价 404 能否用于 man/perldoc。
4. **500 是否占槽**——决定 #259 先做闸还是先做 #249 的大响应治理。

这四项都应在 P1 结束前有答案；没有答案就不进入 P3 的限流参数设定。

## 6. 非目标

- 不引入 Composer / 框架 / 运行时依赖；
- 不改 XHTML 1.0 Transitional 约束；
- 不为过门而改语义；
- 不缩短 210 天缓存 TTL（除非 #256 的数据支持）；
- 不对爬虫做 UA/IP 黑名单（轮换 + 分散，无效）。

---

## 7. 搜索全链路：把 pydoc / ri / perldoc 灌进索引，然后拆掉回退

> 起源：2026-11-05 查「命令行模式搜索慢」。分节列表那半已修（`17b3251`，见
> `isSectionListingQuery()`）；这一节是剩下的那半。
> 登记在 [#280](https://github.com/chedong/phpman/issues/280)。

### 7.1 现状与数据（生产，24h）

| mode | 渲染数 | >3s | >10s | 慢的比例 |
|---|---|---|---|---|
| search | 14 | 14 | 12 | **100%，p50 21.6s** |
| pydoc | 4,507 | 492 | **173** | 11% |
| perldoc | 15,184 | 323 | 24 | 2% |
| ri | 6,787 | 169 | 19 | 2% |
| man | 12,572 | 376 | 3 | 3% |
| info | 516 | 16 | 0 | 3% |

`search_fts` 的实际来源（`section` 列就是来源）：**只有 man**
（3pm 3468 / 1 2728 / 8 1156 / 3perl 598 / 5 490 / 7 394 / …），
**pydoc、ri、perldoc、info 各 0 行**。

所以 `case "search":` 的级联是：apropos 查 man → pydoc 的 FTS 空 →
`pydoc3 -k`（8–14s）→ ri 的 FTS 空 → `ri`（0.4–1.3s）。
**每个关键词 search 请求必然两次 shell，索引一次都没帮上忙。**

（分节列表 `(1)`..`(9)` 已单独修掉，那条与本节无关。）

### 7.2 目标

1. 关键词 search 不再 shell 出去 —— 由 FTS 回答；
2. 三条回退（`getPydocSearchPage` / `getRiSearchPage` / man 的 apropos）
   在**索引覆盖到的范围内**不再被触达；
3. 索引重建仍是一次离线动作（`make reindex`），不进请求路径。

### 7.3 非目标

- 不追求 pydoc 的真 docstring —— 索引里只有模块名 + 通用描述；
- 不改 `?mode=pydoc` 的**页面查找**（`getPydocPage`），那条本来就快；
- 不引入运行时依赖（不加 Python daemon、不建 socket、不读 `/proc`）。

### 7.4 索引要灌什么

| 来源 | 清单命令 | 条数 | 离线耗时 | 描述列 |
|---|---|---|---|---|
| **ri** | `ri -l` | 3,699 | 0.42s | 常量（"Ruby class"） |
| **perldoc** | 递归扫 `@INC` 的 `*.pm` → `Foo::Bar` | 5,357 | 0.98s | 常量（"Perl module"） |
| **pydoc** | `pkgutil.walk_packages(onerror=…)` | 6,197 | 6.17s | 常量（"Python 3 module"） |

合计 **约 7.6 秒**，一次性。写进 `search_fts`，`section` 列取 `ri` / `perldoc` /
`pydoc` —— `searchFtsBySource($q, $source, …)` 的 `WHERE s.section = :source`
本来就是这么用的，读侧不用改。

三处实现要点：

1. **pydoc 必须传 `onerror`。** 不传的话 `walk_packages()` 会在第一个 import
   失败的模块上抛异常并**静默截断** —— 生产上实测只走到 791 个就停了，而全量是
   6,197 个。
2. **不 import 模块取描述**，那正是 `pydoc3 -k` 慢的原因（它为了拿摘要把整个
   stdlib import 一遍）。名字来自目录遍历，描述用常量。
3. **`::` 已经有人处理了**：`expandNameForFts()` 会把 `File::Find` 展开成
   `File Find file find`，现有索引里 `Image::ExifTool::Shift` 就是这么存的。
   perldoc 的名字直接沿用同一条路径，不需要新逻辑。

### 7.5 回退怎么拆

**按来源逐个拆，用索引的覆盖情况当开关 —— 不硬编码。**

阶段 1：索引建好后，如果 `search_index_meta` / `search_fts` 里该来源的行数 > 0，
该来源的回退就不跑。用行数而不是写死常量，是为了「新装的机器索引还没建」时
仍然能回退，而不是搜索什么都搜不到。

阶段 2（可选）：

| 来源 | 回退 | 建议 |
|---|---|---|
| man | `apropos` | **保留** —— 0.03s，且能兜住索引重建之前新装的 man page |
| pydoc | `pydoc3 -k` | **删掉** —— 8–14s，命中率低 |
| ri | `ri` | **删掉** —— 同样贵，且索引覆盖 3,699 条已够 |
| perldoc | 无（本来就不 shell） | — |

**不推荐一次全删**：索引是离线的，删掉回退意味着「新装的 gem / pip 包在下次
reindex 之前完全搜不到」，而 reindex 是手工的。

### 7.6 验证

1. `make reindex` 后 `search_fts` 里 pydoc / ri / perldoc 各 > 0；
2. 用 exec 日志确认 `/search/<关键词>` **不再**出现 `pydoc3 -k` / `ri`
   （方法与分节列表那次相同：临时给 `phpManExec` 加一行日志）；
3. `cli/metrics.php` 里 `mode=search` 的 p95 从 21s 落到 < 1s；
4. 新装一个 gem / pip 包 → 搜不到 → `make reindex` 后搜得到（时效符合预期）。

### 7.7 风险

- **索引与真实工具链会漂移。** reindex 是手工的，新装的库在两次 reindex 之间
  不可搜索。这条要写进用户可见的文档，不能被当成 bug。
- **匹配语义变了。** `pydoc3 -k` 是「模块名 + 摘要」的**子串**匹配，FTS5 是
  前缀/布尔查询。搜类名（`DatabaseError`）两者都找不到 —— 索引里只有模块名，
  与 #252 已经记过的边界一致。
- **索引体积**：约 15,000 行新数据，相对现有 ~15,000 行翻倍；`search_fts` 是
  FTS5 外部内容表，实测增长可接受，但 `make reindex` 的耗时和 DB 大小要在实施时
  量一次。
