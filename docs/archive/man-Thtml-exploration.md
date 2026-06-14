# -Thtml Mode Exploration

## Background

Currently phpMan uses `man -Tutf8` to get man page output, then passes it through custom overstrike cleaning logic
(`formatManPerlDoc` / `formatManPerlDocToMarkdown` / `formatToJSON`) to generate HTML, Markdown, and JSON output respectively.

`-Tutf8` produces ANSI SGR escape sequences (`ESC[1m...ESC[0m`) on most Linux servers, but falls back to traditional
overstrike format (`N^HNA^HAM^HME^HE`) on some man-db versions.

## -Thtml Approach

`man -Thtml` lets groff output HTML directly, bypassing all overstrike parsing.

### Test Results (chedong.com server, groff 1.22.4)

| Command | Lines | Sections | Output Quality |
|------|------|----------|---------|
| ls | 702 | 5 | ✅ |
| tset | 848 | 8 | ✅ |
| bash | 14015 | 23 | ✅ |
| grep | 1227 | 9 | ✅ |
| passwd(5) | - | 4 | ✅ |
| crontab(5) | - | 9 | ✅ |
| iptables(8) | - | 14 | ✅ |
| mount(8) | - | 18 | ✅ |

### Output Structure

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

<!-- Options use <table> -->
<table>
<tr><td><p><b>-c</b></p></td>
    <td><p>Set control characters and modes.</p></td></tr>
</table>

<!-- Lists use &bull; -->
<p>&bull;</p>
```

### Advantages

1. **Eliminates overstrike parsing** — no more `formatManPerlDoc`, `formatManPerlDocToMarkdown`,
   `formatToJSON` with `\x08` cleaning logic
2. **Single input, multiple outputs** — HTML is structured; parsing HTML is much cleaner than parsing overstrike:
   `<h2>` → section, `<b>` → option name, `<table>` → definition list
3. **Native groff handling** — bullets, smart quotes, tables all handled correctly by groff
4. **No more UTF-8 + `\x08` conflicts**

### Disadvantages & Challenges

1. **HTML 4.01 Transitional** — groff output DOCTYPE is not XHTML 1.0, requires post-processing
2. **Inline CSS** — `style="margin-left:11%"` etc. need overriding or stripping
3. **perldoc takes a different path** — perldoc doesn't use groff, needs separate handling (possibly `perldoc -o html`)
4. **Major refactoring** — requires new HTML parsing layer, replacing 3 existing parsing paths
5. **Performance** — roughly equal to `-Tutf8` (bash ~0.4s for both)

### Feasible Migration Path

1. **Phase 1**: Add `getManPageHtml()` function, calling `man -Thtml`
2. **Phase 2**: JSON/MCP endpoints switch to parsing HTML → structured JSON (prove feasibility)
3. **Phase 3**: HTML/Markdown endpoints switch to cleaning groff HTML (maintain XHTML compliance)
4. **Phase 4**: Remove old `formatManPerlDoc` / `formatManPerlDocToMarkdown` / `formatToJSON` overstrike cleaning

### Current Decision

Keep `-Tutf8` + `[ -~]` ASCII-safe fix approach. `-Thtml` recorded as a long-term refactoring direction.
