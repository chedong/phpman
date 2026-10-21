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
# One 5xx line cannot say *why* it was a 5xx: a client that gave up mid-response
# (EPIPE) and a process that was killed look identical from the access log alone.
# The server error log holds the missing half — a "Broken pipe" line carries the
# client IP and the minute — so this pairs the two on (MMDD HH:MM, IP) and splits
# the 5xx count into families. The two logs timestamp differently (error log:
# "Tue Oct 20 15:02:19 2026"; access log: "20/Oct/2026:15:02:19 -0700"), so both
# are normalised to MMDD HH:MM inside awk. (#249)
#
# The window is printed alongside the count on purpose. This section reads only
# the last $RECENT lines of the live file, and on 2026-10-20 it reported "none"
# while that same file held 38 503s — every one of them outside the window. A
# count that can silently misstate its own scope is worse than no count.
echo ""
echo "--- Access log: 5xx ---"
if [ -n "$ACCESS_LOG" ] && [ -f "$ACCESS_LOG" ]; then
    echo "path: $ACCESS_LOG"
    # awk reads the error log itself (BEGIN + getline) so the access log can
    # arrive on stdin. Two file arguments would need the NR==FNR idiom to tell
    # them apart, and that misfires when the first file is empty — the access
    # log would then be read as if it were the error log.
    errlog="$SERVER_ERROR_LOG"
    [ -f "$errlog" ] || errlog=/dev/null
    tail -n "$RECENT" "$ACCESS_LOG" | awk -v errlog="$errlog" -v errlabel="$SERVER_ERROR_LOG" '
        BEGIN {
            split("Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec", mn, " ")
            for (i = 1; i <= 12; i++) mon[mn[i]] = sprintf("%02d", i)

            # Pass 1 — the server error log. Only "Broken pipe" lines are kept:
            # they are the server side of the EPIPE family. (70007/APR_TIMEUP
            # lines are a different errno and a different family — deliberately
            # not collected here.)
            #
            # The count of what was collected is reported in END: the two logs
            # rotate on their own schedules, so "EPIPE: 0" is evidence of nothing
            # when the error log has already rotated past the access window.
            while ((getline line < errlog) > 0) {
                if (index(line, "Broken pipe") == 0) continue
                if (match(line, /\[[A-Z][a-z][a-z] +[A-Z][a-z][a-z] +[0-9]+ [0-9][0-9]:[0-9][0-9]/)) {
                    # "[Tue Oct 20 10:01" → p[2]=month, p[3]=day, p[4]=HH:MM.
                    # p[1] is the weekday — indexing the month map with it yields
                    # an empty month, so every key silently becomes "00 <day>".
                    split(substr(line, RSTART + 1, RLENGTH - 1), p, " ")
                    k = mon[p[2]] sprintf("%02d", p[3]) " " p[4]
                    ets = p[2] " " p[3] " " p[4]
                    # The error log writes the client as IP:port; the access log
                    # carries the bare address. Without dropping the port no key
                    # ever matches. (Apache appends :port for IPv6 too, so the
                    # same strip applies there.)
                    if (match(line, /client [0-9a-fA-F:.]+/)) {
                        ip = substr(line, RSTART + 7, RLENGTH - 7)
                        sub(/:[0-9]+$/, "", ip)
                        epipe[k " " ip] = 1
                        ebreak++
                        if (efirst == "") efirst = ets
                        elast = ets
                    }
                }
            }
            close(errlog)
        }
        {
            lines++
            if (match($0, /\[[0-9][0-9]\/[A-Z][a-z][a-z]\/[0-9][0-9][0-9][0-9]:[0-9][0-9]:[0-9][0-9]:[0-9][0-9]/)) {
                raw = substr($0, RSTART + 1, RLENGTH - 1)
                if (wfirst == "") wfirst = raw
                wlast = raw
                split(raw, q, /[\/:]/)
                key = mon[q[2]] q[1] " " q[4] ":" q[5]
            } else { key = "" }

            if ($9 + 0 < 500 || $9 + 0 > 599) next
            n++
            recent5[n % 5] = $0
            if (key != "" && ((key " " $1) in epipe)) { fam_epipe++; next }
            # "-" is what Apache writes when no body was sent, i.e. zero bytes.
            b = ($10 == "-" || $10 == "") ? 0 : $10 + 0
            if (b > 0) fam_body++; else fam_zero++
        }
        END {
            printf "  window: last %d lines", lines
            if (wfirst != "") printf ", %s → %s", wfirst, wlast
            printf "\n"
            if (n > 0) {
                printf "  5xx in window: %d\n", n
                printf "    + body, Broken pipe in server log (EPIPE family): %d\n", fam_epipe + 0
                printf "    + body, no Broken pipe match:                     %d\n", fam_body + 0
                printf "    zero-byte body:                                   %d\n", fam_zero + 0
            } else {
                print "  5xx in window: 0"
            }
            # What the pairing source actually holds. The two logs rotate on
            # their own schedules, so "EPIPE: 0" means "no client gave up" only
            # while the error log still reaches back over the access window —
            # otherwise it means the evidence aged out, which is a different
            # claim. Say which one it is.
            if (ebreak > 0)
                printf "    pairing source: %d Broken pipe line(s), %s → %s\n", ebreak, efirst, elast
            else
                printf "    pairing source: no Broken pipe lines in %s — EPIPE=0 proves nothing here\n", errlabel
            if (n == 0) exit
            print "  last 5:"
            start = (n > 5) ? n - 4 : 1
            for (i = start; i <= n; i++) printf "    %s\n", substr(recent5[i % 5], 1, 140)
        }
    '
else
    echo "(not configured or not found: DEMO_ACCESS_LOG='$ACCESS_LOG')"
fi

echo ""
echo "=== Log check complete ==="

# Informational only: a release must never be reported as failed by a read-only
# check. Stated explicitly rather than left to the last command's status.
exit 0
