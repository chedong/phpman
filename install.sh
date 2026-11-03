#!/bin/bash
# phpMan One-Line Installer
# =============================================================================
# Usage:
#   curl -sSL https://raw.githubusercontent.com/chedong/phpman/master/install.sh | bash
#   curl ... | bash -s -- --update
#   curl ... | bash -s -- --reindex
#   curl ... | bash -s -- --webroot /var/www/html
#   curl ... | bash -s -- --webroot /var/www/html --no-server
#   curl ... | bash -s -- --webroot /var/www/html --data-owner www-data --no-server
#   PHPMAN_PORT=9090 curl ... | bash
#   PHPMAN_HOME=/srv/phpman curl ... | bash
# =============================================================================
set -eo pipefail

INSTALL_DIR="${PHPMAN_HOME:-$HOME/.phpman}"
REPO_URL="https://github.com/chedong/phpman.git"
PORT="${PHPMAN_PORT:-45678}"
WEBROOT=""
WEBROOT_EXPLICIT=false
NO_SERVER=false
NO_SERVER_EXPLICIT=false
DATA_OWNER=""
MODE="install"

STATE_FILE=""
STATE_WEBROOT=""
STATE_NO_SERVER="0"
STATE_DATA_OWNER=""

case "$INSTALL_DIR" in
    /*) ;;
    *)  echo "✗ PHPMAN_HOME must be an absolute path: $INSTALL_DIR" >&2; exit 1 ;;
esac
case "$INSTALL_DIR" in
    *$'\n'*) echo "✗ PHPMAN_HOME must not contain newlines" >&2; exit 1 ;;
esac

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'
YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'

die() {
    echo -e "${RED}✗ $1${NC}" >&2
    exit 1
}

require_abs_path() {
    local value="$1" what="$2"
    [ -n "$value" ] || die "$what needs a non-empty path"
    case "$value" in
        /*) ;;
        *)  die "$what must be an absolute path: $value" ;;
    esac
    case "$value" in
        *$'\n'*) die "$what must not contain newlines" ;;
    esac
}

banner() {
    echo -e "${GREEN}"
    echo '  ____  _          __  ___'
    echo ' |  _ \| |__  _ __|  \/  | __ _ _ __'
    echo ' | |_) | '"'"'_ \| '"'"'_ \ |\/| |/ _` | '"'"'_ \'
    echo ' |  __/| | | | |_) | |  | | (_| | | | |'
    echo ' |_|   |_| |_| .__/|_|  |_|\__,_|_| |_|'
    echo '             |_|   Unix Man Page Web Interface & MCP Server'
    echo -e "${NC}"
}

# ─── Dependency Checks ────────────────────────────────────────────────────────

check_php() {
    if ! command -v php &>/dev/null; then
        echo -e "${RED}✗ PHP not found.${NC}"
        echo ""
        case "$(uname -s)" in
            Darwin) echo "  Install: brew install php" ;;
            Linux)
                if   command -v apt   &>/dev/null; then echo "  Install: sudo apt update && sudo apt install php-cli php-sqlite3 php-mbstring"
                elif command -v dnf   &>/dev/null; then echo "  Install: sudo dnf install php-cli php-sqlite3 php-mbstring"
                elif command -v yum   &>/dev/null; then echo "  Install: sudo yum install php-cli php-sqlite3 php-mbstring"
                elif command -v pacman &>/dev/null; then echo "  Install: sudo pacman -S php php-sqlite"
                else echo "  Please install PHP 8.1+ with SQLite3 and FTS5 support"
                fi ;;
        esac
        echo ""
        exit 1
    fi
    local ver; ver=$(php -r 'echo PHP_VERSION;')
    local major; major=$(php -r 'echo PHP_MAJOR_VERSION;')
    local minor; minor=$(php -r 'echo PHP_MINOR_VERSION;')

    # 8.1, not 8.0: format_common.php calls array_is_list() (8.1) and config.php
    # calls str_starts_with() (8.0) — both on the path of every request.
    if [ "$major" -lt 8 ] || { [ "$major" -eq 8 ] && [ "$minor" -lt 1 ]; }; then
        echo -e "${RED}✗ PHP $ver — need 8.1+ (array_is_list(), str_starts_with())${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓${NC} PHP ${ver}"
}

check_sqlite3() {
    if ! php -r 'exit(extension_loaded("sqlite3") ? 0 : 1);' 2>/dev/null; then
        echo -e "${RED}✗ SQLite3 extension missing.${NC}"
        case "$(uname -s)" in
            Linux) echo "  Install: sudo apt install php-sqlite3 (or php<version>-sqlite3)" ;;
        esac
        exit 1
    fi
    echo -e "${GREEN}✓${NC} SQLite3 extension"
}

check_fts5() {
    local fts; fts=$(php -r '
        try { $db = new SQLite3(":memory:");
              $r = $db->querySingle("SELECT 1 FROM pragma_compile_options WHERE compile_options LIKE \"ENABLE_FTS5\"");
              echo $r ? "yes" : "no"; }
        catch (Exception $e) { echo "error"; }' 2>/dev/null)
    case "$fts" in
        yes) echo -e "${GREEN}✓${NC} FTS5 support" ;;
        no)  echo -e "${RED}✗ SQLite compiled without FTS5. Reinstall PHP with FTS5-enabled SQLite.${NC}"; exit 1 ;;
        *)   echo -e "${YELLOW}⚠${NC} Could not verify FTS5 (non-fatal)" ;;
    esac
}

check_mbstring() {
    # Hard requirement, not a nicety: mb_convert_encoding() (src/format_html.php)
    # and mb_strtolower()/mb_substr() (src/search_index.php, src/cache.php,
    # src/source_search.php) are called unguarded. The install itself dies first —
    # build-index.php reaches mb_strtolower() — so a host without mbstring fails
    # partway through with an opaque fatal instead of here.
    if ! php -r 'exit(extension_loaded("mbstring") ? 0 : 1);' 2>/dev/null; then
        echo -e "${RED}✗ mbstring extension missing.${NC}"
        case "$(uname -s)" in
            Darwin) echo "  Install: brew install php  (mbstring is bundled)" ;;
            Linux)
                if   command -v apt    &>/dev/null; then echo "  Install: sudo apt install php-mbstring (or php<version>-mbstring)"
                elif command -v dnf    &>/dev/null; then echo "  Install: sudo dnf install php-mbstring"
                elif command -v yum    &>/dev/null; then echo "  Install: sudo yum install php-mbstring"
                elif command -v pacman &>/dev/null; then echo "  Install: sudo pacman -S php"
                else echo "  Please install the PHP mbstring extension"
                fi ;;
        esac
        exit 1
    fi
    echo -e "${GREEN}✓${NC} mbstring extension"
}

check_git() {
    if ! command -v git &>/dev/null; then
        echo -e "${RED}✗ git not found — required for install/update.${NC}"
        echo "  macOS: xcode-select --install"
        echo "  Linux: sudo apt install git"
        exit 1
    fi
    echo -e "${GREEN}✓${NC} git $(git --version | awk '{print $NF}')"
}

run_checks() {
    echo ""
    echo -e "${CYAN}── System Check ──${NC}"
    check_php
    check_sqlite3
    check_mbstring
    check_fts5
    check_git
    echo -e "${GREEN}── All checks passed ──${NC}"
    echo ""
}

lint_php() {
    local out
    if ! out=$(php -l "$1" 2>&1); then
        echo -e "${RED}✗ PHP syntax error in $1${NC}" >&2
        printf '%s\n' "$out" | sed 's/^/    /' >&2
        exit 1
    fi
}

# ─── Install state ───────────────────────────────────────────────────────────

# Stored inside .git/ so pulls cannot touch it. Values are read line-by-line
# (never sourced), so a path is data, not shell code.
state_path() {
    printf '%s\n' "$INSTALL_DIR/.git/phpman-install-state"
}

state_get() {
    local key="$1" file="$2" line=""
    [ -f "$file" ] || return 0
    line=$(grep -m1 "^${key}=" "$file" 2>/dev/null || true)
    printf '%s\n' "${line#*=}"
}

load_state() {
    STATE_FILE=$(state_path)
    [ -f "$STATE_FILE" ] || return 0
    STATE_WEBROOT=$(state_get webroot "$STATE_FILE")
    STATE_NO_SERVER=$(state_get no_server "$STATE_FILE")
    STATE_DATA_OWNER=$(state_get data_owner "$STATE_FILE")
}

save_state() {
    [ -d "$INSTALL_DIR/.git" ] || return 0
    STATE_FILE=$(state_path)
    local no_server=0
    if $NO_SERVER; then no_server=1; fi
    {
        printf 'webroot=%s\n' "$WEBROOT"
        printf 'no_server=%s\n' "$no_server"
        printf 'data_owner=%s\n' "$DATA_OWNER"
    } > "$STATE_FILE" 2>/dev/null || {
        echo -e "${YELLOW}⚠  Could not save install state to $STATE_FILE${NC}"
        return 0
    }
    chmod 600 "$STATE_FILE" 2>/dev/null || true
}

# ─── Data directories / ownership ────────────────────────────────────────────

prepare_data_dirs() {
    [ "${DATA_DIRS_PREPARED:-0}" = "1" ] && return 0
    DATA_DIRS_PREPARED=1

    local d
    for d in "$INSTALL_DIR/db" "$INSTALL_DIR/logs" "$INSTALL_DIR/backups"; do
        mkdir -p "$d" || die "cannot create $d"
        if [ -n "$DATA_OWNER" ]; then
            chown "$DATA_OWNER" "$d" 2>/dev/null \
                || die "cannot chown $d to $DATA_OWNER (re-run with sudo?)"
            # Files already in the dir must change hands too, or SQLite cannot
            # write to them. The dirs are flat (db shards / log files).
            local f
            for f in "$d"/*; do
                [ -f "$f" ] || continue
                chown "$DATA_OWNER" "$f" 2>/dev/null || true
            done
            chmod 750 "$d" || die "cannot chmod $d"
        fi
        [ -w "$d" ] || die "$d is not writable by $(id -un)"
        if [ "$(id -u)" = "0" ] && [ -n "$DATA_OWNER" ] && command -v sudo >/dev/null 2>&1; then
            sudo -u "$DATA_OWNER" test -w "$d" 2>/dev/null \
                || die "$DATA_OWNER cannot write $d"
        fi
    done

    if [ -n "$WEBROOT" ] && [ -z "$DATA_OWNER" ]; then
        echo -e "${YELLOW}⚠  Data dirs are owned by $(id -un); make sure the web server user can write:${NC}"
        echo "    $INSTALL_DIR/db and $INSTALL_DIR/logs"
        echo "    Re-run with --data-owner www-data (or chown -R www-data those dirs)."
    fi
}

# A root + --data-owner deploy is only useful if the web user can read the
# source tree through $INSTALL_DIR's parents (a 0700 home blocks everything).
check_web_reader() {
    [ -n "$DATA_OWNER" ] || return 0
    [ "$(id -u)" = "0" ] || return 0
    command -v sudo >/dev/null 2>&1 || return 0

    local f
    for f in "$INSTALL_DIR/phpMan.php" "$INSTALL_DIR/src/config.php" "$INSTALL_DIR/phpman.config.php"; do
        [ -e "$f" ] || continue
        sudo -u "$DATA_OWNER" test -r "$f" 2>/dev/null \
            || die "$DATA_OWNER cannot read $f — make parent dirs traversable (e.g. chmod o+x \"$(dirname "$INSTALL_DIR")\") or install under a shared path via PHPMAN_HOME=..."
    done
}

# ─── Config Generator ─────────────────────────────────────────────────────────

# Compare user config against .example — report new config keys added since install
check_config_updates() {
    local config_file="${1:-$INSTALL_DIR/phpman.config.php}"
    local example="$INSTALL_DIR/phpman.config.php.example"

    if [ ! -f "$config_file" ] || [ ! -f "$example" ]; then
        return
    fi

    # Extract define('KEY' names from .example — robust against multi-line
    # comments. Uses sed to find all define() calls (commented or not).
    local missing=""
    while IFS= read -r key; do
        [ -z "$key" ] && continue
        if ! grep -q "define('$key'" "$config_file"; then
            # Find the first comment line mentioning this key in .example
            local hint; hint=$(grep -B5 "define('$key'" "$example" | grep '//' | tail -1 | sed 's/^[[:space:]]*\/\/[[:space:]]*//')
            [ -z "$hint" ] && hint="$key"
            if [ -z "$missing" ]; then
                missing="$key — $hint"
            else
                missing="$missing"$'\n'"        $key — $hint"
            fi
        fi
    done < <(sed -n "s/.*define('\([A-Z_][A-Z_0-9]*\)'.*/\1/p" "$example" | sort -u)

    if [ -n "$missing" ]; then
        echo ""
        echo -e "${YELLOW}── New config options available (not in your phpman.config.php) ──${NC}"
        echo "  $missing"
        echo ""
        echo "  → Compare: diff $config_file $example"
        echo ""
    fi
}

# Generate ~/.phpman/phpman.config.php — the single source of truth for ALL config.
# The webroot gets no config file at all: do_deploy_webroot() copies phpMan.php +
# phpman.css + phpman.js and patches PHPMAN_HOME into phpMan.php itself.
generate_config() {
    local config_file="$INSTALL_DIR/phpman.config.php"

    if [ -f "$config_file" ]; then
        echo "  phpman.config.php already exists, skipping."
        return
    fi

    local example="$INSTALL_DIR/phpman.config.php.example"
    if [ ! -f "$example" ]; then
        echo -e "${RED}✗ Config template not found: $example${NC}"
        exit 1
    fi

    # Copy from .example — single source of truth for config format
    cp "$example" "$config_file"

    # Generate a random 32-char hex key for MCP authentication
    local mcp_key; mcp_key=$(php -r 'echo bin2hex(random_bytes(16));')
    sed -i '' "s|// define('MCP_API_KEY'.*|define('MCP_API_KEY', '${mcp_key}');|" "$config_file" 2>/dev/null || \
    sed -i "s|// define('MCP_API_KEY'.*|define('MCP_API_KEY', '${mcp_key}');|" "$config_file"

    echo "  Generated: $config_file"
    echo "  MCP_API_KEY: $mcp_key"
    echo "  → MCP clients must send: X-API-Key: $mcp_key"
    echo "  → Edit $config_file to set PHPMAN_BASE_URL, PHPMAN_GA_ID"
}

# ─── Deploy to Webroot ────────────────────────────────────────────────────────

# Write a deployed copy of phpMan.php with PHPMAN_HOME baked in. var_export
# keeps paths with quotes/backslashes safe (the old sed broke on them), and the
# temp-file + mv keeps a concurrent request from reading a partial file.
deploy_phpman_file() {
    local target="$1"
    local src="$INSTALL_DIR/phpMan.php"
    local tmp="$target/.phpMan.php.tmp.$$"

    php -r '
        $src = $argv[1]; $dest = $argv[2]; $home = $argv[3];
        $code = file_get_contents($src);
        if ($code === false) { fwrite(STDERR, "cannot read $src\n"); exit(1); }
        $lines = explode("\n", $code);
        $patched = false;
        foreach ($lines as $i => $line) {
            if (preg_match("/^define\(\x27PHPMAN_HOME\x27, /", $line)) {
                $lines[$i] = "define(\x27PHPMAN_HOME\x27, " . var_export($home, true) . ");";
                $patched = true;
                break;
            }
        }
        if (!$patched) { fwrite(STDERR, "PHPMAN_HOME define line not found in $src\n"); exit(1); }
        if (file_put_contents($dest, implode("\n", $lines)) === false) {
            fwrite(STDERR, "cannot write $dest\n"); exit(1);
        }
    ' -- "$src" "$tmp" "$INSTALL_DIR" || {
        rm -f "$tmp"
        die "failed to patch PHPMAN_HOME into $target/phpMan.php"
    }

    chmod 644 "$tmp" || { rm -f "$tmp"; die "cannot chmod $tmp"; }
    mv -f "$tmp" "$target/phpMan.php" || { rm -f "$tmp"; die "cannot replace $target/phpMan.php"; }
}

do_deploy_webroot() {
    local target="$1"

    [ -d "$target" ] || die "webroot does not exist: $target"
    [ -w "$target" ] || die "webroot is not writable: $target (fix ownership or re-run with sudo)"

    echo "→ Deploying phpMan to $target ..."

    # Copy dispatcher + CSS + JS (only 3 files in webroot)
    deploy_phpman_file "$target"
    cp "$INSTALL_DIR/phpman.css" "$target/" 2>/dev/null || true
    cp "$INSTALL_DIR/phpman.js" "$target/" 2>/dev/null || true
    chmod 644 "$target/phpMan.php" "$target/phpman.css" "$target/phpman.js" 2>/dev/null || true

    prepare_data_dirs
    check_web_reader

    echo ""
    echo -e "${GREEN}✓ Deployed to $target${NC}"
    echo "  phpMan.php  → $target/phpMan.php"
    [ -f "$target/phpman.css" ] && echo "  phpman.css   → $target/phpman.css"
    [ -f "$target/phpman.js" ] && echo "  phpman.js    → $target/phpman.js"
    echo "  config       → $INSTALL_DIR/phpman.config.php (all settings)"
    echo "  data dir     → $INSTALL_DIR/ (src/ cli/ db/ logs/)"
    echo ""
    echo "  Next: configure your web server to serve PHP from $target"
    echo "  Edit $INSTALL_DIR/phpman.config.php to configure:"
    echo "    PHPMAN_BASE_URL  — your public URL for correct link generation"
    echo "    PHPMAN_GA_ID     — for Google Analytics"
}

# ─── Install ──────────────────────────────────────────────────────────────────

do_install() {
    banner
    run_checks

    mkdir -p "$INSTALL_DIR" || die "cannot create $INSTALL_DIR"

    if [ -d "$INSTALL_DIR/.git" ]; then
        echo -e "${YELLOW}phpMan already installed at $INSTALL_DIR${NC}"
        echo "Use --update to pull latest, or --reindex to rebuild index."
        echo ""
    else
        if [ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]; then
            die "$INSTALL_DIR is not empty and is not a git clone — move it aside and re-run"
        fi
        echo "→ Cloning phpMan into $INSTALL_DIR ..."
        git clone --depth 1 "$REPO_URL" "$INSTALL_DIR" \
            || die "git clone failed: $REPO_URL"
    fi

    [ -f "$INSTALL_DIR/phpMan.php" ] || die "phpMan.php missing in $INSTALL_DIR"
    [ -d "$INSTALL_DIR/src" ] || die "src/ missing in $INSTALL_DIR"
    lint_php "$INSTALL_DIR/phpMan.php"

    cd "$INSTALL_DIR"

    # 1. Generate config FIRST — phpMan.php and CLI tools need it
    echo "→ Generating config..."
    generate_config

    # 2. Create data directories (db/, logs/, backups/) under PHPMAN_HOME
    prepare_data_dirs

    # 3. Build FTS5 search index (config must exist first for PHPMAN_HOME)
    echo "→ Building FTS5 search index (man + pydoc3 + ri) ..."
    echo "  (this may take 1–2 minutes on first run)"
    PHPMAN_HOME="$INSTALL_DIR" php cli/build-index.php

    echo ""
    echo -e "${GREEN}✓ phpMan installed!${NC}"
    echo "  phpMan.php  → $INSTALL_DIR/phpMan.php"
    echo "  config      → $INSTALL_DIR/phpman.config.php (all settings)"
    echo "  src/        → $INSTALL_DIR/src/ ($(ls "$INSTALL_DIR"/src/*.php 2>/dev/null | wc -l) files)"
    echo "  cli/        → $INSTALL_DIR/cli/"
    echo "  data dir    → $INSTALL_DIR/"
    echo ""

    # If --webroot was given (now or by a previous run), deploy there too
    if [ -n "$WEBROOT" ]; then
        do_deploy_webroot "$WEBROOT"
    fi

    save_state

    if [ "$NO_SERVER" = false ]; then
        start_server
    fi
}

# ─── Update ───────────────────────────────────────────────────────────────────

do_update() {
    banner

    [ -d "$INSTALL_DIR/.git" ] || die "not installed at $INSTALL_DIR — run without --update first"

    run_checks

    cd "$INSTALL_DIR"

    # Older installers patched the tracked phpMan.php in place, which makes
    # `git pull --ff-only` fail. The placeholder now resolves from PHPMAN_HOME
    # at runtime, so restore only that patch before pulling.
    if ! git diff --quiet -- phpMan.php; then
        local patch_diff
        patch_diff=$(git diff -- phpMan.php)
        case "$patch_diff" in
            *PHPMAN_HOME*)
                echo -e "${YELLOW}⚠  Restoring installer-patched phpMan.php before pull${NC}"
                git checkout -- phpMan.php || die "cannot restore phpMan.php"
                ;;
            *)
                die "phpMan.php has local changes unrelated to PHPMAN_HOME — commit/stash them, then re-run"
                ;;
        esac
    fi

    echo "→ Pulling latest code..."
    git pull --ff-only \
        || die "git pull failed — resolve local changes or divergence in $INSTALL_DIR, then re-run"

    lint_php "$INSTALL_DIR/phpMan.php"

    # Check for new config options in .example not in user's config
    check_config_updates "$INSTALL_DIR/phpman.config.php"

    prepare_data_dirs

    echo "→ Rebuilding FTS5 search index..."
    PHPMAN_HOME="$INSTALL_DIR" php cli/build-index.php

    if [ -n "$WEBROOT" ]; then
        do_deploy_webroot "$WEBROOT"
    fi

    save_state

    echo ""
    echo -e "${GREEN}✓ phpMan updated!${NC}"
    echo "  Version: $(git describe --tags --always 2>/dev/null || echo 'unknown')"

    if [ "$NO_SERVER" = false ]; then
        start_server
    fi
}

# ─── Reindex Only ─────────────────────────────────────────────────────────────

do_reindex() {
    [ -f "$INSTALL_DIR/phpMan.php" ] || die "phpMan.php not found at $INSTALL_DIR — run install first"

    prepare_data_dirs
    echo "→ Rebuilding search index..."
    PHPMAN_HOME="$INSTALL_DIR" php "$INSTALL_DIR/cli/build-index.php"
    echo -e "${GREEN}✓ Index rebuilt.${NC}"
}

# ─── Start Server ─────────────────────────────────────────────────────────────

start_server() {
    echo ""
    echo "  ┌──────────────────────────────────────────────────────────┐"
    printf "  │  %-56s │\n" "${GREEN}phpMan ready${NC}"
    printf "  │  %-56s │\n" "${BLUE}→ http://localhost:${PORT}/phpMan.php${NC}"
    echo "  │                                                          │"
    printf "  │  %-56s │\n" "Man page:    /phpMan.php/man/ls"
    printf "  │  %-56s │\n" "Perldoc:     /phpMan.php/perldoc/File::Basename"
    printf "  │  %-56s │\n" "Python:      /phpMan.php/pydoc/os.path"
    printf "  │  %-56s │\n" "Ruby:        /phpMan.php/ri/String"
    printf "  │  %-56s │\n" "Search:      /phpMan.php/search/git"
    printf "  │  %-56s │\n" "MCP endpoint:/phpMan.php/mcp"
    echo "  │  ${YELLOW}Press Ctrl+C to stop${NC}                                   │"
    echo "  └──────────────────────────────────────────────────────────┘"
    echo ""

    cd "$INSTALL_DIR"
    PHPMAN_HOME="$INSTALL_DIR" php -S "localhost:${PORT}"
}

# ─── Main ────────────────────────────────────────────────────────────────────

# ─── Help ────────────────────────────────────────────────────────────────────

usage() {
    cat <<'USAGE'
phpMan Installer

Usage:
  curl -sSL https://raw.githubusercontent.com/chedong/phpman/master/install.sh | bash

With options (note the -s -- between bash and options):
  curl ... | bash -s -- --update
  curl ... | bash -s -- --webroot /var/www/html --no-server
  curl ... | bash -s -- --webroot /var/www/html --data-owner www-data --no-server
  curl ... | bash -s -- --reindex

Options:
  --update, -u         Pull latest code, reindex and (if configured) redeploy
  --reindex, -r        Rebuild the FTS5 search index only
  --webroot <path>     Deploy phpMan.php + css/js to an absolute webroot path
  --no-server          Skip starting php -S (alias: --no-start)
  --data-owner <user>  chown db/, logs/ and backups/ to <user> and chmod 750
                       (alias: --cache-owner). Needed when the web server
                       runs as another user.
  --help, -h           Show this help

Environment:
  PHPMAN_PORT=9090     Override the default port (45678)
  PHPMAN_HOME=<path>   Install directory (default: ~/.phpman)

The webroot, --no-server and data-owner choices are saved in
$PHPMAN_HOME/.git/phpman-install-state and re-used by later --update runs.
Delete that file to forget a saved target.
USAGE
}

# Parse arguments
while [ $# -gt 0 ]; do
    case "$1" in
        --update|-u)
            MODE="update"; shift ;;
        --reindex|-r)
            MODE="reindex"; shift ;;
        --webroot)
            [ $# -ge 2 ] || die "--webroot needs a path"
            WEBROOT="$2"; WEBROOT_EXPLICIT=true; shift 2 ;;
        --webroot=*)
            WEBROOT="${1#*=}"; WEBROOT_EXPLICIT=true; shift ;;
        --no-server|--no-start)
            NO_SERVER=true; NO_SERVER_EXPLICIT=true; shift ;;
        --data-owner|--cache-owner)
            [ $# -ge 2 ] || die "$1 needs a user name"
            DATA_OWNER="$2"; shift 2 ;;
        --data-owner=*|--cache-owner=*)
            DATA_OWNER="${1#*=}"; shift ;;
        --help|-h)
            usage; exit 0 ;;
        -*)
            die "unknown option: $1 (see --help)" ;;
        *)
            die "unexpected argument: $1 (see --help)" ;;
    esac
done

# Re-use the deployment target and ownership recorded by the previous run.
if [ -d "$INSTALL_DIR/.git" ]; then
    load_state
fi
if [ "$WEBROOT_EXPLICIT" = false ] && [ -n "$STATE_WEBROOT" ]; then
    WEBROOT="$STATE_WEBROOT"
    if [ "$MODE" != "reindex" ]; then
        echo -e "${GREEN}↺${NC} Using previous webroot: $WEBROOT"
    fi
fi
if [ "$NO_SERVER_EXPLICIT" = false ] && [ "$STATE_NO_SERVER" = "1" ]; then
    NO_SERVER=true
fi
if [ -z "$DATA_OWNER" ] && [ -n "$STATE_DATA_OWNER" ]; then
    DATA_OWNER="$STATE_DATA_OWNER"
fi

if [ -n "$WEBROOT" ]; then
    require_abs_path "$WEBROOT" "--webroot"
fi
case "$PORT" in
    ''|*[!0-9]*) die "invalid port: $PORT" ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    die "invalid port: $PORT (expected 1-65535)"
fi
if [ -n "$DATA_OWNER" ]; then
    id -u "$DATA_OWNER" >/dev/null 2>&1 || die "unknown user: $DATA_OWNER"
fi

case "$MODE" in
    install) do_install ;;
    update)  do_update ;;
    reindex) do_reindex ;;
esac
