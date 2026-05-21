# Walkthrough: Modernizing phpMan for PHP 8.5 and AI Agents

We have modernized `phpMan.php` to ensure seamless compatibility with modern PHP versions up to **PHP 8.5** and introduced a clean **Markdown format API** specifically targeted at LLM spiders and AI agents.

## Changes Made

### 1. PHP 8.5 Compatibility Modernization
- **Strict Typing & Type Hints**: Added strict type hints to helper functions (`mixed` and specific scalar/array type declarations) and return types (e.g. `void`, `string`) for all function signatures.
- **Modernized Regex Backreferences**: Replaced deprecated regex replacement backreferences (e.g., `\\1`, `\\2`) with the modern `$1`, `$2` or single quotes syntax.
- **Safer Aliases**: Replaced the alias `show_source` with the standard `highlight_file`.
- **Warning Mitigation**: Removed the closing `?>` PHP tag at the end of the file to prevent trailing output whitespace, and ensured all arrays/variables are initialized to avoid undefined variable warnings in strict error reporting modes.

### 2. Markdown Web API with /MODE/COMMAND/SECTION/FORMAT Routing
We added route format parsing and a new markdown output pipeline following the structure:
`phpMan.php/MODE/COMMAND/SECTION/FORMAT`

- **Flexible Routing Rules**:
  - **MODE**: `man`, `perldoc`, `info` (if not provided, defaults to `man`). If the first segment is not in the list of allowed modes, it is intelligently treated as the `COMMAND` segment and `MODE` defaults to `man` (e.g. `/gcc` is parsed as `MODE=man`, `COMMAND=gcc`).
  - **COMMAND**: The command name, function name, etc. Must be provided, otherwise the user is directed to the home/index page.
  - **SECTION**: Unix section number (e.g., `1` through `9`). If not provided, defaults to `1` when `MODE` is `man` and a command is requested.
  - **FORMAT**: `html` or `markdown` (defaults to `html` or `Accept` header preference).
- **Format Detection**:
  - Automatically identifies markdown requests via route format: `/man/gcc/1/markdown`.
  - Also fallback-compatible with query parameters: `?parameter=perlintro&mode=perldoc&format=markdown`.
  - Performs HTTP Content Negotiation using the `Accept: text/markdown` or `Accept: text/x-markdown` headers.
- **Markdown Formatter (`formatManPerlDocToMarkdown`)**:
  - Sanitizes Unix backspace sequences (`?^H?` etc.) and perldoc ANSI escape sequences using safe intermediate delimiters (`\x01` through `\x04`), merging consecutive bold (`**`) and italics/underline (`_`) tags without affecting raw double-underscores (like `__init__`).
  - Converts main UNIX section titles (e.g. `NAME`, `SYNOPSIS`, `DESCRIPTION`) into Markdown headers (`## NAME`).
  - Linkifies emails and URLs into standard Markdown links.
  - Linkifies manpage cross-references (e.g. `tar(1)`) and Perl modules (e.g. `File::Path`) into active Markdown links that request the markdown format recursively: `[tar(1)](/phpMan.php/man/tar/1/markdown)`.
- **Short-circuit Render**: Direct raw response of `text/markdown` content, bypassing all HTML layout, styling, and search form rendering.

---

## Verification and Usage Examples

### 1. Local Development Server
To start the local developer server, run:
```bash
php -S 127.0.0.1:8080 phpMan.php
```

### 2. Requesting HTML format (Browser/Default)
- **Direct Link**: `http://127.0.0.1:8080/man/gcc/1` or `http://127.0.0.1:8080/man/gcc/1/html`
- **Default Section**: `http://127.0.0.1:8080/man/gcc` (resolves to section 1)
- **Omitted Mode**: `http://127.0.0.1:8080/gcc/1` (resolves to mode man, command gcc, section 1)

### 3. Requesting Markdown format (Agents/Spiders)
- **Direct Route**:
  ```bash
  curl -i "http://127.0.0.1:8080/man/gcc/1/markdown"
  ```
- **Omitted Mode and Section**:
  ```bash
  curl -i "http://127.0.0.1:8080/gcc/markdown"
  ```
- **Query Parameter Fallback**:
  ```bash
  curl -i "http://127.0.0.1:8080?parameter=perlintro&mode=perldoc&format=markdown"
  ```
- **Content Negotiation (Accept Header)**:
  ```bash
  curl -i -H "Accept: text/markdown" "http://127.0.0.1:8080/man/gcc/1"
  ```
