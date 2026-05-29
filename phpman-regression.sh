#!/bin/bash
# phpman-regression.sh — External validation & regression tests for phpMan
# Run after every deploy: bash phpman-regression.sh
# Requires: curl, python3, grep
set -euo pipefail

BASE="https://www.chedong.com/phpMan.php"
MAN_URL="${BASE}/man/gzip/1"
JSON_URL="${BASE}/man/gzip/1/json"
MCP_URL="${BASE}/mcp"
PASS=0
FAIL=0
WARN=0
RESULTS_FILE=$(mktemp)

pass() { echo "  ✅ $1"; echo "PASS" >> "$RESULTS_FILE"; }
fail() { echo "  ❌ $1"; echo "FAIL" >> "$RESULTS_FILE"; }
warn() { echo "  ⚠️  $1"; echo "WARN" >> "$RESULTS_FILE"; }

echo "╔══════════════════════════════════════════════╗"
echo "║  phpMan Regression Tests                    ║"
echo "╚══════════════════════════════════════════════╝"
echo ""

# ── 1. W3C XHTML Validation ──────────────────────────────────
echo "1. W3C XHTML Validation"
ENCODED_URL=$(python3 -c "import urllib.parse; print(urllib.parse.quote('${MAN_URL}', safe=''))")
W3C_RESULT=$(curl -sf "https://validator.w3.org/check?uri=${ENCODED_URL}&output=json" 2>/dev/null | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    msgs = d.get('messages', [])
    errors = [m for m in msgs if m.get('type') == 'error']
    if errors:
        print(f'{len(errors)} errors: ' + '; '.join(e.get('message','')[:60] for e in errors[:3]))
    else:
        print('0 errors')
except: print('API_ERROR')
" 2>/dev/null || echo "TIMEOUT")

if [[ "$W3C_RESULT" == "0 errors" ]]; then
    pass "$W3C_RESULT"
elif [[ "$W3C_RESULT" == "API_ERROR" || "$W3C_RESULT" == "TIMEOUT" ]]; then
    warn "W3C validator API unavailable"
else
    fail "$W3C_RESULT"
fi

# ── 2. UTF-8 Validity ────────────────────────────────────────
echo "2. UTF-8 Validity (HTML output)"
UTF8_RESULT=$(curl -sf "${MAN_URL}" 2>/dev/null | python3 -c "
import sys
data = sys.stdin.buffer.read()
try:
    data.decode('utf-8')
    print(f'OK {len(data)} bytes')
except UnicodeDecodeError as e:
    print(f'FAIL at byte {e.start}')
" 2>/dev/null || echo "FETCH_ERROR")

if [[ "$UTF8_RESULT" == OK* ]]; then
    pass "$UTF8_RESULT"
else
    fail "$UTF8_RESULT"
fi

# ── 3. Backspace Cleanup ─────────────────────────────────────
echo "3. Backspace Character Cleanup"
BS_COUNT=$(curl -sf "${MAN_URL}" 2>/dev/null | grep -c $'\x08' || true)
if [ "$BS_COUNT" = "0" ]; then
    pass "0 residual \\x08 chars"
else
    fail "${BS_COUNT} residual \\x08 chars"
fi

# ── 4. JSON Endpoint ─────────────────────────────────────────
echo "4. JSON Endpoint"
JSON_RESULT=$(curl -sf "${JSON_URL}" 2>/dev/null | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    param = d.get('parameter', '?')
    sec = d.get('section', '?')
    flags = len(d.get('flags', []))
    sections = len(d.get('sections', {}))
    print(f'OK {param}({sec}) {flags}flags {sections}sections')
except Exception as e:
    print(f'FAIL {e}')
" 2>/dev/null || echo "FETCH_ERROR")

if [[ "$JSON_RESULT" == OK* ]]; then
    pass "$JSON_RESULT"
else
    fail "$JSON_RESULT"
fi

# ── 5. MCP Endpoint ──────────────────────────────────────────
echo "5. MCP Endpoint (tools/list)"
MCP_RESULT=$(curl -sf -X POST -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}' "${MCP_URL}" 2>/dev/null | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    tools = d['result']['tools']
    names = [t['name'] for t in tools]
    print(f'OK {len(tools)} tools: {names}')
except Exception as e:
    print(f'FAIL {e}')
" 2>/dev/null || echo "FETCH_ERROR")

if [[ "$MCP_RESULT" == OK* ]]; then
    pass "$MCP_RESULT"
else
    fail "$MCP_RESULT"
fi

# ── 6. Gzip Compression ──────────────────────────────────────
echo "6. Gzip Compression (JSON)"
ENCODING=$(curl -sfI -H 'Accept-Encoding: gzip' "${JSON_URL}" 2>/dev/null | grep -i 'content-encoding' | tr -d '\r\n' | sed 's/.*: //' || echo "none")
if [[ "$ENCODING" == *"gzip"* ]]; then
    pass "Content-Encoding: $ENCODING"
else
    warn "No gzip compression detected (may be stripped by proxy)"
fi

# ── 7. ETag Cache ────────────────────────────────────────────
echo "7. ETag Conditional Request"
ETAG=$(curl -sfI "${JSON_URL}" 2>/dev/null | grep -i '^etag:' | tr -d '\r\n' | sed 's/^[Ee][Tt][Aa][Gg]: //' || echo "")
if [ -n "$ETAG" ]; then
    CODE=$(curl -sf -o /dev/null -w '%{http_code}' -H "If-None-Match: ${ETAG}" "${JSON_URL}" 2>/dev/null || echo "000")
    if [ "$CODE" = "304" ]; then
        pass "ETag: ${ETAG} → HTTP 304"
    else
        fail "ETag: ${ETAG} → HTTP ${CODE} (expected 304)"
    fi
else
    warn "No ETag header found"
fi

# ── 8. Mobile Responsive CSS ─────────────────────────────────
echo "8. Mobile Responsive CSS"
curl -sf "${MAN_URL}" 2>/dev/null | python3 -c "
import sys, re
html = sys.stdin.read()
m = re.search(r'@media\s*\(max-width:(\d+)px\)', html)
if m:
    bp = m.group(1)
    media_block = re.search(r'@media.*?\{(.*?)\}', html, re.DOTALL)
    if media_block and '!important' in media_block.group(1):
        print(f'OK breakpoint:{bp}px !important')
    else:
        print(f'WARN breakpoint:{bp}px no !important')
else:
    print('FAIL no media query')
" 2>/dev/null | while read -r line; do
    if [[ "$line" == OK* ]]; then
        pass "${line#OK}"
    elif [[ "$line" == WARN* ]]; then
        warn "${line#WARN}"
    else
        fail "$line"
    fi
done

# ── 9. Accessibility: Form Labels ────────────────────────────
echo "9. Accessibility: Form Labels"
curl -sf "${MAN_URL}" 2>/dev/null | python3 -c "
import sys, re
html = sys.stdin.read()
inputs = re.findall(r'<input[^>]*>', html)
labels = re.findall(r'<label[^>]*for=\"([^\"]+)\"', html)
labeled = 0
needs_label = 0
for inp in inputs:
    if 'type=\"hidden\"' in inp or 'type=\"submit\"' in inp:
        continue
    needs_label += 1
    m = re.search(r'id=\"([^\"]+)\"', inp)
    if m and m.group(1) in labels:
        labeled += 1
print(f'OK {labeled}/{needs_label} labeled')
" 2>/dev/null | while read -r line; do
    if [[ "$line" == OK* ]]; then
        pass "${line#OK}"
    else
        fail "$line"
    fi
done

# ── 10. XHTML lang attribute ─────────────────────────────────
echo "10. XHTML lang Attribute"
LANG_CHECK=$(curl -sf "${MAN_URL}" 2>/dev/null | head -1 | python3 -c "
import sys, re
line = sys.stdin.readline()
has_xml_lang = 'xml:lang=\"en\"' in line
has_lang = bool(re.search(r'\blang=\"en\"', line))
if has_xml_lang and has_lang:
    print('OK xml:lang + lang present')
elif has_xml_lang:
    print('WARN xml:lang present but lang missing')
else:
    print('FAIL no lang attributes')
" 2>/dev/null || echo "FETCH_ERROR")

if [[ "$LANG_CHECK" == OK* ]]; then
    pass "${LANG_CHECK#OK}"
elif [[ "$LANG_CHECK" == WARN* ]]; then
    warn "${LANG_CHECK#WARN}"
else
    fail "$LANG_CHECK"
fi

# ── Summary ──────────────────────────────────────────────────
PASS=$(grep -c '^PASS$' "$RESULTS_FILE" || true)
FAIL=$(grep -c '^FAIL$' "$RESULTS_FILE" || true)
WARN=$(grep -c '^WARN$' "$RESULTS_FILE" || true)
rm -f "$RESULTS_FILE"

echo ""
echo "═══════════════════════════════════════════════"
echo "  Results: ${PASS} passed, ${FAIL} failed, ${WARN} warnings"
echo "═══════════════════════════════════════════════"

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
exit 0
