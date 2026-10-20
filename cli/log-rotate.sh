#!/bin/sh
# Rotate the phpMan logs: the application error log and the machine-render
# telemetry log (#251 — they are separate files, so both rotate here).
#
# Three ways to run it:
#   make log-rotate                     # piped over ssh, nothing to deploy first
#   sh ~/.phpman/cli/log-rotate.sh      # on the server, from the deployed cli/
#   LOG_ROTATE_DRY_RUN=1 sh …/log-rotate.sh   # show what it would do
#
# Safe while the site is serving: the rotation is a rename(2), and both writers
# (error_log() and phpManLogMachine()) open the path per write, so the next entry
# lands in a fresh file and no restart is needed. Nothing is ever written into
# the rotated file again.
#
# An empty log is left alone — that is what keeps a daily cron entry from
# filling the directory with rotated copies of nothing.
#
# Override with env vars: PHPMAN_LOG (default ~/.phpman/logs/phpman_error.log),
# PHPMAN_MF_LOG (default ~/.phpman/logs/phpman_mf.log),
# LOG_ROTATE_KEEP (default 5), LOG_ROTATE_DRY_RUN=1.

PHPMAN_LOG="${PHPMAN_LOG:-$HOME/.phpman/logs/phpman_error.log}"
PHPMAN_MF_LOG="${PHPMAN_MF_LOG:-$HOME/.phpman/logs/phpman_mf.log}"
KEEP="${LOG_ROTATE_KEEP:-5}"
DRY="${LOG_ROTATE_DRY_RUN:-}"

echo "=== log rotate ==="

# Rotate one log, then prune its own rotations. Split out because there are two
# files now and the prune must stay scoped to each one's own name pattern.
rotate_one() {
    log="$1"

    if [ ! -f "$log" ]; then
        echo "(no log at $log)"
        return 0
    fi

    lines=$(wc -l < "$log" | tr -d ' ')
    bytes=$(wc -c < "$log" | tr -d ' ')
    if [ "$bytes" -eq 0 ]; then
        echo "nothing to rotate: $log is empty"
    else
        stamp=$(date +%Y%m%d-%H%M%S)
        rotated="$log.$stamp"
        if [ -n "$DRY" ]; then
            echo "would rotate: $log ($lines lines, $bytes bytes) -> $rotated"
        else
            mv "$log" "$rotated" || { echo "ERROR: cannot rename $log" >&2; return 1; }
            echo "rotated: $rotated ($lines lines, $bytes bytes)"
            # Let the writer create the new file rather than pre-creating it: a
            # request that writes between the rename and now would otherwise be
            # truncated by our open. Only create it if nothing has yet.
            if [ ! -e "$log" ]; then
                : > "$log"
                chmod 644 "$log" 2>/dev/null || true
            fi
        fi
    fi

    # Prune old rotations, keeping the newest $KEEP. Sorted by *name*,
    # descending: the rotated name ends in %Y%m%d-%H%M%S, so name order is
    # chronological order and it stays correct when mtimes are equal — which
    # they are for the rotations a burst of runs within one second produces.
    # `ls -t` looked right and was not: with equal mtimes it falls back to name
    # order *ascending*, so the file just created was the last in line and got
    # pruned while an older one survived.
    #
    # Only files matching this log's own rotated-name pattern are candidates, so
    # a bad glob cannot turn into a delete of something else (same guard as
    # atomic-release.sh's prune).
    kept=0
    pruned=0
    for f in $(ls -1r "$log".* 2>/dev/null); do
        case "$f" in
            "$log".*) ;;
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
}

rotate_one "$PHPMAN_LOG" || exit 1
echo ""
rotate_one "$PHPMAN_MF_LOG" || exit 1

# Anything else sitting in the log directory is reported, never deleted. The
# LLM-era tooling left batch-*/cache-*/enhance-* logs behind when it was removed
# in v4.10; they are dead weight but they are not ours to reap behind the
# operator's back.
logdir=$(dirname "$PHPMAN_LOG")
others=$(ls -1 "$logdir" 2>/dev/null \
    | grep -v "^$(basename "$PHPMAN_LOG")" \
    | grep -v "^$(basename "$PHPMAN_MF_LOG")" | wc -l | tr -d ' ')
if [ "$others" -gt 0 ]; then
    echo ""
    echo "other files in $logdir (untouched): $others"
    du -sh "$logdir" 2>/dev/null | sed 's/^/  total: /'
    ls -1t "$logdir" | grep -v "^$(basename "$PHPMAN_LOG")" \
        | grep -v "^$(basename "$PHPMAN_MF_LOG")" | head -5 | sed 's/^/  /'
    [ "$others" -gt 5 ] && echo "  …"
fi

echo "=== done ==="
