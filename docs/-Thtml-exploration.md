# -Thtml 模式探索

## 背景

当前 phpMan 使用 `man -Tutf8` 获取 man page 输出，再通过自定义的 overstrike 清洗逻辑
（`formatManPerlDoc` / `formatManPerlDocToMarkdown` / `formatToJSON`）分别生成 HTML、
Markdown 和 JSON 输出。

`-Tutf8` 在多数 Linux 服务器上产生 ANSI SGR 转义序列（`ESC[1m...ESC[0m`），但在某些
man-db 版本上会退化为传统 overstrike 格式（`N^HNA^HAM^HME^HE`）。

## -Thtml 方案

`man -Thtml` 直接让 groff 输出 HTML，绕过所有 overstrike 解析。

### 测试结果（chedong.com 服务器，groff 1.22.4）

| 命令 | 行数 | Sections | 输出质量 |
|------|------|----------|---------|
| ls | 702 | 5 | ✅ |
| tset | 848 | 8 | ✅ |
| bash | 14015 | 23 | ✅ |
| grep | 1227 | 9 | ✅ |
| passwd(5) | - | 4 | ✅ |
| crontab(5) | - | 9 | ✅ |
| iptables(8) | - | 14 | ✅ |
| mount(8) | - | 18 | ✅ |

### 输出结构

```html
<!-- Creator     : groff version 1.22.4 -->
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" ...>
<html>
<head><title>tset</title></head>
<body>
<h1 align="center">tset</h1>

<!-- TOC -->
<a href="#NAME">NAME</a><br>
<a href="#SYNOPSIS">SYNOPSIS</a><br>

<hr>

<h2>NAME<a name="NAME"></a></h2>
<p><b>tset</b>, <b>reset</b> - terminal initialization</p>

<h2>DESCRIPTION<a name="DESCRIPTION"></a></h2>
<p><b>tset - initialization</b><br>This program initializes terminals.</p>

<!-- 选项用 <table> -->
<table>
<tr><td><p><b>-c</b></p></td>
    <td><p>Set control characters and modes.</p></td></tr>
</table>

<!-- 列表用 &bull; -->
<p>&bull;</p>
```

### 优点

1. **消除 overstrike 解析** — 不再需要 `formatManPerlDoc`、`formatManPerlDocToMarkdown`、
   `formatToJSON` 中的 `\x08` 清洗逻辑
2. **一份输入，多种输出** — HTML 是结构化的，解析 HTML 比解析 overstrike 干净得多：
   `<h2>` → section，`<b>` → option name，`<table>` → definition list
3. **groff 原生处理** — bullet、smart quotes、表格等格式都由 groff 正确处理
4. **不再有 UTF-8 + `\x08` 冲突**

### 缺点与挑战

1. **HTML 4.01 Transitional** — groff 输出的 DOCTYPE 不是 XHTML 1.0，需要后处理
2. **inline CSS** — `style="margin-left:11%"` 等内联样式需要覆盖或剥离
3. **perldoc 不同路** — perldoc 不使用 groff，需单独处理（可能有 `perldoc -o html`）
4. **重构量大** — 需要新的 HTML 解析层，替换现有 3 条解析路径
5. **性能** — 与 `-Tutf8` 基本持平（bash 均 ~0.4s）

### 可行迁移路径

1. **Phase 1**：新增 `getManPageHtml()` 函数，调用 `man -Thtml`
2. **Phase 2**：JSON/MCP 端点改为解析 HTML → 结构化 JSON（验证可行性）
3. **Phase 3**：HTML/Markdown 端点改为清洗 groff HTML（保持 XHTML 合规）
4. **Phase 4**：删除旧的 `formatManPerlDoc` / `formatManPerlDocToMarkdown` / `formatToJSON` 中的 overstrike 清洗

### 当前决策

保持 `-Tutf8` + `[ -~]` ASCII-safe 修复方案。`-Thtml` 作为长期重构方向记录。
