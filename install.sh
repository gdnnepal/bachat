#!/usr/bin/env bash
# =============================================================================
# VCMS — One-command cPanel installer for bachat.gdn.com.np
#
# Paste this ONE LINE into your cPanel Terminal:
#   bash <(curl -fsSL https://raw.githubusercontent.com/gdnnepal/bachat/master/install.sh)
# =============================================================================
set -euo pipefail

CYAN='\033[0;36m'; GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${CYAN}[VCMS]${NC} $*"; }
ok()    { echo -e "${GREEN}  ✔  $*${NC}"; }
warn()  { echo -e "${YELLOW}[warn]${NC} $*"; }
die()   { echo -e "${RED}[ERROR]${NC} $*" >&2; exit 1; }

REPO="https://github.com/gdnnepal/bachat"
REPO_ZIP="https://codeload.github.com/gdnnepal/bachat/zip/refs/heads/master"

# ─── Banner ──────────────────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   Village Cooperative Management System      ║${NC}"
echo -e "${CYAN}║   Installer  •  github.com/gdnnepal/bachat   ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
echo ""

# ─── 1. Install directory (addon domain root) ────────────────────────────────
DEFAULT_DIR="$HOME/bachat.gdn.com.np"
read -rp "$(echo -e "${CYAN}?${NC} Addon domain folder [${DEFAULT_DIR}]: ")" INPUT_DIR
INSTALL_DIR="${INPUT_DIR:-$DEFAULT_DIR}"
mkdir -p "$INSTALL_DIR"
cd "$INSTALL_DIR"
info "Installing into: $INSTALL_DIR"

# ─── 2. Download & extract ───────────────────────────────────────────────────
ARCHIVE="vcms.zip"
info "Downloading source from $REPO ..."
if command -v curl >/dev/null 2>&1; then
  curl -fsSL "$REPO_ZIP" -o "$ARCHIVE"
elif command -v wget >/dev/null 2>&1; then
  wget -q "$REPO_ZIP" -O "$ARCHIVE"
else
  die "curl or wget is required."
fi

command -v unzip >/dev/null 2>&1 || die "unzip is required."
EXTRACTED="$(unzip -Z1 "$ARCHIVE" 2>/dev/null | head -1 | cut -d/ -f1)"
unzip -oq "$ARCHIVE"
[ -d "$EXTRACTED" ] && { cp -R "$EXTRACTED/." .; rm -rf "$EXTRACTED"; }
rm -f "$ARCHIVE"
ok "Source extracted."

# ─── 3. Configuration prompts ────────────────────────────────────────────────
echo ""
echo -e "${CYAN}── Database ──────────────────────────────────${NC}"
read -rp  "  MySQL host     [localhost]: " DB_HOST;  DB_HOST="${DB_HOST:-localhost}"
read -rp  "  MySQL port     [3306]:      " DB_PORT;  DB_PORT="${DB_PORT:-3306}"
read -rp  "  MySQL database name:        " DB_NAME
read -rp  "  MySQL username:             " DB_USER
read -rsp "  MySQL password:             " DB_PASS; echo ""

echo ""
echo -e "${CYAN}── Site ──────────────────────────────────────${NC}"
read -rp  "  Site URL  [https://bachat.gdn.com.np]: " SITE_URL
SITE_URL="${SITE_URL:-https://bachat.gdn.com.np}"
# Strip trailing slash
SITE_URL="${SITE_URL%/}"

echo ""
echo -e "${CYAN}── Cooperative ───────────────────────────────${NC}"
read -rp  "  Cooperative name [My Cooperative]: " COOP_NAME
COOP_NAME="${COOP_NAME:-My Cooperative}"

echo ""
echo -e "${CYAN}── Super Admin ───────────────────────────────${NC}"
read -rp  "  Username [admin]:      " SA_USER; SA_USER="${SA_USER:-admin}"
read -rsp "  Password [admin123]:   " SA_PASS; SA_PASS="${SA_PASS:-admin123}"; echo ""

[[ -n "$DB_NAME" ]] || die "Database name is required."
[[ -n "$DB_USER" ]] || die "Database username is required."

# ─── 4. Write backend/.env ───────────────────────────────────────────────────
info "Writing backend/.env ..."
cat > backend/.env <<ENV
APP_ENV=production
APP_NAME=VCMS
BASE_URL=${SITE_URL}
ALLOWED_ORIGINS=${SITE_URL}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
ENV
ok "backend/.env written."

# ─── 5. PHP dependencies ─────────────────────────────────────────────────────
if command -v composer >/dev/null 2>&1; then
  info "Installing PHP dependencies ..."
  composer install --no-dev --optimize-autoloader -q -d backend/
  ok "Composer packages installed."
else
  warn "composer not found — skipping. Run manually: composer install --no-dev -d backend/"
fi

# ─── 6. Database migration ───────────────────────────────────────────────────
info "Running database migration ..."
php backend/install.php \
  --db-host="$DB_HOST"   \
  --db-port="$DB_PORT"   \
  --db-name="$DB_NAME"   \
  --db-user="$DB_USER"   \
  --db-pass="$DB_PASS"   \
  --coop-name="$COOP_NAME" \
  --admin-user="$SA_USER"  \
  --admin-pass="$SA_PASS"  \
  --site-url="$SITE_URL"
ok "Database ready."

# ─── 7. Build React frontend ─────────────────────────────────────────────────
if command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1; then
  info "Building frontend ..."
  # Write production env for Vite
  cat > frontend/.env.production <<FENV
VITE_API_BASE_URL=${SITE_URL}/api/v1
FENV
  npm ci --prefix frontend --silent
  npm run build --prefix frontend --silent
  ok "Frontend built."
else
  warn "node/npm not found. Pre-built frontend will be used if available."
fi

# ─── 8. Deploy frontend build to addon domain root ───────────────────────────
info "Deploying frontend to public root ..."
if [ -d "frontend/dist" ]; then
  # Copy all built assets to the root (index.html, assets/, etc.)
  cp -R frontend/dist/. .
  ok "Frontend deployed to root."
else
  warn "frontend/dist missing — React build not found."
fi

# ─── 9. Write root .htaccess ─────────────────────────────────────────────────
info "Writing root .htaccess ..."
cat > .htaccess <<'HTACCESS'
# VCMS — Addon domain root .htaccess
Options -Indexes
DirectoryIndex index.html index.php

# ── React SPA routing ────────────────────────────────────────────────────────
# Route /api/v1/* to the backend; everything else to the React index.html
RewriteEngine On
RewriteBase /

# Block direct access to backend internals
RewriteRule ^backend/app/     - [F,L]
RewriteRule ^backend/lang/    - [F,L]
RewriteRule ^backend/database/ - [F,L]

# Forward /api/v1/* to backend/public/index.php
RewriteRule ^api/v1/(.*)$  backend/public/index.php [QSA,L]

# Serve existing files/directories directly (assets, favicon, etc.)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# All other requests → React SPA
RewriteRule ^ index.html [L]
HTACCESS
ok ".htaccess written."

# ─── 10. backend/public/.htaccess ────────────────────────────────────────────
info "Securing backend/public/.htaccess ..."
cat > backend/public/.htaccess <<'BHTACCESS'
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]

# Block direct access to uploads except backups download
<FilesMatch "^(?!backup_).*\.(php|sh|py|rb|pl)$">
  Order allow,deny
  Deny from all
</FilesMatch>
BHTACCESS
ok "backend/public/.htaccess secured."

# ─── 11. Permissions ─────────────────────────────────────────────────────────
info "Setting permissions ..."
find backend/app    -type f -exec chmod 644 {} \;
find backend/app    -type d -exec chmod 755 {} \;
chmod -R 775 backend/public/uploads/backups
chmod -R 775 backend/public/uploads/logs
chmod 600 backend/.env
ok "Permissions set."

# ─── Done ────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   VCMS installed successfully!               ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║   URL:      ${SITE_URL}${NC}"
echo -e "${GREEN}║   Login:    ${SA_USER} / ${SA_PASS}${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  One-line installer URL:"
echo -e "  ${CYAN}bash <(curl -fsSL https://raw.githubusercontent.com/gdnnepal/bachat/master/install.sh)${NC}"
echo ""
