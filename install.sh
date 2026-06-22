#!/bin/bash
# phpMan One-Line Installer
# =============================================================================
# Usage:
#   curl -sSL https://raw.githubusercontent.com/chedong/phpman/master/install.sh | bash
#   curl ... | bash -s -- --update
#   curl ... | bash -s -- --reindex
#   curl ... | bash -s -- --webroot /var/www/html
#   curl ... | bash -s -- --webroot /var/www/html --no-server
#   PHPMAN_PORT=9090 curl ... | bash
# =============================================================================
set -e

INSTALL_DIR="$HOME/.phpman"
REPO_URL="https://github.com/chedong/phpman.git"
PORT="${PHPMAN_PORT:-45678}"
WEBROOT=""
NO_SERVER=false

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'
YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'

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
                if   command -v apt   &>/dev/null; then echo "  Install: sudo apt update && sudo apt install php-cli php-sqlite3"
                elif command -v dnf   &>/dev/null; then echo "  Install: sudo dnf install php-cli php-sqlite3"
                elif command -v yum   &>/dev/null; then echo "  Install: sudo yum install php-cli php-sqlite3"
                elif command -v pacman &>/dev/null; then echo "  Install: sudo pacman -S php php-sqlite"
                else echo "  Please install PHP 7.2+ with SQLite3 and FTS5 support"
                fi ;;
        esac
        echo ""
        exit 1
    fi
    local ver; ver=$(php -r 'echo PHP_VERSION;')
    local major; major=$(php -r 'echo PHP_MAJOR_VERSION;')
    local minor; minor=$(php -r 'echo PHP_MINOR_VERSION;')

    if [ "$major" -lt 7 ] || { [ "$major" -eq 7 ] && [ "$minor" -lt 2 ]; }; then
        echo -e "${RED}✗ PHP $ver — need 7.2+ (for SQLite3 FTS5)${NC}"
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
    check_fts5
    check_git
    echo -e "${GREEN}── All checks passed ──${NC}"
    echo ""
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

generate_config() {
    local target_dir="${1:-$INSTALL_DIR}"
    local config_file="$target_dir/phpman.config.php"

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

    local home; home=$(php -r 'echo getenv("HOME") ?: ($_SERVER["HOME"] ?? "");')

    # Uncomment and set PHPMAN_HOME
    sed -i '' "s|// define('PHPMAN_HOME'.*|define('PHPMAN_HOME', '${home}/.phpman');|" "$config_file" 2>/dev/null || \
    sed -i "s|// define('PHPMAN_HOME'.*|define('PHPMAN_HOME', '${home}/.phpman');|" "$config_file"

    echo "  Generated: $config_file"
    echo "  → Edit $config_file to set PHPMAN_BASE_URL and PHPMAN_GA_ID"
}

# Generate ~/.phpman/config.local.php — backend secrets (LLM keys, MCP auth).
# This file is NEVER in webroot and NOT tracked by git.
generate_local_config() {
    local local_config="$HOME/.phpman/config.local.php"

    if [ -f "$local_config" ]; then
        echo "  config.local.php already exists, skipping."
        return
    fi

    local mcp_key; mcp_key=$(php -r 'echo bin2hex(random_bytes(16));')

    cat > "$local_config" << 'LOCALEOF'
<?php
// phpMan backend configuration — NOT in webroot, NOT tracked by git.
// Loaded by src/config.php after all defaults are set.
// Only define values you need to override here.
//
// === LLM emoji enhancement (v4.0) ===
// define('LLM_API_KEY', '');
// define('LLM_API_URL', '');
// define('LLM_MODEL', '');
// define('LLM_MAX_TOKENS', 4096);
// define('PHPMAN_ENHANCE_MAX_CHARS', 32000);
//
// === Fallback LLM (v4.2.2) ===
// define('LLM_FALLBACKS', [
//     ['url' => 'https://api.openai.com/v1/chat/completions', 'key' => 'sk-xxx', 'model' => 'gpt-4o-mini'],
// ]);
//
// === MCP Authentication (#97) ===
LOCALEOF

    echo "define('MCP_API_KEY', '${mcp_key}');" >> "$local_config"

    echo "  Generated: $local_config"
    echo "  MCP_API_KEY: $mcp_key"
    echo "  → MCP clients must send: X-API-Key: $mcp_key"
    echo "  → Edit $local_config to add LLM_API_KEY for emoji enhancement"
}

# ─── Deploy to Webroot ────────────────────────────────────────────────────────

do_deploy_webroot() {
    local target="$1"

    if [ ! -d "$target" ]; then
        echo -e "${RED}✗ Webroot does not exist: $target${NC}"
        exit 1
    fi

    echo "→ Deploying phpMan to $target ..."

    # Copy dispatcher + CSS + JS (only 3 files in webroot)
    cp "$INSTALL_DIR/phpMan.php" "$target/"
    cp "$INSTALL_DIR/phpman.css" "$target/" 2>/dev/null || true
    cp "$INSTALL_DIR/phpman.js" "$target/" 2>/dev/null || true
    chmod 644 "$target/phpMan.php" "$target/phpman.css" "$target/phpman.js" 2>/dev/null || true

    # Generate webroot config (does nothing if already exists)
    generate_config "$target"

    # Data directories live outside webroot, under PHPMAN_HOME (~/.phpman)
    mkdir -p "$HOME/.phpman/db" "$HOME/.phpman/logs" "$HOME/.phpman/backups"

    # Generate backend config with MCP_API_KEY (does nothing if already exists)
    generate_local_config

    # Symlink webroot config ← PHPMAN_HOME for CLI tools (batch-enhance etc.)
    ln -sf "$target/phpman.config.php" "$HOME/.phpman/phpman.config.php" 2>/dev/null || true

    echo ""
    echo -e "${GREEN}✓ Deployed to $target${NC}"
    echo "  phpMan.php → $target/phpMan.php"
    [ -f "$target/phpman.css" ] && echo "  phpman.css  → $target/phpman.css"
    [ -f "$target/phpman.js" ] && echo "  phpman.js   → $target/phpman.js"
    echo "  web config  → $target/phpman.config.php"
    echo "  backend cfg → $HOME/.phpman/config.local.php (LLM keys, MCP auth)"
    echo "  data dir    → $HOME/.phpman/ (src/ cli/ db/ logs/)"
    echo ""
    echo "  Next: configure your web server to serve PHP from $target"
    echo "  For correct link generation, edit $target/phpman.config.php"
    echo "  and set:  define('PHPMAN_BASE_URL', 'https://your-domain.com/phpMan.php');"
    echo "  For LLM emoji enhancement, edit $HOME/.phpman/config.local.php"
}

# ─── Install ──────────────────────────────────────────────────────────────────

do_install() {
    banner
    run_checks

    mkdir -p "$INSTALL_DIR"

    if [ -d "$INSTALL_DIR/.git" ]; then
        echo -e "${YELLOW}phpMan already installed at $INSTALL_DIR${NC}"
        echo "Use --update to pull latest, or --reindex to rebuild index."
        echo ""
    else
        echo "→ Cloning phpMan into $INSTALL_DIR ..."
        git clone --depth 1 "$REPO_URL" "$INSTALL_DIR"
    fi

    cd "$INSTALL_DIR"

    # 1. Generate webroot config FIRST — phpMan.php and CLI tools need it
    echo "→ Generating config..."
    generate_config "$INSTALL_DIR"

    # 2. Generate backend config (~/.phpman/config.local.php) for LLM/MCP keys
    generate_local_config

    # 3. Create data directories (db/, logs/, backups/) under PHPMAN_HOME
    mkdir -p "$HOME/.phpman/db" "$HOME/.phpman/logs" "$HOME/.phpman/backups"

    # 4. Build FTS5 search index (config must exist first for PHPMAN_HOME)
    echo "→ Building FTS5 search index (man + pydoc3 + ri) ..."
    echo "  (this may take 1–2 minutes on first run)"
    php cli/build-index.php

    echo ""
    echo -e "${GREEN}✓ phpMan installed!${NC}"
    echo "  phpMan.php → $INSTALL_DIR/phpMan.php"
    echo "  web config → $INSTALL_DIR/phpman.config.php"
    echo "  backend cfg→ $HOME/.phpman/config.local.php (LLM keys, MCP auth)"
    echo "  src/       → $INSTALL_DIR/src/ ($(ls $INSTALL_DIR/src/*.php 2>/dev/null | wc -l) files)"
    echo "  cli/       → $INSTALL_DIR/cli/"
    echo "  data dir   → $HOME/.phpman/"
    echo ""

    # If --webroot specified, deploy there too
    if [ -n "$WEBROOT" ]; then
        do_deploy_webroot "$WEBROOT"
    fi

    if [ "$NO_SERVER" = false ]; then
        start_server
    fi
}

# ─── Update ───────────────────────────────────────────────────────────────────

do_update() {
    banner

    if [ ! -d "$INSTALL_DIR/.git" ]; then
        echo -e "${RED}Not installed. Run without --update first.${NC}"
        exit 1
    fi

    run_checks

    cd "$INSTALL_DIR"
    echo "→ Pulling latest code..."
    git pull --ff-only

    # Check for new config options in .example not in user's config
    check_config_updates "$INSTALL_DIR/phpman.config.php"
    # Ensure backend config exists (won't overwrite existing)
    generate_local_config

    echo "→ Rebuilding FTS5 search index..."
    php cli/build-index.php

    if [ -n "$WEBROOT" ]; then
        do_deploy_webroot "$WEBROOT"
    fi

    echo ""
    echo -e "${GREEN}✓ phpMan updated!${NC}"
    echo "  Version: $(git describe --tags --always 2>/dev/null || echo 'unknown')"

    if [ "$NO_SERVER" = false ]; then
        start_server
    fi
}

# ─── Reindex Only ─────────────────────────────────────────────────────────────

do_reindex() {
    if [ ! -f "$INSTALL_DIR/phpMan.php" ]; then
        echo -e "${RED}phpMan.php not found at $INSTALL_DIR — run install first.${NC}"
        exit 1
    fi

    echo "→ Rebuilding search index..."
    php "$INSTALL_DIR/cli/build-index.php"
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
    php -S "localhost:${PORT}"
}

# ─── Main ────────────────────────────────────────────────────────────────────

# Parse arguments
while [ $# -gt 0 ]; do
    case "$1" in
        --update|-u)
            MODE="update"; shift ;;
        --reindex|-r)
            MODE="reindex"; shift ;;
        --webroot)
            WEBROOT="$2"; shift 2 ;;
        --webroot=*)
            WEBROOT="${1#*=}"; shift ;;
        --no-server)
            NO_SERVER=true; shift ;;
        --help|-h)
            echo "phpMan Installer"
            echo ""
            echo "Usage:"
            echo "  curl -sSL https://raw.githubusercontent.com/chedong/phpman/master/install.sh | bash"
            echo ""
            echo "With options (note the -s -- between bash and options):"
            echo "  curl ... | bash -s -- --update"
            echo "  curl ... | bash -s -- --webroot /var/www/html"
            echo "  curl ... | bash -s -- --webroot /var/www/html --no-server"
            echo "  curl ... | bash -s -- --reindex"
            echo ""
            echo "Options:"
            echo "  --update          Pull latest code + reindex + start server"
            echo "  --reindex         Rebuild FTS5 search index only"
            echo "  --webroot <path>  Also deploy phpMan.php + config to a webroot directory"
            echo "  --no-server       Skip starting php -S (use with --webroot for Apache/Nginx)"
            echo ""
            echo "Environment:"
            echo "  PHPMAN_PORT=9090   Override default port (45678)"
            exit 0
            ;;
        *)  shift ;;
    esac
done

case "${MODE:-install}" in
    install) do_install ;;
    update)  do_update ;;
    reindex) do_reindex ;;
esac
