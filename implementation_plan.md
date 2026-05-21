# Implementation Plan: Modernize phpMan for PHP 8.5 and LLM Agents

This plan outlines the modernization of the `phpMan` application to support PHP 8.5, the addition of a Markdown API endpoint specifically designed for LLMs and AI Agents, and the setup of a robust local development environment.

## User Review Required

> [!IMPORTANT]
> **API Design for Markdown Output**:
> We propose two ways to trigger Markdown output:
> 1. A query parameter `?format=markdown` (e.g. `phpMan.php/man/gcc/1?format=markdown`).
> 2. Content negotiation via the `Accept: text/markdown` or `Accept: text/x-markdown` HTTP headers.
>
> When Markdown is requested:
> - The output is served with `Content-Type: text/markdown; charset=UTF-8`.
> - The HTML layout, search form, CSS styling, page headers/footers, and GPL copyright text are completely omitted, returning only the clean markdown content.
> - Man page hyperlinks (like `tar(1)`) are formatted as Markdown links: `[tar(1)](/phpMan.php/man/tar/1?format=markdown)`.

## Open Questions

> [!NOTE]
> 1. **URL Scheme in Markdown Links**: Should Markdown links use relative paths (e.g., `/phpMan.php/man/tar/1?format=markdown`), or absolute URLs? Since LLMs usually request URLs directly, relative paths based on the current request domain are usually sufficient and standard.
> 2. **Local system capabilities**: Does the local development machine (macOS) have standard commands like `man`, `info`, `perldoc` and `apropos` installed? On macOS, `man` and `apropos` are built-in, but `info` and `perldoc` might require installation via brew (`brew install texinfo perl`).

## Proposed Changes

### Core Logic & API

#### [MODIFY] [phpMan.php](file:///Users/chedong/CodeWork/phpunixman-git/phpMan.php)
1. **PHP 8.5 Compatibility Modernization**:
   - Add parameter type declarations and return type declarations to all functions (e.g. `formatManPerlDoc`, `getManPage`, `showForm`, etc.).
   - Replace outdated backreferences like `\\1` in `preg_replace` with `$1` or `${1}` to adhere to modern PHP standards.
   - Replace `show_source` with the standard `highlight_file` function.
   - Eliminate any potential warnings or deprecations in PHP 8.x/8.5.
2. **Markdown Format Detection**:
   - Check if `format=markdown` query parameter is present or if the `Accept` header contains `text/markdown` / `text/x-markdown`.
   - Store this preference in a `$format` variable (default `"html"`).
3. **Markdown Formatter**:
   - Add a `formatManPerlDocToMarkdown` function to convert raw man/perldoc/info outputs containing backspace sequences (`?^H?` or ANSI escape codes) into clean markdown text:
     - Headers: Convert section headers (e.g., `NAME`, `SYNOPSIS`) into `# NAME`, `## SYNOPSIS`.
     - Bold: Convert bold text to `**text**`.
     - Underline/Italics: Convert underlined text to `*text*` or `_text_`.
     - Links: Convert references to other man pages or perl modules into Markdown links pointing back to phpMan's markdown endpoint (e.g. `[tar(1)](/phpMan.php/man/tar/1?format=markdown)`).
     - Emails & URLs: Convert to standard markdown links.
4. **Conditional Output Render**:
   - If format is `"markdown"`, skip calling `showHeader`, `showForm`, and `showFooter`. Output the raw markdown content directly with the `text/markdown; charset=UTF-8` content type header and exit.

## Verification & Local Development Plan

### Local Development Setup
1. **PHP Built-in Server**:
   To run phpMan locally, we can start the PHP built-in web server with `phpMan.php` as a router:
   ```bash
   php -S 127.0.0.1:8080 phpMan.php
   ```
   This routes all incoming requests (e.g. `http://127.0.0.1:8080/man/gcc/1`) through `phpMan.php`, correctly populating `$_SERVER['PATH_INFO']` as `/man/gcc/1`.

2. **Required Tools**:
   Check if `man`, `apropos` are working locally on the macOS host.

### Automated/Manual Verification
- Run a curl command to verify the HTML output:
  ```bash
  curl -i http://127.0.0.1:8080/man/ls/1
  ```
- Run a curl command to verify the Markdown output:
  ```bash
  curl -i "http://127.0.0.1:8080/man/ls/1?format=markdown"
  ```
- Run a curl command with the `Accept` header to verify content negotiation:
  ```bash
  curl -i -H "Accept: text/markdown" http://127.0.0.1:8080/man/ls/1
  ```
