#!/usr/bin/env bash

# =============================================================================
# VCMS — cPanel Installer
# Repository: https://github.com/gdnnepal/bachat
#
# Usage:
#   bash install.sh
#
# Or:
#   bash <(curl -fsSL https://raw.githubusercontent.com/gdnnepal/bachat/master/install.sh)
# =============================================================================

set -Eeuo pipefail

# -----------------------------------------------------------------------------
# Colors
# -----------------------------------------------------------------------------

CYAN='\033[0;36m'
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

info() {
    echo -e "${CYAN}[VCMS]${NC} $*"
}

ok() {
    echo -e "${GREEN}  ✔ $*${NC}"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $*"
}

die() {
    echo -e "${RED}[ERROR]${NC} $*" >&2
    exit 1
}

# -----------------------------------------------------------------------------
# Error handler
# -----------------------------------------------------------------------------

trap 'echo -e "${RED}[ERROR]${NC} Installer stopped at line $LINENO."; exit 1' ERR

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------

REPO_OWNER="gdnnepal"
REPO_NAME="bachat"
REPO_BRANCH="master"

REPO_URL="https://github.com/${REPO_OWNER}/${REPO_NAME}.git"
API_URL="https://api.github.com/repos/${REPO_OWNER}/${REPO_NAME}/git/trees/${REPO_BRANCH}?recursive=1"

DEFAULT_DIR="${HOME}/bachat.gdn.com.np"

# -----------------------------------------------------------------------------
# Banner
# -----------------------------------------------------------------------------

clear 2>/dev/null || true

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   Village Cooperative Management System      ║${NC}"
echo -e "${CYAN}║   cPanel Installer                            ║${NC}"
echo -e "${CYAN}║   github.com/gdnnepal/bachat                  ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
echo ""

# -----------------------------------------------------------------------------
# 1. Detect required commands
# -----------------------------------------------------------------------------

info "Checking server environment..."

command -v bash >/dev/null 2>&1 || die "Bash is required."

if command -v curl >/dev/null 2>&1; then
    DOWNLOADER="curl"
elif command -v wget >/dev/null 2>&1; then
    DOWNLOADER="wget"
else
    die "curl or wget is required."
fi

command -v php >/dev/null 2>&1 || die "PHP CLI is not available."

PHP_VERSION="$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"

[[ -n "$PHP_VERSION" ]] || die "Unable to determine PHP version."

ok "PHP $PHP_VERSION detected."

# -----------------------------------------------------------------------------
# 2. Installation directory
# -----------------------------------------------------------------------------

echo ""

read -rp "$(echo -e "${CYAN}?${NC} Installation directory [${DEFAULT_DIR}]: ")" INPUT_DIR

INSTALL_DIR="${INPUT_DIR:-$DEFAULT_DIR}"

if [[ "$INSTALL_DIR" != /* ]]; then
    INSTALL_DIR="${HOME}/${INSTALL_DIR}"
fi

mkdir -p "$INSTALL_DIR"

cd "$INSTALL_DIR"

info "Installation directory:"
echo "       $INSTALL_DIR"

# -----------------------------------------------------------------------------
# 3. Safety check
# -----------------------------------------------------------------------------

echo ""

if [[ -f "backend/.env" ]]; then
    warn "Existing backend/.env detected."

    read -rp "Keep existing .env? [Y/n]: " KEEP_ENV

    KEEP_ENV="${KEEP_ENV:-Y}"

    if [[ "$KEEP_ENV" =~ ^[Nn]$ ]]; then
        rm -f backend/.env
        info "Existing .env removed."
    else
        info "Existing .env will be preserved."
    fi
fi

# -----------------------------------------------------------------------------
# 4. Download source
# -----------------------------------------------------------------------------

echo ""
info "Downloading application source..."

# -------------------------------------------------------------------------
# Preferred method: Git
# -------------------------------------------------------------------------

if command -v git >/dev/null 2>&1; then

    info "Git detected."

    # If this directory already contains a git repository, update it.
    if [[ -d ".git" ]]; then

        info "Existing Git repository detected."

        git fetch origin "$REPO_BRANCH"
        git reset --hard "origin/$REPO_BRANCH"

        ok "Source updated."

    else

        # Make sure the directory isn't occupied by unrelated files.
        if [[ -n "$(find . -mindepth 1 -maxdepth 1 -not -name '.env' -print -quit)" ]]; then
            warn "Installation directory is not empty."

            read -rp "Continue and copy repository files over existing files? [y/N]: " CONTINUE

            if [[ ! "$CONTINUE" =~ ^[Yy]$ ]]; then
                die "Installation cancelled."
            fi

            TEMP_DIR="$(mktemp -d)"

            git clone \
                --depth 1 \
                --branch "$REPO_BRANCH" \
                "$REPO_URL" \
                "$TEMP_DIR"

            cp -R "$TEMP_DIR"/. "$INSTALL_DIR"/
            rm -rf "$TEMP_DIR"

        else

            git clone \
                --depth 1 \
                --branch "$REPO_BRANCH" \
                "$REPO_URL" \
                "$INSTALL_DIR/.vcms-source"

            cp -R "$INSTALL_DIR/.vcms-source"/. "$INSTALL_DIR"/
            rm -rf "$INSTALL_DIR/.vcms-source"

        fi

        ok "Source downloaded."
    fi

# -------------------------------------------------------------------------
# Fallback: GitHub API
# -------------------------------------------------------------------------

else

    warn "Git is not available."

    info "Using GitHub API to download files individually..."

    command -v python3 >/dev/null 2>&1 || \
        die "Git is not installed and python3 is unavailable."

    TREE_FILE="$(mktemp)"

    cleanup() {
        rm -f "$TREE_FILE" 2>/dev/null || true
    }

    trap cleanup EXIT

    if [[ "$DOWNLOADER" == "curl" ]]; then

        curl \
            -fsSL \
            "$API_URL" \
            -o "$TREE_FILE"

    else

        wget \
            -q \
            "$API_URL" \
            -O "$TREE_FILE"

    fi

    python3 - "$TREE_FILE" "$INSTALL_DIR" "$REPO_OWNER" "$REPO_NAME" "$REPO_BRANCH" <<'PY'
import json
import os
import sys
import urllib.request

tree_file = sys.argv[1]
install_dir = sys.argv[2]
owner = sys.argv[3]
repo = sys.argv[4]
branch = sys.argv[5]

with open(tree_file, "r", encoding="utf-8") as f:
    data = json.load(f)

if data.get("truncated"):
    raise SystemExit(
        "GitHub API returned a truncated repository tree. "
        "Please enable Git or use a ZIP release."
    )

tree = data.get("tree", [])

for item in tree:

    if item.get("type") != "blob":
        continue

    path = item.get("path")

    if not path:
        continue

    # Don't download Git metadata.
    if path.startswith(".git/") or path == ".git":
        continue

    destination = os.path.join(install_dir, path)

    # Prevent path traversal.
    destination_real = os.path.realpath(destination)
    install_real = os.path.realpath(install_dir)

    if not destination_real.startswith(install_real + os.sep):
        raise SystemExit(f"Unsafe repository path: {path}")

    os.makedirs(os.path.dirname(destination), exist_ok=True)

    url = (
        f"https://raw.githubusercontent.com/"
        f"{owner}/{repo}/{branch}/{path}"
    )

    print(f"  downloading: {path}")

    urllib.request.urlretrieve(url, destination)

print("Repository files downloaded successfully.")
PY

    ok "Source downloaded directly from GitHub."

fi

# -----------------------------------------------------------------------------
# 5. Verify application structure
# -----------------------------------------------------------------------------

echo ""
info "Checking application structure..."

[[ -d "backend" ]] || die "backend/ directory not found."
[[ -d "frontend" ]] || die "frontend/ directory not found."
[[ -f "backend/install.php" ]] || die "backend/install.php not found."
[[ -f "frontend/package.json" ]] || die "frontend/package.json not found."

ok "Application structure verified."

# -----------------------------------------------------------------------------
# 6. PHP information
# -----------------------------------------------------------------------------

echo ""
info "Checking PHP extensions..."

PHP_EXTENSIONS=(
    "pdo"
    "pdo_mysql"
    "mbstring"
    "json"
    "openssl"
    "curl"
)

MISSING_EXTENSIONS=()

for EXT in "${PHP_EXTENSIONS[@]}"; do

    if php -m 2>/dev/null | grep -qi "^${EXT}$"; then
        ok "PHP extension: $EXT"
    else
        MISSING_EXTENSIONS+=("$EXT")
        warn "Missing PHP extension: $EXT"
    fi

done

if [[ ${#MISSING_EXTENSIONS[@]} -gt 0 ]]; then

    echo ""

    warn "Some PHP extensions are missing:"
    echo "       ${MISSING_EXTENSIONS[*]}"

    warn "You may need to enable them in cPanel → MultiPHP → PHP Extensions."

fi

# -----------------------------------------------------------------------------
# 7. Database configuration
# -----------------------------------------------------------------------------

echo ""
echo -e "${CYAN}── Database Configuration ────────────────────${NC}"

read -rp "  MySQL host [localhost]: " DB_HOST
DB_HOST="${DB_HOST:-localhost}"

read -rp "  MySQL port [3306]: " DB_PORT
DB_PORT="${DB_PORT:-3306}"

read -rp "  MySQL database name: " DB_NAME

read -rp "  MySQL username: " DB_USER

read -rsp "  MySQL password: " DB_PASS
echo ""

[[ -n "$DB_NAME" ]] || die "Database name is required."
[[ -n "$DB_USER" ]] || die "Database username is required."

# -----------------------------------------------------------------------------
# 8. Site configuration
# -----------------------------------------------------------------------------

echo ""
echo -e "${CYAN}── Site Configuration ────────────────────────${NC}"

read -rp \
    "  Site URL [https://bachat.gdn.com.np]: " \
    SITE_URL

SITE_URL="${SITE_URL:-https://bachat.gdn.com.np}"

SITE_URL="${SITE_URL%/}"

# -----------------------------------------------------------------------------
# 9. Cooperative configuration
# -----------------------------------------------------------------------------

echo ""
echo -e "${CYAN}── Cooperative Configuration ────────────────${NC}"

read -rp \
    "  Cooperative name [My Cooperative]: " \
    COOP_NAME

COOP_NAME="${COOP_NAME:-My Cooperative}"

# -----------------------------------------------------------------------------
# 10. Administrator
# -----------------------------------------------------------------------------

echo ""
echo -e "${CYAN}── Super Admin ───────────────────────────────${NC}"

read -rp \
    "  Username [admin]: " \
    SA_USER

SA_USER="${SA_USER:-admin}"

read -rsp \
    "  Password: " \
    SA_PASS

echo ""

[[ -n "$SA_PASS" ]] || die "Admin password cannot be empty."

# -----------------------------------------------------------------------------
# 11. Create backend/.env
# -----------------------------------------------------------------------------

echo ""

if [[ -f "backend/.env" ]]; then

    info "Using existing backend/.env"

else

    info "Creating backend/.env ..."

    cat > backend/.env <<EOF
APP_ENV=production
APP_NAME=VCMS
BASE_URL=${SITE_URL}
ALLOWED_ORIGINS=${SITE_URL}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF

    chmod 600 backend/.env

    ok "backend/.env created."

fi

# -----------------------------------------------------------------------------
# 12. Composer
# -----------------------------------------------------------------------------

echo ""
info "Checking Composer..."

if command -v composer >/dev/null 2>&1; then

    COMPOSER_VERSION="$(composer --version 2>/dev/null | head -1)"

    ok "$COMPOSER_VERSION"

    info "Installing PHP dependencies..."

    composer install \
        --no-dev \
        --optimize-autoloader \
        --working-dir=backend

    ok "PHP dependencies installed."

else

    warn "Composer command not found."

    # Try common cPanel Composer locations.

    POSSIBLE_COMPOSERS=(
        "$HOME/bin/composer"
        "$HOME/.local/bin/composer"
        "/opt/cpanel/composer/bin/composer"
    )

    COMPOSER_FOUND=""

    for C in "${POSSIBLE_COMPOSERS[@]}"; do

        if [[ -x "$C" ]]; then
            COMPOSER_FOUND="$C"
            break
        fi

    done

    if [[ -n "$COMPOSER_FOUND" ]]; then

        info "Found Composer at $COMPOSER_FOUND"

        "$COMPOSER_FOUND" \
            install \
            --no-dev \
            --optimize-autoloader \
            --working-dir=backend

        ok "PHP dependencies installed."

    else

        die "Composer is required but was not found."
    fi

fi

# -----------------------------------------------------------------------------
# 13. Database migration
# -----------------------------------------------------------------------------

echo ""
info "Running database installation..."

php backend/install.php \
    --db-host="$DB_HOST" \
    --db-port="$DB_PORT" \
    --db-name="$DB_NAME" \
    --db-user="$DB_USER" \
    --db-pass="$DB_PASS" \
    --coop-name="$COOP_NAME" \
    --admin-user="$SA_USER" \
    --admin-pass="$SA_PASS" \
    --site-url="$SITE_URL"

ok "Database installation completed."

# -----------------------------------------------------------------------------
# 14. Node / npm
# -----------------------------------------------------------------------------

echo ""
info "Checking Node.js..."

if command -v node >/dev/null 2>&1; then

    NODE_VERSION="$(node --version)"

    ok "Node.js $NODE_VERSION"

else

    die "Node.js is not installed. Enable Node.js in cPanel or install it before running this installer."
fi

# -----------------------------------------------------------------------------
# 15. npm
# -----------------------------------------------------------------------------

if command -v npm >/dev/null 2>&1; then

    NPM_VERSION="$(npm --version)"

    ok "npm $NPM_VERSION"

else

    die "npm is not installed."
fi

# -----------------------------------------------------------------------------
# 16. Frontend environment
# -----------------------------------------------------------------------------

echo ""
info "Configuring React frontend..."

cat > frontend/.env.production <<EOF
VITE_API_BASE_URL=${SITE_URL}/api/v1
EOF

ok "frontend/.env.production created."

# -----------------------------------------------------------------------------
# 17. Install frontend dependencies
# -----------------------------------------------------------------------------

info "Installing frontend dependencies..."

if [[ -f "frontend/package-lock.json" ]]; then

    npm ci \
        --prefix frontend

else

    warn "package-lock.json not found."
    info "Running npm install instead."

    npm install \
        --prefix frontend

fi

ok "Frontend dependencies installed."

# -----------------------------------------------------------------------------
# 18. Build frontend
# -----------------------------------------------------------------------------

echo ""
info "Building React frontend..."

npm run build \
    --prefix frontend

[[ -d "frontend/dist" ]] || \
    die "Frontend build completed but frontend/dist was not created."

ok "Frontend build completed."

# -----------------------------------------------------------------------------
# 19. Deploy frontend
# -----------------------------------------------------------------------------

echo ""
info "Deploying frontend to addon-domain root..."

cp -R frontend/dist/. "$INSTALL_DIR"/

ok "Frontend deployed."

# -----------------------------------------------------------------------------
# 20. Create backend public directories
# -----------------------------------------------------------------------------

echo ""
info "Preparing upload directories..."

mkdir -p backend/public/uploads/backups
mkdir -p backend/public/uploads/logs

chmod 775 backend/public/uploads
chmod 775 backend/public/uploads/backups
chmod 775 backend/public/uploads/logs

ok "Upload directories prepared."

# -----------------------------------------------------------------------------
# 21. Root .htaccess
# -----------------------------------------------------------------------------

echo ""
info "Creating root .htaccess..."

cat > .htaccess <<'HTACCESS'
# =============================================================================
# VCMS cPanel / Apache configuration
# =============================================================================

Options -Indexes

DirectoryIndex index.html

RewriteEngine On
RewriteBase /

# -----------------------------------------------------------------------------
# Protect backend application source
# -----------------------------------------------------------------------------

RewriteRule ^backend/app/ - [F,L]
RewriteRule ^backend/lang/ - [F,L]
RewriteRule ^backend/database/ - [F,L]
RewriteRule ^backend/vendor/ - [F,L]
RewriteRule ^backend/\.env - [F,L]

# -----------------------------------------------------------------------------
# API
# -----------------------------------------------------------------------------

RewriteRule ^api/v1/(.*)$ backend/public/index.php [QSA,L]

# -----------------------------------------------------------------------------
# Existing files/directories
# -----------------------------------------------------------------------------

RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# -----------------------------------------------------------------------------
# React SPA
# -----------------------------------------------------------------------------

RewriteRule ^ index.html [L]
HTACCESS

ok "Root .htaccess created."

# -----------------------------------------------------------------------------
# 22. Backend .htaccess
# -----------------------------------------------------------------------------

info "Creating backend/public/.htaccess..."

cat > backend/public/.htaccess <<'HTACCESS'
Options -Indexes

RewriteEngine On

RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

RewriteRule ^ index.php [QSA,L]

# Prevent execution of uploaded scripts.

<FilesMatch "\.(php|php[0-9]?|phtml|phar|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
HTACCESS

ok "Backend .htaccess created."

# -----------------------------------------------------------------------------
# 23. Protect sensitive files
# -----------------------------------------------------------------------------

info "Protecting sensitive files..."

chmod 600 backend/.env 2>/dev/null || true

find backend/app \
    -type f \
    -exec chmod 644 {} \; \
    2>/dev/null || true

find backend/app \
    -type d \
    -exec chmod 755 {} \; \
    2>/dev/null || true

ok "Permissions configured."

# -----------------------------------------------------------------------------
# 24. Remove installer leftovers
# -----------------------------------------------------------------------------

rm -f vcms.zip 2>/dev/null || true

# -----------------------------------------------------------------------------
# 25. Final verification
# -----------------------------------------------------------------------------

echo ""
info "Running final checks..."

[[ -f "index.html" ]] || die "index.html was not deployed."

[[ -f ".htaccess" ]] || die ".htaccess was not created."

[[ -f "backend/.env" ]] || die "backend/.env is missing."

[[ -f "backend/public/index.php" ]] || \
    die "backend/public/index.php is missing."

ok "Application files verified."

# -----------------------------------------------------------------------------
# Done
# -----------------------------------------------------------------------------

echo ""

echo -e "${GREEN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                                              ║${NC}"
echo -e "${GREEN}║       VCMS INSTALLED SUCCESSFULLY            ║${NC}"
echo -e "${GREEN}║                                              ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║ URL:   ${SITE_URL}${NC}"
echo -e "${GREEN}║ User:  ${SA_USER}${NC}"
echo -e "${GREEN}║                                              ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════╝${NC}"

echo ""
warn "For security, keep your database password and admin password private."

echo ""
info "Installation directory:"
echo "       $INSTALL_DIR"

echo ""
ok "Done."
ok "Done."