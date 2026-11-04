#!/bin/sh
# verify-release.sh — 发布后验证：只读 HTTP，逐项 PASS/FAIL，任一不过就非 0。
#
# 为什么需要
# ----------
# `make verify` 只打印一个 HTTP 状态码，而 2026-10-19 那次事故正是它放过的一类：
# 代码部署了、状态码 200，但每次缓存未命中都 500 —— 状态码看不出页面能不能用。
# 这个脚本把「部署是否生效」拆成几条可以机器判定的检查，接在 _release-tag
# **之前**：验证不过就不打 tag，坏版本号不会被推到远端。
#
# 用法: verify-release.sh <expected-tag> [base-url]
#   verify-release.sh v4.12.17
#   verify-release.sh v4.12.17 https://test.chedong.com
#   全部通过：退出 0
#   任一不过：退出 1，并提示 make rollback STEP=1
#
# 依赖只有 curl 与 php（JSON 用 php -r 解析，不假设有 jq）。
#
# 刻意不做的两件事
# ----------------
# 1. **不自动回滚。** 验证器自己出故障时自动回滚会把生产弄坏；它只负责报警。
# 2. **不校验 ETag 的公式。** HTML 的 ETag 含 cacheAge，不知道 DB 状态就复算不
#    出来，强行复算只会制造伪失败。只校验形态（32 位十六进制、带引号）。
#
# MCP 那一项需要 key：设了 PHPMAN_MCP_KEY 才检查，否则记为 SKIP 而不是 FAIL ——
# 版本号在页脚与静态资源上已经各有一份，MCP 是第三条独立通路，值得有但不该因为
# 开发机上没有生产 key 就让整个发布会话失败。

set -u

TAG=${1:-}
BASE=${2:-https://www.chedong.com}
case "$BASE" in */) BASE=${BASE%/};; esac
VER=${TAG#v}

if [ -z "$TAG" ]; then
    echo "用法: $0 <expected-tag> [base-url]" >&2
    echo "  例: $0 v4.12.17 https://www.chedong.com" >&2
    exit 2
fi

pass=0
fail=0
skip=0
ok()   { printf '[PASS] %-22s %s\n' "$1" "$2"; pass=$((pass + 1)); }
no()   { printf '[FAIL] %-22s %s\n' "$1" "$2"; fail=$((fail + 1)); }
skp()  { printf '[SKIP] %-22s %s\n' "$1" "$2"; skip=$((skip + 1)); }

TMP=$(mktemp 2>/dev/null) || TMP=""
cleanup() { [ -n "$TMP" ] && rm -f "$TMP"; }
trap cleanup EXIT INT TERM

# fetch <url> → stdout, empty on any failure
fetch() {
    curl -fsS --max-time 20 "$1" 2>/dev/null || true
}

echo "=== verify release: expected $TAG at $BASE ==="

# ── 1. 页脚版本（GIT_DESCRIBE） ────────────────────────────────────────────
PAGE=$(fetch "$BASE/phpMan.php/man/ls/1")
if [ -z "$PAGE" ]; then
    no "version footer" "could not fetch $BASE/phpMan.php/man/ls/1"
else
    GOT=$(printf '%s' "$PAGE" | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?' | head -1)
    if [ "$GOT" = "$TAG" ]; then
        ok "version footer" "$GOT"
    else
        no "version footer" "expected $TAG, got ${GOT:-<none>}"
    fi
fi

# ── 2. MCP serverInfo.version ─────────────────────────────────────────────
if [ -z "${PHPMAN_MCP_KEY:-}" ]; then
    skp "version MCP" "PHPMAN_MCP_KEY not set (footer + assets already cover the version)"
else
    RESP=$(curl -sS --max-time 20 -X POST "$BASE/phpMan.php/mcp" \
        -H 'Content-Type: application/json' \
        -H 'Accept: application/json, text/event-stream' \
        -H "X-API-Key: ${PHPMAN_MCP_KEY}" \
        -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"verify-release","version":"1"}}}' \
        2>/dev/null || true)
    GOT=$(printf '%s' "$RESP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["result"]["serverInfo"]["version"] ?? "";' 2>/dev/null || true)
    if [ "$GOT" = "$VER" ]; then
        ok "version MCP" "$GOT"
    else
        no "version MCP" "expected $VER, got ${GOT:-<none>}"
    fi
fi

# ── 3. 静态资源 ?v=（PHPMAN_VERSION） ──────────────────────────────────────
if [ -z "$PAGE" ]; then
    no "assets ?v=" "no page to inspect"
else
    GOT=$(printf '%s' "$PAGE" | grep -oE '\?v=[0-9A-Za-z._-]+' | head -1 | sed 's/^?v=//')
    if [ "$GOT" = "$VER" ]; then
        ok "assets ?v=" "$GOT"
    else
        no "assets ?v=" "expected $VER, got ${GOT:-<none>}"
    fi
fi

# ── 4. 已知不存在：404 且给出版面说明（不是裸标题） ────────────────────────
NF_URL="$BASE/phpMan.php/man/zzz_phpman_verify/1/markdown"
NF_CODE=$(curl -s -o "${TMP:-/dev/null}" -w '%{http_code}' --max-time 20 "$NF_URL" 2>/dev/null || echo 000)
NF_BODY=$(cat "${TMP:-/dev/null}" 2>/dev/null || true)
if [ "$NF_CODE" != "404" ]; then
    no "not-found page" "expected 404, got $NF_CODE"
elif printf '%s' "$NF_BODY" | grep -q 'No man page matches'; then
    ok "not-found page" "404 + notice"
else
    no "not-found page" "404 but the body has no notice (bare title looks broken)"
fi

# ── 5. 缓存命中：第二次应远快于 1s ─────────────────────────────────────────
HIT_URL="$BASE/phpMan.php/man/ls/1/markdown"
T1=$(curl -s -o /dev/null -w '%{time_total}' --max-time 30 "$HIT_URL" 2>/dev/null || echo 99)
T2=$(curl -s -o /dev/null -w '%{time_total}' --max-time 30 "$HIT_URL" 2>/dev/null || echo 99)
if php -r 'exit((float)$argv[1] < 1.0 ? 0 : 1);' "$T2" 2>/dev/null; then
    ok "cache second hit" "${T2}s (first ${T1}s)"
else
    no "cache second hit" "${T2}s — expected < 1s"
fi

# ── 6. ETag 形态 ───────────────────────────────────────────────────────────
ETAG=$(curl -sI --max-time 20 "$BASE/phpMan.php/man/ls/1" 2>/dev/null \
    | tr -d '\r' | sed -n 's/^[Ee][Tt][Aa][Gg]: *//p' | head -1)
if printf '%s' "$ETAG" | grep -qE '^"[0-9a-f]{32}"$'; then
    ok "ETag shape" "$ETAG"
else
    no "ETag shape" "expected \"<32 hex>\", got ${ETAG:-<none>}"
fi

# ── 汇总 ───────────────────────────────────────────────────────────────────
echo "=== $pass passed, $fail failed, $skip skipped ==="
if [ "$fail" -gt 0 ]; then
    echo "" >&2
    echo "验证未通过。部署可能没生效或已退化。" >&2
    echo "回滚：make rollback STEP=1   （本脚本不会自动回滚）" >&2
    exit 1
fi
exit 0
