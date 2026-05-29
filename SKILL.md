# phpMan Skill

Access Unix/Linux man pages, Perl perldoc, and GNU info pages via REST API or MCP protocol.

## REST API

**Base URL**: `https://www.chedong.com/phpMan.php`

### Endpoints

| Purpose | URL Pattern | Example |
|---------|-------------|---------|
| Man page | `/man/{command}/{section?}/json` | `/man/ls/1/json` |
| Perldoc | `/perldoc/{module}/json` | `/perldoc/File::Basename/json` |
| Info | `/info/{page}/json` | `/info/bash/json` |
| Search | `/search/{query}/{section?}/json` | `/search/recursive/json` |

**Section numbers**: 1 (user commands), 2 (syscalls), 3 (libraries), 4 (devices), 5 (formats), 6 (games), 7 (misc), 8 (admin), 3pm (Perl modules)

### Response Schema

```json
{
  "mode": "man|perldoc|info|search",
  "parameter": "command name",
  "section": "1|2|3|3pm|...",
  "url": "https://www.chedong.com/phpMan.php/man/ls/1/json",
  "generated": "2026-05-27T10:30:00Z",
  "synopsis": "ls [OPTION]... [FILE]...",
  "summary": "ls - list directory contents",
  "sections": {
    "NAME": {
      "name": "NAME",
      "level": 1,
      "content": "ls - list directory contents",
      "subsections": []
    },
    "OPTIONS": {
      "name": "OPTIONS",
      "level": 1,
      "content": "",
      "subsections": [
        {
          "name": "-a, --all",
          "content": "do not ignore entries starting with .",
          "flag": "-a",
          "long": "--all",
          "arg": null
        }
      ]
    }
  },
  "flags": [
    {
      "flag": "-a",
      "long": "--all",
      "arg": null,
      "description": "do not ignore entries starting with ."
    }
  ],
  "examples": [
    "ls -la",
    "ls --color=auto"
  ],
  "see_also": [
    {
      "name": "dir",
      "section": "1",
      "url": "https://www.chedong.com/phpMan.php/man/dir/1/json"
    }
  ]
}
```

### Examples

**Get man page for `tar`**:
```bash
curl https://www.chedong.com/phpMan.php/man/tar/1/json
```

**Get perldoc for `File::Basename`**:
```bash
curl https://www.chedong.com/phpMan.php/perldoc/File::Basename/json
```

**Search for "recursive delete"**:
```bash
curl https://www.chedong.com/phpMan.php/search/recursive%20delete/json
```

**Get info page for `bash`**:
```bash
curl https://www.chedong.com/phpMan.php/info/bash/json
```

## MCP Protocol

**Endpoint**: `https://www.chedong.com/phpMan.php/mcp`  
**Protocol**: JSON-RPC 2.0 over HTTP POST  
**Transport**: Streamable HTTP (not SSE)

### Available Tools

1. **cli_help** - Get structured man/perldoc/info page
2. **cli_search** - Search man pages by keyword

### MCP Call Examples

**Initialize session**:
```bash
curl -X POST https://www.chedong.com/phpMan.php/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {}
  }'
```

**List available tools**:
```bash
curl -X POST https://www.chedong.com/phpMan.php/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/list",
    "params": {}
  }'
```

**Get help for `gzip` command**:
```bash
curl -X POST https://www.chedong.com/phpMan.php/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "tools/call",
    "params": {
      "name": "cli_help",
      "arguments": {
        "command": "gzip",
        "section": "1"
      }
    }
  }'
```

**Search for "network" commands**:
```bash
curl -X POST https://www.chedong.com/phpMan.php/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 4,
    "method": "tools/call",
    "params": {
      "name": "cli_search",
      "arguments": {
        "query": "network"
      }
    }
  }'
```

**Get perldoc for `DBI`**:
```bash
curl -X POST https://www.chedong.com/phpMan.php/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 5,
    "method": "tools/call",
    "params": {
      "name": "cli_help",
      "arguments": {
        "command": "DBI",
        "section": "3pm"
      }
    }
  }'
```

### MCP Response Format

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "# gzip (man)\n\n**Summary:** gzip - compress or expand files\n..."
      }
    ],
    "structuredContent": {
      "command": "gzip",
      "section": "1",
      "mode": "man",
      "summary": "gzip - compress or expand files",
      "synopsis": "gzip [ -acdfhklLnNrtvV19 ] [name ...]",
      "flags": [
        {
          "flag": "-c",
          "long": "--stdout",
          "arg": null,
          "description": "write on standard output"
        }
      ],
      "examples": ["gzip file", "gzip -d file.gz"],
      "see_also": [
        {"name": "gunzip", "section": "1"},
        {"name": "zcat", "section": "1"}
      ],
      "section_outline": [
        {
          "name": "NAME",
          "lines": 2,
          "subsections": []
        },
        {
          "name": "OPTIONS",
          "lines": 85,
          "subsections": [
            {"name": "-c, --stdout", "lines": 3, "flag": "-c", "long": "--stdout"}
          ]
        }
      ]
    }
  }
}
```

## Key Fields

- **sections**: Full content organized by man page sections (NAME, SYNOPSIS, OPTIONS, etc.)
- **flags**: Extracted command-line flags with short/long forms and descriptions
- **examples**: Code examples from EXAMPLES section
- **see_also**: Related commands with direct links
- **section_outline**: Compact overview showing section sizes and subsection names

## Usage Tips

1. **For quick lookups**: Use `section_outline` to locate relevant sections before reading full content
2. **For flag reference**: Use `flags` array for structured flag data
3. **For command examples**: Use `examples` array for practical usage
4. **For related commands**: Use `see_also` for discovery
5. **Section detection**: Omit section for best-match, or specify (e.g., "1" for user commands, "3pm" for Perl)

## Discovery Endpoint

For MCP clients that support auto-discovery:
```bash
curl https://www.chedong.com/phpMan.php/.well-known/mcp.json
```

Returns server metadata, available tools, and usage instructions.
