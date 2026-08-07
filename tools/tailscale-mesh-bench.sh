#!/bin/bash
# tailscale-mesh-bench.sh — Tailscale 全网状 iperf3 带宽测试
set -euo pipefail

# ── Help ──
usage() {
  cat <<'EOF'
Usage: tailscale-mesh-bench.sh [control_node] [options]

  Tailscale 全网状 iperf3 带宽测试工具。
  自动发现 Tailscale 网络中的 macOS 节点，两两测试带宽，输出矩阵报表。

Positional:
  control_node              用于发现节点列表的参考机器，默认 macminidong

Options:
  -h, --help                显示此帮助
  --port=N                  iperf3 端口，默认 5201
  --duration=N              每次测试秒数，默认 4
  --json                    同时输出 machines.json 原始数据
  --skip=host1,host2        跳过指定节点（逗号分隔）
  --only=host1,host2        仅测试指定节点（逗号分隔）

Examples:
  # 全量测试，5 秒每次
  ./tailscale-mesh-bench.sh --duration=5

  # 仅测试北京办公室节点到 Vancouver
  ./tailscale-mesh-bench.sh --only=macminidong,mbairdong --duration=4

  # 跳过问题节点，输出 JSON
  ./tailscale-mesh-bench.sh --skip=mbairdong --json

  # 指定其他控制节点
  ./tailscale-mesh-bench.sh mbairm2dong --duration=3

Node labels (auto-detected from tailscale status):
  exit   — 活跃 exit node，可做网关出口
EOF
  exit 0
}

# Check for help before anything else
for arg in "$@"; do
  case "$arg" in -h|--help) usage ;; esac
done

CONTROL="${1:-macminidong}"
# If first arg starts with --, it's an option, not a control node
if [[ "${1:-}" == --* ]]; then
  CONTROL="macminidong"
fi

PORT=5201
DURATION=4
SKIP=""
ONLY=""
OUTPUT_JSON=""

# Re-parse: first arg might be control_node
ALL_ARGS=("$@")
if [[ "${1:-}" != --* && -n "${1:-}" ]]; then
  ALL_ARGS=("${@:2}")
fi

for arg in "${ALL_ARGS[@]}"; do
  case "$arg" in
    --port=*)    PORT="${arg#*=}" ;;
    --duration=*) DURATION="${arg#*=}" ;;
    --json)      OUTPUT_JSON="machines.json" ;;
    --skip=*)    SKIP="${arg#*=}" ;;
    --only=*)    ONLY="${arg#*=}" ;;
  esac
done

# ── Helpers ──
log()  { echo "[$(date +%H:%M:%S)] $*" >&2; }
warn() { echo "[$(date +%H:%M:%S)] WARN: $*" >&2; }

# ── Phase 1: Discover nodes ──
log "Phase 1: discovering Tailscale nodes from $CONTROL ..."
TAILSCALE="/Applications/Tailscale.app/Contents/MacOS/Tailscale"
STATUS=$(ssh -o ConnectTimeout=10 "$CONTROL" "$TAILSCALE status 2>/dev/null" 2>/dev/null)
if [ -z "$STATUS" ]; then
  log "ERROR: cannot get tailscale status from $CONTROL"
  exit 1
fi

# Build parallel arrays: HOSTS, IPS, LABELS
HOSTS=""
IPS=""
LABELS=""
while IFS= read -r line; do
  ip=$(echo "$line" | awk '{print $1}')
  host=$(echo "$line" | awk '{print $2}')
  if ! echo "$line" | grep -q "chedong@.*macOS"; then continue; fi
  if echo "$line" | grep -q "offline"; then
    warn "skipping offline: $host"; continue
  fi
  if [ -n "$SKIP" ] && echo "$SKIP" | grep -q "$host"; then
    warn "skipping (--skip): $host"; continue
  fi
  if [ -n "$ONLY" ] && ! echo "$ONLY" | grep -q "$host"; then
    continue
  fi
  lab=""
  if echo "$line" | grep -q "exit node"; then lab="exit"; fi
  HOSTS="$HOSTS $host"
  IPS="$IPS $ip"
  LABELS="$LABELS $lab"
  log "  discovered: $host ($ip) $lab"
done <<< "$STATUS"

# Convert to arrays
HOSTS=($HOSTS)
IPS=($IPS)
LABELS=($LABELS)

if [ ${#HOSTS[@]} -lt 2 ]; then
  log "ERROR: need at least 2 nodes, found ${#HOSTS[@]}"
  exit 1
fi

log "  ${#HOSTS[@]} nodes: ${HOSTS[*]}"

# Helper: get IP by hostname
get_ip() {
  for i in "${!HOSTS[@]}"; do
    [ "${HOSTS[$i]}" = "$1" ] && echo "${IPS[$i]}" && return
  done
  echo "?"
}
get_label() {
  for i in "${!HOSTS[@]}"; do
    [ "${HOSTS[$i]}" = "$1" ] && echo "${LABELS[$i]:-}" && return
  done
  echo ""
}

# ── Phase 2: Find iperf3, start servers ──
log "Phase 2: starting iperf3 servers (port $PORT) ..."
IPERF_AVAILABLE=""
for host in "${HOSTS[@]}"; do
  bin=$(ssh -o ConnectTimeout=10 "$host" \
    "find /usr/local /opt/homebrew -name iperf3 -type f 2>/dev/null | head -1" 2>/dev/null || true)
  if [ -z "$bin" ]; then
    warn "$host: iperf3 not found — skipping as server"
    continue
  fi
  IPERF_AVAILABLE="$IPERF_AVAILABLE $host"
  ssh -o ConnectTimeout=10 "$host" \
    "pkill iperf3 2>/dev/null; nohup $bin -s -p $PORT --daemon 2>&1" 2>/dev/null &
  log "  $host: server started ($bin)"
done
wait
sleep 1
IPERF_AVAILABLE=($IPERF_AVAILABLE)

# Helper: is iperf3 available on this host?
has_iperf() {
  for h in "${IPERF_AVAILABLE[@]}"; do
    [ "$h" = "$1" ] && return 0
  done
  return 1
}

# Helper: get iperf3 path on host
get_iperf_path() {
  ssh -o ConnectTimeout=10 "$1" \
    "find /usr/local /opt/homebrew -name iperf3 -type f 2>/dev/null | head -1" 2>/dev/null || true
}

# ── Phase 3: Full mesh test ──
log "Phase 3: running mesh tests (${DURATION}s each) ..."
# Matrix stored as flat file: src_host dst_host bw
MATRIX_FILE=$(mktemp /tmp/ts-mesh-XXXXXX)
total=$(( ${#HOSTS[@]} * (${#HOSTS[@]} - 1) ))
done_count=0

for src_host in "${HOSTS[@]}"; do
  if ! has_iperf "$src_host"; then
    for dst_host in "${HOSTS[@]}"; do
      [ "$src_host" = "$dst_host" ] && continue
      done_count=$((done_count + 1))
      echo "$src_host $dst_host N/A" >> "$MATRIX_FILE"
    done
    continue
  fi
  src_iperf=$(get_iperf_path "$src_host")

  for dst_host in "${HOSTS[@]}"; do
    [ "$src_host" = "$dst_host" ] && continue
    dst_ip=$(get_ip "$dst_host")
    done_count=$((done_count + 1))
    pct=$(( done_count * 100 / total ))

    result=$(ssh -o ConnectTimeout=10 -o ServerAliveInterval=5 "$src_host" \
      "$src_iperf -c $dst_ip -p $PORT -t $DURATION 2>&1" 2>/dev/null || true)

    if echo "$result" | grep -q "Connection refused"; then
      bw="REFUSED"
    elif echo "$result" | grep -q "No route to host\|Host is down\|timed out"; then
      bw="UNREACH"
    elif echo "$result" | grep -q "sender"; then
      sender=$(echo "$result" | grep "sender" | tail -1)
      val=$(echo "$sender" | awk '{for(i=1;i<=NF;i++) if($i=="Mbits/sec") print $(i-1)}')
      if [ -n "$val" ]; then
        bw="${val} Mbps"
      else
        val=$(echo "$sender" | awk '{for(i=1;i<=NF;i++) if($i=="Kbits/sec") print $(i-1)}')
        [ -n "$val" ] && bw="${val} Kbps" || bw="ERR"
      fi
    else
      bw="ERR"
    fi

    echo "$src_host $dst_host $bw" >> "$MATRIX_FILE"
    log "  [$pct%] $src_host → $dst_host: $bw"
  done
done

# Helper: lookup matrix value
matrix_get() {
  awk -v s="$1" -v d="$2" '$1==s && $2==d {print $3; exit}' "$MATRIX_FILE"
}

# ── Phase 4: Cleanup ──
log "Phase 4: stopping servers ..."
for host in "${HOSTS[@]}"; do
  ssh -o ConnectTimeout=5 "$host" "pkill iperf3" 2>/dev/null &
done
wait

# ── Phase 5: Report ──
echo ""
echo "══════════════════════════════════════════════════════════════════"
echo "  Tailscale Mesh Bandwidth Report"
echo "  $(date '+%Y-%m-%d %H:%M:%S %Z') | ${#HOSTS[@]} nodes | ${DURATION}s tests"
echo "══════════════════════════════════════════════════════════════════"
echo ""

COL_W=16
# Header
printf "%-${COL_W}s" "FROM \\ TO"
for dst_host in "${HOSTS[@]}"; do
  printf "%-${COL_W}s" "$dst_host"
done
echo ""
# Separator
printf "%-${COL_W}s" "$(printf '%*s' $COL_W '' | tr ' ' '-')"
for dst_host in "${HOSTS[@]}"; do
  printf "%-${COL_W}s" "$(printf '%*s' $COL_W '' | tr ' ' '-')"
done
echo ""
# Data
for src_host in "${HOSTS[@]}"; do
  printf "%-${COL_W}s" "$src_host"
  for dst_host in "${HOSTS[@]}"; do
    if [ "$src_host" = "$dst_host" ]; then
      printf "%-${COL_W}s" "—"
    else
      bw=$(matrix_get "$src_host" "$dst_host")
      printf "%-${COL_W}s" "${bw:-N/A}"
    fi
  done
  echo ""
done

echo ""
echo "  Node details:"
for host in "${HOSTS[@]}"; do
  ip=$(get_ip "$host")
  lab=$(get_label "$host")
  echo "    $host  $ip  ${lab:-}"
done

# ── Phase 6: JSON ──
if [ -n "$OUTPUT_JSON" ]; then
  {
    echo "{"
    echo "  \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
    echo "  \"duration_sec\": $DURATION,"
    echo "  \"nodes\": ["
    first=true
    for host in "${HOSTS[@]}"; do
      $first || echo ","
      first=false
      ip=$(get_ip "$host")
      lab=$(get_label "$host")
      printf '    {"host":"%s","ip":"%s","label":"%s"}' "$host" "$ip" "${lab:-}"
    done
    echo ""
    echo "  ],"
    echo "  \"matrix\": {"
    first=true
    for src_host in "${HOSTS[@]}"; do
      for dst_host in "${HOSTS[@]}"; do
        [ "$src_host" = "$dst_host" ] && continue
        bw=$(matrix_get "$src_host" "$dst_host")
        $first || echo ","
        first=false
        printf '    "%s→%s": "%s"' "$src_host" "$dst_host" "${bw:-N/A}"
      done
    done
    echo ""
    echo "  }"
    echo "}"
  } > "$OUTPUT_JSON"
  log "  JSON written to $OUTPUT_JSON"
fi

rm -f "$MATRIX_FILE"
log "Done."