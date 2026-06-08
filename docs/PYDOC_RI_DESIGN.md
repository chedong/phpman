# pydoc3 / ri Document Format Parsing Design

> v2.3+: HTML/Markdown/JSON/MCP output for pydoc3 (Python 3) and ri (Ruby) CLI documentation,
> sharing the unified content pipeline with existing man/perldoc/info modes.

---

## 1. Architecture Overview

```
pydoc3 <module>  ─┐
                   ├──→ formatManPerlDoc($lines, "pydoc")    → HTML
                   ├──→ formatManPerlDocToMarkdown($lines)   → Markdown
                   ├──→ formatToJSON($lines, "", "pydoc")    → JSON
                   └──→ formatForOutput(json, "mcp")         → MCP

ri <Class#method> ─┐
                   ├──→ formatManPerlDoc($lines, "ri")       → HTML
                   ├──→ formatManPerlDocToMarkdown($lines)   → Markdown
                   ├──→ formatToJSON($lines, "", "ri")       → JSON
                   └──→ formatForOutput(json, "mcp")         → MCP
```

pydoc/ri reuse the existing `formatManPerlDoc()` / `formatToJSON()` / `formatManPerlDocToMarkdown()` pipeline, differentiated by `$mode` parameter. Code location: phpMan.php line 1717–1938.

---

## 2. URL Routing

| Route | Function | Handler |
|------|------|----------|
| `GET /pydoc/{module}/{format}` | Python module docs | `getPydocPage()` |
| `GET /pydoc/{format}` | Python module index | `getPydocIndex()` |
| `GET /ri/{Class#method}/{format}` | Ruby class/method docs | `getRiPage()` |
| `GET /ri/{format}` | Ruby class index | `getRiIndex()` |

### Search Cascade

Since v3.6, search always aggregates results from all three sources (no longer only cascaded when apropos is empty):

```
getSearchPage()                  → FTS5/apropos (man pages)
  + getPydocSearchPage()         → pydoc3 -k or FTS5 pydoc index
  + getRiSearchPage()            → ri command or FTS5 ri index
```

Search priority: FTS5 offline index > command-line search > FTS5 per-source search.

See [SEARCH_FTS5_DESIGN.md](SEARCH_FTS5_DESIGN.md).

### MCP Auto-Detection

`cli_help` selects document source by naming convention (line 1520–1559):

| Input feature | Document source | Example |
|----------|--------|------|
| Contains `::` | `getPerldocPage()` | `Digest::MD5` |
| Contains `#` | `getRiPage()` | `Array#map` |
| Contains `.` (no `::`) | `getPydocPage()` | `json.loads`, `os.path` |
| Other | `getManPage()` → pydoc fallback → ri fallback | `ls` |

---

## 3. pydoc3 Format Parsing

### 3.1 Raw Output Format

`pydoc3 <module>` outputs plain text (no overstrike/ANSI), typical structure:

```
Help on package json:

NAME
    json

DESCRIPTION
    JSON (JavaScript Object Notation) ...

CLASSES
    builtins.OSError(builtins.Exception)
        JSONDecodeError

    class JSONDecoder(builtins.object)
     |  Simple JSON decoder
     ...

    class JSONEncoder(builtins.object)
     ...

FUNCTIONS
    dump(obj, fp, ...)
        Serialize obj as JSON ...

    dumps(obj, ...)
        Serialize obj to JSON string ...
```

### 3.2 Section Heading Detection

pydoc output uses **ALL CAPS lines** as L1 section headings (`NAME`, `DESCRIPTION`, `CLASSES`, `FUNCTIONS`, `DATA`, etc.), matched by the existing `detectL1Heading()` function via ALL CAPS regex (line 386).

pydoc mode does **not** use the ri-specific RDoc marker detection (line 433–444), instead using the standard man/perldoc L1/L2 detection flow.

### 3.3 Class/Function Definition Detection (L2 Sub-sections)

pydoc has two dedicated L2 patterns, handled in `detectL2IndentedPatterns()` (line 363–373):

**Class definition** — `detectL2IndentedPatterns()` line 363:
```
    class Name(ParentClass)
```
- 4-space indent + `class` + class name + `(`
- Parentheses may contain HTML `<a>` links (parent classes are linked); stripped first with `preg_replace`
- Extracted as: `['level' => 2, 'text' => 'class Name']`

**Function/method definition** — line 369:
```
    funcName(args)
```
- 4-space indent + lowercase-starting identifier + `(`
- Excludes Python keywords
- Extracted as: `['level' => 2, 'text' => 'funcName']`

### 3.4 HTML Link Handling (mode="pydoc")

In `formatManPerlDoc()`, pydoc mode uses a specific link pattern (line 2332–2335):

```
pattern: /class (\w+)\((\w+(?:\.\w+)*)\)/
replace: class $1(<a href="/pydoc/$2">$2</a>)
```

Parent class references become clickable links: `class JSONDecodeError(ValueError)` → `class JSONDecodeError(<a href="/pydoc/ValueError">ValueError</a>)`.

### 3.5 Module Index Format

`getPydocIndex()` runs `pydoc3 modules`, which outputs multi-column text:

```
Please wait ... (calculating module list)

BaseHTTPServer      email               json                ...
Bastion             encodings           keyword             ...
...
Enter any module name ...
```

Parsing strategy (line 1742–1808):
1. Skip header before blank line
2. Stop at `Enter any module name`
3. Split multi-column layout on 2+ spaces via `preg_split('/\s{2,}/', ...)`
4. Deduplicate, sort
5. Output in requested format (HTML/Markdown/JSON/MCP)

### 3.6 Search Format

`getPydocSearchPage()` runs `pydoc3 -k <keyword>`, output format:

```
module_name - Description of the module
another_module - Another description
```

Parsing (line 1863–1922): regex `^(\S+)\s*-\s*(.+)` splits module name and description. Entries without descriptions are listed as plain module names. Results include `name`, `description`, `link` fields.

---

## 4. ri (Ruby RDoc) Format Parsing

### 4.1 Raw Output Format

`ri <Class#method>` outputs **overstrike format** (char^Hchar, same as man), typical structure:

```
= A\bAr\brr\bra\bay\by \b <\b< \b O\bOb\bbj\bje\bec\bct\bt

------------------------------------------------------------------------
= I\bIn\bnc\bcl\blu\bud\bde\bes\bs:\b:
Enumerable (from ruby core)

(from ruby core)
------------------------------------------------------------------------
Array indexing starts at 0...

= C\bCl\bla\bas\bss \b m\bme\bet\bth\bho\bod\bds\bs:\b:

  new
  try_convert

= I\bIn\bns\bst\bta\ban\bnc\bce\be \b m\bme\bet\bth\bho\bod\bds\bs:\b:

  %
  *
  []
  map
  ...
```

### 4.2 RDoc Marker Heading Detection (ri-Specific)

ri mode uses a **completely independent** heading detection logic, bypassing standard L1/L2 detection. In `detectHeadingType()`, when `$mode === "ri"`, RDoc marker detection is returned directly (line 433–444):

| Marker | Meaning | Detection Result |
|------|------|----------|
| `= Heading` | L1 section | `['level' => 1, 'text' => 'Heading']` |
| `== Subheading` | L2 sub-section | `['level' => 2, 'text' => 'Subheading']` |

Processing flow:
1. Strip HTML tags and markdown formatting markers (`**`, `_`)
2. Regex `/^= (.+)/` matches L1, `/^== (.+)/` matches L2
3. Filter out lines that are just `=` or `==` (separator line false positives)

**Design decision**: ri completely bypasses standard detection because:
- ri overstrike cleanup produces `= **Section** **Name**` format
- Standard `detectL2BoldSubheading` may misclassify it as L2
- RDoc markers `= / ==` are simple and reliable; no need to reuse complex logic

### 4.3 TOC Label Stripping

ri's RDoc `=` and `==` prefixes are stripped in TOC. In `buildToc()`:

```php
// L1 TOC: strip "= Heading" → "Heading" (line 1652-1653)
$label = preg_replace('/^=\s*/', '', $label);

// L2 TOC: strip "== Heading" → "Heading" (line 1663-1664)
$label = preg_replace('/^==\s*/', '', $label);
```

### 4.4 HTML Link Handling (mode="ri")

In `formatManPerlDoc()`, ri mode uses two link patterns (line 2336–2342):

**Parent class links** (shared with pydoc):
```
pattern: /class (\w+)\((\w+(?:\.\w+)*)\)/
replace: class $1(<a href="/ri/$2">$2</a>)
```

**Ruby constant/module references** (`::` notation):
```
pattern: /((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/
replace: $3<a href="/ri/$4">$4</a>$6
```

Effect:
- `SomeModule::SubClass` → `<a href="/ri/SomeModule::SubClass">SomeModule::SubClass</a>`
- Only replaces in whitespace/comma/HTML-tag-delimited contexts, avoiding false matches

### 4.5 Index Format

`getRiIndex()` runs `ri -l`, outputting one class/module name per line (plain text), no special parsing.

### 4.6 Search Strategy

ri has **no** native `ri -k` (keyword search), so `getRiSearchPage()` directly runs `ri <query>`. ri has built-in fuzzy matching: if no exact match, it tries partial matching.

First-line filter rules (no-result detection):
- `Nothing known about` — standard no-result response
- `.xxx not found` — ri special response for lowercase names (e.g., `ri json` returns `.json not found`)

When `ri` command yields no results, the search cascade falls back to FTS5 ri index. See [SEARCH_FTS5_DESIGN.md](SEARCH_FTS5_DESIGN.md).

---

## 5. Shared Content Pipeline

pydoc and ri content processing pipeline is **fully shared** with man/perldoc/info:

| Processing Stage | pydoc Specifics | ri Specifics |
|----------|-------------|-----------|
| `cleanTerminalOutput()` | No overstrike, passes through | Has overstrike, same as man |
| `detectHeadingType()` | Standard L1/L2 + pydoc class/func patterns | Dedicated RDoc marker mode (`=`/`==`) |
| `formatManPerlDoc()` | pydoc parent class link pattern | ri parent class + `::` constant links |
| `formatToJSON()` | Standard JSON structuring | Standard JSON structuring |
| `formatForOutput()` | Standard MCP wrapping | Standard MCP wrapping |
| `buildToc()` | `=`/`==` prefix stripping | `=`/`==` prefix stripping |
| `fetchOfficialTldr()` | No TLDR (not triggered for pydoc/ri) | No TLDR |

### cleanTerminalOutput()

Location: phpMan.php line 146–172. Converts overstrike and ANSI sequences in raw terminal output to markdown-style markers:

- `X^HX` → `**X**` (bold)
- `_^HX` → `_X_` (underline)
- `ESC[1m...ESC[0/22m` → `**...**` (ANSI bold)
- `ESC[4m...ESC[0/24m` → `_..._` (ANSI underline)

pydoc output contains none of these sequences, so the function passes through directly.

---

## 6. "Not Found" Handling

When pydoc/ri documentation is not found on the server, external search links are shown:

| Mode | External Link | URL |
|------|------|-----|
| man | cheat.sh | `https://cheat.sh/{command}` |
| perldoc | MetaCPAN | `https://metacpan.org/pod/{module}` |
| **pydoc** | Python Docs | `https://docs.python.org/3/search.html?q={module}` |
| **ri** | Ruby-Doc | `https://ruby-doc.org/search.html?q={class}` |
| info | Google | `https://www.google.com/search?q={topic}` |

Code: phpMan.php line 1269–1288.

---

## 7. Key Design Decisions

### 7.1 Why ri Uses Independent Heading Detection

- ri's RDoc markers (`= Section`, `== Subsection`) are simple and clear; no need to reuse man's complex L1/L2 heuristics
- Overstrike cleanup produces `= **Bold** **Text**` format that standard L2 bold detection may misclassify
- Isolated logic: future RDoc format changes only affect ri mode, not polluting other modes

### 7.2 Why pydoc Reuses Standard Detection

- pydoc output is plain-text ALL CAPS sections, compatible with perldoc's `=head1` format
- Class/function definitions are handled as L2 sub-sections via extended `detectL2IndentedPatterns`
- No need for an independent detection path, reducing maintenance cost

### 7.3 No Overstrike ≠ No cleanTerminalOutput

pydoc output has no overstrike/ANSI; `cleanTerminalOutput()` is a pass-through. But the unified pipeline ensures format consistency if pydoc output format changes in the future.

---

## 8. Code Location Index

| Feature | File | Line |
|------|------|------|
| URL routing dispatch | phpMan.php | 843–866 |
| MCP auto-detection | phpMan.php | 1520–1559 |
| `getPydocPage()` | phpMan.php | 1717–1727 |
| `getRiPage()` | phpMan.php | 1729–1739 |
| `getPydocIndex()` | phpMan.php | 1741–1808 |
| `getRiIndex()` | phpMan.php | 1810–1860 |
| `getPydocSearchPage()` | phpMan.php | 1862–1922 |
| `getRiSearchPage()` | phpMan.php | 1924–1938 |
| ri heading detection | phpMan.php | 433–444 |
| pydoc class/func detection | phpMan.php | 363–373 |
| mode-specific link patterns | phpMan.php | 2331–2353 |
| TOC label stripping (=/==) | phpMan.php | 1652, 1663 |
| Not found external links | phpMan.php | 1272–1288 |
| `cleanTerminalOutput()` | phpMan.php | 146–172 |
| `detectHeadingType()` | phpMan.php | 429–461 |
| `formatManPerlDoc()` | phpMan.php | 2285–2393 |
| `formatToJSON()` | phpMan.php | 3100–3338 |
| `formatForOutput()` | phpMan.php | 2398–2415 |
