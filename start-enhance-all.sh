#!/bin/bash
# start-enhance-all.sh — Warm content caches, then enhance all 5 modes
# Phase 1: generate HTML+MD cache for all pages (fast, no LLM, no rate limit)
# Phase 2: LLM emoji enhancement (rate-limited, default 60s)
#
# Already-cached/enhanced entries are skipped automatically (no --rebuild).
# Clean up stale PIDs before starting.

SERVER="chedong@chedong.com"
LOGDIR="/home/chedong/.phpman/logs"
MODES=("man" "perldoc" "info" "pydoc" "ri")
RATE=${1:-60}  # seconds between LLM calls, default 60

echo "=== Cleaning stale PID files ==="
ssh "$SERVER" "rm -f ~/.phpman/logs/batch_*.pid"

echo ""
echo "=== Phase 1: Generate HTML + Markdown caches (no LLM, skips cached) ==="
for MODE in "${MODES[@]}"; do
  echo "  [$MODE] Starting cache generation..."
  ssh "$SERVER" "cd ~/.phpman && php cli/batch-enhance.php \
    --mode=$MODE \
    --format=both \
    --cache-only \
    --yes \
    > logs/cache_${MODE}.log 2>&1"
  echo "  [$MODE] Done."
done

echo ""
echo "=== Phase 2: LLM emoji enhancement (rate=${RATE}s, skips enhanced) ==="
for MODE in "${MODES[@]}"; do
  echo "Starting $MODE enhance..."
  ssh "$SERVER" "cd ~/.phpman && nohup php cli/batch-enhance.php \
    --mode=$MODE \
    --format=both \
    --cached-first \
    --rate-limit=$RATE \
    --yes \
    --pid-file=logs/batch_${MODE}.pid \
    > logs/batch_${MODE}.log 2>&1 &"
  sleep 3
  PID=$(ssh "$SERVER" "cat ~/.phpman/logs/batch_${MODE}.pid 2>/dev/null | cut -d' ' -f1")
  echo "  $MODE: PID $PID"
done

echo ""
echo "=== Started. Check status: ==="
echo "  ssh $SERVER 'php ~/.phpman/cli/batch-enhance.php --status'"
echo ""
echo "=== Monitor logs: ==="
for MODE in "${MODES[@]}"; do
  echo "  ssh $SERVER 'tail -5 ~/.phpman/logs/batch_${MODE}.log'"
done
echo ""
echo "=== Cache generation logs: ==="
for MODE in "${MODES[@]}"; do
  echo "  ssh $SERVER 'tail -3 ~/.phpman/logs/cache_${MODE}.log'"
done
