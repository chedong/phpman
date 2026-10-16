#!/bin/sh
# log-analyze.sh — web 服务器访问日志分析：爬虫构成、热门页面、状态码、时段分布。
#
# 这个脚本是**通用的**：项目里不含任何具体站点的域名、路径或爬虫名单。站点相关的
# 东西全部从外部进来，三项：
#
#   PHPMAN_WEBLOG_DIR    必填。web 服务器访问日志所在目录。
#                        DreamHost 上形如 ~/logs/<domain>/https
#   位置参数             可选的日期范围：YYYY-MM-DD [YYYY-MM-DD]，默认今天
#   PHPMAN_SPIDER_EXTRA  可选。额外的爬虫名，一行一个（# 开头为注释），追加到项目
#                        自带的通用清单之后。**本站特有的长尾写这里，不要写进项目**。
#                        默认取 $HOME/.phpman/spider-extra.txt
#
# 用法:
#   PHPMAN_WEBLOG_DIR=$HOME/logs/example.com/https sh log-analyze.sh
#   PHPMAN_WEBLOG_DIR=... sh log-analyze.sh 2026-10-15
#   PHPMAN_WEBLOG_DIR=... sh log-analyze.sh 2026-10-15 2026-10-16
#
# 日志文件按日轮转，形如 access.log.2026-10-15；未轮转的那份叫 access.log。

# ── 配置：全部来自外部 ──

WEBLOG_DIR="${PHPMAN_WEBLOG_DIR:-}"
if [ -z "$WEBLOG_DIR" ]; then
  echo "错误：未设置 PHPMAN_WEBLOG_DIR —— 本脚本不含任何站点专属路径。" >&2
  echo "      例：PHPMAN_WEBLOG_DIR=\$HOME/logs/example.com/https sh $0" >&2
  exit 2
fi
WEBLOG_DIR="${WEBLOG_DIR%/}"
if [ ! -d "$WEBLOG_DIR" ]; then
  echo "错误：$WEBLOG_DIR 不是目录。" >&2
  exit 2
fi

# 项目自带的通用爬虫清单。
#
# 只用小写配 `grep -i`：这份清单原来把 GPT/gpt、Claude/claude 这类大小写变体各写了
# 一遍，而两个 grep 本来就带 -i（一个带、一个没带，靠变体补齐）—— 现在两处都带 -i，
# 变体就不需要了。
#
# `bot|spider|crawler` 三个通用词覆盖绝大多数：Googlebot、bingbot、Baiduspider、
# YandexBot、DuckDuckBot、GPTBot、ClaudeBot、CCBot、Amazonbot、Bytespider、AhrefsBot
# …。后面几个是名字里**不含**这三个词的，只能逐个列出。
SPIDER_GENERIC='bot|spider|crawler|slurp|ia_archiver|anthropic|meta-externalagent|oai-'

# 站点特有的长尾：外部文件提供，不存在就只用通用清单。
SPIDER_EXTRA_FILE="${PHPMAN_SPIDER_EXTRA:-$HOME/.phpman/spider-extra.txt}"
SPIDER_EXTRA=''
if [ -f "$SPIDER_EXTRA_FILE" ]; then
  SPIDER_EXTRA=$(grep -vE '^[[:space:]]*(#|$)' "$SPIDER_EXTRA_FILE" | paste -sd'|' -)
  [ -n "$SPIDER_EXTRA" ] && echo "🐛 已加载站点爬虫清单: $SPIDER_EXTRA_FILE"
fi

# 单一来源：下面两处 grep 都用它。原脚本把同一份清单用两种形式各写了一遍
# （一个 grep 模式串、一个 300+ 字符的正则），改一处必须记得改另一处。
SPIDER_RE="$SPIDER_GENERIC"
[ -n "$SPIDER_EXTRA" ] && SPIDER_RE="$SPIDER_RE|$SPIDER_EXTRA"

# ── 日期范围 ──

DATE_START="${1:-$(date +%Y-%m-%d)}"
DATE_END="${2:-$DATE_START}"

# ISO 日期（YYYY-MM-DD）按字符串比较就是按日期比较，所以判断区间只要看三行是否
# 已排好序（sort -C 成功即有序）。
in_range() { printf '%s\n%s\n%s\n' "$2" "$1" "$3" | sort -C; }

# 日期 +1 天。`date -d` 是 GNU 专有、`date -v` 是 BSD 专有，两个都试。
# 原实现只有 `date -d`，在 macOS 上静默失败 —— 于是归档收集循环一次都不跑，
# 脚本看起来「没有日志」。
next_day() {
  date -d "$1 +1 day" +%Y-%m-%d 2>/dev/null \
    || date -j -v+1d -f "%Y-%m-%d" "$1" +%Y-%m-%d 2>/dev/null \
    || return 1
}

echo "╔══════════════════════════════════════════════╗"
echo "║  phpMan Access Log Analysis                 ║"
echo "║  $DATE_START → $DATE_END"
echo "╚══════════════════════════════════════════════╝"
echo ""

# ── 收集日志文件 ──
#
# 日志按日轮转成 access.log.YYYY-MM-DD，未轮转的那份叫 access.log。
#
# 按日期**拼文件名**，不 glob 目录：DreamHost 上那个日志目录不可列举（`ls` 报
# Permission denied），glob 展开不出任何东西，而按名字直读是可以的。glob 作为
# 兜底留在后面，只在按名字一个都没找到时试一次。
LOGS=''
D="$DATE_START"
while in_range "$D" "$DATE_START" "$DATE_END"; do
  f="$WEBLOG_DIR/access.log.$D"
  if [ -f "$f" ]; then
    LOGS="$LOGS $f"
    echo "📁 Found: $D ($(wc -l < "$f" | tr -d ' ') lines)"
  fi
  D=$(next_day "$D") || break
done

# 兜底：目录可列举、但归档不叫 access.log.<YYYY-MM-DD> 的主机
if [ -z "$LOGS" ]; then
  for f in "$WEBLOG_DIR"/access.log.*; do
    [ -f "$f" ] || continue
    d=${f##*/access.log.}
    case "$d" in
      [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]) ;;
      *) continue ;;
    esac
    if in_range "$d" "$DATE_START" "$DATE_END"; then
      LOGS="$LOGS $f"
      echo "📁 Found: $d ($(wc -l < "$f" | tr -d ' ') lines)"
    fi
  done
fi

# 未轮转的当前日志：范围覆盖今天时纳入
TODAY=$(date +%Y-%m-%d)
if [ -f "$WEBLOG_DIR/access.log" ] && in_range "$TODAY" "$DATE_START" "$DATE_END"; then
  LOGS="$LOGS $WEBLOG_DIR/access.log"
  echo "📁 Found: today's log ($(wc -l < "$WEBLOG_DIR/access.log" | tr -d ' ') lines)"
fi

if [ -z "$LOGS" ]; then
  echo "❌ $WEBLOG_DIR 里没有 $DATE_START → $DATE_END 的日志" >&2
  exit 1
fi

# ── 合并 ──

MERGED=$(mktemp)
FILTERED="$MERGED.sorted"
SPIDER_TMP=$(mktemp)
trap 'rm -f "$MERGED" "$FILTERED" "$SPIDER_TMP"' EXIT INT TERM

for f in $LOGS; do cat "$f" >> "$MERGED" 2>/dev/null; done
sort -t'[' -k2 "$MERGED" > "$FILTERED" 2>/dev/null || cp "$MERGED" "$FILTERED"
TOTAL=$(wc -l < "$FILTERED" | tr -d ' ')
echo "📊 Total merged: $TOTAL lines"
echo ""

# ── 1. Spider/Crawler Breakdown ──
echo "━━━ 🕷️  Spider & Crawler Requests ━━━"
grep -iE "$SPIDER_RE" "$FILTERED" > "$SPIDER_TMP"
SPIDER_COUNT=$(wc -l < "$SPIDER_TMP" | tr -d ' ')
# 百分比单独算：把它塞进 echo 的 $() 里需要嵌套三层引号，POSIX sh 下解析不稳
# （awk 收到的是被截断的程序）。-v 传参也顺手避免了 TOTAL=0 时的除零。
SPIDER_PCT=$(awk -v n="$SPIDER_COUNT" -v t="$TOTAL" 'BEGIN {printf "%.1f", (t > 0 ? n/t*100 : 0)}')
echo "Total spider requests: $SPIDER_COUNT ($SPIDER_PCT%)"
echo ""

# Extract simplified UA names
echo "--- By Spider ($DATE_START → $DATE_END) ---"
# 提取每行的 UA。这里对**所有**引号段做匹配，但要排掉请求行 ——
# "GET /robots.txt HTTP/1.1" 也含 "robot"，不排掉就会把 robots.txt 请求
# 当成一个叫「GET /robots.txt HTTP/1.1」的爬虫报出来。
grep -ioE "\"([^\"]*($SPIDER_RE)[^\"]*)\"" "$SPIDER_TMP" | \
  grep -viE '^"(GET|POST|HEAD|PUT|DELETE|OPTIONS|PROPFIND|PATCH) ' | \
  sed 's/"//g' | \
  sed 's/Mozilla\/5\.0 //;s/(compatible; //;s/(Linux; [^)]*)/ /g' | \
  sed 's/AppleWebKit\/[^ ]*//g;s/\(KHTML, like Gecko\)//g' | \
  sed 's/Chrome\/[0-9.]*//g;s/Safari\/[0-9.]*//g' | \
  sed 's/Gecko\/[0-9]*//g;s/Firefox\/[0-9.]*//g' | \
  sed 's/;//g;s/  */ /g;s/^ *//;s/ *$//' | \
  sort | uniq -c | sort -rn | head -25
echo ""

# ── 2. Top spider IPs ──
echo "--- Top Spider IPs ---"
awk '{print $1}' "$SPIDER_TMP" | sort | uniq -c | sort -rn | head -10 | \
  while read count ip; do
    host=$(host "$ip" 2>/dev/null | awk '{print $NF}' | sed 's/\.$//')
    printf "  %5d  %-18s %s\n" "$count" "$ip" "${host:-unknown}"
  done
echo ""

# ── 3. Top page types ──
echo "━━━ 📄 Top Page Types ━━━"
echo "--- By URL pattern ---"
grep -oE '"GET (/[^ ]*)' "$FILTERED" | \
  sed 's/"GET //' | \
  sed 's|/phpMan.php/[a-z]*/[^/]*|/phpMan.php/MODE/NAME|g' | \
  sed 's|/phpMan.php/[a-z]*/[^/]*/[^/]*|/phpMan.php/MODE/NAME/SECTION|g' | \
  sed 's|/phpMan.php/[a-z]*|/phpMan.php/MODE/INDEX|g' | \
  sed 's|/phpMan.php|/phpMan.php (root)|g' | \
  sort | uniq -c | sort -rn | head -20
echo ""

# ── 4. phpMan specific stats ──
echo "━━━ 🔧 phpMan-Specific Stats ━━━"
PHPMAN_TOTAL=$(grep -c '/phpMan.php' "$FILTERED")
echo "Total phpMan requests: $PHPMAN_TOTAL"
echo ""

echo "--- phpMan by mode ---"
grep -oE '"GET /phpMan\.php/[a-z]*' "$FILTERED" | \
  sed 's/"GET \/phpMan\.php\//mode=/;s/$/ (index)/' | sort | uniq -c | sort -rn | head -10
grep -oE '"GET /phpMan\.php\?[^"]*mode=([a-z]+)[^"]*' "$FILTERED" | \
  sed 's/"GET /  /;s/mode=\([a-z]*\).*/ \1 (query)/' | sort | uniq -c | sort -rn | head -10
echo ""

echo "--- phpMan top pages ---"
grep '/phpMan.php/' "$FILTERED" | \
  grep -oE '"GET /phpMan\.php/([a-z]+/[^/ "'"'"']+)' | \
  sed 's/"GET //' | sort | uniq -c | sort -rn | head -20
echo ""

# ── 5. HTTP Status Codes ──
echo "━━━ 📡 HTTP Status Codes ━━━"
awk '{print $9}' "$FILTERED" | sort | uniq -c | sort -rn | head -10 | \
  while read count code; do
    case $code in
      200) desc="OK";;
      301) desc="Moved";;
      302) desc="Found";;
      304) desc="Not Modified";;
      400) desc="Bad Request";;
      403) desc="Forbidden";;
      404) desc="Not Found";;
      429) desc="Rate Limited";;
      500) desc="Server Error";;
      *) desc="";;
    esac
    printf "  %6d  %s %s\n" "$count" "$code" "$desc"
  done
echo ""

# ── 6. Hourly distribution ──
echo "━━━ ⏰ Hourly Traffic ━━━"
awk '{split($4,a,":"); print a[1]}' "$FILTERED" | sed 's/\[//' | sort | uniq -c | \
  awk '{printf "  %s: %6d %s\n", $2, $1, substr("████████████████████",1,$1/100)}'
echo ""

# ── 结尾 ──
# 原实现这里打的是 $LOG —— 一个从未定义过的变量（脚本定义的是 LOG_CURRENT），
# 所以这两行一直打不出来。
echo "═══════════════════════════════════════════════"
echo "Log dir:    $WEBLOG_DIR"
echo "Files:      $(printf '%s' "$LOGS" | wc -w | tr -d ' ') ($TOTAL lines)"
echo "Last entry: $(tail -1 "$FILTERED" | awk '{print $4, $5}' | tr -d '[]')"
echo "═══════════════════════════════════════════════"
