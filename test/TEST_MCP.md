# MCP Mode Test Cases

Deployment: `https://www.chedong.com/phpMan.php/mcp`
Methods: POST (JSON-RPC 2.0) and GET (REST /mcp format)

---

## MCP Protocol Tests (JSON-RPC POST)

### T1: initialize (handshake)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{}}}' | python3 -m json.tool
```
Expected: `result.protocolVersion == "2024-11-05"`, `result.serverInfo.name == "phpMan"`, `result.capabilities.tools.listChanged == false`

### T2: tools/list
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | python3 -m json.tool
```
Expected: 2 tools (`cli_help`, `cli_search`), each with `inputSchema`

### T3: tools/call cli_help (man page)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"ls","section":"1"}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['result']['content'][0]['text']); assert r['mode']=='man'; assert r['name']=='ls(1)'; assert len(r['sections'])>0; print('PASS')"
```
Expected: `mode=man`, `name=ls(1)`, at least 1 section

### T4: tools/call cli_search
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"cli_search","arguments":{"query":"cron"}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['result']['content'][0]['text']); assert r['count']>0; assert any(m['name']=='cron' for m in r['results']); print('PASS')"
```
Expected: `count > 0`, results contain `cron`

### T5: perldoc auto-detect (module with ::)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"File::Basename"}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['result']['content'][0]['text']); assert r['mode']=='perldoc'; print('PASS')"
```
Expected: auto-detected as `mode=perldoc`

### T6: perldoc auto-detect (section 3pm)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"CGI","section":"3pm"}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['result']['content'][0]['text']); assert r['mode']=='perldoc'; print('PASS')"
```
Expected: auto-detected as `mode=perldoc`

### T7: error — unknown tool (JSON-RPC error)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"nonexistent"}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'error' in d; assert d['error']['code']==-32603; print('PASS')"
```
Expected: JSON-RPC error code -32603, message contains "Unknown tool"

### T8: error — missing required param (JSON-RPC error)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"cli_help","arguments":{}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'error' in d; assert d['error']['code']==-32603; print('PASS')"
```
Expected: JSON-RPC error code -32603, message about missing command parameter

### T9: nonexistent command (returns empty man page, not error)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"this_command_does_not_exist_xyz"}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['result']['content'][0]['text']); assert r['mode']=='man'; assert r['sections']==[]; print('PASS (empty man page)')"
```
Expected: valid MCP response with empty sections (man returns nothing for unknown commands)

### T10: notifications/initialized (no-op)
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'jsonrpc' in d; print('PASS')"
```
Expected: HTTP 202, empty JSON-RPC response

### T11: error — invalid JSON body
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d 'not json' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert d['error']['code']==-32700; print('PASS')"
```
Expected: error code -32700 (Parse error)

### T12: cli_search with section filter
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"cli_search","arguments":{"query":"printf","section":"3"}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['result']['content'][0]['text']); print(f\"count={r['count']}\"); assert r['count']>0; print('PASS')"
```
Expected: results from section 3 only

---

## REST GET /mcp Format Tests

REST GET `/mcp` endpoints return the SAME `{"content":[{"type":"text","text":"<json-man-page>"}]}` format
as MCP POST `tools/call`. The REST URL mirrors the existing `/json` format but wraps output for MCP clients.

### T13: GET man page with /mcp format
```bash
curl -s 'https://www.chedong.com/phpMan.php/man/ls/1/mcp' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'content' in d; assert d['content'][0]['type']=='text'; r=json.loads(d['content'][0]['text']); assert r['mode']=='man'; assert r['name']=='ls(1)'; print('PASS')"
```
Expected: MCP content wrapper, inner JSON has `mode=man`, `name=ls(1)`

### T14: GET search with /mcp format
```bash
curl -s 'https://www.chedong.com/phpMan.php/search/cron/mcp' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['content'][0]['text']); assert r['mode']=='search'; assert r['count']>0; assert any(m['name']=='cron' for m in r['results']); print('PASS')"
```
Expected: MCP content wrapper, inner JSON has search results containing `cron`

### T15: GET perldoc with /mcp format
```bash
curl -s 'https://www.chedong.com/phpMan.php/perldoc/Digest::MD5/mcp' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['content'][0]['text']); assert r['mode']=='perldoc'; assert 'Digest::MD5' in r['name']; print('PASS')"
```
Expected: MCP content wrapper, inner JSON has `mode=perldoc`

### T16: GET /mcp and POST /mcp return identical inner JSON
```bash
# Compare REST GET vs MCP POST for same man page
REST=$(curl -s 'https://www.chedong.com/phpMan.php/man/cat/1/mcp' | python3 -c "import sys,json; print(json.loads(json.load(sys.stdin)['content'][0]['text'])['name'])")
MCP=$(curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":16,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"cat","section":"1"}}}' \
  | python3 -c "import sys,json; print(json.loads(json.load(sys.stdin)['result']['content'][0]['text'])['name'])")
[ "$REST" = "$MCP" ] && echo "PASS: both return '$REST'" || echo "FAIL: REST='$REST' MCP='$MCP'"
```
Expected: REST GET and MCP POST return identical inner man page data

---

## Run All Tests
```bash
for i in $(seq 1 16); do
  echo "=== T${i} ==="
  # copy each test block here
done
```
