#!/bin/bash
# phpman-log-analyze.sh — Web access log analysis for phpMan
# Usage:
#   bash log-analyze.sh                           # Today
#   bash log-analyze.sh 2026-07-06                # Specific day
#   bash log-analyze.sh 2026-07-05 2026-07-06    # Date range

LOG_DIR="/home/chedong/logs/chedong.com/https"
LOG_CURRENT="$LOG_DIR/access.log"

# Date handling: YYYY-MM-DD → rotated filename, or use current log
if [ $# -ge 1 ]; then
  DATE_START="$1"
  DATE_END="${2:-$1}"
else
  DATE_START=$(date +'%Y-%m-%d')
  DATE_END="$DATE_START"
fi

echo "╔══════════════════════════════════════════════╗"
echo "║  phpMan Spider & Traffic Analysis           ║"
echo "║  $DATE_START → $DATE_END"
echo "╚══════════════════════════════════════════════╝"
echo ""

# Collect log files for date range
LOGS=""
D="$DATE_START"
while [ "$D" != "$(date -d "$DATE_END +1 day" '+%Y-%m-%d' 2>/dev/null)" ] && [ "$D" != "$DATE_END" ]; do
  ROTATED="$LOG_DIR/access.log.$D"
  if [ -f "$ROTATED" ]; then
    LOGS="$LOGS $ROTATED"
    echo "📁 Found: $D ($(wc -l < "$ROTATED") lines)"
  fi
  D=$(date -d "$D +1 day" '+%Y-%m-%d' 2>/dev/null)
done

# Also include current log if date matches today
TODAY=$(date +'%Y-%m-%d')
if [ "$DATE_START" == "$TODAY" ] || [ "$DATE_END" == "$TODAY" ] || [ "$DATE_START" \> "$TODAY" ]; then
  if [ -f "$LOG_CURRENT" ]; then
    LOGS="$LOGS $LOG_CURRENT"
    echo "📁 Found: today's log ($(wc -l < "$LOG_CURRENT") lines)"
  fi
fi

if [ -z "$LOGS" ]; then
  echo "❌ No log files found for $DATE_START → $DATE_END"
  exit 1
fi

# Merge all matching logs
MERGED=$(mktemp)
for f in $LOGS; do cat "$f" >> "$MERGED" 2>/dev/null; done
sort -t'[' -k2 "$MERGED" > "${MERGED}.sorted" 2>/dev/null || true
FILTERED="${MERGED}.sorted"
test -s "$FILTERED" || cp "$MERGED" "$FILTERED"
TOTAL=$(wc -l < "$FILTERED")
echo "📊 Total merged: $TOTAL lines"
echo ""

# ── 1. Spider/Crawler Breakdown ──
echo "━━━ 🕷️  Spider & Crawler Requests ━━━"
SPIDER_TMP=$(mktemp)
grep -i 'bot\|spider\|crawler\|GPT\|Claude\|ByteSpider\|Perplexity\|ChatGPT\|OAI\|Amazonbot\|ccbot\|anthropic\|GoogleOther\|Google-Extended\|Barkrowler\|PetalBot\|Ahrefs\|DotBot\|meta-externalagent\|Baiduspider\|Yandex\|Sogou\|Semrush\|Mojeek\|DataForSeo\|Criteo\|Seznam\|Bingbot\|DuckDuckGo\|FacebookExternalHit\|Twitterbot\|Slurp\|ia_archiver\|YisouSpider\|360Spider\|Bytespider' "$FILTERED" > "$SPIDER_TMP"
SPIDER_COUNT=$(wc -l < "$SPIDER_TMP")
echo "Total spider requests: $SPIDER_COUNT ($(awk "BEGIN {printf \"%.1f\", $SPIDER_COUNT/$TOTAL*100}")%)"
echo ""

# Extract simplified UA names
echo "--- By Spider ($(date -d 'today' '+%Y-%m-%d' 2>/dev/null || date '+%Y-%m-%d')) ---"
grep -oE '"([^"]*(?:bot|Bot|spider|Spider|Crawler|crawler|GPT[^"]*|Claude[^"]*|ByteSpider[^"]*|Perplexity[^"]*|ChatGPT[^"]*|OAI[^"]*|Amazonbot[^"]*|ccbot[^"]*|anthropic[^"]*|GoogleOther[^"]*|Google-Extended[^"]*|Barkrowler[^"]*|PetalBot[^"]*|Ahrefs[^"]*|DotBot[^"]*|meta-externalagent[^"]*|Baiduspider[^"]*|Yandex[^"]*|Sogou[^"]*|Semrush[^"]*|Mojeek[^"]*|DataForSeo[^"]*|Criteo[^"]*|Seznam[^"]*|Bing[^"]*bot[^"]*|DuckDuck[^"]*|Twitter[^"]*|Slurp[^"]*|ia_archiver[^"]*|Yisou[^"]*|360Spider[^"]*|Baidu[^"]*|Googlebot[^"]*)[^"]*)"' "$SPIDER_TMP" | \
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
  sed 's|/blog/archives/[0-9]*.html|/blog/archives/NNNNNN.html|g' | \
  sed 's|/blog/docs/[a-z_]*.html|/blog/docs/PAGE.html|g' | \
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
awk '{split($4,a,":"); print a[1]}' "$FILTERED" | sed 's/\[//' | sort | uniq -c | awk '{printf "  %s: %6d %s\n", $2, $1, substr("████████████████████",1,$1/100)}'
echo ""

# ── Cleanup ──
rm -f "$FILTERED" "$SPIDER_TMP"

echo "═══════════════════════════════════════════════"
echo "Log file: $LOG ($(wc -c < "$LOG") bytes)"
echo "Last entry: $(tail -1 "$LOG" | awk '{print $4, $5}' | tr -d '[]')"
echo "═══════════════════════════════════════════════"
