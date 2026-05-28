# MCP Mode Test Cases

Deployment: `https://www.chedong.com/phpMan.php/mcp`
Method: POST with JSON-RPC 2.0 body

---

## MCP Protocol Tests

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
Expected: `count > 0`, results contain `cron` in section 8

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

### T7: error — unknown tool
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"nonexistent"}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'isError' in d['result']; print('PASS')"
```
Expected: `result.isError == true`, text contains error message

### T8: error — missing required param
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"cli_help","arguments":{}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['result']['content'][0]['text']); assert 'error' in r; print('PASS')"
```
Expected: error message about missing command parameter

### T9: error — nonexistent command
```bash
curl -s -X POST 'https://www.chedong.com/phpMan.php/mcp' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"cli_help","arguments":{"command":"this_command_does_not_exist_xyz"}}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); r=json.loads(d['result']['content'][0]['text']); assert 'isError' in d['result']; print('PASS')"
```
Expected: `isError=true`, error about no man page found

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

## Run All Tests
```bash
for i in $(seq 1 12); do
  echo "=== T${i} ==="
  # copy each test block here
done
```
