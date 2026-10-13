#!/bin/sh
# Rotate the phpMan error log.
#
# Three ways to run it:
#   make log-rotate                     # piped over ssh, nothing to deploy first
#   sh ~/.phpman/cli/log-rotate.sh      # on the server, from the deployed cli/
#   LOG_ROTATE_DRY_RUN=1 sh …/log-rotate.sh   # show what it would do
#
# Safe while the site is serving: the rotation is a rename(2), and error_log()
# opens the path per write, so the next entry lands in a fresh file and no
# restart is needed. Nothing is ever written into the rotated file again.
#
# An empty log is left alone — that is what keeps a daily cron entry from
# filling the directory with rotated copies of nothing.
#
# Override with env vars: PHPMAN_LOG (default ~/.phpman/logs/phpman_error.log),
# LOG_ROTATE_KEEP (default 5), LOG_ROTATE_DRY_RUN=1.

PHPMAN_LOG="${PHPMAN_LOG:-$HOME/.phpman/logs/phpman_error.log}"
KEEP="${LOG_ROTATE_KEEP:-5}"
DRY="${LOG_ROTATE_DRY_RUN:-}"

echo "=== log rotate ==="

if [ ! -f "$PHPMAN_LOG" ]; then
    echo "(no log at $PHPMAN_LOG)"
    exit 0
fi

lines=$(wc -l < "$PHPMAN_LOG" | tr -d ' ')
bytes=$(wc -c < "$PHPMAN_LOG" | tr -d ' ')
if [ "$bytes" -eq 0 ]; then
    echo "nothing to rotate: $PHPMAN_LOG is empty"
else
    stamp=$(date +%Y%m%d-%H%M%S)
    rotated="$PHPMAN_LOG.$stamp"
    if [ -n "$DRY" ]; then
        echo "would rotate: $PHPMAN_LOG ($lines lines, $bytes bytes) -> $rotated"
    else
        mv "$PHPMAN_LOG" "$rotated" || { echo "ERROR: cannot rename $PHPMAN_LOG" >&2; exit 1; }
        echo "rotated: $rotated ($lines lines, $bytes bytes)"
        # Let error_log() create the new file rather than pre-creating it: a
        # request that writes between the rename and now would otherwise be
        # truncated by our open. Only create it if nothing has yet.
        if [ ! -e "$PHPMAN_LOG" ]; then
            : > "$PHPMAN_LOG"
            chmod 644 "$PHPMAN_LOG" 2>/dev/null || true
        fi
    fi
fi

# Prune old rotations, keeping the newest $KEEP. Sorted by *name*, descending:
# the rotated name ends in %Y%m%d-%H%M%S, so name order is chronological order
# and it stays correct when mtimes are equal — which they are for the rotations
# a burst of runs within one second produces. `ls -t` looked right and was not:
# with equal mtimes it falls back to name order *ascending*, so the file just
# created was the last in line and got pruned while an older one survived.
#
# Only files matching this log's own rotated-name pattern are candidates, so a
# bad glob cannot turn into a delete of something else (same guard as
# atomic-release.sh's prune).
kept=0
pruned=0
for f in $(ls -1r "$PHPMAN_LOG".* 2>/dev/null); do
    case "$f" in
        "$PHPMAN_LOG".*) ;;
        *) echo "  refusing to touch unexpected path: $f" >&2; continue ;;
    esac
    kept=$((kept + 1))
    if [ "$kept" -le "$KEEP" ]; then
        continue
    fi
    if [ -n "$DRY" ]; then
        echo "  would prune: $f"
    else
        rm -f "$f" && echo "  pruned: $f"
    fi
    pruned=$((pruned + 1))
done
echo "rotations kept: $((kept > KEEP ? KEEP : kept)) of $kept (LOG_ROTATE_KEEP=$KEEP), pruned now: $pruned"

# Anything else sitting in the log directory is reported, never deleted. The
# LLM-era tooling left batch-*/cache-*/enhance-* logs behind when it was removed
# in v4.10; they are dead weight but they are not ours to reap behind the
# operator's back.
logdir=$(dirname "$PHPMAN_LOG")
others=$(ls -1 "$logdir" 2>/dev/null | grep -v "^$(basename "$PHPMAN_LOG")" | wc -l | tr -d ' ')
if [ "$others" -gt 0 ]; then
    echo ""
    echo "other files in $logdir (untouched): $others"
    du -sh "$logdir" 2>/dev/null | sed 's/^/  total: /'
    ls -1t "$logdir" | grep -v "^$(basename "$PHPMAN_LOG")" | head -5 | sed 's/^/  /'
    [ "$others" -gt 5 ] && echo "  …"
fi

echo "=== done ==="
