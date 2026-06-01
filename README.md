# phpMan — Unix Man Page / Perldoc / Info Page Web Interface & MCP Server

phpMan is an open-source Linux Command MCP Server and Structured JSON API web interface with HTML and markdown format. It provides comprehensive Unix/Linux man pages, perldoc and texinfo, optimized for human developers and LLM AI Agents.

**For AI Agents:** Query Unix documentation via MCP protocol or REST API. Get structured man pages with parsed flags, examples, and cross-references.

## Quick Start for Agents

phpMan implements [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) via **Streamable HTTP** transport — no local installation or `npx` wrapper needed. Just point your MCP client at the endpoint URL.

### Claude Desktop

Edit `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "phpman": {
      "url": "https://www.chedong.com/phpMan.php/mcp"
    }
  }
}
```

### Cursor

Open **Settings → MCP** and click **Add new MCP server**, or edit `.cursor/mcp.json` in your project root:

```json
{
  "mcpServers": {
    "phpman": {
      "url": "https://www.chedong.com/phpMan.php/mcp"
    }
  }
}
```

### OpenAI Codex CLI

Add to `~/.codex/config.json`:

```json
{
  "mcp_servers": {
    "phpman": {
      "url": "https://www.chedong.com/phpMan.php/mcp"
    }
  }
}
```

### Claude Code

```bash
claude mcp add --transport http phpman https://www.chedong.com/phpMan.php/mcp
```

### Generic MCP Client (YAML)

Any MCP-compatible client that accepts YAML config:

```yaml
mcpServers:
  phpman:
    url: "https://www.chedong.com/phpMan.php/mcp"
```

### REST API (Fallback)

For clients that don't support MCP, use the REST endpoints directly:

```bash
# Get structured man page as JSON
curl "https://www.chedong.com/phpMan.php/man/ls/1/json"

# Get MCP-wrapped output (same format as MCP tools/call response)
curl "https://www.chedong.com/phpMan.php/man/ls/1/mcp"
```

---

## Configuration

phpMan uses environment variables for configuration. All variables have sensible defaults and are optional except `LLM_API_KEY` (required for TLDR generation).

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `LLM_API_URL` | _(empty)_ | OpenAI-compatible API endpoint (e.g. `https://api.openai.com/v1/chat/completions`) |
| `LLM_API_KEY` | _(empty)_ | API key for LLM provider. **Required** for `/tldr` endpoint |
| `LLM_MODEL` | `gpt-4o-mini` | Model name (e.g. `gpt-4o`, `claude-3-haiku`, `qwen-max`) |
| `LLM_TIMEOUT` | `15` | LLM request timeout in seconds |
| `TLDR_CACHE_DIR` | `./tldr_cache` | Cache directory for generated TLDR pages |
| `TLDR_CACHE_TTL` | `604800` | Cache TTL in seconds (default: 7 days) |

### Server Configuration

**Apache (.htaccess or VirtualHost):**
```apache
SetEnv LLM_API_KEY sk-ant-xxxxx
SetEnv LLM_MODEL gpt-4o-mini
SetEnv TLDR_CACHE_DIR /var/cache/phpman
```

**Nginx (server block):**
```nginx
location ~ \.php$ {
    fastcgi_param LLM_API_KEY sk-ant-xxxxx;
    fastcgi_param LLM_MODEL gpt-4o-mini;
    fastcgi_param TLDR_CACHE_DIR /var/cache/phpman;
    # ... other fastcgi params
}
```

**PHP-FPM (pool configuration):**
```ini
env[LLM_API_KEY] = sk-ant-xxxxx
env[LLM_MODEL] = gpt-4o-mini
env[TLDR_CACHE_DIR] = /var/cache/phpman
```

### Security Notes

- **Never commit API keys to git.** Use environment variables or `.env` files (excluded from version control).
- **Cache directory permissions:** Ensure `TLDR_CACHE_DIR` is writable by the web server user (e.g. `www-data`).
- **Cache security:** The `tldr_cache/.htaccess` file denies direct web access to cached files.

---

## Project Home

- **GitHub:** <https://github.com/chedong/phpman>
- **Live Demo:** <https://www.chedong.com/phpMan.php>
- **Archived at SourceForge:** <https://sourceforge.net/projects/phpunixman>

> **Development has moved to GitHub.** The SourceForge repository is frozen at v2.1 and will not receive further updates.

## Screenshot

![phpMan: perldoc page with TOC sidebar](https://a.fsdn.com/con/app/proj/phpunixman/screenshots/%E4%BC%81%E4%B8%9A%E5%BE%AE%E4%BF%A120260525-161915%402x-8c442be2.png/750/400)

---

## MCP Server Protocol

phpMan implements the [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) specification version `2024-11-05` via Streamable HTTP transport.

### Endpoint

```
POST https://www.chedong.com/phpMan.php/mcp
Content-Type: application/json
```

### Available Tools

#### 1. `cli_help` — Get Command Documentation

Returns structured documentation for any Unix command, Perl module, or GNU info page.

**Input Schema:**
```json
{
  "command": "string (required) — Command name (e.g. 'ls', 'git', 'File::Basename')",
  "section": "string (optional) — Manual section (e.g. '1', '3pm'). Omit for best-match."
}
```

**Returns:**
- `content[0].text` — Full JSON response as string (for LLM parsing)
- `structuredContent` — Programmatic access to flags, examples, synopsis, and cross-references

**Example:**
```bash
curl -X POST "https://www.chedong.com/phpMan.php/mcp" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/call",
    "params": {
      "name": "cli_help",
      "arguments": {"command": "tar", "section": "1"}
    }
  }'
```

**Auto-detection:**
- Commands containing `::` or section `3pm`/`3perl` → `perldoc` mode
- Other commands → `man` mode

#### 2. `cli_search` — Search Man Pages

Search across all man page names and descriptions using `apropos`.

**Input Schema:**
```json
{
  "query": "string (required) — Search keyword (e.g. 'recursive delete', 'network', 'cron')",
  "section": "string (optional) — Restrict to manual section (e.g. '1', '8')"
}
```

**Returns:**
- `content[0].text` — JSON with search results
- `structuredContent` — Programmatic access to results array

**Example:**
```bash
curl -X POST "https://www.chedong.com/phpMan.php/mcp" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/call",
    "params": {
      "name": "cli_search",
      "arguments": {"query": "cron"}
    }
  }'
```

### MCP Protocol Flow

**1. Initialize** (handshake):
```json
{"jsonrpc":"2.0", "id":1, "method":"initialize", "params":{"protocolVersion":"2024-11-05"}}
```
Response includes `serverInfo.name = "phpMan"`, `capabilities.tools.listChanged = false`

**2. List Tools**:
```json
{"jsonrpc":"2.0", "id":2, "method":"tools/list"}
```
Returns the two tools above with their `inputSchema`

**3. Call Tool**:
```json
{"jsonrpc":"2.0", "id":3, "method":"tools/call", "params":{"name":"cli_help", "arguments":{...}}}
```

**4. Notifications** (optional):
```json
{"jsonrpc":"2.0", "method":"notifications/initialized"}
```
Server returns HTTP 202 (no-op)

### Error Handling

MCP errors follow JSON-RPC 2.0:

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "error": {
    "code": -32603,
    "message": "Internal error: Unknown tool: nonexistent"
  }
}
```

**Common error codes:**
- `-32700` — Parse error (invalid JSON)
- `-32600` — Invalid request (missing method)
- `-32601` — Method not found
- `-32602` — Invalid params
- `-32603` — Internal error (tool execution failed)

---

## JSON Schema Reference

Both MCP `structuredContent` and REST `/json` endpoints return the same schema.

### Man Page Response

```json
{
  "mode": "man",
  "parameter": "tar",
  "section": "1",
  "url": "https://www.chedong.com/phpMan.php/man/tar/1/json",
  "generated": "2026-01-15T10:30:00Z",
  
  "synopsis": "tar [OPTION...] [FILE]...",
  "summary": "tar - An archiving utility",
  
  "sections": {
    "NAME": {
      "content": "tar - An archiving utility",
      "subsections": []
    },
    "SYNOPSIS": {
      "content": "tar [OPTION...] [FILE]...",
      "subsections": []
    },
    "DESCRIPTION": {
      "content": "The GNU tar program...",
      "subsections": []
    },
    "OPTIONS": {
      "content": "",
      "subsections": [
        {
          "name": "-c, --create",
          "content": "Create a new archive",
          "flag": "-c",
          "long": "--create",
          "arg": null
        },
        {
          "name": "-f, --file=ARCHIVE",
          "content": "Use archive file or device ARCHIVE",
          "flag": "-f",
          "long": "--file",
          "arg": "ARCHIVE"
        }
      ]
    },
    "EXAMPLES": {
      "content": "tar -cvf archive.tar file1 file2\ntar -xvf archive.tar",
      "subsections": []
    }
  },
  
  "flags": [
    {
      "flag": "-c",
      "long": "--create",
      "arg": null,
      "description": "Create a new archive"
    },
    {
      "flag": "-f",
      "long": "--file",
      "arg": "ARCHIVE",
      "description": "Use archive file or device ARCHIVE"
    }
  ],
  
  "examples": [
    "tar -cvf archive.tar file1 file2",
    "tar -xvf archive.tar"
  ],
  
  "see_also": [
    {
      "name": "gzip",
      "section": "1",
      "url": "https://www.chedong.com/phpMan.php/man/gzip/1/json"
    }
  ]
}
```

### Field Descriptions

| Field | Type | Description |
|-------|------|-------------|
| `mode` | string | `"man"`, `"perldoc"`, `"info"`, or `"search"` |
| `parameter` | string | Command or module name |
| `section` | string | Manual section number (e.g. `"1"`, `"3pm"`) |
| `url` | string | Canonical JSON API URL for this page |
| `generated` | string | ISO 8601 timestamp (UTC) |
| `synopsis` | string | Command synopsis (extracted from SYNOPSIS section) |
| `summary` | string | One-line description (extracted from NAME section) |
| `sections` | object | Map of section names → section objects |
| `sections[name].content` | string | Section body text (newline-separated) |
| `sections[name].subsections` | array | Level-2 headings within section |
| `subsections[].name` | string | Subsection heading (e.g. `"-c, --create"`) |
| `subsections[].content` | string | Subsection body text |
| `subsections[].flag` | string\|null | Short flag (e.g. `"-c"`) — only for option subsections |
| `subsections[].long` | string\|null | Long flag (e.g. `"--create"`) — only for option subsections |
| `subsections[].arg` | string\|null | Argument placeholder (e.g. `"ARCHIVE"`) — only for option subsections |
| `flags` | array | Extracted command-line flags with descriptions |
| `flags[].flag` | string | Short flag (e.g. `"-c"`) |
| `flags[].long` | string\|null | Long flag (e.g. `"--create"`) |
| `flags[].arg` | string\|null | Argument placeholder |
| `flags[].description` | string | Flag description (single line) |
| `examples` | array | Command usage examples (from EXAMPLES section) |
| `see_also` | array | Cross-references to related man pages |
| `see_also[].name` | string | Related command name |
| `see_also[].section` | string | Related command section |
| `see_also[].url` | string | JSON API URL for related command |

### Search Response

```json
{
  "mode": "search",
  "query": "cron",
  "count": 5,
  "results": [
    {
      "name": "cron",
      "section": "8",
      "description": "daemon to execute scheduled commands"
    },
    {
      "name": "crontab",
      "section": "1",
      "description": "maintain crontab files for individual users"
    }
  ]
}
```

### MCP Wrapper Format

When using `/mcp` endpoint or MCP POST, the response is wrapped:

```json
{
  "content": [
    {
      "type": "text",
      "text": "<full JSON response as string>"
    }
  ],
  "structuredContent": {
    "command": "tar",
    "section": "1",
    "mode": "man",
    "summary": "tar - An archiving utility",
    "synopsis": "tar [OPTION...] [FILE]...",
    "flags": [...],
    "examples": [...],
    "see_also": [...],
    "section_outline": [
      {
        "name": "NAME",
        "lines": 1,
        "subsections": []
      },
      {
        "name": "OPTIONS",
        "lines": 45,
        "subsections": [
          {"name": "-c, --create", "lines": 3, "flag": "-c", "long": "--create"},
          {"name": "-f, --file=ARCHIVE", "lines": 5, "flag": "-f", "long": "--file", "arg": "ARCHIVE"}
        ]
      }
    ]
  }
}
```

The `content[0].text` field contains the full JSON response as a string (for LLM consumption). The `structuredContent` field provides programmatic access to key metadata (for agent tooling).

---

## REST API Endpoints

For clients that don't support MCP, phpMan exposes REST endpoints with identical structured output.

### JSON API

Append `/json` to any detail page URL, or send `Accept: application/json` header:

```bash
# Man page with structured sections
curl "https://www.chedong.com/phpMan.php/man/ls/1/json"

# Apropos search results
curl "https://www.chedong.com/phpMan.php/search/git/json"

# Accept header (works on any URL)
curl -H "Accept: application/json" "https://www.chedong.com/phpMan.php/man/bash"
```

### MCP Format (REST GET)

The `/mcp` format suffix wraps JSON output in MCP's `content` array — making REST GET and MCP POST responses identical:

```bash
# Same man page, same output format as MCP POST tools/call
curl "https://www.chedong.com/phpMan.php/man/ls/1/mcp"
# → {"content":[{"type":"text","text":"..."}],"structuredContent":{...}}

# Search with MCP format
curl "https://www.chedong.com/phpMan.php/search/cron/mcp"

# Perldoc with MCP format
curl "https://www.chedong.com/phpMan.php/perldoc/Digest::MD5/mcp"
```

This means any MCP client can `GET /man/ls/1/mcp` and parse the result identically to `POST /mcp` `tools/call`.

### TLDR Endpoint

Generate cheatsheet-style summaries from man pages:

```bash
curl "https://www.chedong.com/phpMan.php/tldr/tar"
```

Returns Markdown with:
- Command summary
- 5-8 practical examples
- Common flags with descriptions
- Auto-generated `--help` and `--version` examples

---

## What's New in v2.1

### Cross-Platform Width Control

- **perldoc**: Uses `pod2text -w N` pipeline for consistent output width on Linux and macOS
- **man (macOS/BSD)**: `MANWIDTH` fallback when `groff -Tutf8` is unavailable
- **man (Linux)**: `MANROFFOPT=-rLL=Nn` + `groff -Tutf8` for precise width

### Roadmap

See [docs/PLAN.md](docs/PLAN.md) for the full project plan:

- **pydoc / ri** — Python and Ruby documentation support (pydoc3 / ri)
- **LLM-powered** — AI translation (identifier-preserving), cheat sheets, example generation
- **Search** — Alphabetic sidebar navigation for large index pages (>150 entries)
- **MCP** — Streaming output, error standardization, dynamic tool discovery
- **I18N** — LANG-based locale support + AI fallback translation

---

## Features

- **Man Pages** — Browse any Unix/Linux manual page with `-Tutf8` output (SGR bold/underline support)
- **Perldoc** — Read Perl module documentation in-browser
- **Info Pages** — View GNU info documentation
- **Apropos Search** — Full-text search across man page summaries
- **TOC Sidebar** — Two-level floating table of contents for navigation
- **Markdown Output** — Append `/markdown` for machine-readable format
- **JSON API** — Append `/json` for structured JSON output with semantic fields
- **MCP Format** — Append `/mcp` for MCP-compatible output
- **MCP Server** — Model Context Protocol endpoint for AI agent integration
- **TLDR Endpoint** — Append `/tldr` for cheatsheet-style summaries
- **SEO Optimized** — Canonical URLs, meta description, robots directives
- **Clean URLs** — PATH_INFO routing: `/man/ls/1`

## Comparison: man / Info / Perldoc Modes

phpMan supports three Unix documentation retrieval methods, each corresponding to different system commands, data sources, and documentation format specifications.

### 1. man Mode

| Item | Description |
|------|-------------|
| **System Command** | `man -Tutf8 <argument>` |
| **Data Source** | `/usr/share/man/`, `/usr/local/share/man/` — files with `.1.gz`, `.3pm.gz` etc. |
| **Source Format** | **troff / groff** (AT&T typesetting language), original content contains overstrike sequences (e.g., `W^HWA^HAR^HRN^H...`) |
| **Standard** | **man-pages(7)** — 9 sections: 1=user commands, 2=system calls, 3=C library functions, 4=device files, 5=file formats, 6=games, 7=miscellaneous, 8=system administration, 9=kernel routines |
| **Internal Structure** | Flat document per page, fixed sections include NAME, SYNOPSIS, DESCRIPTION, OPTIONS, EXAMPLES, SEE ALSO, etc. |
| **Subsections** | Supports second-level subsections (`.SS` macro → bold/underline), fully displayed in TOC |

### 2. info Mode

| Item | Description |
|------|-------------|
| **System Command** | `info <argument>` |
| **Data Source** | `/usr/share/info/` — files with `.info.gz`, `.info` |
| **Source Format** | **Texinfo** (GNU documentation format), original content includes typesetting markers (`* Menu:`, section numbers `4.1`, cross-references `(node)`) |
| **Standard** | **Texinfo** → can generate PDF, HTML, and info. Node is the basic unit, with hypertext links via `(node)` forming a documentation tree |
| **Internal Structure** | Tree-like node structure, can contain submenu nodes, supports jump navigation |
| **Subsections** | Plain text output from `info` has only section numbers (`3.1`, `3.2`) and indentation, no identifiable explicit heading macros, so TOC shows **only first level** |

### 3. perldoc Mode

| Item | Description |
|------|-------------|
| **System Command** | `perldoc <module>` → `perldoc -f <function>` → `perldoc -q <regex>` (three-level fallback) |
| **Data Source** | `.pod` files in Perl installation paths |
| **Source Format** | **POD** (Plain Old Documentation), Perl documentation format, uses `=head1`, `=head2`, `=over`, `=item` markers |
| **Standard** | **perlpod(1)** — `=head1` for major sections, `=head2` for subsections |
| **Internal Structure** | Flat document with clear `=head1` → `=head2` hierarchy |
| **Subsections** | Supports second-level subsections (`=head2`), fully displayed in TOC |

### 4. Cross-Comparison

| Dimension | man | info | perldoc |
|-----------|-----|------|---------|
| Ecosystem | BSD / Unix general | GNU project specific | Perl language specific |
| Source Format | troff / groff | Texinfo | POD |
| Overstrike Output | ✅ Yes | ❌ No | ❌ No (but has ANSI escapes) |
| Second-level Headings | `.SS` → bold/underline | Section number + indent | `=head2` → Title Case |
| TOC Depth | ✅ Full two levels | ❌ First level only | ✅ Full two levels |
| Linking Capability | Weak (cross-reference `name(sec)`) | Strong (node tree `(node)` navigation) | Weak (module reference `Module::Name`) |
| Typical Content | Command references, syscalls, config formats | GNU project complete manuals (tutorials, concepts) | Perl module API references |

> ℹ️ **About info Subsections:** info mode currently cannot generate a second-level TOC because `info` plain text output only has section numbers (e.g., `3.1 Simple options`), lacking explicit heading markers like man's `.SS` or perldoc's `=head2`. Support can be added by extending the heading recognition logic.

## Check Out Source Code

### HTTPS (read-only)

```bash
git clone https://github.com/chedong/phpman.git
```

### SSH (developers)

```bash
git clone git@github.com:chedong/phpman.git
```

## Quick Start

Deploy phpMan on any PHP 8.x server with a single file:

```bash
# Clone the repository
git clone https://github.com/chedong/phpman.git

# Copy to your web server's document root
cp phpman/phpMan.php /var/www/html/

# Access in browser
# https://your-server/phpMan.php
```

For Apache 2.x, ensure `AcceptPathInfo On` is configured to enable clean URL routing.

## Publish Updates

The repository includes a generic `Makefile` for local checks, staging deployment,
production deployment, and release upload. Site-specific values are loaded from
`.deploy.mk`, which is intentionally ignored by git.

Create your local deployment config from the example:

```bash
cp .deploy.mk.example .deploy.mk
```

Then edit `.deploy.mk` for your server:

```make
TEST_USER = your-user
TEST_HOST = example.com
TEST_PORT = 22
TEST_PATH = /home/your-user/example.com/test
TEST_URL  = https://example.com/test/phpMan.php

DEMO_USER = your-user
DEMO_HOST = example.com
DEMO_PORT = 22
DEMO_PATH = /home/your-user/example.com
DEMO_URL  = https://example.com/phpMan.php
```

### 1. Test Locally

```bash
make test
```

### 2. Commit and Push to GitHub

```bash
git add phpMan.php README.md Makefile .deploy.mk.example .gitignore
git commit -m "description of changes"
git push origin master
```

### 3. Update Staging Demo

```bash
make deploy
```

This deploys only `phpMan.php` to the staging path configured by `TEST_PATH`.

### 4. Update Production Demo

```bash
make release
make deploy-verify
```

> ⚠️ Do **not** overwrite `index.php` — only update `phpMan.php`.

### 5. Create GitHub Release

Tag and create a release on GitHub:

```bash
git tag v2.2
git push origin v2.2
gh release create v2.2 --title "v2.2" --notes "Release notes here"
```

Or upload a release artifact via the Makefile:

```bash
make upload-release
```

## License

GNU General Public License v2.0 — see [copyright page](https://www.chedong.com/phpMan.php/copyright).

## Author

Che Dong — <https://www.chedong.com/>
