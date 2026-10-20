#!/bin/bash
# Post-deploy health check for the phpMan error log and the web server logs.
#
# `make logcheck` pipes this over ssh and runs it on the server:
#   ssh host "DEMO_ERROR_LOG=... DEMO_ACCESS_LOG=... bash -s" < cli/logcheck.sh
# It is not deployed with the code; it runs from stdin so the version in the
# checkout is always the one that runs.
#
# Why not just `tail`: on 2026-10-03 a 12,597-line burst (12,107 of them
# "ETag DB query failed: no such table: cache") sat at the top of a log whose
# last ten lines looked routine. A tail cannot show a burst, so the summary
# below counts per day and per message type. The tail is kept only so the newest
# entries are visible verbatim.
#
# Informational only: it always exits 0, so a release is never reported as
# failed by what is a read-only check.
#
# Override with env vars: LOGCHECK_DAYS (default 7), LOGCHECK_RECENT_LINES
# (default 2000).

PHPMAN_LOG="${PHPMAN_LOG:-$HOME/.phpman/logs/phpman_error.log}"
PHPMAN_MF_LOG="${PHPMAN_MF_LOG:-$HOME/.phpman/logs/phpman_mf.log}"
SERVER_ERROR_LOG="${DEMO_ERROR_LOG:-}"
ACCESS_LOG="${DEMO_ACCESS_LOG:-}"
DAYS="${LOGCHECK_DAYS:-7}"
RECENT="${LOGCHECK_RECENT_LINES:-2000}"

echo "=== Post-deploy log check ==="

# ─── phpMan application error log ───
echo "--- phpMan error log ---"
if [ ! -f "$PHPMAN_LOG" ]; then
    echo "(no phpman_error.log at $PHPMAN_LOG)"
else
    echo "path:   $PHPMAN_LOG"
    echo "total:  $(wc -l < "$PHPMAN_LOG") lines"
    echo "newest: $(tail -1 "$PHPMAN_LOG" | cut -c1-96)"

    # Rotated history. After `make log-rotate` the current file starts empty, and
    # a report that only ever looks at it would read as "this log has always been
    # clean" — the opposite of what a fresh rotation means. Say how much history
    # sits beside it. Sorted by name, descending: the rotation name ends in
    # %Y%m%d-%H%M%S, and cli/log-rotate.sh prunes in the same order.
    archives=$(ls -1r "$PHPMAN_LOG".* 2>/dev/null)
    if [ -n "$archives" ]; then
        n=$(printf '%s\n' "$archives" | grep -c .)
        bytes=$(du -ch $archives 2>/dev/null | tail -1 | cut -f1)
        newest_archive=$(printf '%s\n' "$archives" | head -1)
        span_from=$(head -1 "$newest_archive" | sed -n 's/^\[\([^]]*\)\].*/\1/p' | awk '{print $1, $2}')
        span_to=$(tail -1 "$newest_archive" | sed -n 's/^\[\([^]]*\)\].*/\1/p' | awk '{print $1, $2}')
        echo "rotated: $n file(s), ${bytes:-?} beside it; newest spans ${span_from:-(empty)} → ${span_to:-(empty)}"
    fi
    echo ""

    # Day labels come from the log itself (entries are contiguous per day), so
    # this needs no date arithmetic and reports the log's own last N days.
    # Per-day volume with the fatal count beside it: a burst of routine-looking
    # lines is what the old tail could not show, and fatals are the part that
    # means a request actually died. Scoped to the window, not the whole log.
    awk -v days="$DAYS" '
        match($0, /^\[[0-9][0-9]-[A-Za-z][A-Za-z][A-Za-z]-[0-9][0-9][0-9][0-9]/) {
            lbl = substr($0, RSTART + 1, RLENGTH - 1)
            if (lbl != prev) { order[++n] = lbl; prev = lbl }
            total[lbl]++
            if ($0 ~ /PHP Fatal error/) fatal[lbl]++
        }
        END {
            start = (n > days) ? n - days + 1 : 1
            printf "  %-14s %8s %8s\n", "day", "entries", "fatals"
            ftotal = 0
            for (i = start; i <= n; i++) {
                f = fatal[order[i]] + 0
                ftotal += f
                printf "  %-14s %8d %8d\n", order[i], total[order[i]], f
            }
            printf "  %-14s %8s %8d\n", "last " days " days", "", ftotal
        }
    ' "$PHPMAN_LOG"
    # Scoped to this file on purpose: after a rotation, "(none)" here would read
    # as "this install has never had a fatal", when the fatals are simply in the
    # archive. The rotated line above says how much history that is.
    last_fatal=$(grep 'PHP Fatal error' "$PHPMAN_LOG" | tail -1 | cut -c1-96)
    if [ -n "$last_fatal" ]; then
        echo "  newest fatal in this log: $last_fatal"
    elif [ -n "$archives" ]; then
        echo "  newest fatal in this log: (none; earlier history is in the rotated file(s) above)"
    else
        echo "  newest fatal in this log: (none)"
    fi
    echo ""

    # A plain tail shows one message repeated; aggregating shows which message
    # is repeating, with URLs collapsed so they group together.
    echo "top message types in the last $RECENT lines:"
    tail -"$RECENT" "$PHPMAN_LOG" \
        | sed -E 's/^\[[^]]*\] +//; s/\[(WEB|CLI): [^]]*\]/[\1]/; s#https?://[^] ]+##g' \
        | cut -c1-70 \
        | sort | uniq -c | sort -rn | head -6 \
        | sed 's/^/  /'
    echo ""

    echo "last 5 entries:"
    tail -5 "$PHPMAN_LOG" | cut -c1-96 | sed 's/^/  /'
fi

# ─── phpMan machine-render telemetry (#251) ───
# Deliberately a separate file from the error log — telemetry is not an error,
# and while the two shared a file this view reported "fatals 0" through the
# whole 2026-10-19 outage. Reported here so the volume stays visible: it is the
# signal for how much first-time machine-format traffic the site is taking.
if [ -f "$PHPMAN_MF_LOG" ]; then
    echo ""
    echo "--- phpMan machine-render telemetry (not errors) ---"
    echo "path:   $PHPMAN_MF_LOG"
    echo "total:  $(wc -l < "$PHPMAN_MF_LOG") lines"
    mf_archives=$(ls -1r "$PHPMAN_MF_LOG".* 2>/dev/null)
    if [ -n "$mf_archives" ]; then
        mf_n=$(printf '%s\n' "$mf_archives" | grep -c .)
        mf_bytes=$(du -ch $mf_archives 2>/dev/null | tail -1 | cut -f1)
        echo "rotated: $mf_n file(s), ${mf_bytes:-?} beside it"
    fi
fi

# ─── Web server error log ───
echo ""
echo "--- Server error log ---"
if [ -n "$SERVER_ERROR_LOG" ] && [ -f "$SERVER_ERROR_LOG" ]; then
    echo "path: $SERVER_ERROR_LOG"
    total=$(tail -n "$RECENT" "$SERVER_ERROR_LOG" | grep -c .)
    noise=$(tail -n "$RECENT" "$SERVER_ERROR_LOG" | grep -c 'ModSecurity')
    echo "last $RECENT lines: $total entries, $noise of them ModSecurity noise"
    if [ "$total" -eq "$noise" ]; then
        echo "  (nothing but ModSecurity noise)"
    else
        echo "  non-ModSecurity entries (last 5):"
        tail -n "$RECENT" "$SERVER_ERROR_LOG" | grep -v 'ModSecurity' | tail -5 | cut -c1-140 | sed 's/^/  /'
    fi
else
    echo "(not configured or not found: DEMO_ERROR_LOG='$SERVER_ERROR_LOG')"
fi

# ─── Access log 5xx ───
echo ""
echo "--- Access log: 5xx ---"
if [ -n "$ACCESS_LOG" ] && [ -f "$ACCESS_LOG" ]; then
    echo "path: $ACCESS_LOG"
    fivexx=$(tail -n "$RECENT" "$ACCESS_LOG" | grep -cE '" 5[0-9][0-9] ')
    if [ "$fivexx" -eq 0 ]; then
        echo "  none in the last $RECENT lines"
    else
        echo "  $fivexx in the last $RECENT lines:"
        tail -n "$RECENT" "$ACCESS_LOG" | grep -E '" 5[0-9][0-9] ' | tail -5 | cut -c1-140 | sed 's/^/  /'
    fi
else
    echo "(not configured or not found: DEMO_ACCESS_LOG='$ACCESS_LOG')"
fi

echo ""
echo "=== Log check complete ==="
