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
# 用法: verify-release.sh <expected-tag> [entry-url]
#   verify-release.sh v4.12.17
#   verify-release.sh v4.12.17 https://www.chedong.com/phpMan.php
#   verify-release.sh v4.12.17 https://test.chedong.com      # 只给主机也行
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
# BASE 是 phpman 的**入口 URL**，也就是用户在浏览器里打开的那个地址。Makefile
# 传的是 $(DEMO_URL)，其中已经含 phpMan.php，所以这里绝不能再拼一次入口文件：
# 早先这么拼，拼出了 /phpMan.php/phpMan.php/man/ls/1，四项检查全报「取不到页面」。
BASE=${2:-https://www.chedong.com}
case "$BASE" in */) BASE=${BASE%/};; esac
case "$BASE" in
    */phpMan.php) ;;                          # 已带入口文件
    *)            BASE="$BASE/phpMan.php" ;;  # 只给了主机或目录，补上
esac
VER=${TAG#v}

if [ -z "$TAG" ]; then
    echo "用法: $0 <expected-tag> [entry-url]" >&2
    echo "  例: $0 v4.12.17 https://www.chedong.com/phpMan.php" >&2
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
PAGE=$(fetch "$BASE/man/ls/1")
if [ -z "$PAGE" ]; then
    no "version footer" "could not fetch $BASE/man/ls/1"
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
    RESP=$(curl -sS --max-time 20 -X POST "$BASE/mcp" \
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
NF_URL="$BASE/man/zzz_phpman_verify/1/markdown"
NF_CODE=$(curl -s -o "${TMP:-/dev/null}" -w '%{http_code}' --max-time 20 "$NF_URL" 2>/dev/null || echo 000)
NF_BODY=$(cat "${TMP:-/dev/null}" 2>/dev/null || true)
if [ "$NF_CODE" != "404" ]; then
    no "not-found page" "expected 404, got $NF_CODE"
elif printf '%s' "$NF_BODY" | grep -q 'No man page matches'; then
    ok "not-found page" "404 + notice"
else
    no "not-found page" "404 but the body has no notice (bare title looks broken)"
fi

# ── 5. 缓存命中：同一连接上的第二次请求应远快于 1s ─────────────────────────
#
# 两次请求放进**同一次** curl 调用（--next）：第二条复用同一条连接，DNS/TCP/TLS
# 只在第一次付。从开发机到 DreamHost，光握手就要 0.7–0.8s —— 同一 vhost 上的静态
# phpman.css 单次请求也要 1.2s。拿单次请求的总耗时去卡 1s，量到的是跨洋链路，不是
# 缓存；那样这条检查会把完全健康的发布判死（2026-11-04 的 v4.12.18 就是这样被
# 挡下的）。排除握手后，第二次请求的耗时才真正反映服务端有没有走缓存。
HIT_URL="$BASE/man/ls/1/markdown"
TIMES=$(curl -fsS -o /dev/null -w '%{time_total}\n' --max-time 30 "$HIT_URL" \
            --next -fsS -o /dev/null -w '%{time_total}\n' --max-time 30 "$HIT_URL" 2>/dev/null || true)
T1=$(printf '%s\n' "$TIMES" | sed -n '1p')
T2=$(printf '%s\n' "$TIMES" | sed -n '2p')
if [ -z "$T2" ]; then
    no "cache second hit" "the second request on the reused connection failed"
elif php -r 'exit((float)$argv[1] > 0 && (float)$argv[1] < 1.0 ? 0 : 1);' "$T2" 2>/dev/null; then
    ok "cache second hit" "${T2}s (first ${T1}s, handshake excluded)"
else
    no "cache second hit" "${T2}s on a warm connection — expected < 1s (first ${T1}s)"
fi

# ── 6. ETag 形态 ───────────────────────────────────────────────────────────
ETAG=$(curl -sI --max-time 20 "$BASE/man/ls/1" 2>/dev/null \
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
