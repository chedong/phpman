#!/bin/sh
# atomic-release.sh — switch a phpMan install to a new release in one rename.
#
# Why this exists
# ---------------
# A phpMan deploy ships two things that must agree: phpMan.php and src/. They
# were uploaded as two separate steps, so there was always a window where one
# had landed and the other had not. A request in that window runs the new half
# against the old half, and it 500s. Both directions have bitten us:
#
#   * additive change (new src/ function, new phpMan.php calls it):
#     phpMan.php first -> "Call to undefined function"  (2026-07-13, prod)
#   * removal (constant deleted from src/, old phpMan.php still reads it):
#     src/ first -> "Undefined constant"                (2026-10-02, prod)
#
# No fixed upload order fixes both, because the two directions want opposite
# orders. Uploading both into releases/<id>/ and then swapping a single
# `current` symlink does fix both: rename(2) is atomic, so every request sees
# a matched pair — old/old or new/new, never mixed.
#
# Layout it maintains
# -------------------
#   $PHP_HOME/releases/<id>/phpMan.php     one immutable release
#   $PHP_HOME/releases/<id>/src/
#   $PHP_HOME/current -> releases/<id>     swapped by this script
#   $PHP_HOME/src     -> current/src       so the CLI follows the same release
#   $DOCROOT/phpMan.php -> $PHP_HOME/current/phpMan.php
#
# The web entry script resolves its own directory (PHP resolves __DIR__ through
# symlinks), so it picks up releases/<id>/src automatically. Nothing else moves:
# config, db, logs and cache stay in $PHP_HOME and are shared across releases.
#
# One dependency worth knowing before you move anything: src/config.php finds
# phpman.config.php through PHPMAN_HOME, NOT by walking up from src/. Keeping
# src/ one level below $PHP_HOME (releases/<id>/src) is fine only because of
# that; a lookup relative to src/ would land in the release directory and miss
# the install's real config. See test/unit/test_config_resolution.php.
#
# Usage: atomic-release.sh <php_home> <docroot> <entry_file> <release_id> [noprune]
set -e

PHP_HOME="${1:?usage: atomic-release.sh <php_home> <docroot> <entry_file> <release_id> [noprune]}"
DOCROOT="${2:?docroot required}"
ENTRY="${3:?entry file required}"
ID="${4:?release id required}"
PRUNE="${5:-prune}"

REL="$PHP_HOME/releases/$ID"
[ -f "$REL/$ENTRY" ] || { echo "atomic-release: missing $REL/$ENTRY" >&2; exit 1; }
[ -d "$REL/src" ]    || { echo "atomic-release: missing $REL/src" >&2; exit 1; }

chmod 644 "$REL/$ENTRY"

# 1. Flip the release pointer. mv -T renames the temp symlink over `current`:
#    a single atomic rename, so a concurrent reader sees either the whole old
#    release or the whole new one. `ln -sfn` on its own would unlink first and
#    leave a moment with no `current` at all.
ln -sfn "releases/$ID" "$PHP_HOME/.current.tmp"
mv -T "$PHP_HOME/.current.tmp" "$PHP_HOME/current"

# 2. Put $PHP_HOME/src on the same release. The web path does not need this —
#    it resolves src/ next to the entry script — but the CLI loads
#    $PHP_HOME/src, so without this the CLI would keep running the old code and
#    silently drift from the web. Runs once; afterwards it is already a symlink.
if [ ! -L "$PHP_HOME/src" ]; then
    if [ -e "$PHP_HOME/src" ]; then
        mv "$PHP_HOME/src" "$PHP_HOME/src.pre-symlink.$(date +%Y%m%d-%H%M%S)"
    fi
    ln -s current/src "$PHP_HOME/src"
fi

# 3. Point the web entry script at the current release, same atomic swap. The
#    temp name ends in .tmp so the web server will not execute it as PHP if a
#    request happens to land on it.
ln -sfn "$PHP_HOME/current/$ENTRY" "$DOCROOT/.$ENTRY.tmp"
mv -T "$DOCROOT/.$ENTRY.tmp" "$DOCROOT/$ENTRY"

# 4. Prune old releases, keeping the newest 5 including the current one. Skipped
#    on rollback (noprune), where the release being rolled back FROM is newer
#    than `current` and pruning could delete it. Guarded so a bad glob can never
#    turn into `rm -rf` of something unexpected.
if [ "$PRUNE" != "noprune" ]; then
KEEP=5
CUR=$(basename "$(readlink "$PHP_HOME/current")")
kept=1
for d in $(ls -1dt "$PHP_HOME"/releases/*/ 2>/dev/null | sed 's|/$||'); do
    b=$(basename "$d")
    [ "$b" = "$CUR" ] && continue
    case "$d" in
        "$PHP_HOME"/releases/*) ;;
        *) echo "atomic-release: refusing to prune unexpected path $d" >&2; continue ;;
    esac
    if [ "$kept" -lt "$KEEP" ]; then
        kept=$((kept + 1))
    else
        rm -rf "$d" && echo "atomic-release: pruned old release $b"
    fi
done
fi

echo "atomic-release: current -> $(readlink "$PHP_HOME/current")"
echo "atomic-release: $DOCROOT/$ENTRY -> $(readlink "$DOCROOT/$ENTRY")"
