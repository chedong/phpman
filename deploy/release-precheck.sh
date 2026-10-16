#!/bin/sh
# release-precheck.sh — 发布前的三条守卫，任一不过就中止。
#
# 为什么需要它们
# --------------
# 版本号的权威来源改成了 origin 的 tag（见 deploy/next-version.sh），这修好了
# 「同一个提交在不同机器上算出不同版本号」，但引入了一个新的失败模式：版本号从
# **远端**算，代码却从**本地**工作树传上去。两者一旦不同源，算出来的新版本号就会
# 被贴到别的代码上。三条守卫分别堵住一种不同源的方式：
#
#   1. origin/master 是 HEAD 的祖先 —— 本地落后时，会算出新版本号、部署旧代码、
#      再把旧 HEAD 打成新版本号推走。这不是假想：写设计文档那天本地就落后一个提交。
#   2. HEAD 未被 origin 上的任何 tag 指着 —— 同一个提交不该被发布两次；版本号与
#      提交一一对应，页脚上的版本号才能唯一确定一份代码。
#   3. 部署路径上工作树干净 —— 部署上传的是工作树里的 src/ 与 phpMan.php，从脏树
#      发布会部署出「tag 指向的提交」之外的字节，而 tag 推出去就挪不动了。
#
# 守卫 2 只查**远端**，不查本地：这样「部署成功但 push 失败」能自愈 —— 重跑时远端
# 仍无 tag，三条守卫全过，重新算出同一个版本号再补推。与之配套，Makefile 的
# _release-tag 在 push 失败时会删掉本地 tag。
#
# 用法: release-precheck.sh [PATH...]
#   PATH 是「脏」的判定范围；不传则用下面这份默认值（与 Makefile 的 DEPLOY_PATHS 一致）
#   全部通过：退出 0，不输出任何东西
#   任一不过：往 stderr 写原因与证据，退出 1
set -e

PATHS="$*"
if [ -z "$PATHS" ]; then
    PATHS="src phpMan.php cli deploy phpman.config.php.example phpman.css phpman.js"
fi

# ── 守卫 1 ──
# 先 fetch：origin/master 是本地跟踪引用，本身可能就是陈旧的，不 fetch 这个检查
# 形同虚设。fetch 失败（断网、认证失效）必须中止 —— 取不到远端状态时，「没落后」
# 和「不知道」是两回事，不能当成前者。
if ! git fetch -q origin master 2>/dev/null; then
    echo "错误：取不到 origin/master（网络或认证问题），无法判断本地是否落后。中止。" >&2
    exit 1
fi

if ! git merge-base --is-ancestor origin/master HEAD 2>/dev/null; then
    echo "错误：origin/master 不是 HEAD 的祖先 —— 这个 checkout 落后了。" >&2
    echo "      HEAD          $(git rev-parse --short HEAD)" >&2
    echo "      origin/master $(git rev-parse --short origin/master)" >&2
    echo "      落后时发布会部署旧代码、再把旧 HEAD 打成新版本号推走。" >&2
    echo "      先 git pull --rebase 再发布。" >&2
    exit 1
fi

# ── 守卫 2 ──
HEAD_SHA=$(git rev-parse HEAD)

if ! REMOTE_TAGS=$(git ls-remote --tags origin 2>/dev/null); then
    echo "错误：取不到 origin 的 tag 列表，无法判断这个提交是否已经发布过。中止。" >&2
    exit 1
fi

# annotated tag 在 ls-remote 里有两行（tag 对象 sha 与 "…^{}" 提交 sha），
# lightweight tag 只有一行且直接就是提交 sha。先剥掉 ^{} 后缀再按 sha 比对，
# 两种形态就统一了。
#
# 注意：剥 "refs/tags/" 前缀必须在 awk 里做，不能用 `sed 's#.*refs/tags/##'` ——
# 那条 sed 的 `.*` 是贪婪的，会把行首的 sha 一起吃光，awk 就再也匹配不上 HEAD。
TAGGED=$(printf '%s\n' "$REMOTE_TAGS" \
    | sed -e 's#\^{}$##' \
    | awk -v sha="$HEAD_SHA" '$1 == sha { sub(/^refs\/tags\//, "", $2); print $2 }' \
    | sort -u)

if [ -n "$TAGGED" ]; then
    echo "错误：这个提交已经发布过，tag: $(printf '%s' "$TAGGED" | tr '\n' ' ')" >&2
    echo "      HEAD $(git rev-parse --short HEAD)" >&2
    echo "      版本号与提交一一对应，同一个提交不发布两次。要发新版本请先提交新代码。" >&2
    exit 1
fi

# ── 守卫 3 ──
# $PATHS 刻意不加引号：要按空格拆成多个 pathspec。
# -uall 是必须的：默认 git 会把整个未跟踪目录折叠成一行 "?? src/"，那样报错只说得出
# 目录名，说不出到底是哪个文件 —— 而这条报错的全部价值就是让人知道该去处理什么。
DIRTY=$(git status --porcelain -uall -- $PATHS)

if [ -n "$DIRTY" ]; then
    echo "错误：部署路径上有未提交的改动 —— 传上去的字节不会等于 tag 指向的提交。" >&2
    printf '%s\n' "$DIRTY" | sed 's/^/      /' >&2
    exit 1
fi

exit 0
