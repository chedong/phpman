# MCP Mode Test Cases

Deployment: `https://www.chedong.com/phpMan.php/mcp`
Methods: POST (JSON-RPC 2.0) only.

**Authentication is required and fail-closed**: every request must carry the
target's `MCP_API_KEY` in `X-Api-Key`, and a target with no key configured
answers `401` for everything. Export it before running the tests:

```bash
export MCP_KEY='<the target MCP_API_KEY>'
```

**Where the data lives.** A successful `tools/call` result has two fields:

| Field | Contents |
|---|---|
| `result.structuredContent` | the structured payload — `mode`, `command`/`query`, `sections`, `flags`, `count`, `results`, … |
| `result.content[0].text` | a **markdown rendering** of the same page, for the model to read |

The assertions below read `structuredContent`. Parsing `content[0].text` as JSON
does not work — it is markdown.

Error codes: `-32700` parse error, `-32602` invalid params (unknown tool,
missing required parameter), `-32603` internal error, `-32001` unauthorized.

---

## MCP Protocol Tests (JSON-RPC POST)

### T1: initialize (handshake)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{}}}' | python3 -m json.tool
```
Expected: `result.protocolVersion == "2024-11-05"`, `result.serverInfo.name == "phpMan"`, `result.capabilities.tools.listChanged == false`

### T2: tools/list
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | python3 -m json.tool
```
Expected: 2 tools (`cli_help`, `cli_search`), each with `inputSchema`

### T3: tools/call cli_help (man page)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"ls","section":"1"}}}' \
  | python3 -c "import sys,json; r=json.load(sys.stdin)['result']['structuredContent']; assert r['mode']=='man'; assert r['command']=='ls'; assert len(r['sections'])>0; print('PASS')"
```
Expected: `mode=man`, `command=ls`, at least 1 section

### T4: tools/call cli_search
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"cli_search","arguments":{"query":"cron"}}}' \
  | python3 -c "import sys,json; r=json.load(sys.stdin)['result']['structuredContent']; assert r['mode']=='search'; assert r['count']>0; assert any(m['name']=='cron' for m in r['results']); print('PASS')"
```
Expected: `mode=search`, `count > 0`, results contain `cron`

### T5: perldoc auto-detect (module with ::)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"File::Basename"}}}' \
  | python3 -c "import sys,json; r=json.load(sys.stdin)['result']['structuredContent']; assert r['mode']=='perldoc'; print('PASS')"
```
Expected: auto-detected as `mode=perldoc`

### T6: perldoc auto-detect (section 3pm)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"CGI","section":"3pm"}}}' \
  | python3 -c "import sys,json; r=json.load(sys.stdin)['result']['structuredContent']; assert r['mode']=='perldoc'; print('PASS')"
```
Expected: auto-detected as `mode=perldoc`

### T7: error — unknown tool (-32602)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"nonexistent"}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert d['error']['code']==-32602; assert 'Unknown tool' in d['error']['message']; print('PASS')"
```
Expected: `-32602` (invalid params — a bad request, not a broken server), message names the tool

### T8: error — missing required param (-32602)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"cli_help","arguments":{}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert d['error']['code']==-32602; assert 'Missing required parameter' in d['error']['message']; print('PASS')"
```
Expected: `-32602`, message names the missing parameter

### T9: nonexistent command (empty result, not an error)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"this_command_does_not_exist_xyz"}}}' \
  | python3 -c "import sys,json; r=json.load(sys.stdin)['result']['structuredContent']; assert r['mode']=='man'; assert r['command']=='this_command_does_not_exist_xyz'; assert r['sections']==[]; assert r['summary'] is None; print('PASS (empty result)')"
```
Expected: a normal result with empty `sections` and null `summary` — the call succeeded, the command simply has no page

### T10: notifications/initialized (no-op)
```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'
```
Expected: HTTP 202

### T11: error — invalid JSON body
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d 'not json' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert d['error']['code']==-32700; print('PASS')"
```
Expected: error code -32700 (Parse error)

### T12: cli_search with section filter
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $MCP_KEY" \
  -d '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"cli_search","arguments":{"query":"printf","section":"3"}}}' \
  | python3 -c "import sys,json; r=json.load(sys.stdin)['result']['structuredContent']; print(f\"count={r['count']}\"); assert r['count']>0; print('PASS')"
```
Expected: results from section 3 only

---

## Runnable equivalents

The cases above are the manual/reference form. `test/e2e/test_agent_scenarios.php`
(A02–A08) and `test/e2e/test_security.php` (P09–P10) cover the same ground and
actually assert — run them with the target's key:

```bash
PHPMAN_TEST_URL=https://test.chedong.com/phpMan.php \
PHPMAN_TEST_MCP_KEY="$MCP_KEY" \
  php test/e2e/test_agent_scenarios.php
```
