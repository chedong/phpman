#!/bin/sh
# next-version.sh — 从 tag 列表算下一个发布版本号。
#
# 为什么 tag 列表从 stdin 进来
# ---------------------------
# 版本号的权威来源是**远端**的 tag 集合，不是本地的。`git describe` 读的是运行 make
# 那台机器碰巧有的 tag，所以同一个提交在两台机器上会被描述成不同的东西 —— 生产就是
# 这样把已经越过 v4.12.2 的代码标成了 v4.11.2-59-gf06411a（phpman#250）。
# 调用方把 `git ls-remote --tags origin` 的输出喂进来，测试喂 fixture；
# 脚本本身永远不碰网络。
#
# 用法: next-version.sh [EXPLICIT_VERSION]
#   stdin:  每行一个 tag 名（"refs/tags/v4.12.2" / "v4.12.2" / "4.12.2" 都认）
#   stdout: 三段版本号，不带 v 前缀（如 "4.12.3"）—— 只输出这一个值
#   stderr: 失败原因或警告
set -e

# 只认三段纯数字。仓库里真实存在的两段 tag（v2.0 … v4.5）、带后缀的
# （v3.6.1-readme）和根本不是版本的（phpMan_2 / time）都落在这条正则之外。
# annotated tag 在 `git ls-remote` 里会多出一行 "…^{}"，同样被滤掉。
LATEST=$(sed -e 's#.*refs/tags/##' -e 's#^v##' \
    | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' \
    | sort -V | tail -1)

if [ -z "$LATEST" ]; then
    LATEST=0.0.0
    echo "next-version: 远端没有任何三段 tag，按首次发布处理" >&2
fi

EXPLICIT=$1

if [ -z "$EXPLICIT" ]; then
    MAJOR=${LATEST%%.*}
    REST=${LATEST#*.}
    MINOR=${REST%%.*}
    PATCH=${REST#*.}
    echo "$MAJOR.$MINOR.$((PATCH + 1))"
    exit 0
fi

# 归一化成三段：4.13 -> 4.13.0、5.0 -> 5.0.0。
#
# 不归一化的话，VERSION=4.13 会产出 tag v4.13；下一次默认发布的正则只认三段、
# 扫不到它，于是从 v4.12.2 算出 v4.12.3 —— 正是这个脚本要消灭的那种静默倒退，
# 而且「新版本号必须更大」这条守卫拦不住它（守卫比较的是同一个扫描结果）。
NORM=$(printf '%s' "$EXPLICIT" | sed -e 's/^v//')

case "$NORM" in
    ''|*[!0-9.]*|.*|*.|*..*)
        echo "next-version: VERSION='$EXPLICIT' 不是合法的版本号（要 1-3 段纯数字）" >&2
        exit 1 ;;
esac

A=$(printf '%s' "$NORM" | cut -d. -f1)
B=$(printf '%s' "$NORM" | cut -d. -f2)
C=$(printf '%s' "$NORM" | cut -d. -f3)
D=$(printf '%s' "$NORM" | cut -d. -f4)

if [ -n "$D" ]; then
    echo "next-version: VERSION='$EXPLICIT' 超过三段" >&2
    exit 1
fi

[ -n "$A" ] || A=0
[ -n "$B" ] || B=0
[ -n "$C" ] || C=0
NORM="$A.$B.$C"

# 必须**严格**大于远端最新。等于也不行：那会让同一个版本号被发两次。
if [ "$NORM" = "$LATEST" ] \
    || [ "$(printf '%s\n%s\n' "$LATEST" "$NORM" | sort -V | tail -1)" != "$NORM" ]; then
    echo "next-version: VERSION=$EXPLICIT 归一化为 $NORM，但不大于远端最新 $LATEST" >&2
    exit 1
fi

echo "$NORM"
