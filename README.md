# phpMan — Unix Man Page / Perldoc / Info Page Web Interface

A single-file PHP web interface for Unix `man`, `perldoc`, `info`, and `apropos` commands.
Read lengthy manual pages in your browser — with syntax highlighting, section navigation, and a floating TOC sidebar.

## Project Home

- **SourceForge:** <https://sourceforge.net/projects/phpunixman>
- **Live Demo:** <https://www.chedong.com/phpMan.php>
- **Static Site:** <https://phpunixman.sourceforge.io/>

> ⚠️ **SourceForge no longer supports PHP (since 2025-05-20).**
> The dynamic demo runs on `chedong.com`; the `sourceforge.io` site is a static project introduction page.

## Screenshot

![phpMan: perldoc page with TOC sidebar](https://a.fsdn.com/con/app/proj/phpunixman/screenshots/QQ20121226-2.png/600/450/1)

## Features

- **Man Pages** — Browse any Unix/Linux manual page by section
- **Perldoc** — Read Perl module documentation in-browser
- **Info Pages** — View GNU info documentation
- **Apropos Search** — Full-text search across man page summaries
- **TOC Sidebar** — Two-level floating table of contents for navigation
- **Markdown Output** — Append `/markdown` to any URL for machine-readable format
- **SEO Optimized** — Canonical URLs, meta description, robots directives
- **Clean URLs** — PATH_INFO routing: `/man/ls/1`

## Man / Info / Perldoc 三种模式对比

phpMan 支持三种 Unix 手册获取方式，分别对应不同的系统命令、数据源和文档格式规范。

### 一、man 模式

| 项目 | 说明 |
|------|------|
| **系统命令** | `man -Tascii <参数>` |
| **数据源位置** | `/usr/share/man/`, `/usr/local/share/man/` — 文件后缀 `.1.gz`, `.3pm.gz` 等 |
| **源格式** | **troff / groff**（AT&T 排版语言），原始内容含下覆盖打印序列（如 `W^HWA^HAR^HRN^H...`） |
| **规范** | **man-pages(7)** — 约定 9 个章节：1=用户命令, 2=系统调用, 3=C 库函数, 4=设备文件, 5=文件格式, 6=游戏, 7=杂项, 8=系统管理, 9=内核例程 |
| **内部结构** | 每页扁平文档，固定段包括 NAME、SYNOPSIS、DESCRIPTION、OPTIONS、EXAMPLES、SEE ALSO 等 |
| **子章节** | 支持二级子章节（`.SS` 宏 → 加粗/下划线），TOC 中完整展示 |

### 二、info 模式

| 项目 | 说明 |
|------|------|
| **系统命令** | `info <参数>` |
| **数据源位置** | `/usr/share/info/` — 文件后缀 `.info.gz`, `.info` |
| **源格式** | **Texinfo**（GNU 项目文档格式），原始内容带排版标记（`* Menu:`、节号 `4.1`、交叉引用 `(node)`） |
| **规范** | **Texinfo** → 可同时生成 PDF、HTML 和 info。节点（Node）是基本单位，通过 `(node)` 建立超文本文档树 |
| **内部结构** | 树状节点结构，可含子节点菜单，支持跳转导航 |
| **子章节** | info 的纯文本输出中只有节编号（`3.1`、`3.2`）和缩进，缺乏能识别的显式标题宏，因此 TOC 中**只显示一级** |

### 三、perldoc 模式

| 项目 | 说明 |
|------|------|
| **系统命令** | `perldoc <模块>` → `perldoc -f <函数>` → `perldoc -q <正则>`（三级降级） |
| **数据源位置** | Perl 安装路径下的 `.pod` 文件 |
| **源格式** | **POD**（Plain Old Documentation），Perl 文档格式，使用 `=head1`, `=head2`, `=over`, `=item` 等标记 |
| **规范** | **perlpod(1)** — `=head1` 对应大节，`=head2` 对应小节 |
| **内部结构** | 扁平文档，有明确的 `=head1` → `=head2` 层级 |
| **子章节** | 支持二级子章节（`=head2`），TOC 中完整展示 |

### 四、三者横评

| 维度 | man | info | perldoc |
|------|-----|------|---------|
| 所属阵营 | BSD / Unix 通用 | GNU 项目特有 | Perl 语言专用 |
| 源格式 | troff / groff | Texinfo | POD |
| 输出是否含 overstrike | ✅ 有 | ❌ 无 | ❌ 无（但有 ANSI 转义） |
| 二级标题 | `.SS` → 加粗/下划线 | 节编号 + 缩进 | `=head2` → 首字母大写 |
| TOC 层级 | ✅ 完整两级 | ❌ 仅一级 | ✅ 完整两级 |
| 链接能力 | 弱（仅交叉引用 `name(sec)`） | 强（节点树 `(node)` 跳转） | 弱（仅模块引用 `Module::Name`） |
| 典型内容 | 命令参考、系统调用、配置格式 | GNU 项目完整手册（含教程、概念） | Perl 模块 API 参考 |

> ℹ️ **关于 info 子章节：** info 模式目前无法生成二级 TOC，原因是 `info` 命令输出的纯文本中只有节编号（如 `3.1 Simple options`），没有 man 的 `.SS` 或 perldoc 的 `=head2` 那样的显式标题标记。如需支持，可扩展标题识别逻辑。

## Check Out Source Code

### HTTPS (read-only)

```bash
git clone https://git.code.sf.net/p/phpunixman/code phpman
```

### SSH (developers)

```bash
git clone ssh://chedong@git.code.sf.net/p/phpunixman/code.git phpman
```

## Quick Start

Deploy phpMan on any PHP 8.x server with a single file:

```bash
# Clone the repository
git clone https://git.code.sf.net/p/phpunixman/code phpman

# Copy to your web server's document root
cp phpman/phpMan.php /var/www/html/

# Access in browser
# https://your-server/phpMan.php
```

For Apache 2.x, ensure `AcceptPathInfo On` is configured to enable clean URL routing.

## Publish Updates

### 1. Commit and Push to SourceForge Git

```bash
git add phpMan.php
git commit -m "description of changes"
git push origin master
```

### 2. Update Live Demo (chedong.com)

```bash
scp phpMan.php chedong.com:~/chedong.com/phpMan.php
```

> ⚠️ Do **not** overwrite `index.php` — only update `phpMan.php`.

### 3. Update Static Site (SourceForge project web)

```bash
scp index.html chedong@web.sourceforge.net:/home/project-web/phpunixman/htdocs/index.html
```

The `index.html` is a static project introduction page with screenshot and a demo link pointing to `chedong.com/phpMan.php`.

### 4. Upload Release

Upload the compressed archive and README to SourceForge File Release System:

```bash
gzip -k -f phpMan.php
scp phpMan.php.gz README.md chedong@frs.sourceforge.net:/home/frs/project/phpunixman/
```

README.md will be rendered below the file listing on the Files page.
Or upload manually via: <https://sourceforge.net/projects/phpunixman/files/>

## License

GNU General Public License v2.0 — see [copyright page](https://www.chedong.com/phpMan.php/copyright).

## Author

Che Dong — <https://www.chedong.com/>
