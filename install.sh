#!/usr/bin/env bash
set -e

REPO_URL="${REPO_URL:-https://github.com/gdnnepal/bachat.git}"
TARGET_DIR="${1:-$HOME/nispakshya/bachat.gdn.com.np}"
SITE_URL="${SITE_URL:-https://bachat.gdn.com.np}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin123}"
COOP_NAME="${COOP_NAME:-My Cooperative}"

if ! command -v git >/dev/null 2>&1; then
  echo "git is required but was not found." >&2
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "php is required but was not found." >&2
  exit 1
fi

mkdir -p "$TARGET_DIR"

if [ -d "$TARGET_DIR/.git" ]; then
  echo "[1/6] Updating existing repository..."
  git -C "$TARGET_DIR" pull origin master
else
  echo "[1/6] Cloning repository to $TARGET_DIR"
  git clone "$REPO_URL" "$TARGET_DIR"
fi

mkdir -p "$TARGET_DIR/backend/public"
mkdir -p "$TARGET_DIR/frontend"

read -p "MySQL host [localhost]: " DB_HOST
DB_HOST="${DB_HOST:-localhost}"
read -p "MySQL port [3306]: " DB_PORT
DB_PORT="${DB_PORT:-3306}"
read -p "MySQL database name: " DB_NAME
read -p "MySQL username: " DB_USER
read -s -p "MySQL password: " DB_PASS
printf "\n"

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
  echo "Database name and username are required." >&2
  exit 1
fi

cat > "$TARGET_DIR/backend/.env" <<EOF
APP_ENV=production
APP_NAME=VCMS
BASE_URL=${SITE_URL}/backend/public
ALLOWED_ORIGINS=${SITE_URL}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF

cat > "$TARGET_DIR/frontend/.env" <<EOF
VITE_API_TARGET=${SITE_URL}
VITE_API_BASE_PATH=/backend/public/api/v1
VITE_BASE_PATH=/frontend/
EOF

echo "[2/6] Running backend installer..."
php "$TARGET_DIR/backend/install.php" \
  --db-host="$DB_HOST" \
  --db-port="$DB_PORT" \
  --db-name="$DB_NAME" \
  --db-user="$DB_USER" \
  --db-pass="$DB_PASS" \
  --site-url="$SITE_URL" \
  --coop-name="$COOP_NAME" \
  --admin-user="$ADMIN_USER" \
  --admin-pass="$ADMIN_PASS"

echo "[3/6] Installing frontend dependencies..."
if command -v npm >/dev/null 2>&1; then
  (cd "$TARGET_DIR/frontend" && npm install)
else
  echo "npm was not found. Please install Node.js/npm and rebuild the frontend manually." >&2
  exit 1
fi

echo "[4/6] Building frontend..."
(cd "$TARGET_DIR/frontend" && npm run build)

echo "[5/6] Preparing deployment folder..."
mkdir -p "$TARGET_DIR/public_html/frontend"
cp -R "$TARGET_DIR/frontend/dist/." "$TARGET_DIR/public_html/frontend/"

echo "[6/6] Installation complete."
echo "Frontend: $TARGET_DIR/public_html/frontend"
echo "Backend: $TARGET_DIR/backend"
echo "Login: $ADMIN_USER / $ADMIN_PASS"
echo "Open: $SITE_URL/frontend"
