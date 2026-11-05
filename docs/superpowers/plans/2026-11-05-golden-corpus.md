# 黄金语料与参考实现差分（#255）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 man/perldoc 的真实输出冻结成 6 页语料，让"渲染器退化"在 CI 里变红。

**Architecture:** 采集（生产机，一次性）与比对（CI，纯函数）分离。语料以工具原始输出逐字节存档；CI 只把 raw 喂给现有渲染函数，与已提交的黄金 Markdown / 结构指纹比较，并校验整页 XHTML。不引入 Composer 或任何运行时依赖。

**Tech Stack:** PHP 8.3（语料作业）、SQLite（既有）、xmllint（CI 安装 libxml2-utils）、GitHub Actions。

**Spec:** `docs/superpowers/specs/2026-11-05-golden-corpus-design.md`

## Global Constraints

- 不引入 Composer / 框架 / 运行时依赖（spec §2、09-QUALITY-GATES §8）。
- CI 中**不得执行** man / groff / perl / pydoc3 —— 语料是冻结的字节。
- 黄金由 PHP **8.3** 生成；语料作业只在 CI 的 8.3 腿运行。
- 语料渲染固定 `PHPMAN_BASE_URL=https://www.chedong.com/phpMan.php`，并使用临时 `PHPMAN_HOME` 隔离用户配置。
- 语料渲染必须关闭 TLDR（`PHPMAN_TLDR_DISABLE=1`）。
- XHTML 校验对象是**完整页面**，DTD 随仓库保存、离线解析。
- 现有 832 条断言保持全绿；`test/run_all.php` 的用例清单是硬编码的，新用例必须登记。

## Review Focus

- **man 页眉页脚**：不同 section、超长名字的页眉形态不同；归一化规则若过宽会删掉正文首行。
- **perldoc 没有页眉**：把 man 的规则直接套到 perldoc 上会误删末行正文。
- **Markdown 里的绝对链接**：由 `baseUrl()` 生成，环境不同黄金就不稳定。
- **TLDR 联网**：开关若失效，`man ls` / `man tar` 的黄金会随网络与缓存变化。
- **xmllint / DTD 缺失**：CI 未装 libxml2-utils 或 DTD 路径错，会把健康构建判红。

---

### Task 1: 语料清单、归一化库与结构指纹

**Files:**
- Create: `test/corpus/pages.txt`
- Create: `test/corpus/normalize.php`
- Create: `test/corpus/fingerprint.php`
- Modify: `test/structure_regression.php`（改为 require `test/corpus/fingerprint.php`，删掉本地同名函数）
- Test: `test/unit/test_corpus_normalize.php`
- Modify: `test/run_all.php`（登记新用例）

**Interfaces:**
- Produces:
  - `corpusReadManifest(string $path): array` — 返回 `[['id'=>string,'tool'=>'man'|'perldoc','name'=>string,'section'=>string], …]`
  - `corpusStripFormatting(string $text): string`
  - `corpusNormalizeText(string $text): string`
  - `corpusTokens(string $text): array`
  - `corpusMissingTokens(array $rawTokens, array $renderedTokens): array`
  - `corpusTextFromHtml(string $html): string`
  - `jsonStructureFingerprint(array $jsonData): string`（从 `structure_regression.php` 原样搬出）

- [ ] **Step 1: 写清单文件**

`test/corpus/pages.txt`（制表符分隔，`#` 开头为注释）：

```
# id	tool	name	section
man-ls	man	ls	1
man-tar	man	tar	1
man-ssh_config	man	ssh_config	5
perldoc-perlfunc	perldoc	perlfunc	
perldoc-perlvar	perldoc	perlvar	
perldoc-DBI	perldoc	DBI	
```

- [ ] **Step 2: 写失败的测试**

`test/unit/test_corpus_normalize.php`，覆盖：

```php
// 清单：注释与空 section 都要正确处理
$m = corpusReadManifest(__DIR__ . '/../corpus/pages.txt');
assert_equals(6, count($m), "六页语料");
assert_equals('', $m[3]['section'], "空 section 解析为空串");
assert_equals('man', $m[0]['tool'], "工具名保留");

// ANSI 与 overstrike
assert_equals("LS(1)", corpusStripFormatting("\x1b[4mLS\x1b[24m(1)"), "去 ANSI");
assert_equals("bold", corpusStripFormatting("b\x08bo\x08ol\x08ld\x08d"), "去 overstrike");

// man 页眉页脚：首末两行是页眉页脚时丢弃
$manPage = "LS(1)   User Commands   LS(1)\n\nNAME\n    ls - list\n\nLS(1)   User Commands   LS(1)\n";
assert_equals("NAME\nls - list", corpusNormalizeText($manPage), "页眉页脚被丢弃");

// perldoc 没有页眉：末行正文不能被当成页脚删掉
$pod = "NAME\n    DBI - database interface\n\nDESCRIPTION\n    DBI is a database access module.\n";
assert_contains("DBI is a database access module.", corpusNormalizeText($pod), "perldoc 末行保留");

// 词多重集
$missing = corpusMissingTokens(['ls','-l','ls'], ['ls','other']);
assert_equals(['-l','ls'], $missing, "缺失词按次数报告");

// HTML 抽文本
assert_equals("a b", corpusTextFromHtml("<p>a</p><p>b</p>"), "标签去除并折叠空白");
```

- [ ] **Step 3: 运行测试确认失败**

Run: `php test/unit/test_corpus_normalize.php`
Expected: FAIL — `corpusReadManifest()` not defined

- [ ] **Step 4: 实现 `test/corpus/normalize.php`**

规则（写进注释）：
- `corpusStripFormatting`：先 `preg_replace('/\x1b\[[0-9;]*m/', '', $t)`，再 `preg_replace('/(.)\x08\1/', '$1', $t)`。
- 页眉/页脚：一条 `preg_match('/^[A-Z0-9][A-Z0-9_.:\-]*\(\d[a-z]*\)\s/', $line)`；**只有当同一行文本在首、末各出现一次时才丢弃**（用 `array_search` 比较首末非空行是否相同），避免误删 perldoc 正文。
- `corpusNormalizeText`：strip → 逐行 `rtrim` → 丢页眉页脚 → 连续空行压成一个 → `trim`。
- `corpusTokens`：`preg_split('/\s+/', trim($s))`，过滤空串。
- `corpusMissingTokens`：对 raw 做计数多重集，逐 token 消耗 rendered 的计数，返回仍未满足的 token（保持 raw 中的顺序）。
- `corpusTextFromHtml`：`strip_tags` → `html_entity_decode(..., ENT_QUOTES|ENT_HTML5, 'UTF-8')` → `preg_replace('/\s+/', ' ', ...)` → `trim`。

- [ ] **Step 5: 实现 `test/corpus/fingerprint.php`**

把 `structure_regression.php:17` 的 `jsonStructureFingerprint` 原样移入，`structure_regression.php` 顶部改为 `require_once __DIR__ . '/corpus/fingerprint.php';` 并删掉本地定义。

- [ ] **Step 6: 运行测试确认通过并登记用例**

Run: `php test/unit/test_corpus_normalize.php` → 全绿
Run: `grep -n test_corpus_normalize test/run_all.php` → 必须有输出（把该路径加进 `test/run_all.php` 的 `$tests` 列表）
Run: `php test/run_all.php` → 全绿

- [ ] **Step 7: Commit**

```bash
git add test/corpus/pages.txt test/corpus/normalize.php test/corpus/fingerprint.php test/structure_regression.php test/unit/test_corpus_normalize.php test/run_all.php
git commit -m "test(corpus): 语料清单、归一化库与结构指纹（#255）"
```

---

### Task 2: TLDR 关闭开关

**Files:**
- Modify: `src/tldr.php`（`fetchOfficialTldr()` 开头）
- Modify: `src/config.php`（MCP 配置段之后）
- Test: `test/unit/test_tldr_disable.php`
- Modify: `test/run_all.php`

**Interfaces:**
- Produces: 常量 `PHPMAN_TLDR_DISABLE`（bool，默认 false，可由常量或环境变量 `PHPMAN_TLDR_DISABLE` 覆盖）

- [ ] **Step 1: 写失败的测试**

```php
// 子进程里定义常量后调用，避免常量不可撤销的问题
$code = 'define("PHPMAN_TLDR_DISABLE", true); require "phpMan.php"; '
      . 'var_export(fetchOfficialTldr("ls", "man", "1"));';
$out = trim((string)shell_exec('php -r ' . escapeshellarg($code) . ' 2>/dev/null'));
assert_equals('array (' . "\n" . ')', $out, "关闭时直接返回空数组，不触网");
```

（具体断言写法以 `test_helper.php` 为准；关键是"关闭时返回 `[]` 且不发起请求"。）

- [ ] **Step 2: 运行确认失败**

Run: `php test/unit/test_tldr_disable.php`
Expected: FAIL — 未定义开关，函数仍会尝试联网（测试环境无网时返回 []，需用 `--bootstrap` 断言方式区分；若难区分，则断言函数体包含 `PHPMAN_TLDR_DISABLE`，与 `test_release_precheck.php` 的 tripwire 写法一致）

- [ ] **Step 3: 实现**

`src/tldr.php` 的 `fetchOfficialTldr()` 最前面加：

```php
if (defined('PHPMAN_TLDR_DISABLE') && PHPMAN_TLDR_DISABLE) return [];
```

`src/config.php` 加：

```php
if (!defined('PHPMAN_TLDR_DISABLE')) {
    define('PHPMAN_TLDR_DISABLE', getenv('PHPMAN_TLDR_DISABLE') === '1');
}
```

- [ ] **Step 4: 运行确认通过**

Run: `php test/unit/test_tldr_disable.php` → 全绿
Run: `php test/run_all.php` → 全绿

- [ ] **Step 5: Commit**

```bash
git add src/tldr.php src/config.php test/unit/test_tldr_disable.php test/run_all.php
git commit -m "feat(tldr): 增加 PHPMAN_TLDR_DISABLE，供语料渲染离线运行（#255）"
```

---

### Task 3: `capture` 子命令

**Files:**
- Create: `cli/corpus.php`
- Test: `test/unit/test_corpus_capture.php`
- Modify: `test/run_all.php`

**Interfaces:**
- Produces:
  - `corpusBuildCommand(array $entry): string` — 纯函数，返回 shell 命令
  - `corpusVersionsText(array $versions): string` — 生成 `VERSIONS.md` 正文
  - CLI：`php cli/corpus.php capture [--out=DIR]`，默认 `DIR` 为 `test/corpus`
- 注意：`capture` **不 require `_bootstrap.php`** —— 它只跑系统命令、写文件，必须能在未部署的机器上用单文件运行。

- [ ] **Step 1: 写失败的测试**

```php
require_once __DIR__ . '/../../cli/corpus.php';   // 该文件需在 CLI 之外被包含时不执行主流程

$man = ['id'=>'man-ls','tool'=>'man','name'=>'ls','section'=>'1'];
assert_equals(
    "MANROFFOPT=-rLL=100n man -Tutf8 1 'ls'",
    corpusBuildCommand($man),
    "man 命令与 source_man.php 一致"
);

$pod = ['id'=>'perldoc-DBI','tool'=>'perldoc','name'=>'DBI','section'=>''];
assert_contains("pod2text -w 100", corpusBuildCommand($pod), "perldoc 走 pod2text 管线");

assert_contains('groff', corpusVersionsText(['man'=>'man 2.12.0','groff'=>'GNU groff version 1.23.0']), "版本写入 VERSIONS.md");
```

- [ ] **Step 2: 运行确认失败**

Run: `php test/unit/test_corpus_capture.php`
Expected: FAIL — 文件不存在

- [ ] **Step 3: 实现 `cli/corpus.php`**

- 文件顶部：`if (PHP_SAPI !== 'cli') { http_response_code(400); die("CLI only\n"); }`
- 主流程只在 `realpath($argv[0]) === __FILE__` 时执行（便于测试 require）。
- `corpusBuildCommand`：
  - man：`MANROFFOPT=-rLL=' . PHPMAN_WIDTH . 'n man -Tutf8 ' . escapeshellarg($section) . ' ' . escapeshellarg($name)`（宽度固定 100，不依赖 bootstrap；写成常量 `100` 并在注释里说明与 `PHPMAN_WIDTH` 同源）
  - perldoc：`perldoc -l <name> 2>/dev/null | head -1 | tr '\n' '\0' | xargs -0 pod2text -w 100 2>/dev/null`
- `capture`：逐条执行、捕获 stdout（不经过 `exec()` 的按行切分，要用 `shell_exec` 保留原始字节）、写 `raw/<id>.txt`；收集 `man --version` / `groff --version` / `perl -v` / `pod2text --version`（失败则写 "(unavailable)"）；写 `VERSIONS.md`（含每页字节数与 `hash('sha256', $bytes)`）。
- 任何一页输出为空 → 打印 `FAIL <id>: empty output` 并 `exit 1`。

- [ ] **Step 4: 运行确认通过**

Run: `php test/unit/test_corpus_capture.php` → 全绿

- [ ] **Step 5: Commit**

```bash
git add cli/corpus.php test/unit/test_corpus_capture.php test/run_all.php
git commit -m "feat(corpus): capture 子命令——在生产机冻结原始输出（#255）"
```

---

### Task 4: `render` 子命令

**Files:**
- Modify: `cli/corpus.php`（新增 `render`）
- Test: `test/unit/test_corpus_render.php`
- Modify: `test/run_all.php`

**Interfaces:**
- Consumes: `corpusReadManifest()`、`jsonStructureFingerprint()`
- Produces: `golden/<id>.md`、`golden/<id>.fingerprint`
- 环境隔离（`render` 内固定，不读用户配置）：

```php
define('PHPMAN_HOME', sys_get_temp_dir() . '/phpman-corpus-home');
define('PHPMAN_BASE_URL', 'https://www.chedong.com/phpMan.php');
define('PHPMAN_TLDR_DISABLE', true);
require_once dirname(__DIR__) . '/src/bootstrap.php';
```

- [ ] **Step 1: 写失败的测试**

在临时目录造一页最小语料（`raw/test-page.txt` 内容为 `NAME\n    demo - a demo\n\nSEE ALSO\n    other(1)\n`），调用 render，断言：

- `golden/test-page.md` 存在且包含 `demo - a demo`；
- `golden/test-page.fingerprint` 非空；
- 输出包含 `https://www.chedong.com/phpMan.php/man/other/1/markdown` —— 这条把 Review Focus 第 3 项（基准 URL 固定）钉死：环境不同不能让黄金变样。

- [ ] **Step 2: 运行确认失败**

Run: `php test/unit/test_corpus_render.php`
Expected: FAIL — `render` 未实现

- [ ] **Step 3: 实现**

- 读 manifest；对每页读 `raw/<id>.txt`，`explode("\n", ...)` 得到 `$lines`（注意 `explode` 后尾部空串要保留，与 `exec()` 行为对齐——**用 `preg_split('/\n/', $raw)` 并丢弃最后一个空元素**，与 `exec()` 一致）。
- 每个格式各用一份 `$lines` 副本（函数按引用修改）。
- man：`formatManPerlDocToMarkdown($lines, $name, 'man', $section)`
- perldoc：`formatManPerlDocToMarkdown($lines, $name, 'perldoc')`
- 指纹：`buildJsonData($lines, $name, $section, $mode)` → `jsonStructureFingerprint()`
- 写文件；`--out` 默认 `test/corpus`。

- [ ] **Step 4: 运行确认通过**

Run: `php test/unit/test_corpus_render.php` → 全绿

- [ ] **Step 5: Commit**

```bash
git add cli/corpus.php test/unit/test_corpus_render.php test/run_all.php
git commit -m "feat(corpus): render 子命令——由冻结 raw 生成黄金（#255）"
```

---

### Task 5: 检查脚本（黄金、文本完整性、结构、JSON 契约）

**Files:**
- Create: `test/corpus/check.php`
- Test: `test/unit/test_corpus_check.php`
- Modify: `test/run_all.php`

**Interfaces:**
- Produces: CLI `php test/corpus/check.php [--corpus=DIR]`，全部通过 `exit 0`，否则 `exit 1` 并逐项打印 `[FAIL] <id> <check>: <reason>`
- 四条检查：`golden-md`、`tokens`、`fingerprint`、`json-contract`

- [ ] **Step 1: 写失败的测试**

构造临时语料：一页正常、一页 golden 被改坏，运行 check 子进程，断言退出码 1 且输出含 `[FAIL]` 与页 id；再修好，断言退出码 0。

- [ ] **Step 2: 运行确认失败**

Run: `php test/unit/test_corpus_check.php`
Expected: FAIL — check.php 不存在

- [ ] **Step 3: 实现**

- 与 Task 4 相同的隔离头（固定 BASE_URL / 临时 HOME / 关闭 TLDR）。
- 对每页：
  1. `golden-md`：渲染 md 与 `golden/<id>.md` 逐字节比较（不一致时打印首个不同的行号与前 3 行差异）。
  2. `tokens`：`corpusTokens(corpusNormalizeText(raw))` 减去 `corpusTokens(corpusNormalizeText(corpusTextFromHtml(html)))`，缺失非空即失败。
  3. `fingerprint`：渲染 JSON → `buildJsonData` → `jsonStructureFingerprint`，与 `golden/<id>.fingerprint` 比较。
  4. `json-contract`：`json_decode` 非 null、`sections` 键存在、`json_encode` 后长度 > 0。
- 汇总计数并 `exit($fail > 0 ? 1 : 0)`。

- [ ] **Step 4: 运行确认通过**

Run: `php test/unit/test_corpus_check.php` → 全绿

- [ ] **Step 5: Commit**

```bash
git add test/corpus/check.php test/unit/test_corpus_check.php test/run_all.php
git commit -m "test(corpus): 黄金/文本完整性/结构/JSON 四条检查（#255）"
```

---

### Task 6: XHTML 整页校验

**Files:**
- Create: `test/corpus/dtd/xhtml1-transitional.dtd`、`xhtml-lat1.ent`、`xhtml-special.ent`、`xhtml-symbol.ent`
- Modify: `test/corpus/check.php`（新增 `xhtml` 检查）
- Test: `test/unit/test_corpus_check.php`（新增用例）

**Interfaces:**
- Consumes: `showHeader()`、`showFooter()`、`formatManPerlDoc()`
- Produces: 检查名 `xhtml`；依赖外部命令 `xmllint --noout --dtdvalid test/corpus/dtd/xhtml1-transitional.dtd <file>`

- [ ] **Step 1: 写失败的测试**

用一页最小语料渲染整页，断言子进程输出含 `[PASS]`；再篡改渲染出的页面（如把 `<body>` 改成未闭合），断言 `[FAIL]`。若本机没有 `xmllint`，测试 `markTestSkipped` 等价处理（打印 `[SKIP] xhtml` 并返回 0）。

- [ ] **Step 2: 运行确认失败**

Run: `php test/unit/test_corpus_check.php`
Expected: FAIL — 无 `xhtml` 检查

- [ ] **Step 3: 下载并提交 DTD**

从 `https://www.w3.org/TR/xhtml1/DTD/` 取四个文件，逐字节保存到 `test/corpus/dtd/`（一次性；之后离线）。在 `check.php` 注释里记录来源与日期。

- [ ] **Step 4: 实现整页渲染与校验**

```php
ob_start();
showHeader($title, $name, $section, $mode, true, false, "");
echo formatManPerlDoc($lines, $mode);
showFooter("", false, $mode, $name, $section, "", "");
$page = (string)ob_get_clean();
```

写临时文件 → `xmllint --noout --dtdvalid <dtd> <file> 2>&1`；退出码非 0 即失败，打印 stderr 前 5 行。

- [ ] **Step 5: 运行确认通过**

Run: `php test/unit/test_corpus_check.php` → 全绿（无 xmllint 时 `xhtml` 记 SKIP）

- [ ] **Step 6: Commit**

```bash
git add test/corpus/dtd test/corpus/check.php test/unit/test_corpus_check.php
git commit -m "test(corpus): XHTML 整页校验，DTD 随仓库离线解析（#255）"
```

---

### Task 7: CI 作业与 Makefile 目标

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `Makefile`
- Modify: `test/run_all.php`（如需）

**Interfaces:**
- Produces: `make test-corpus`；CI 作业名 `corpus`

- [ ] **Step 1: 加 Makefile 目标**

```make
test-corpus:
	php test/corpus/check.php
```

- [ ] **Step 2: 加 CI 作业**

```yaml
  corpus:
    name: corpus (PHP 8.3)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
          extensions: mbstring, sqlite3
      - run: sudo apt-get update && sudo apt-get install -y libxml2-utils
      - run: cp .deploy.mk.example .deploy.mk
      - run: make test-corpus
```

- [ ] **Step 3: 本地验证**

Run: `make test-corpus`
Expected: 六页全部 `[PASS]`（语料尚未采集时先跳过，见 Task 8；此时可让 check 在 `raw/` 为空时打印 `[SKIP] no corpus` 并 `exit 0`）

- [ ] **Step 4: Commit**

```bash
git add Makefile .github/workflows/ci.yml
git commit -m "ci: 新增 corpus 作业（PHP 8.3 + xmllint）（#255）"
```

---

### Task 8: 采集真实语料、审阅黄金并提交

**Files:**
- Create: `test/corpus/raw/*.txt`、`test/corpus/golden/*.md`、`test/corpus/golden/*.fingerprint`、`test/corpus/VERSIONS.md`

**Interfaces:**
- Consumes: Task 3 的 `capture`、Task 4 的 `render`、Task 5-6 的 `check`

- [ ] **Step 1: 在生产机采集**

```bash
scp cli/corpus.php chedong@chedong.com:/tmp/corpus.php
ssh chedong@chedong.com 'mkdir -p /tmp/phpman-corpus && php /tmp/corpus.php capture --out=/tmp/phpman-corpus'
rsync -av chedong@chedong.com:/tmp/phpman-corpus/ test/corpus/
```

- [ ] **Step 2: 本地生成黄金（PHP 8.3）**

Run: `php cli/corpus.php render`
Expected: `test/corpus/golden/` 下 12 个文件

- [ ] **Step 3: 人工审阅黄金（不可省）**

逐页检查 `golden/*.md`：标题层级、列表、代码块、链接是否存在且合理。发现渲染错误**不要**直接接受为黄金——先修渲染器或记录为已知差异（`test/corpus/known-diffs.txt`），再重新生成。

- [ ] **Step 4: 跑检查**

Run: `make test-corpus`
Expected: 六页全部 `[PASS]`

- [ ] **Step 5: Commit**

```bash
git add test/corpus/VERSIONS.md test/corpus/raw test/corpus/golden test/corpus/known-diffs.txt
git commit -m "test(corpus): 采集六页真实语料与黄金输出（#255）"
```

---

## Self-Review

- **Spec coverage:** §4.1 目录布局（Task 1/3/4/8）、§4.2 六页选择（Task 1）、§4.3 采集（Task 3/8）、§4.4 检查 1-3（Task 5）、检查 4-5（Task 5/6）、§4.5 归一化（Task 1）、§4.6 更新流程（Task 4/8）、§3 约束（Global Constraints）；§5 与 `structure_regression.php` 的关系（Task 1 Step 5 只抽指纹函数，迁移留作后续）。
- **未覆盖、需维护者决定：** `structure_regression.php` 迁到冻结语料（spec §6 列为可选）。
