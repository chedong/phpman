#!/bin/sh
# rollback.sh — flip a phpMan install back to the previous release.
#
# Releases are the rollback targets: the entry script and src/ are symlinks into
# releases/<id>/, so rolling back is the same atomic rename a deploy uses —
# nothing is copied and there is no mixed-version window. This replaces the old
# behaviour of copying a .bak file over the entry script, which would now write
# *through* the symlink and corrupt the current release instead of switching it.
#
# Usage: rollback.sh <php_home> <docroot> <entry_file> [steps]
set -e

# Same GNU `mv -T` requirement as atomic-release.sh, which this delegates to.
# Checked here too so a BSD/macOS mv fails with a clear message instead of an
# "illegal option" from the inner script. See the note there.
if ! mv --version >/dev/null 2>&1; then
    echo "rollback: GNU mv (coreutils) required; BSD/macOS mv is not supported" >&2
    exit 1
fi

PHP_HOME="${1:?usage: rollback.sh <php_home> <docroot> <entry_file> [steps]}"
DOCROOT="${2:?docroot required}"
ENTRY="${3:?entry file required}"
STEPS="${4:-1}"

CUR=$(basename "$(readlink "$PHP_HOME/current")")
[ -n "$CUR" ] || { echo "rollback: no current release" >&2; exit 1; }

# Walk releases newest-first, skipping the current one, and take the STEPS'th.
TARGET=""
n=0
for d in $(ls -1dt "$PHP_HOME"/releases/*/ 2>/dev/null | sed 's|/$||'); do
    b=$(basename "$d")
    [ "$b" = "$CUR" ] && continue
    n=$((n + 1))
    if [ "$n" -eq "$STEPS" ]; then
        TARGET="$b"
        break
    fi
done

[ -n "$TARGET" ] || { echo "rollback: no release $STEPS step(s) back from '$CUR'" >&2; exit 1; }

echo "rollback: $CUR -> $TARGET"
# noprune: a rollback must never delete the release it is rolling back FROM.
sh "$PHP_HOME/deploy/atomic-release.sh" "$PHP_HOME" "$DOCROOT" "$ENTRY" "$TARGET" noprune
