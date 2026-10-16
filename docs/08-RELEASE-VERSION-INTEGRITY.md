# 08 — 发布版本号完整性

> 状态：设计已定，待实施
> 日期：2026-10-16
> 相关 issue：[phpman#250](https://github.com/chedong/phpman/issues/250)

## 为什么要做

生产页脚显示 `v4.11.2-59-gf06411a`，比 10/13 已经发过的 `v4.12.2` **还低**，而代码其实是更新的提交。同一个串还进了 release 目录名、CSS/JS 的 `?v=`、ETag、MCP 的 `serverInfo.version`、User-Agent。staging 则停在 `v4.12.2`（代码更旧、版本号更高）—— 两边方向相反。

根因不是某次手滑，是**版本号从本地环境推导**：

```make
GIT_TAG     := $(shell git describe --tags --always --dirty 2>/dev/null || echo "local")
GIT_VERSION := $(shell (git describe --tags --abbrev=0 2>/dev/null || echo "v0.0.0") | sed 's/^v//')
```

`git describe` 读的是**运行 make 那台机器的 tag 集合**。同一个提交 `f06411a`，在 tag 齐全的机器上是 `v4.12.2-1-gf06411a`，在 tag 停在 v4.11.2 的机器上是 `v4.11.2-59-gf06411a`。两个串**都长得完全合法**，所以发布一路绿灯、退出码 0、日志干净 —— 错误只在事后看页脚时暴露。

`make tag` 有同一个毛病，而且方向更糟。它从本地 tag 推下一个版本号：

```make
latest=$$(git tag -l 'v*' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$$' | sort -V | tail -1 | sed 's/^v//')
next="$$major.$$minor.$$((patch + 1))"
git tag -a "v$(VERSION)" -m "v$(VERSION)"; git push origin master "v$(VERSION)"
```

在 tag 陈旧的机器上它算出 **v4.11.3**、打上、推到 origin —— 一个版本号倒退的 tag 被**永久写进仓库**。`make release` 只是把错的版本显示出去，`make tag` 是把它写下来。

一句话：**版本号是从本地环境推导出来的，不是跟着代码走的。**

## 目标

1. 版本号的权威来源是 **origin 的 tag** —— 任何一台机器上算出来都一样，本地 tag 陈不陈旧都不影响
2. 发布出去的版本号**不可能倒退**
3. 出问题时**拦住人**并说清原因，不静默、不自动修正
4. staging 不再假装自己是某个发布版本

## 非目标

- 不动 `deploy/atomic-release.sh` 的原子切换机制（它与版本号无关，`RELEASE_ID` 对它只是路径）
- 不动 `package` / `upload-release`（用的是另一个 `$(TAG)`，不在这条链上）
- 不动 rollback（按 release 目录时间走，与版本号无关）
- 不引入 CI/CD；发布仍然是从本地 checkout 手跑

## 反目标（做成了也不算成功）

- 让日常的 `make test` / `make logcheck` 多出网络往返
- 把「重新部署同一份代码」变成不可能
- 让 tag 指向的提交和实际部署的代码对不上

## 约束

- **`GIT_TAG` / `GIT_VERSION` / `RELEASE_ID` 现在是 parse-time `:=`** —— make 一启动就求值，连 `make logcheck` 都会跑那两条 `$(shell git describe)`。任何新加的 `git ls-remote` **不能**放在这里，否则每次 make 都多一次网络往返。
- 每个 recipe 行是**独立的 shell**，`export` 不跨行。版本号需要「算一次、多处用」。
- `PHPMAN_VERSION` 在代码里只当**不透明字符串**用（ETag 的 md5 输入、`?v=`、MCP `serverInfo.version`、User-Agent、页脚），全仓库没有 `version_compare` 或任何数值解析 —— 所以 `staging-<sha>` 这种非数值串是安全的。
- 仓库默认分支是 **`master`**（不是 main）。
- 生产与 staging 的部署路径不同（`.phpman` / `.phpman_test`），但版本注入点相同（`_release-code` / `_deploy-code` 里的两条 `sed -e`）。

## 已定的做法（站主决定）

| # | 决定 |
|---|---|
| 1 | `make tag` / `tag-minor` **删除**，tag 由 release 负责 |
| 2 | `make release` / `release-reindex` 从 **origin 的 tag** 算下一个版本 |
| 3 | 默认补丁 +1；`VERSION=4.13` / `VERSION=5.0` 显式覆盖 |
| 4 | 顺序：**先部署，成功后再打 tag + push** |
| 5 | HEAD 已被 tag 过 → **拒绝** |
| 6 | 发布前要求 **`origin/master` 是 HEAD 的祖先** |
| 7 | `make staging` 显示 **`staging-<sha>`**（工作树脏时为 `staging-<sha>-dirty`） |
| 8 | 一律**拦住报错**，不自动修正 |
| 9 | 工作树脏 → `make release` **拒绝** |
| 10 | `make release` **同时 push master** |
| 11 | 单元测试成为 `release` / `release-reindex` 的**前置** |

### 为什么「先部署后打 tag」

这条顺序本身值钱。如果先打 tag 再部署，部署中途失败（rsync 断、`atomic-release.sh` 报错）就会留下一个**已推送到 origin 的 tag**，而代码没上线 —— 重跑时撞上「已 tag 过」的守卫，人被卡住。

反过来，部署失败时 tag 根本还没建：重跑时 HEAD 未被 tag 过，从远端重算一遍版本号（远端 tag 没变，算出来还是同一个），再部署、再打 tag。**最常见的重试场景因此在「拒绝」方案下也不会卡住。**

### 为什么「拒绝」而不是「复用已有版本号」

「拒绝」的代价集中在**已经成功发布过之后再发一次**（回滚后回退、重发同一份代码）。这些是低频场景，而且真发生的时候，「这个提交已经作为 v4.12.3 发布过」本身就是有用的信息。

换来的是：**版本号和提交一一对应** —— 一个 tag 只指一个提交，也只发布一次。诊断和回滚时，页脚上的版本号能唯一确定一份代码。

## 站主之外的判断（实施者可以驳，但需要给出理由）

- **`VERSION=4.13` 归一化成 tag `v4.13.0`。** 扫描仍只认三段的 `^v[0-9]+\.[0-9]+\.[0-9]+$`，版本比较也在归一化后的值上做。这一条不是洁癖：如果 `VERSION=4.13` 直接产出 tag `v4.13`，**下一次默认发布就扫不到它**，会从 v4.12.2 算出 v4.12.3 —— 正是本文要消灭的那个静默倒退，而且守卫拦不住（它比较的是同一个扫描结果）。仓库里已有的两段 tag（`v2.0` … `v4.5`）与带后缀的（`v3.6.1-readme`）继续被忽略。
- **「脏」的判定范围 = 实际会被上传的路径。** 检查 `src/`、`phpMan.php`、`cli/`、`deploy/`、`phpman.config.php.example`、`phpman.css`、`phpman.js` 下有无**被跟踪文件的改动**或**未跟踪文件**。范围刻意收在这些路径上：仓库根目录放个草稿笔记不该拦住发布，而 `src/` 里一个没 `git add` 的新文件**会**被 `rsync -avz src/` 原样传上去，必须拦。staging 用同一套判定。
- **显式 `VERSION=` 给了比远端最新更小的号 → 也拒绝。** 与「不可能倒退」这条目标一致；`VERSION=4.13` 的用途是跨 minor/major，不是往回走。
- **部署成功但 push 失败 → 大声报错、非 0 退出。** 此时代码已上线、master 与 tag 都没上 origin。重跑时 HEAD 未被 tag、`origin/master` 仍是 HEAD 的祖先、算出的版本号仍大于远端最新 —— 四条守卫全过，天然自愈，不需要额外机制。
- **`<sha>` 用短形式（7 位）**，例如 `staging-f06411a`、`staging-f06411a-dirty`。
- **staging 的 `GIT_DESCRIBE` 与 `PHPMAN_VERSION` 置成同一个值。** 两个常量在代码里都只是不透明字符串，置成同一个最不容易误导。

## 留给实施者的

### 1. 版本号怎么「算一次、多处用」

`_release-code` 里版本号出现在至少四处：`echo "=== Deploying ... ==="`、两条 `sed -e`（`GIT_DESCRIBE` / `PHPMAN_VERSION`）、`RELEASE_ID`（目录名）。而 recipe 每行一个 shell。

同时**不能**把 `git ls-remote` 放进 parse-time 的 `:=`。

可行的路子（实施者选一条，或想更好的）：

- 用一个 helper（如 `deploy/next-version.sh`）在 recipe 首行算出结果写入临时文件，后续行 `$$(cat ...)` 读取；
- 或者用 `$(filter release release-reindex,$(MAKECMDGOALS))` 把版本计算限制成「只在发布时求值」的 parse-time 变量；
- 或者把「算版本 + 部署 + 打 tag + push」整体收进一个 `deploy/release.sh`。

判据是：**`make test` / `make logcheck` 等目标不能因此多出网络调用**，且版本号在一次发布内处处一致。

### 2. 四个守卫的具体写法

| 守卫 | 意图 |
|---|---|
| `origin/master` 是 HEAD 的祖先 | 挡住「从落后的 checkout 发布」—— 那会部署旧代码、再把旧 HEAD 打成新版本号推走 |
| HEAD 未被 tag 过 | 挡住重复发布同一提交 |
| 新版本号 > 远端最新 | 挡住显式 `VERSION=` 往回走 |
| 部署路径上工作树干净 | 挡住「部署的字节 ≠ tag 指向的提交」 |

注意 `origin/master` 是**本地跟踪引用**，可能本身是陈旧的；祖先检查要先 `git fetch` 才有意义。同样，「已被 tag 过」的判断要考虑 tag 在远端还是只在本地。这两处的取舍（多一次 fetch 换准确性）留给实施者，但**守卫的语义不能因此变弱**。

守卫 ① 不是可选的，而且它防的是一个**由本设计新引入**的失败模式。旧方案里版本号来自本地 `describe`：本地落后时，算出来的是那个旧提交自己的 tag，自洽 —— 只是发了个旧版本，不会说谎。把版本号改成以 origin 为准之后，同样的落后状态会变成：算出**新**版本号（origin 最新 + 1）、部署**旧**代码、把旧 HEAD 打成新版本号推走。

这不是假想。2026-10-16 写这份文档时就正好撞上：本地 `master` 停在 `698a4a8`，`origin/master` 已经是 `f06411a`，本地落后一个提交 —— 一个普通的 `git fetch` 没做而已。

### 3. tag / push 的落点

`release` 与 `release-reindex` 共享 `_release-code` 作为前置，但 `release-reindex` 的 index 重建与 sitemap 生成跑在它**之后**。tag 必须在**整个目标的所有步骤**都成功之后才推，所以它**不能**放在共享前置里 —— 要么在每个目标自己的 recipe 末尾各写一遍，要么抽一个两者都作为**最后一步**调用的收尾目标。

（`_release-code` 最后那行 `$(MAKE) logcheck` 不构成问题：它永远退 0。但它的位置说明「`_release-code` 的最后一行」并不等于「这次发布的最后一步」。）

### 4. 失败恢复

需要明确：部署成功但 push 失败时，脚本的输出和退出码是什么；重跑时四条守卫的判定结果是否与预期一致（HEAD 未 tag → 通过；`origin/master` 是祖先 → 通过；版本号仍大于远端最新 → 通过；工作树干净 → 通过）。这一条要有测试。

### 5. 测试

`make test` 目前只跑 `php -l` 与 `sh -n`；仓库里所有行为测试在 `test/unit/*.php`，由 `test/run_all.php` 汇总，而 **`make test` 从不调用它**，Makefile 里也**没有 `test-unit` 目标**。所以要先新增一个（`php test/run_all.php`），再挂到 `release` / `release-reindex` 的前置上。

新测试至少要覆盖：

- **不联网**的版本号计算：给定 tag 列表 + 当前 HEAD，算出正确的下一个版本号（含 `VERSION=` 覆盖、含两段→三段的归一化、含拒绝倒退）
- 四条守卫的负例：落后的 checkout 被拒、已 tag 的 HEAD 被拒、倒退的 `VERSION=` 被拒、脏树被拒

版本 helper 要能**注入 tag 列表**（而不是自己去 `ls-remote`），否则没法离线测。

### 6. 文档同步

- `README.md:822` 附近的手工 `git push origin master` 步骤要改（release 现在自己推）
- `README.md:569` 的「`PHPMAN_VERSION` written by `make tag`」要改
- `docs/05-PLAN.md` 里若有发布流程描述
- `CHANGELOG.md` 记一条
- `AGENTS.md` / `CLAUDE.md` 若有发布步骤，同步

## 验收

- 在一个 `origin/master` 落后的 checkout 上跑 `make release` → **失败**并说明原因，而不是发出去
- 在一个已被 tag 过的 HEAD 上跑 `make release` → **失败**并指出已有版本号
- 在工作树脏（部署路径上有改动或未跟踪文件）时跑 `make release` → **失败**
- 缺 tag 的机器上跑 `make release` → 版本号与 tag 齐全的机器**算出同一个**
- `make release VERSION=4.13` → 产出 tag **`v4.13.0`**，且下一次默认发布算出 `v4.13.1`（不是 `v4.12.3`）
- 正常发布后：页脚、`PHPMAN_VERSION`、release 目录名三者一致，且版本号 ≥ 上一次发布；master 与 tag 都已到 origin
- `make staging` 后：页脚显示 `staging-<sha>`；工作树脏时显示 `staging-<sha>-dirty`
- `make test` / `make logcheck` **没有**新增网络调用
- 单元测试跑不过时 `make release` **不发车**
- 部署成功但 push 失败时，重跑 `make release` 能补上，且不产生第二个版本号
