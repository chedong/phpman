#!/bin/sh
# release-precheck.sh — 发布前的四条守卫，任一不过就中止。
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
#   全部通过：退出 0，**把 origin 的 tag 列表原样打到 stdout**（见下）
#   任一不过：往 stderr 写原因与证据，退出 1
#
# 为什么成功时输出 tag 列表
# -------------------------
# 版本号要由同一份 tag 快照算出来，而且不能多打一次网络请求（到 github.com:22 的连接
# 在这台机器上偶发超时）。所以本脚本把已经取到、并且守卫 2 已经据以判断过的这份列表
# 交给调用方：
#
#     deploy/release-precheck.sh $(DEPLOY_PATHS) > .release-remote-tags
#     deploy/next-version.sh $(VERSION) < .release-remote-tags > .release-version.tmp
#
# 这样「守卫看到的状态」与「算版本号用的状态」是同一份。若让下游自己再 ls-remote 一次，
# 那次失败会得到空输入，而 next-version.sh 会把空输入当成「远端还没有 tag」算出 0.0.1
# —— 一个静默的版本倒退，正是这套机制要消灭的东西。
set -e

PATHS="$*"
if [ -z "$PATHS" ]; then
    PATHS="src phpMan.php cli deploy phpman.config.php.example phpman.css phpman.js"
fi

# 到 github.com:22 的连接在这台机器的网络上偶发超时（实测 10 次里挂 1-2 次：
# "ssh: connect to host github.com port 22: Operation timed out"）。守卫的职责是判断
# **状态**（是否落后 / 是否已发布过），而一次抖动只能说明「暂时问不到」。不重试的话，
# 一次抖动会被报成「落后」或「取不到 tag」，10-20% 的假阳性会让 make release 没法用。
#
# 有限次 + 线性退避（不无限重试）。PRECHECK_ATTEMPTS / PRECHECK_BACKOFF 可覆盖，
# 测试用它们把重试关掉以免拖慢。
ATTEMPTS=${PRECHECK_ATTEMPTS:-3}
BACKOFF=${PRECHECK_BACKOFF:-2}

# 跑一条可能因网络失败的命令；成功时把它的 stdout 留在 $_out 里，由调用方读取。
# 用法: with_retry "<描述>" <命令...>
#
# **这里刻意没有 eval，也不要加回来。** 早先的版本用 `eval "$_var=\$_out"` 把输出
# 写进调用方指名的变量。那个写法**当时是安全的** —— `\$` 让 eval 收到的是字面量
# `$_out`，而赋值右值不参与分词，所以值里的 `;`/反引号/`$( )` 都只是数据（已在
# sh/bash/dash 上各用 7 种 payload 实测）。但它离可注入只差一个字符：去掉 `\` 之后
# 值会被拼进 eval 的字符串再重新分词，实测 `_out='a; touch /tmp/X'` 会**真的执行**。
# `\$` 看起来正像多余的转义，是清理代码时会顺手删掉的东西 —— 所以干脆不要 eval。
# 只有两个调用点，让调用方直接读 $_out 比省这一行更值。
with_retry() {
    _what=$1
    shift
    _out=''
    _n=1
    while :; do
        if _out=$("$@" 2>/dev/null); then
            return 0
        fi
        if [ "$_n" -ge "$ATTEMPTS" ]; then
            echo "错误：$_what 失败（已试 $_n 次）。网络或认证问题，中止。" >&2
            return 1
        fi
        echo "  $_what 失败（第 $_n 次），${BACKOFF}s 后重试…" >&2
        sleep "$BACKOFF"
        _n=$((_n + 1))
    done
}

# ── 守卫 1 ──
# 先 fetch：origin/master 是本地跟踪引用，本身可能就是陈旧的，不 fetch 这个检查
# 形同虚设。fetch 失败必须中止 —— 取不到远端状态时，「没落后」和「不知道」是两回事，
# 不能当成前者。
with_retry "取 origin/master" git fetch -q origin master || exit 1

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

with_retry "取 origin 的 tag 列表" git ls-remote --tags origin || exit 1
REMOTE_TAGS=$_out

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

# ── 守卫 4 ──
# 渲染输出的变化必须让页面缓存失效（RENDERER_VERSION）。
#
# 2026-11-09 的教训：v4.12.23 部署了 SGR 状态机与 pydoc 模式门两处渲染修复，线上却
# 原样返回旧输出 —— 页面缓存按 RENDERER_VERSION 失效，而那次发布没有 bump 它。语料门
# 直接调渲染函数、绕过了缓存，所以它能证明"渲染器对了"，证明不了"线上会变"。
#
# 判据用冻结语料的黄金：黄金变了 = 渲染输出变了。与上一个发布 tag 比较，要求同一段
# 提交里 src/config.php 的 RENDERER_VERSION 定义也变了。重新采集语料（工具升级）同样
# 会触发它 —— 那种情况缓存里也是旧工具的输出，同样该刷新。
#
# 没有可达 tag 时跳过（首次发布没有比较基准）。
#
# 覆盖面：语料只冻结了 man/perldoc 六页。其他渲染路径（pydoc/info/ri、页头页脚）
# 改了但黄金没变时，硬判据不会触发 —— 那里只打一条警告，不中止。为什么不一并做成
# 硬失败：无谓的 bump 会让整库缓存失效、部署后瞬间全量重建，正是 #259 那类 503 的
# 触发条件。硬判据只认"有证据的输出变化"，其余交给人和警告。
PREV_TAG=$(git describe --tags --abbrev=0 HEAD 2>/dev/null || true)
# 没有 src/config.php 的检出（测试夹具里的假仓库）不是 phpman，这条守卫不适用。
if [ -n "$PREV_TAG" ] && git cat-file -e "HEAD:src/config.php" 2>/dev/null; then
    # 比较**解析出的数值**，不是 diff 行。按 diff 行匹配有两个绕过口：删掉定义
    # （有一行 "-"）或只改注释（有一行 "+"）都会让计数非零，守卫就放行了。
    # `git show TAG:path` 对 annotated tag 会先剥到提交，两种 tag 都能用。
    PREV_RV=$(git show "${PREV_TAG}:src/config.php" 2>/dev/null \
        | sed -n "s/.*define([\"']RENDERER_VERSION[\"'], *\([0-9][0-9]*\)).*/\1/p" | head -1)
    HEAD_RV=$(git show "HEAD:src/config.php" 2>/dev/null \
        | sed -n "s/.*define([\"']RENDERER_VERSION[\"'], *\([0-9][0-9]*\)).*/\1/p" | head -1)
    if [ -z "$HEAD_RV" ]; then
        echo "错误：src/config.php 里解析不出 RENDERER_VERSION 的数值定义。" >&2
        echo "      它在缓存失效机制里是必需的；先确认这行没被删掉或改坏。" >&2
        exit 1
    fi
    # 基线解析不出来就**失败关闭**：空值会与任何 HEAD_RV 都不等，守卫就静默放行了。
    # 唯一合法的「缺失」是上一个 tag 早于这个常量；那种情况用显式基线覆盖。
    if [ -z "$PREV_RV" ]; then
        if [ -z "${RENDERER_VERSION_BASELINE:-}" ]; then
            echo "错误：无法从 ${PREV_TAG} 解析 RENDERER_VERSION 基线。" >&2
            echo "      若上一版确实没有这个定义，确认本次已 bump 后，用显式基线重跑：" >&2
            echo "      RENDERER_VERSION_BASELINE=<上一版的语义值> make release" >&2
            exit 1
        fi
        PREV_RV=$RENDERER_VERSION_BASELINE
    fi
    GOLDEN_CHANGED=$(git diff --name-only "$PREV_TAG"..HEAD -- test/corpus/golden | head -1)
    if [ -n "$GOLDEN_CHANGED" ] && [ "$HEAD_RV" = "$PREV_RV" ]; then
        echo "错误：语料黄金变了，但 RENDERER_VERSION 没有变（仍是 ${HEAD_RV}）。" >&2
        echo "      上一个发布 tag: $PREV_TAG" >&2
        echo "      改动示例: $GOLDEN_CHANGED" >&2
        echo "      黄金变 = 渲染输出变；缓存按 RENDERER_VERSION 失效，不 bump 的话" >&2
        echo "      线上会继续返回旧渲染（v4.12.23 就是这样把修复发了个空）。" >&2
        echo "      在 src/config.php 里 bump RENDERER_VERSION 并写明改了什么，再发布。" >&2
        exit 1
    fi
    if [ -z "$GOLDEN_CHANGED" ] && [ "$HEAD_RV" = "$PREV_RV" ]; then
        RENDERER_CHANGED=$(git diff --name-only "$PREV_TAG"..HEAD -- \
            src/format_common.php src/format_html.php src/format_markdown.php \
            src/format_json.php src/format_mcp.php src/source_man.php \
            src/source_perldoc.php src/source_info.php src/source_pydoc.php \
            src/source_ri.php src/source_search.php src/web_header.php \
            src/web_footer.php src/exec.php src/tldr.php | head -1)
        if [ -n "$RENDERER_CHANGED" ]; then
            echo "提示：改动触及渲染路径，但语料黄金与 RENDERER_VERSION 都没变。" >&2
            echo "      示例: ${RENDERER_CHANGED}（上一个 tag: ${PREV_TAG}）" >&2
            echo "      如果这次真的改了渲染输出，请 bump RENDERER_VERSION —— 否则线上缓存" >&2
            echo "      会继续返回旧输出。语料只覆盖 man/perldoc 六页，pydoc/info/ri 与" >&2
            echo "      页头页脚不在它的判据里。" >&2
        fi
    fi
fi

# 三条守卫都过了。把这份已经验证过的 tag 快照交给调用方算版本号（见文件头）。
printf '%s\n' "$REMOTE_TAGS"
exit 0
