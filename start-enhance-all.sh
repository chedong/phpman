#!/bin/bash
# start-enhance-all.sh — Rebuild index, warm content caches, then enhance all 5 modes
# Phase 1: rebuild search index (ensures metadata is current)
# Phase 2: generate HTML+MD cache for all pages (fast, no LLM)
# Phase 3: LLM emoji enhancement (slow, rate-limited)
#
# Each phase runs one mode at a time. Modes run independently.
# Already-cached/enhanced entries are skipped automatically.

SERVER="chedong@chedong.com"
LOGDIR="/home/chedong/.phpman/logs"
MODES=("man" "perldoc" "info" "pydoc" "ri")

echo "=== Cleaning stale PID files ==="
ssh "$SERVER" "rm -f ~/.phpman/logs/batch_*.pid"

echo ""
echo "=== Phase 1: Rebuild search index ==="
ssh "$SERVER" "cd ~/.phpman && php cli/build-index.php --cron"
echo "  Index rebuilt."

echo ""
echo "=== Phase 2: Generate HTML + Markdown caches (no LLM) ==="
for MODE in "${MODES[@]}"; do
  echo "  [$MODE] Generating content caches..."
  ssh "$SERVER" "cd ~/.phpman && php cli/batch-enhance.php \
    --mode=$MODE \
    --format=both \
    --cache-only \
    --yes \
    > logs/cache_${MODE}.log 2>&1"
  echo "  [$MODE] Done."
done

echo ""
echo "=== Phase 3: LLM emoji enhancement ==="
for MODE in "${MODES[@]}"; do
  echo "Starting $MODE enhance..."
  ssh "$SERVER" "cd ~/.phpman && nohup php cli/batch-enhance.php \
    --mode=$MODE \
    --format=both \
    --cached-first \
    --yes --fast \
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
