#!/bin/bash
# tailscale-mesh-bench.sh — Tailscale 全网状 iperf3 带宽测试
set -euo pipefail

# ── Help ──
usage() {
  cat <<'EOF'
Usage: tailscale-mesh-bench.sh [control_node] [options]

  Tailscale 全网状 iperf3 带宽测试工具。
  自动发现 Tailscale 网络中的 macOS 节点，两两测试带宽，输出矩阵报表。
  无参数时显示本帮助。

Positional:
  control_node              回退用的参考机器（仅当本机 tailscale status
                            不可用时才 SSH 到它发现节点），默认 macminidong

Options:
  -h, --help                显示此帮助
  --port=N                  iperf3 端口，默认 5201
  --duration=N              每次测试秒数，默认 4
  --json                    同时输出 machines.json 原始数据
  --skip=host1,host2        跳过指定节点（逗号分隔）
  --only=host1,host2        仅测试指定节点（逗号分隔）

节点发现（任选其一，优先前者）:
  1. 本机 tailscale status（本机在网格内时）
  2. SSH 到 control_node 跑 tailscale status
  收编条件：status 里的 macOS 节点，且主机名能在 ~/.ssh/config
  解析为 Host 别名（不再限定 chedong@ 账号，liyangche@ 等亦可）。

Examples:
  # 无参数：显示本帮助
  ./tailscale-mesh-bench.sh

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

# No arguments: show help instead of silently running
if [ $# -eq 0 ]; then
  usage
fi

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
# tailscale status works on ANY node in the mesh, so prefer the local machine
# and only fall back to SSH'ing into the control node if needed.
TAILSCALE="/Applications/Tailscale.app/Contents/MacOS/Tailscale"
log "Phase 1: discovering Tailscale nodes ..."
STATUS=""
# Try local tailscale CLI first (covers chedong@-owned machines where the app runs)
if command -v tailscale >/dev/null 2>&1; then
  STATUS=$(tailscale status 2>/dev/null)
  if [ -z "$STATUS" ]; then
    # /usr/local/bin/tailscale may need the app path; try the app bundle
    [ -x "$TAILSCALE" ] && STATUS=$($TAILSCALE status 2>/dev/null)
  fi
fi
# Fall back to SSH'ing into the control node
if [ -z "$STATUS" ]; then
  log "  local tailscale unavailable, falling back to $CONTROL ..."
  STATUS=$(ssh -o ConnectTimeout=10 "$CONTROL" "$TAILSCALE status 2>/dev/null" 2>/dev/null)
fi
if [ -z "$STATUS" ]; then
  log "ERROR: cannot get tailscale status (local or via $CONTROL)"
  exit 1
fi

# Build parallel arrays: HOSTS, IPS, LABELS
# Instead of hard-coding the chedong@ account, include any macOS node whose
# hostname resolves to a Host alias in ~/.ssh/config (so liyangche@ nodes like
# mbprolia are picked up too). Full DNS names are normalized to the short alias.
SSH_CONFIG="${SSH_CONFIG:-$HOME/.ssh/config}"

# Identify the local node (the machine running this script) from the first
# status line. It can run iperf3 directly without SSH, so it is always included
# even if it has no ~/.ssh/config alias.
LOCAL_HOST=""
LOCAL_IP=""
if [ -n "$STATUS" ]; then
  local_first=$(echo "$STATUS" | head -1)
  LOCAL_IP=$(echo "$local_first" | awk '{print $1}')
  LOCAL_HOST=$(echo "$local_first" | awk '{print $2}')
fi

# Run a command on a node: locally for the local host, via SSH otherwise.
run_on() {
  local node="$1"; shift
  if [ "$node" = "$LOCAL_HOST" ]; then
    sh -c "$*"
  else
    ssh -o ConnectTimeout=10 "$node" "$*"
  fi
}

# Return the short Host alias for a tailscale hostname, or "" if not in config.
# The incoming name may be a short name (mbprolia) or a full FQDN
# (mbprolia.tail2bbe8a.ts.net). Match either the Host alias itself or its HostName.
resolve_alias() {
  local name="$1"
  # 1. Direct match as a Host alias (short name)
  if grep -qE "^[[:space:]]*Host[[:space:]]+${name}([[:space:]]|$)" "$SSH_CONFIG" 2>/dev/null; then
    echo "$name"; return
  fi
  # 2. Single-pass scan: track current Host alias, and if a HostName equals the
  #    incoming name, return that alias. Skips the "*" wildcard block.
  awk -v target="$name" '
    /^[[:space:]]*Host[[:space:]]+/ {
      alias=""
      for (i=2; i<=NF; i++) { if ($i != "*") { alias=$i; break } }
      next
    }
    /^[[:space:]]*HostName[[:space:]]+/ {
      if (alias != "" && $2 == target) { print alias; found=1; exit }
    }
    END { if (!found) exit 1 }
  ' "$SSH_CONFIG" 2>/dev/null
}

HOSTS=""
IPS=""
LABELS=""
while IFS= read -r line; do
  ip=$(echo "$line" | awk '{print $1}')
  host=$(echo "$line" | awk '{print $2}')
  # Only macOS nodes (this is a macOS mesh bench)
  if ! echo "$line" | grep -q "macOS"; then continue; fi
  # Normalize full DNS name to a resolvable .ssh/config alias
  # (|| echo "" guards against set -e killing the loop when awk finds no match)
  alias=$(resolve_alias "$host" || echo "")
  if [ -n "$alias" ]; then
    host="$alias"
  elif [ "$host" = "$LOCAL_HOST" ]; then
    # Local node: usable without SSH, always include it
    log "  local node: $host ($ip)"
  else
    warn "skipping (no .ssh/config alias): $host"; continue
  fi
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
  [ -n "$LABELS" ] && LABELS="$LABELS,"
  LABELS="$LABELS$lab"
  log "  discovered: $host ($ip) $lab"
done <<< "$STATUS"

# Convert to arrays (comma-separated LABELS/EXIT_USAGE so empty entries survive)
HOSTS=($HOSTS)
IPS=($IPS)
IFS=',' read -r -a LABELS <<< "$LABELS"

if [ ${#HOSTS[@]} -lt 2 ]; then
  log "ERROR: need at least 2 nodes, found ${#HOSTS[@]}"
  exit 1
fi

log "  ${#HOSTS[@]} nodes: ${HOSTS[*]}"

# ── Collect node StableID → hostname mapping (from tailscale status --json) ──
# Needed to translate each node's ExitNodeID (a stable ID) back to a hostname.
ID_STATUS=$(tailscale status --json 2>/dev/null)
if [ -z "$ID_STATUS" ]; then
  ID_STATUS=$($TAILSCALE status --json 2>/dev/null)
fi
IDS=""
ID_HOSTS=""
if [ -n "$ID_STATUS" ]; then
  while IFS="|" read -r id hn; do
    [ -z "$id" ] && continue
    IDS="$IDS $id"
    ID_HOSTS="$ID_HOSTS $hn"
  done <<< "$(echo "$ID_STATUS" | python3 -c '
import json,sys
d=json.load(sys.stdin)
nodes=[d.get("Self",{})]+[p for p in d.get("Peer",{}).values()]
for n in nodes:
    hn=n.get("HostName","")
    if hn: print("%s|%s" % (n.get("ID",""), hn))
' 2>/dev/null)"
fi
ID_ARR=($IDS)
IDHOST_ARR=($ID_HOSTS)

# Lookup hostname by StableID
id_to_host() {
  for i in "${!ID_ARR[@]}"; do
    [ "${ID_ARR[$i]}" = "$1" ] && echo "${IDHOST_ARR[$i]}" && return
  done
  echo "$1"
}

# ── Determine each node's active exit node (if any) ──
# Query each node's tailscale prefs for its ExitNodeID, then map to a hostname.
EXIT_USAGE=""      # space-separated hostname → exit node usage
EXIT_USAGE_HOSTS=""
for host in "${HOSTS[@]}"; do
  prefs=$(run_on "$host" "$TAILSCALE debug prefs 2>/dev/null" 2>/dev/null || true)
  enid=$(echo "$prefs" | grep -E '"ExitNodeID"' | sed -E 's/.*"ExitNodeID"[[:space:]]*:[[:space:]]*"([^"]*)".*/\1/')
  if [ -n "$enid" ]; then
    enhost=$(id_to_host "$enid")
    EXIT_USAGE_HOSTS="$EXIT_USAGE_HOSTS $host"
    EXIT_USAGE="$EXIT_USAGE $enhost"
    log "  $host: uses exit node $enhost"
  else
    log "  $host: no exit node"
  fi
done
EXITUSAGE_ARR=($EXIT_USAGE)
EXITUSAGE_HOSTS_ARR=($EXIT_USAGE_HOSTS)

# Lookup the exit-node hostname a node uses ("" if none)
exit_usage_of() {
  for i in "${!EXITUSAGE_HOSTS_ARR[@]}"; do
    [ "${EXITUSAGE_HOSTS_ARR[$i]}" = "$1" ] && echo "${EXITUSAGE_ARR[$i]}" && return
  done
  echo ""
}

# Helper: get IP by hostname
get_ip() {
  for i in "${!HOSTS[@]}"; do
    [ "${HOSTS[$i]}" = "$1" ] && echo "${IPS[$i]}" && return
  done
  echo "?"
}
get_label() {
  # Determine if a node offers exit-node service by scanning the raw status
  # line for that hostname (avoids array-index alignment issues).
  # Matches the short name, or the full FQDN that begins with it (mbprolia).
  line=$(echo "$STATUS" | awk -v h="$1" '$2==h || $2 ~ ("^"h"\\.") {print $0; exit}')
  if echo "$line" | grep -q "exit node"; then
    echo "exit"
  else
    echo ""
  fi
}

# ── Phase 2: Find iperf3, start servers ──
log "Phase 2: starting iperf3 servers (port $PORT) ..."
IPERF_AVAILABLE=""
for host in "${HOSTS[@]}"; do
  bin=$(run_on "$host" \
    "find /usr/local /opt/homebrew -name iperf3 -type f 2>/dev/null | head -1" 2>/dev/null || true)
  if [ -z "$bin" ]; then
    warn "$host: iperf3 not found — skipping as server"
    continue
  fi
  IPERF_AVAILABLE="$IPERF_AVAILABLE $host"
  run_on "$host" \
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
  run_on "$1" \
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

    result=$(run_on "$src_host" \
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
  run_on "$host" "pkill iperf3" 2>/dev/null &
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
  eu=""
  if [ -n "$lab" ]; then
    eu="(is exit node)"
  else
    used=$(exit_usage_of "$host")
    if [ -n "$used" ]; then
      eu="→ exit: $used"
    else
      eu="(no exit node)"
    fi
  fi
  echo "    $host  $ip  ${lab:-}  $eu"
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