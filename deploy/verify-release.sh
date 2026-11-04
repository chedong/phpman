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
warn=0
ok()   { printf '[PASS] %-22s %s\n' "$1" "$2"; pass=$((pass + 1)); }
no()   { printf '[FAIL] %-22s %s\n' "$1" "$2"; fail=$((fail + 1)); }
skp()  { printf '[SKIP] %-22s %s\n' "$1" "$2"; skip=$((skip + 1)); }
warn() { printf '[WARN] %-22s %s\n' "$1" "$2"; warn=$((warn + 1)); }

TMP=$(mktemp 2>/dev/null) || TMP=""
cleanup() { [ -n "$TMP" ] && rm -f "$TMP"; }
trap cleanup EXIT INT TERM

# 跨洋链路会偶发超时或空响应：实测 5 次 HEAD 里有 1 次什么都没收到，而站点一直
# 是好的。任何「一次读数定生死」的检查都会因此随机挡住发布，所以每个网络读数
# 都重试，只有连续失败才算数。
RETRIES=3

# retry_out <cmd...>：非空输出即成功，输出写到 stdout；重试用尽返回 1
retry_out() {
    _i=1
    _o=''
    while [ "$_i" -le "$RETRIES" ]; do
        _o=$("$@" 2>/dev/null) || _o=''
        [ -n "$_o" ] && { printf '%s' "$_o"; return 0; }
        [ "$_i" -lt "$RETRIES" ] && sleep 2
        _i=$((_i + 1))
    done
    return 1
}

# fetch <url> → 页面正文，失败为空
fetch() {
    retry_out curl -fsS --max-time 20 "$1"
}

# head_etag <url> → ETag 值，取不到为空
head_etag() {
    curl -sI --max-time 20 "$1" 2>/dev/null \
        | tr -d '\r' | sed -n 's/^[Ee][Tt][Aa][Gg]: *//p' | head -1
}

# nf_code <url> → HTTP 状态码；连不上返回 1（好让 retry_out 重试）
nf_code() {
    _c=$(curl -s -o "${TMP:-/dev/null}" -w '%{http_code}' --max-time 20 "$1" 2>/dev/null) || _c=000
    [ "$_c" = "000" ] && return 1
    printf '%s' "$_c"
}

# hit_times → 三次请求各占一行（热身 / 缓存页 / 静态对照）；不满三条视为失败
hit_times() {
    _t=$(curl -fsS -o /dev/null -w '%{time_total}\n' --max-time 30 "$HIT_URL" \
             --next -fsS -o /dev/null -w '%{time_total}\n' --max-time 30 "$HIT_URL" \
             --next -fsS -o /dev/null -w '%{time_total}\n' --max-time 30 "$SITE/phpman.css" 2>/dev/null) || return 1
    [ "$(printf '%s\n' "$_t" | grep -c .)" -ge 3 ] || return 1
    printf '%s' "$_t"
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
NF_CODE=$(retry_out nf_code "$NF_URL") || NF_CODE=000
NF_BODY=$(cat "${TMP:-/dev/null}" 2>/dev/null || true)
if [ "$NF_CODE" != "404" ]; then
    no "not-found page" "expected 404, got $NF_CODE"
elif printf '%s' "$NF_BODY" | grep -q 'No man page matches'; then
    ok "not-found page" "404 + notice"
else
    no "not-found page" "404 but the body has no notice (bare title looks broken)"
fi

# ── 5. 缓存页耗时（只告警，不判失败） ──────────────────────────────────────
#
# 三次请求放进**同一次** curl 调用（--next）：第一条约等于热身，第二、三条复用
# 同一条连接，DNS/TCP/TLS 只在第一条付。第三条取同一 vhost 上的静态 phpman.css
# 作对照 —— 它不跑 PHP。
#
# 为什么只告警：这条检查量不出「缓存命中」。跨洋链路抖动（同一分钟内缓存页
# 0.44→2.50s、静态 css 0.55→23s）加上共享主机的 PHP-FCGI 排队（#259），让页面
# 自身耗时的方差大于缓存命中与未命中的差距。绝对阈值和「与静态文件比」都会
# 误杀：实测后者仍有 2/5 次失败，而站点完全正常。
#
# 一个只产生假警报的发布门比没有门更糟 —— 它会训练人忽略验证输出。留着数字
# 供人看，判定权交给前面五项确定性检查。
#
# 真正确定的缓存断言需要服务端证据：给响应加 X-Cache: HIT|MISS，或读 hits
# 计数器。那是 #253 的后续，不是这里能测出来的。
SITE=${BASE%/phpMan.php}
HIT_URL="$BASE/man/ls/1/markdown"
TIMES=$(retry_out hit_times) || TIMES=''
T1=$(printf '%s\n' "$TIMES" | sed -n '1p')
T2=$(printf '%s\n' "$TIMES" | sed -n '2p')
T3=$(printf '%s\n' "$TIMES" | sed -n '3p')
if [ -z "$T2" ] || [ -z "$T3" ]; then
    warn "page vs static" "a request on the reused connection failed (page ${T2:-?}, static ${T3:-?})"
elif php -r 'exit((float)$argv[1] > 0 && (float)$argv[2] > 0 && (float)$argv[1] <= (float)$argv[2] + 0.5 ? 0 : 1);' "$T2" "$T3" 2>/dev/null; then
    ok "page vs static" "page ${T2}s, static ${T3}s on one warm connection (cold ${T1}s)"
else
    warn "page vs static" "page ${T2}s vs static ${T3}s — slower than a static file, worth a look"
fi

# ── 6. ETag 形态 ───────────────────────────────────────────────────────────
ETAG=$(retry_out head_etag "$BASE/man/ls/1") || ETAG=''
if printf '%s' "$ETAG" | grep -qE '^"[0-9a-f]{32}"$'; then
    ok "ETag shape" "$ETAG"
else
    no "ETag shape" "expected \"<32 hex>\", got ${ETAG:-<none>}"
fi

# ── 汇总 ───────────────────────────────────────────────────────────────────
echo "=== $pass passed, $fail failed, $warn warned, $skip skipped ==="
if [ "$fail" -gt 0 ]; then
    echo "" >&2
    echo "验证未通过。部署可能没生效或已退化。" >&2
    echo "回滚：make rollback STEP=1   （本脚本不会自动回滚）" >&2
    exit 1
fi
exit 0
