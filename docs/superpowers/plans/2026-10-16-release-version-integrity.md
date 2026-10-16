# 发布版本号完整性 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让 phpman 的发布版本号以 origin 的 tag 为唯一权威来源，使任何机器上算出的版本号一致、且不可能倒退；把打 tag 从独立的 `make tag` 并入 `make release`。

**Architecture:** 版本计算与守卫从 Makefile 的 parse-time `$(shell)` 里搬出来，落进两个可离线测试的 shell 脚本（`deploy/next-version.sh` 算版本、`deploy/release-precheck.sh` 跑守卫）。Makefile 只负责编排：守卫 → 算版本（写进 `.release-version`）→ 部署 → 全部成功后打 tag 并 push。

**Tech Stack:** GNU Make、POSIX sh（`#!/bin/sh`，与 `deploy/` 下既有脚本一致）、PHP 8 的极简测试框架（`test/test_helper.php` + `test/run_all.php`，无 PHPUnit）。

**Spec:** `docs/08-RELEASE-VERSION-INTEGRITY.md`

## Global Constraints

- 版本号一律**三段** `X.Y.Z`；扫描远端 tag 的正则固定为 `^v?[0-9]+\.[0-9]+\.[0-9]+$`（两段 tag `v2.0`/`v4.5` 与带后缀的 `v3.6.1-readme` 一律忽略）
- 新增脚本一律 `#!/bin/sh` + `set -e`，与 `deploy/atomic-release.sh`、`deploy/rollback.sh` 一致
- **`make test` / `make logcheck` / `make verify` 等非发布目标不得新增任何网络调用**
- 部署路径（「脏」的判定范围）= `src phpMan.php cli deploy phpman.config.php.example phpman.css phpman.js`
- 默认分支是 `master`（不是 main）
- 提交署名：`Co-Authored-By: Claude code <版本> with <模型名> <noreply@<域名>>`，三个值动态读取（见 CLAUDE.md）
- 所有新增测试必须登记进 `test/run_all.php` 的 `$suites["Unit"]` 数组，否则不会被跑到

## Review Focus

以下 5 类输入/失败模式是 spec 暗示、但没有哪个任务的测试天然覆盖的，最可能咬到人。每条都在其所属任务里配了测试：

1. **`git ls-remote` 失败（断网 / 认证失效）** —— 合理预期是**中止**，绝不能把「取不到 tag」当成「没有 tag」而算出 `0.0.1` 发出去
2. **远端一个三段 tag 都没有**（全新 fork、或 tag 被误删）—— 此时 `0.0.1` 是正确结果还是应该报错？本计划取「`0.0.1` 并打一行警告」，因为首次发布确实是这个状态
3. **从 worktree 或 detached HEAD 发布** —— `.release-version` 的路径解析、`git rev-parse --short HEAD` 的行为都要有确定结果
4. **`VERSION=` 恰好等于远端最新**（不是严格大于）—— 必须拒绝；`>=` 会让同一个版本号被发两次
5. **中断的发布留下 `.release-version`** —— 下一次的 `_release-tag` 读到旧值会把 tag 打到错误的版本号上；必须由本次发布重新写入，不能复用残留文件

---

### Task 1: `deploy/next-version.sh` — 从 tag 列表算下一个版本号

**Files:**
- Create: `deploy/next-version.sh`
- Create: `test/unit/test_next_version.php`
- Modify: `test/run_all.php`（在 `$suites["Unit"]` 数组里加一行）
- Modify: `Makefile`（新增 `test-unit` 目标，加进 `.PHONY`）

**Interfaces:**
- Consumes: 无
- Produces: `deploy/next-version.sh [EXPLICIT_VERSION]`，从 **stdin** 逐行读 tag 名（接受 `refs/tags/v4.12.2` 或 `v4.12.2` 或 `4.12.2` 三种写法，`^{}` 行自然被正则滤掉），把**三段数字版本号**（如 `4.12.3`，**不带 `v`**）打到 stdout。失败时往 stderr 写一行原因并以非 0 退出。Task 4 用 `$(shell git ls-remote --tags origin | sed 's#.*refs/tags/##' | deploy/next-version.sh $(VERSION))` 调用它。

- [ ] **Step 1: Write the failing test**

`test/unit/test_next_version.php`，按仓库既有样式（`declare(strict_types=1)`、`require_once __DIR__ . '/../test_helper.php'`、结尾 `exit(test_summary())`）。加一个跑脚本的辅助函数：

```php
function next_version(string $tags, string $explicit = ''): array {
    $script = escapeshellarg(__DIR__ . '/../../deploy/next-version.sh');
    $arg = $explicit === '' ? '' : ' ' . escapeshellarg($explicit);
    $cmd = "printf '%s' " . escapeshellarg($tags) . " | $script$arg 2>&1";
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    return [$rc, trim(implode("\n", $out))];
}
```

断言（`$tags` 是 `\n` 分隔的完整 `git ls-remote --tags` 风格输出）：

```php
// 正常补丁 +1
[$rc, $out] = next_version("refs/tags/v4.11.2\nrefs/tags/v4.12.0\nrefs/tags/v4.12.2\n");
assert_equals(0, $rc, "tag 列表正常时退出 0");
assert_equals("4.12.3", $out, "取最高 tag v4.12.2 → 补丁 +1 = 4.12.3");

// annotated tag 的 ^{} 行被忽略
[$rc, $out] = next_version("refs/tags/v4.12.2\nrefs/tags/v4.12.2^{}\n");
assert_equals("4.12.3", $out, "^{} 行不影响结果");

// 两段 tag 与带后缀的 tag 被忽略（spec: 只认三段）
[$rc, $out] = next_version("refs/tags/v2.0\nrefs/tags/v4.5\nrefs/tags/v3.6.1-readme\nrefs/tags/phpMan_2\nrefs/tags/time\nrefs/tags/v4.12.2\n");
assert_equals("4.12.3", $out, "非三段 tag 一律忽略");

// 一个三段 tag 都没有 → 0.0.1（Review Focus 2）
[$rc, $out] = next_version("refs/tags/v2.0\nrefs/tags/time\n");
assert_equals(0, $rc, "无三段 tag 时退出 0");
assert_equals("0.0.1", $out, "无三段 tag → 0.0.1");

// 显式 VERSION 归一化成三段（spec: VERSION=4.13 → v4.13.0）
[$rc, $out] = next_version("refs/tags/v4.12.2\n", "4.13");
assert_equals("4.13.0", $out, "VERSION=4.13 → 4.13.0");

[$rc, $out] = next_version("refs/tags/v4.12.2\n", "5.0");
assert_equals("5.0.0", $out, "VERSION=5.0 → 5.0.0");

[$rc, $out] = next_version("refs/tags/v4.12.2\n", "v4.13.1");
assert_equals("4.13.1", $out, "VERSION 带 v 前缀也能归一化");

// 显式 VERSION 必须严格大于远端最新（Review Focus 4）
[$rc, $out] = next_version("refs/tags/v4.12.2\n", "4.12.2");
assert_not_equals(0, $rc, "VERSION 等于远端最新 → 拒绝");
assert_contains("4.12.2", $out, "拒绝时说明冲突的版本号");

[$rc, $out] = next_version("refs/tags/v4.12.2\n", "4.11");
assert_not_equals(0, $rc, "VERSION 小于远端最新 → 拒绝");

// 非法 VERSION
[$rc, $out] = next_version("refs/tags/v4.12.2\n", "abc");
assert_not_equals(0, $rc, "非数字 VERSION → 拒绝");
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php test/unit/test_next_version.php`
Expected: FAIL —— 脚本不存在，所有 `$rc` 非 0

- [ ] **Step 3: Implement `deploy/next-version.sh`**

签名与行为已在 Interfaces 里钉死。实现要点：

- 用 `sed` 剥掉 `refs/tags/` 前缀和行首 `v`，再用 `grep -E '^[0-9]+\.[0-9]+\.[0-9]+$'` 过滤
- 最高版本用 `sort -V | tail -1`（与仓库既有的 `make tag` 同一手法；BSD/GNU `sort` 都支持 `-V`）
- 列表为空时 `LATEST=0.0.0`，并往 **stderr** 打一行警告（不是 stdout —— stdout 只能是版本号）
- 显式 `VERSION`：剥 `v` → 按 `.` 拆成至多 3 段、不足补 `.0` → 校验每段是纯数字 → 用 `sort -V` 与 `LATEST` 比较，**必须严格大于**，否则 stderr 写原因并 `exit 1`
- 脚本头部写一段注释说明**为什么权威来源必须是远端**（本地 tag 集合因机器而异，同一个提交在不同机器上 `git describe` 给出不同答案）

- [ ] **Step 4: Run test to verify it passes**

Run: `php test/unit/test_next_version.php`
Expected: PASS，`0 failed`

- [ ] **Step 5: Register the test and add `test-unit`**

`test/run_all.php` 的 `$suites["Unit"]` 数组里加 `"{$testDir}/unit/test_next_version.php"`（放在 `test_mf_render_log.php` 之后）。

`Makefile`：`.PHONY` 行加 `test-unit`；新增目标

```make
test-unit:
	php test/run_all.php
```

- [ ] **Step 6: Verify the suite runs it**

Run: `php test/run_all.php`
Expected: 输出里出现 `✅ test_next_version.php: N passed`，总数里含它

- [ ] **Step 7: Commit**

```bash
git add deploy/next-version.sh test/unit/test_next_version.php test/run_all.php Makefile
git commit -m "feat(deploy): next-version.sh — 版本号以远端 tag 为权威来源 (#250)"
```

---

### Task 2: `deploy/release-precheck.sh` — 发布守卫

**Files:**
- Create: `deploy/release-precheck.sh`
- Create: `test/unit/test_release_precheck.php`
- Modify: `test/run_all.php`

**Interfaces:**
- Consumes: 无（Task 1 的 `next-version.sh` 独立于本脚本）
- Produces: `deploy/release-precheck.sh [PATH...]`，在**当前 git 仓库**里跑三条守卫，全过则退出 0、无输出；任一不过则往 stderr 写一行原因并退出 1。`PATH...` 是「脏」的判定范围；不传时默认 `src phpMan.php cli deploy phpman.config.php.example phpman.css phpman.js`。Task 4 用 `deploy/release-precheck.sh $(DEPLOY_PATHS)` 调用。

三条守卫（按此顺序检查，第一个失败就退出）：

1. `origin/master` 是 HEAD 的祖先 —— 先 `git fetch origin master`（否则 `origin/master` 这个本地跟踪引用本身可能是陈旧的，守卫形同虚设），再 `git merge-base --is-ancestor origin/master HEAD`
2. HEAD 未被 origin 上的任何 tag 指着 —— 用 `git ls-remote --tags origin` 取远端 tag，把 `^{}` 行（annotated tag 解引用后的提交 sha）与 `git rev-parse HEAD` 比对。**只查远端，不查本地** —— 见下面 Step 3 的说明
3. 工作树在给定路径上干净 —— `git status --porcelain -- $PATHS` 必须为空（被跟踪文件的改动与未跟踪文件都算）

- [ ] **Step 1: Write the failing test**

`test/unit/test_release_precheck.php`。需要一个造临时 git 仓库的辅助函数：

```php
/** 造一个临时仓库 + 一个 bare 的 origin，返回 [repoDir, cleanup] */
function make_repo(): array {
    $base = sys_get_temp_dir() . '/precheck-' . bin2hex(random_bytes(6));
    $repo = "$base/repo";
    $origin = "$base/origin.git";
    mkdir($repo, 0777, true);
    $run = function (string $cwd, string $cmd) {
        $out = []; $rc = 0;
        exec("cd " . escapeshellarg($cwd) . " && $cmd 2>&1", $out, $rc);
        return [$rc, trim(implode("\n", $out))];
    };
    $run($repo, "git init -q -b master && git config user.email t@t && git config user.name t");
    $run($repo, "echo a > f.txt && git add f.txt && git commit -qm init");
    $run($base, "git init -q --bare origin.git");
    $run($repo, "git remote add origin " . escapeshellarg($origin) . " && git push -q origin master");
    return [$repo, fn() => exec("rm -rf " . escapeshellarg($base))];
}
```

（`precheck()` 辅助函数与 Task 1 的 `next_version()` 同构：`cd` 进 `$repo` 跑脚本，返回 `[rc, output]`。）

断言：

```php
// 守卫 1：本地落后 origin/master
[$repo, $cleanup] = make_repo();
exec("cd " . escapeshellarg($repo) . " && echo b >> f.txt && git commit -qam second && git push -q origin master");
exec("cd " . escapeshellarg($repo) . " && git reset -q --hard HEAD~1");   // 本地退回，origin 仍在前
[$rc, $out] = precheck($repo);
assert_not_equals(0, $rc, "本地落后 origin/master → 拒绝");
assert_contains("origin/master", $out, "拒绝时点名 origin/master");
$cleanup();

// 守卫 1 的正例：本地领先（有未推送提交）应通过
[$repo, $cleanup] = make_repo();
exec("cd " . escapeshellarg($repo) . " && echo b >> f.txt && git commit -qam second");
[$rc, $out] = precheck($repo);
assert_equals(0, $rc, "本地领先（未推送）→ 通过");
$cleanup();

// 守卫 2：HEAD 已被 origin 上的 tag 指着
[$repo, $cleanup] = make_repo();
exec("cd " . escapeshellarg($repo) . " && git tag -a v9.9.9 -m v9.9.9 && git push -q origin v9.9.9");
[$rc, $out] = precheck($repo);
assert_not_equals(0, $rc, "HEAD 已发布过 → 拒绝");
assert_contains("v9.9.9", $out, "拒绝时点名已有的 tag");
$cleanup();

// 守卫 3：部署路径上有未跟踪文件
[$repo, $cleanup] = make_repo();
exec("cd " . escapeshellarg($repo) . " && mkdir -p src && echo x > src/new.php");
[$rc, $out] = precheck($repo);
assert_not_equals(0, $rc, "src/ 下有未跟踪文件 → 拒绝");
assert_contains("src/new.php", $out, "拒绝时点名具体文件");
$cleanup();

// 守卫 3 的边界：部署路径**之外**的脏文件不拦
[$repo, $cleanup] = make_repo();
exec("cd " . escapeshellarg($repo) . " && echo x > scratch-note.txt");
[$rc, $out] = precheck($repo, "src phpMan.php");
assert_equals(0, $rc, "部署路径之外的脏文件不拦");
$cleanup();

// Review Focus 3：detached HEAD 也要有确定结果（不崩、不误判）
[$repo, $cleanup] = make_repo();
exec("cd " . escapeshellarg($repo) . " && git checkout -q --detach HEAD");
[$rc, $out] = precheck($repo);
assert_equals(0, $rc, "detached HEAD 且未落后 → 通过");
$cleanup();

// Review Focus 1：取不到远端时必须中止，绝不能当成「没落后」放过
[$repo, $cleanup] = make_repo();
exec("cd " . escapeshellarg($repo) . " && git remote set-url origin " . escapeshellarg($repo . '/does-not-exist.git'));
[$rc, $out] = precheck($repo);
assert_not_equals(0, $rc, "origin 不可达 → 拒绝（不能当成没落后）");
$cleanup();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php test/unit/test_release_precheck.php`
Expected: FAIL —— 脚本不存在

- [ ] **Step 3: Implement `deploy/release-precheck.sh`**

签名与三条守卫见 Interfaces。实现要点：

- **守卫 2 只查远端，不查本地** —— 这是为了让「部署成功但 push 失败」能自愈。若本地 tag 也算数，一次 push 失败的发布会把重跑永久挡在门外（tag 已在本地）。与之配套，Task 4 的收尾步骤在 push 失败时会删掉本地 tag。
- 守卫 2 的比对要同时覆盖 annotated tag 与 lightweight tag：`git ls-remote --tags` 对 annotated tag 会输出两行（tag 对象 sha 与 `^{}` 提交 sha），对 lightweight tag 只输出一行（直接就是提交 sha）。按「行尾是 `^{}` 取其 sha，否则也取其 sha」统一比对即可。
- 守卫 1 的 `git fetch origin master` 失败（断网、认证失效）必须**中止**，不能当成「没落后」放过 —— 对应 Review Focus 1。
- 每条失败信息里带**具体证据**（哪个 ref、哪个文件），因为这条脚本的全部价值就是让人一眼知道该去修什么。

- [ ] **Step 4: Run test to verify it passes**

Run: `php test/unit/test_release_precheck.php`
Expected: PASS，`0 failed`

- [ ] **Step 5: Register the test**

`test/run_all.php` 的 `$suites["Unit"]` 里加 `"{$testDir}/unit/test_release_precheck.php"`。

- [ ] **Step 6: Commit**

```bash
git add deploy/release-precheck.sh test/unit/test_release_precheck.php test/run_all.php
git commit -m "feat(deploy): release-precheck.sh — 发布前的三条守卫 (#250)"
```

---

### Task 3: staging 的版本串改为 `staging-<sha>[-dirty]`

**Files:**
- Modify: `Makefile`（`GIT_TAG`/`GIT_VERSION` 定义处约 56-57 行；`_deploy-code` 约 121-122、144 行）

**Interfaces:**
- Consumes: 无
- Produces: Make 变量 `STAGING_VERSION`（如 `staging-f06411a` 或 `staging-f06411a-dirty`）与 `DEPLOY_PATHS`。Task 4 复用 `DEPLOY_PATHS`。

staging 部署的通常是**尚未打 tag** 的提交（tag 现在只在 release 时打），所以说成上一个发布版本号是误导。`staging-<sha>` 诚实；工作树脏时加 `-dirty`，因为那个 sha 描述不了实际传上去的字节。

- [ ] **Step 1: 加 `DEPLOY_PATHS` 与 `STAGING_VERSION`**

在 `GIT_TAG`/`GIT_VERSION` 定义处（约 56 行）附近加：

```make
# 会被实际上传到服务器的路径。既是「工作树是否脏」的判定范围，也是
# release-precheck.sh 的入参 —— 只在这一个地方定义。
DEPLOY_PATHS = src phpMan.php cli deploy phpman.config.php.example phpman.css phpman.js

# staging 部署的通常是未打 tag 的提交（tag 现在只在 release 时打），
# 所以它不能声称自己是某个发布版本。sha 是诚实的；工作树脏时补 -dirty，
# 因为那个 sha 描述不了实际传上去的字节。
STAGING_SHA     := $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
STAGING_DIRTY   := $(shell git status --porcelain -- $(DEPLOY_PATHS) 2>/dev/null | head -1)
STAGING_VERSION := staging-$(STAGING_SHA)$(if $(STAGING_DIRTY),-dirty)
```

这两条 `$(shell ...)` 都是**纯本地**命令（无网络），放在 parse-time 可以接受 —— Global Constraints 禁的是网络调用。

- [ ] **Step 2: 让 `_deploy-code` 用 `STAGING_VERSION`**

`_deploy-code` 里两条 sed（约 121-122 行）与结尾的 echo（约 144 行）把 `$(GIT_TAG)` / `$(GIT_VERSION)` 换成 `$(STAGING_VERSION)`：

```make
    -e "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$(STAGING_VERSION)');/" \
    -e "s/define('PHPMAN_VERSION', '[^']*');/define('PHPMAN_VERSION', '$(STAGING_VERSION)');/" $(FILE) > $(FILE).deploy
```

`GIT_DESCRIBE` 与 `PHPMAN_VERSION` 置成**同一个值**：两个常量在代码里都只当不透明字符串用（ETag 的 md5 输入、`?v=`、MCP `serverInfo.version`、User-Agent、页脚），全仓库没有 `version_compare` 或任何数值解析，所以 `staging-f06411a` 这种非数值串是安全的。

- [ ] **Step 3: 验证 sed 能命中占位符**

Run:
```bash
make -n staging 2>&1 | grep -c "staging-"
sed -n "31p" phpMan.php
```

Expected: 第一条 ≥ 1（`_deploy-code` 的 sed 行里含 `staging-`）；第二条输出 `define('GIT_DESCRIBE', '__GIT_DESCRIBE__');` —— 与 sed 的 pattern `define('GIT_DESCRIBE', '[^']*');` 形态一致，说明能命中。

- [ ] **Step 4: 端到端确认**

Run: `make staging`
Expected: 部署成功；`curl -s https://test.chedong.com/phpMan.php | grep -o 'staging-[0-9a-f]*\(-dirty\)\?'` 输出形如 `staging-f06411a`。工作树有未提交改动时应出现 `-dirty`。

- [ ] **Step 5: Commit**

```bash
git add Makefile
git commit -m "feat(staging): 版本串改为 staging-<sha>[-dirty]，不再冒充发布版本 (#250)"
```

---

### Task 4: release 的版本来源、守卫、打 tag 与 push

**Files:**
- Modify: `Makefile`（约 56-57 行的 `GIT_TAG`/`GIT_VERSION`、68 行的 `RELEASE_ID`、90 行的 require 列表、199-253 行的 `_release-code`、255-257 行的 `release`/`release-reindex`、326-352 行的 `tag`/`tag-minor`）
- Modify: `.gitignore`（加 `.release-version`）

**Interfaces:**
- Consumes: `deploy/next-version.sh`（Task 1）、`deploy/release-precheck.sh`（Task 2）、`DEPLOY_PATHS`（Task 3）
- Produces: Make 目标 `_release-tag`（可被 `release` / `release-reindex` 作为最后一步调用）

- [ ] **Step 1: 删掉 `tag` 与 `tag-minor`**

删掉这两个目标（约 326-352 行），并从 `.PHONY` 行里去掉 `tag tag-minor`。`package` / `upload-release` 用的是另一个 `$(TAG)`，不受影响，保持原样。

- [ ] **Step 2: 把版本计算从 parse-time 搬进 recipe**

删掉 `GIT_TAG` / `GIT_VERSION` 的 `$(shell git describe ...)` 定义（约 56-57 行）与 `RELEASE_ID` 的 parse-time 定义（约 68 行），换成：版本由 `_release-code` 的 recipe 在**运行时**算出并写进 `.release-version`，后续步骤读这个文件。

`.gitignore` 加一行 `.release-version`。

`.release-version` 的格式：**一行**，内容是 `v` 前缀 + 三段版本号（如 `v4.12.3`）—— 直接就是 `GIT_TAG` 要的值，`RELEASE_ID` 与 tag 名都由它派生。

- [ ] **Step 3: 在 `_release-code` 顶部接上守卫与版本计算**

`_release-code` 的 recipe 前两步（在现有 `@echo "=== Deploying ..."` 之前或之后均可，但必须在任何 rsync 之前）：

```make
	@deploy/release-precheck.sh $(DEPLOY_PATHS)
	@git ls-remote --tags origin | sed 's#.*refs/tags/##' | deploy/next-version.sh $(VERSION) > .release-version.tmp \
		&& printf 'v%s\n' "$$(cat .release-version.tmp)" > .release-version && rm -f .release-version.tmp
```

第二行**每次发布都重写** `.release-version`（Review Focus 5）：用 `.tmp` + `mv` 语义避免中断时留下半截文件，且绝不复用上一次的残留值。

- [ ] **Step 4: 让 `_release-code` 的 sed 与 RELEASE_ID 读 `.release-version`**

`_release-code` 是 recipe 每行一个 shell，所以每行各自读文件：

```make
	@RELVER=$$(cat .release-version); \
	  sed -e "s|define('PHPMAN_HOME',[^;]*;|define('PHPMAN_HOME', '$(DEMO_HOME)/.phpman');|" \
	      -e "s/define('GIT_DESCRIBE', '[^']*');/define('GIT_DESCRIBE', '$$RELVER');/" \
	      -e "s/define('PHPMAN_VERSION', '[^']*');/define('PHPMAN_VERSION', '$${RELVER#v}');/" $(FILE) > $(FILE).deploy
```

`RELEASE_ID` 同样从文件取：

```make
	@RELEASE_ID="$$(cat .release-version)-$$(date +%Y%m%d-%H%M%S)"; \
	  ... 后续所有 $(RELEASE_ID) 换成 $$RELEASE_ID
```

时间戳**必须保留**（`RELEASE_ID` 上方那段注释解释了原因：只按 tag 命名的话，同一提交的第二次发布会 rsync 进正在跑的那个 release，就地改线上代码）。把那段注释留在原地。

- [ ] **Step 5: 加 `_release-tag` 目标**

```make
# 部署全部成功之后才打 tag 并 push。刻意不放进 _release-code —— 它是 release 与
# release-reindex 共享的前置，而 release-reindex 的 index 重建与 sitemap 生成跑在
# 它之后；tag 若在那里推走，reindex 失败时就会留下一个指向「已上线但索引没重建」
# 的 tag，而重跑会被守卫 2 挡住。
_release-tag:
	@RELVER=$$(cat .release-version); \
	  if git rev-parse -q --verify "refs/tags/$$RELVER" >/dev/null; then \
	    echo "本地已有 $$RELVER，确认它指向 HEAD"; \
	    [ "$$(git rev-parse $$RELVER^{commit})" = "$$(git rev-parse HEAD)" ] || { echo "错误：本地 $$RELVER 不指向 HEAD" >&2; exit 1; }; \
	  else \
	    git tag -a "$$RELVER" -m "$$RELVER"; \
	  fi; \
	  if ! git push origin master "refs/tags/$$RELVER"; then \
	    git tag -d "$$RELVER" >/dev/null; \
	    echo "错误：代码已上线，但 tag $$RELVER 与 master 未推送到 origin。修好后重跑 make release。" >&2; \
	    exit 1; \
	  fi; \
	  rm -f .release-version
```

本地 tag 已存在时跳过创建（重跑路径）；push 失败时**删掉本地 tag**，这样重跑时守卫 2（只查远端）不会误拦，四条守卫全过，天然自愈。

- [ ] **Step 6: 把 `_release-tag` 接到两个目标末尾，并加 `test-unit` 前置**

```make
release: test test-unit _release-code
	@$(MAKE) _release-tag

release-reindex: test test-unit _release-code
	@echo "=== Rebuilding production search index ==="
	...（原有的 reindex + sitemap 步骤不动）...
	@$(MAKE) _release-tag
```

`_release-code` 末尾那行 `$(MAKE) logcheck` 保持在原地 —— 它永远退 0，不构成「发布是否成功」的判据。

- [ ] **Step 7: 验证守卫真的会在部署之前拦住**

Run（三个负例，都不应产生任何 rsync）：

```bash
# a) 脏树
echo "// scratch" >> src/config.php && make release ; git checkout -- src/config.php
# 期望：release-precheck.sh 报错退出，无 rsync

# b) HEAD 已发布过
git tag -a v0.0.1-test -m t && git push origin v0.0.1-test && make release ; git push origin :v0.0.1-test && git tag -d v0.0.1-test
# 期望：报错点名 v0.0.1-test

# c) 本地落后
git reset -q --hard HEAD~1 && make release ; git reset -q --hard origin/master
# 期望：报错点名 origin/master
```

- [ ] **Step 8: 验证 `make test` / `make logcheck` 没有新增网络调用**

Run: `make -n test 2>&1 | grep -cE "ls-remote|fetch"` 与 `make -n logcheck 2>&1 | grep -cE "ls-remote|fetch"`
Expected: 都是 `0`

- [ ] **Step 9: 端到端发布一次**

发布前先记下当前版本号，发布后比对：

```bash
BEFORE=$(curl -s https://www.chedong.com/phpMan.php | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' | head -1)
make release
AFTER=$(curl -s https://www.chedong.com/phpMan.php | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' | head -1)
printf 'before=%s after=%s\n' "$BEFORE" "$AFTER"
git ls-remote --tags origin | tail -3
```

Expected: `after` **严格大于** `before`；`git ls-remote --tags origin` 里出现 `refs/tags/$AFTER`；本地工作区干净（`.release-version` 已被 `_release-tag` 删掉）。

- [ ] **Step 10: Commit**

```bash
git add Makefile .gitignore
git commit -m "feat(release): 版本号以 origin 的 tag 为准，打 tag 并入 make release (#250)"
```

---

### Task 5: 文档同步

**Files:**
- Modify: `README.md`（约 569 行、约 816-870 行的发布流程）
- Modify: `CHANGELOG.md`
- Modify: `docs/05-PLAN.md`（若其中有发布流程描述）

**Interfaces:**
- Consumes: Task 1-4 的最终行为
- Produces: 无

- [ ] **Step 1: 改 README 的发布流程**

约 816-870 行那段手工流程（`git push origin master` → `make release`）改为：`make release` 自己完成算版本、部署、打 tag、push master 与 tag。删掉 `make tag` 的任何提及。约 569 行的「`PHPMAN_VERSION` written by `make tag`」改成「由 `make release` 写入」。

- [ ] **Step 2: 加 CHANGELOG 条目**

在 `[Unreleased]` 下加一段 `### Fixed —`，说明：版本号的权威来源改为 origin 的 tag；`make tag` / `tag-minor` 并入 `make release`；四条守卫；staging 改为 `staging-<sha>[-dirty]`。引用 #250。

- [ ] **Step 3: 检查 `docs/05-PLAN.md`**

Run: `grep -n "make tag\|tag-minor\|GIT_TAG\|GIT_VERSION" docs/05-PLAN.md README.md AGENTS.md CLAUDE.md`
Expected: 除 `docs/08-RELEASE-VERSION-INTEGRITY.md` 自身的说明性引用外，不应再有描述旧流程的命中；有就一并改。

- [ ] **Step 4: Commit**

```bash
git add README.md CHANGELOG.md docs/05-PLAN.md
git commit -m "docs: 发布流程文档同步到新的版本号方案 (#250)"
```

---

## 实施中的偏差（2026-10-16 落地时记）

实施时发现三处计划没预见到的问题，都改了；记在这里免得计划与代码对不上。

1. **`release-precheck.sh` 成功时把 tag 快照打到 stdout。** 计划里让 `_release-code` 自己
   再 `git ls-remote` 一次喂给 `next-version.sh`。实施中发现那是个洞：到 github.com:22 的
   连接在这台机器上偶发超时（实测 10 次挂 1-2 次），那次失败会得到空输入，被
   `next-version.sh` 当成「远端还没有 tag」算出 `0.0.1` —— 一个静默的版本倒退，正是这套
   机制要消灭的东西。改成由 precheck 把它已经取到、并据以判断过的**同一份**快照交给下游：
   一次网络请求，且守卫看到的状态与算版本号用的状态必然一致。

2. **守卫的网络调用加了有界重试**（`PRECHECK_ATTEMPTS` 默认 3、`PRECHECK_BACKOFF` 默认 2s）。
   同样的网络抖动会让守卫把「暂时问不到」报成「落后」或「取不到 tag」，10-20% 的假阳性会让
   `make release` 没法用。测试用 `PRECHECK_ATTEMPTS=1` 关掉重试以免拖慢。

3. **`RELEASE_ID` 的时间戳在 parse time 解析一次**（`RELEASE_STAMP`），不是在 recipe 行里
   `date`。计划写的是行内 `$$(date +%Y%m%d-%H%M%S)` —— 那会让同一次部署的各行拿到不同时间戳，
   把一次部署劈成两个 release 目录。

另外两条实施记录（不是偏差，是踩坑）：

- 剥 `refs/tags/` 前缀不能用 `sed 's#.*refs/tags/##'`：`.*` 贪婪，会把行首的 sha 一起吃光，
  awk 就再也匹配不上 HEAD（annotated 与 lightweight tag 都漏检）。改在 awk 里 `sub()`。
- 脏检查必须加 `-uall`：默认 git 把整个未跟踪目录折叠成一行 `?? src/`，报错只说得出目录名。

## 完成后的整体验证

对照 spec 的「验收」一节逐条走：

- [ ] 落后的 checkout 上 `make release` → 失败并说明原因（Task 4 Step 7c）
- [ ] 已 tag 的 HEAD 上 `make release` → 失败并指出已有版本号（Task 4 Step 7b）
- [ ] 脏树上 `make release` → 失败（Task 4 Step 7a）
- [ ] 缺 tag 的机器上 `make release` → 与 tag 齐全的机器算出**同一个**版本号（版本号只来自 `git ls-remote`，与本地 tag 集合无关）
- [ ] `make release VERSION=4.13` → tag `v4.13.0`，且下一次默认发布算出 `v4.13.1`（不是 `v4.12.3`）
- [ ] 正常发布后页脚 / `PHPMAN_VERSION` / release 目录名三者一致
- [ ] `make staging` → 页脚 `staging-<sha>`，脏树时 `-dirty`
- [ ] `make test` / `make logcheck` 无新增网络调用（Task 4 Step 8）
- [ ] 单元测试跑不过时 `make release` 不发车（`make test-unit` 是前置）
- [ ] 部署成功但 push 失败时重跑能补上，且不产生第二个版本号
