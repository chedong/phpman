# 黄金语料与参考实现差分（#255）设计

> 状态：设计（2026-11-05）
> 上游：`docs/09-QUALITY-GATES.md` 阶段 2、L2 层、G4/G9
> 相关 issue：[#255](https://github.com/chedong/phpman/issues/255)

## 1. 意图与为什么

09-QUALITY-GATES 的结论是：这个项目缺的不是努力，是**独立判据**——过去三周的误判都是"查了，但结论错"。L2 层（用参考实现的输出当裁判）目前是空的。

本设计要解决的具体问题：**渲染器退化时，没有任何机器判据会变红。** 现有 832 条断言测的是函数行为，不是"真实 man 输出经过 phpman 之后有没有丢内容"。

成功的样子：改坏 `formatManPerlDoc()` 或 `formatManPerlDocToMarkdown()`（丢行、重复行、转义被吃掉、标题识别错位），CI 在几分钟内变红，且失败信息能指出是哪一页、哪一行。

## 2. 目标 / 非目标 / 反目标

**目标**

- 6 页冻结语料：man 3 + perldoc 3，连同采集时的工具版本存档。
- 三条独立检查：黄金 Markdown 对比、文本完整性、XHTML 合法（G4）+ 结构指纹。
- 全部在 CI 上跑，**不在 CI 里执行 man/groff/perl**。

**非目标**

- 不覆盖 pydoc / ri / info（留给后续阶段）。
- 不引入 Composer、框架或任何运行时依赖（09 §8）。
- 不做性能基准；耗时由 `cli/metrics.php` 负责。

**反目标**（做到也算失败）

- 在 CI 里现跑工具生成 oracle —— 判据会随镜像版本漂移。
- 把自动生成的输出直接当黄金、不经人工审阅 —— 等于把现有 bug 冻结成标准答案。
- 为了过门而修改渲染语义。

## 3. 约束

- 采集机是生产主机：man 2.12.0 / groff 1.23.0 / perl 5.38.2 / Python 3.12.3 / ri 6.5.0。
- 生产机**没有 xmllint**，XHTML 校验只能在 CI 做（CI 用 `apt-get install libxml2-utils`）。
- 渲染宽度固定为 `PHPMAN_WIDTH`（当前 100）。
- 采集命令必须与生产代码一致（**不是**直觉里的那条）：
  - man（`src/source_man.php:8-17`）：`MANROFFOPT=-rLL=100n man -Tutf8 <section> <page>`
  - perldoc（`src/source_perldoc.php:9`）：`perldoc -l <module> | head -1 | tr '\n' '\0' | xargs -0 pod2text -w 100`，
    `perldoc <module>` 只是该管线失败时的兜底。两者排版不同（换行宽度、overstrike、标题），
    用错命令等于拿生产永不渲染的输入做语料。`VERSIONS.md` 必须记录 **`pod2text` / `Pod::Text` 的版本**，不只是 perl。
- **TLDR 必须关闭**：`formatManPerlDocToMarkdown()` 在 `$parameter !== ""` 时会调 `fetchOfficialTldr()`
  （`src/format_markdown.php:29-42`），man 第 1 节（`ls`、`tar`）会真的走网络。语料只测"工具原始输出 →
  渲染"，TLDR 是外部内容，不属于这条链路。渲染时用测试专用开关（`PHPMAN_TLDR_DISABLE=1`）关掉它；
  TLDR 块的排版另用固定 fixture 单独测。
- **基准 URL 固定**：Markdown 里的 `foo(1)` / `Module::Name` 会被链接成 `baseUrl()` 拼出的绝对地址
  （`src/format_markdown.php:54-63`）。生成黄金与 CI 比对时都固定
  `PHPMAN_BASE_URL=https://www.chedong.com/phpMan.php`，并用临时 `PHPMAN_HOME` 隔离用户配置。
- **PHP 版本**：黄金由 **8.3**（生产 Web SAPI）生成，语料作业也只在 CI 的 8.3 腿运行；8.1/8.2 腿继续只跑单元测试。
  PHP/PCRE 的差异不是渲染器回归，不该让 8.1 腿把门变红。
- 仓库体积：6 页 raw 合计约 0.95 MB，黄金 Markdown 约 1 MB，可接受。

## 4. 设计

### 4.1 目录布局

```
test/corpus/
  VERSIONS.md                 # 采集机、各工具版本、采集命令、每页 sha256
  raw/man-ls.txt              # 工具原始输出，逐字节保存
  raw/man-tar.txt
  raw/man-ssh_config.txt
  raw/perldoc-perlfunc.txt
  raw/perldoc-perlvar.txt
  raw/perldoc-DBI.txt
  golden/man-ls.md            # phpman 渲染出的 Markdown，人工审阅后提交
  ...
  golden/man-ls.fingerprint   # JSON section 结构指纹
  ...
```

### 4.2 语料选择（维护者的判断）

| 页 | 类型 | 选它的理由 |
|---|---|---|
| `man ls` | 1 | 基线：短、列表、ANSI 加粗/下划线齐全 |
| `man tar` | 1 | 超长、深层嵌套选项列表、体量大（52 KB） |
| `man ssh_config` | 5 | groff tbl 表格、大段说明文字与缩进块（88 KB） |
| `perldoc perlfunc` | POD | 大量 `=item`、代码块、交叉引用（420 KB，最长） |
| `perldoc perlvar` | POD | 特殊符号（`$` `@` `%`）密集，转义易错 |
| `perldoc DBI` | POD | 非核心 POD 风格（CPAN 模块），排版与 core 不同 |

**已知缺口**：没有宽字符 / CJK 语料——生产机上没有中文 man 页。列入 §6，不在本轮解决。

### 4.3 采集（生产机，人工触发）

`php cli/corpus.php capture`：

- 按 §3 的命令逐页执行（man 用 `man -Tutf8`，perldoc 用 `pod2text` 管线），写 `raw/<id>.txt`；
- 重写 `VERSIONS.md`（工具版本 + 每页字节数与 sha256）；
- 采集机的选择是刻意的：语料要反映**真实线上输入**，不是 CI 镜像的近似。

### 4.4 检查（CI，纯函数）

对每页语料：

1. **黄金 Markdown** —— 把 `raw/<id>.txt` 喂给 `formatManPerlDocToMarkdown()`，与 `golden/<id>.md` 逐字节比较，要求 0 差异。
2. **文本完整性** —— 从 HTML 与 Markdown 各抽一次纯文本，与 raw 归一化后比较：raw 的每个非空行都必须按序出现在渲染结果中，反之亦然（行覆盖）。抓丢行、重复行。
3. **结构指纹** —— JSON 的 section 名、行数、L2 列表，与 `golden/<id>.fingerprint` 比较。抓标题识别错误（#252 那一类）。
4. **XHTML 合法（G4）** —— 由 raw 现场渲染**完整页面**（`showHeader()` + 正文 + `showFooter()`），
   用 `xmllint --noout --valid` 校验。校验对象是用户真正拿到的那份文档，不是 `<pre>` 片段；
   页脚里的时间戳/IP 不影响"是否合法"这个判定。DTD 不依赖网络：把
   `xhtml1-transitional.dtd` 及其实体文件随仓库放在 `test/corpus/dtd/`，用 `--path` 指过去。
5. **JSON 基本契约** —— 可解析、非 0 字节、必需字段齐全。

### 4.5 归一化规则

- 去除 ANSI SGR（`\x1b\[[0-9;]*m`）与 overstrike（`X\bX`）；
- 折叠行尾空白，连续空行压成一个；
- man 的页眉页脚（首行 `X(N) ... X(N)`、末行页码）在比对两侧同时丢弃；
- 宽字符不做特殊处理（见 §6）。

### 4.6 黄金更新流程

1. `php cli/corpus.php render` 重新生成 `golden/`；
2. `git diff test/corpus/golden/` **必须人工审阅**后提交；
3. 渲染输出有意变化时，与 `RENDERER_VERSION` 的 bump 一起提交。

## 5. 与现有测试的关系

- `test/structure_regression.php` 目前**现跑 `man`/`perldoc`**，CI 上不可复现。本设计把它改成"对冻结语料比对"，或保留它做本地烟测、由本设计接管 CI 中的结构检查。
- 现有 832 条断言不动。

## 6. 留给构建者的决定

- 页眉页脚的具体识别规则（`man` 与 `perldoc` 的头部形态不同）。
- 文本完整性用「行覆盖」还是「词多重集」——两者抓的东西不同，选一个作为主判据。
- `cli/corpus.php` 的 CLI 形状（子命令与参数）。
- 是否在本轮就把 `structure_regression.php` 迁到冻结语料（可能拆成两个提交）。

## 6.1 评审问题的决定（2026-11-05）

设计初稿经过一次独立评审，提出的五个问题与决定如下。每条都标为**维护者的判断**，构建时可推翻但要说明。

| # | 问题 | 决定 |
|---|---|---|
| 1 | perldoc 语料该用 `pod2text` 管线还是 `perldoc <module>`？ | **`pod2text -w 100` 管线**，与生产一致；`VERSIONS.md` 记 `pod2text`/`Pod::Text` 版本。初稿写错了。 |
| 2 | TLDR 块算不算黄金的一部分？ | **不算**。语料只覆盖"工具原始输出"，TLDR 是外部内容。渲染时用测试开关关闭；TLDR 排版另用固定 fixture 测。 |
| 3 | `corpus.php render` 在哪跑、基准 URL 和配置怎么办？ | 渲染与 CI 都固定 `PHPMAN_BASE_URL` 与临时 `PHPMAN_HOME`；黄金是环境的产物，不能让开发者机器的配置改结果。 |
| 4 | XHTML 校验验片段还是整页？DTD 从哪来？ | **整页**（用户真正拿到的文档）。DTD 随仓库保存，`--path` 离线解析，不依赖 w3.org。 |
| 5 | 哪个 PHP 版本生成/比对黄金？ | **8.3**（生产 Web SAPI）。语料作业只在 8.3 跑；8.1/8.2 腿维持现有单元测试。 |

## 7. 风险

- **归一化过度**：规则太宽会把真实丢失也归一化掉；太严则每页都有噪声 diff。第一版必须先跑一遍六页、把差异清单人工过一遍，再定规则。
- **黄金审阅成本**：首次生成 6 页黄金需要人读一遍（约 1 MB 文本）。这是刻意付出的成本，不是可以省的步骤。

## 8. 实施记录与修订（2026-11-06）

第一次跑真实语料（六页）时，门立刻抓出了设计阶段没有预料到的问题。以下是实际发生的修订，实现以本节为准。

### 8.1 语料门发现的两个线上缺陷

1. **SGR 转 HTML 产生交错标签**（`src/format_html.php`）。真实序列
   `ESC[1m-f ESC[4mESC[22mARCHIVE ESC[24m` 表示"加粗结束与下划线开始同时发生"，
   两条非贪婪正则配对后产出 `<b>-f <u></b>ARCHIVE</u>` —— 无效嵌套。
   `man tar` 与 `man ssh_config` 各有十几到几十行受影响，xmllint 判定 invalid。
   **已修**（commit `ad59aab`）：改用 SGR 状态机，按 `<b><u>` 顺序打开、逆序关闭、行尾全部关闭。
2. **同款缺陷在 markdown 路径**（`src/format_common.php` 的 `cleanTerminalOutput()`）。
   它仍用同样的两条正则，黄金里 32 行强调标记错位（`**-f _**ARCHIVE_`），
   **线上 `/markdown` 输出同样受影响**。修复方式是把状态机移植过去，
   输出该函数既有的 `\x01\x02` / `\x03\x04` 标记。

教训：渲染器里"成对正则"处理 SGR 是不成立的抽象；属性开关是独立状态，必须按状态机处理。

### 8.2 检查规则的修订

- **HTML 抽文本**：块级标签 → 空格，行内标签 → 空串。原规则"所有标签 → 空格"会把
  `[<u>OPTIONS</u>]` 抽成 `[ OPTIONS ]`，与 raw 的词元永远对不上。
- **邮箱归一化**：渲染器刻意把 `user@host` 混淆成 `user<u> AT </u>host`，
  所以 `corpusNormalizeText()` 在两侧都做 `user@host` → `user AT host` 的规范化。
- **XHTML 的 DTD**：W3C 四个文件逐字节收录，另加 5 行追加式 override
  （声明 `script/@nonce`、`script/@async`）—— #273 的 CSP nonce 不在 2002 年的 DTD 里，
  移除它等于回退安全加固。校验用 `--nonet --dtdvalid`，离线。
- **空语料**：`raw/` 为空时打印 `[SKIP] no corpus` 并 `exit 0`，便于 CI 先接线、后采集。

### 8.3 已知限制（不阻塞，留待后续）

- **结构指纹偏弱**：`buildJsonData()` 的 `sections` 以 L1 名为键、content 是字符串，
  指纹里 L1 段恒为 `?(0lines)`；区分度来自 L2 小节名与 flags 数。确定性成立，灵敏度有限。
- **4 空格缩进误判**：man 模式下恰好 4 空格缩进的 `name(` 会被 pydoc 的函数定义启发式
  `/^ {4}([a-z]\w*)\(/` 当成 L2 小节。真实 man 输出里 see-also 是 7 空格，所以语料未受影响，
  但这是一个独立的渲染缺陷，应另开 issue。
- **无宽字符 / CJK 语料**：生产机没有中文 man 页。

### 8.4 同期观测（2026-11-05 24h，来自 `cli/metrics.php`）

- **HTML p95 2664ms，破 SLO（2s）**。驱动项是 pydoc：295 次，p50 **7233ms**、p95 **15313ms**；
  man p50 只有 24ms。这不是渲染退化，而是 pydoc 冷路径（`pydoc3 -k`）—— 见 #280、#258。
- 当日 6 个 500：5 个是大响应 EPIPE（83KB–951KB），1 个是 0 字节且服务端无记录（#249 的预测族）。
- pydoc 20s 超时 7 次/日。
