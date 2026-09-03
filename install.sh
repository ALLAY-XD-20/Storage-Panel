#!/usr/bin/env bash
#
# Storage Panel installer.
# Run as: bash install.sh
#
set -euo pipefail

PANEL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PANEL_DIR"

echo "==================================================="
echo "  Storage Panel — Installer"
echo "==================================================="
echo

# ---------------------------------------------------------------
# 1. Detect OS
# ---------------------------------------------------------------
echo "[1/13] Detecting operating system..."
if [ -f /etc/os-release ]; then
    . /etc/os-release
    echo "   OS: ${PRETTY_NAME:-unknown}"
else
    echo "   Warning: could not detect OS via /etc/os-release. Continuing anyway."
fi

# ---------------------------------------------------------------
# 2. Detect PHP
# ---------------------------------------------------------------
echo "[2/13] Detecting PHP..."
if ! command -v php >/dev/null 2>&1; then
    echo "   ERROR: PHP is not installed or not on PATH."
    echo "   Install PHP 8.2+ first, e.g.:"
    echo "     Debian/Ubuntu: sudo apt install php8.2 php8.2-cli php8.2-sqlite3 php8.2-zip php8.2-mbstring"
    exit 1
fi
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
echo "   PHP version: $PHP_VERSION"
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 2 ]; }; then
    echo "   WARNING: PHP 8.2+ is recommended. You have $PHP_VERSION. Continuing anyway."
fi

# ---------------------------------------------------------------
# 3. Check required PHP extensions
# ---------------------------------------------------------------
echo "[3/13] Checking required PHP extensions..."
REQUIRED_EXT=(pdo pdo_sqlite json fileinfo openssl mbstring zip)
MISSING_EXT=()
for ext in "${REQUIRED_EXT[@]}"; do
    if ! php -m | grep -qi "^${ext}$"; then
        MISSING_EXT+=("$ext")
    fi
done
if [ ${#MISSING_EXT[@]} -ne 0 ]; then
    echo "   ERROR: Missing PHP extensions: ${MISSING_EXT[*]}"
    echo "   Install them, e.g.: sudo apt install php8.2-sqlite3 php8.2-zip php8.2-mbstring"
    exit 1
fi
echo "   All required extensions present: ${REQUIRED_EXT[*]}"

# ---------------------------------------------------------------
# 4. Create required directories
# ---------------------------------------------------------------
echo "[4/13] Creating required directories..."
mkdir -p "$PANEL_DIR/database" "$PANEL_DIR/logs"
echo "   Done."

# ---------------------------------------------------------------
# 5 & 9 & 10. Ask for STORAGE_ROOT, admin username & password
# ---------------------------------------------------------------
echo "[5/13] Configuring STORAGE_ROOT..."
read -rp "   Enter the absolute path to use as STORAGE_ROOT [/var/www/storage]: " STORAGE_ROOT_INPUT
STORAGE_ROOT_INPUT="${STORAGE_ROOT_INPUT:-/var/www/storage}"

case "$STORAGE_ROOT_INPUT" in
  /|/etc|/etc/*|/root|/root/*|/proc|/proc/*|/sys|/sys/*|/var/lib|/var/lib/*|/boot|/boot/*)
    echo "   ERROR: refusing to use a sensitive system path as STORAGE_ROOT."
    exit 1
    ;;
esac

mkdir -p "$STORAGE_ROOT_INPUT"
STORAGE_ROOT_REAL="$(cd "$STORAGE_ROOT_INPUT" && pwd)"
echo "   STORAGE_ROOT set to: $STORAGE_ROOT_REAL"

echo
echo "[Admin account]"
read -rp "   Admin username: " ADMIN_USER
while true; do
    read -rsp "   Admin password (min 8 chars): " ADMIN_PASS
    echo
    read -rsp "   Confirm password: " ADMIN_PASS_CONFIRM
    echo
    if [ "$ADMIN_PASS" != "$ADMIN_PASS_CONFIRM" ]; then
        echo "   Passwords do not match. Try again."
        continue
    fi
    if [ ${#ADMIN_PASS} -lt 8 ]; then
        echo "   Password must be at least 8 characters. Try again."
        continue
    fi
    break
done

# ---------------------------------------------------------------
# Write config.php with the chosen STORAGE_ROOT
# ---------------------------------------------------------------
echo "[6/13] Writing configuration..."
if [ -f "$PANEL_DIR/config.php" ]; then
    cp "$PANEL_DIR/config.php" "$PANEL_DIR/config.php.bak.$(date +%s)"
fi
export STORAGE_ROOT="$STORAGE_ROOT_REAL"
ESCAPED_ROOT=$(printf '%s' "$STORAGE_ROOT_REAL" | sed 's/[&\]/\\&/g')
sed -i.tmp "s#define('STORAGE_ROOT', getenv('STORAGE_ROOT') ?: '[^']*');#define('STORAGE_ROOT', getenv('STORAGE_ROOT') ?: '${ESCAPED_ROOT}');#" "$PANEL_DIR/config.php"
rm -f "$PANEL_DIR/config.php.tmp"
echo "   config.php updated."

# ---------------------------------------------------------------
# 7. Create SQLite database + admin account
# ---------------------------------------------------------------
echo "[7/13] Initializing database and admin account..."
php "$PANEL_DIR/database/init.php" "$ADMIN_USER" "$ADMIN_PASS" "$STORAGE_ROOT_REAL"

# ---------------------------------------------------------------
# 8. Set correct permissions
# ---------------------------------------------------------------
echo "[8/13] Setting file/directory permissions..."
chmod 750 "$PANEL_DIR/database" "$PANEL_DIR/logs" "$STORAGE_ROOT_REAL"
[ -f "$PANEL_DIR/database/panel.sqlite" ] && chmod 640 "$PANEL_DIR/database/panel.sqlite"
touch "$PANEL_DIR/logs/app.log"
chmod 640 "$PANEL_DIR/logs/app.log"

WEB_USER=""
for candidate in www-data nginx apache; do
    if id "$candidate" >/dev/null 2>&1; then WEB_USER="$candidate"; break; fi
done
if [ -n "$WEB_USER" ]; then
    echo "   Detected web server user: $WEB_USER"
    chown -R "$WEB_USER":"$WEB_USER" "$PANEL_DIR/database" "$PANEL_DIR/logs" "$STORAGE_ROOT_REAL" 2>/dev/null || \
        echo "   (Could not chown — run installer as root or chown manually to your web server user.)"
else
    echo "   Could not auto-detect the web server user. Set ownership manually, e.g.:"
    echo "     chown -R www-data:www-data '$PANEL_DIR/database' '$PANEL_DIR/logs' '$STORAGE_ROOT_REAL'"
fi

# ---------------------------------------------------------------
# 11. Test filesystem permissions
# ---------------------------------------------------------------
echo "[9/13]  (11) Testing filesystem write access to STORAGE_ROOT..."
TEST_FILE="$STORAGE_ROOT_REAL/.storage-panel-write-test"
if touch "$TEST_FILE" 2>/dev/null; then
    rm -f "$TEST_FILE"
    echo "   Write access OK."
else
    echo "   WARNING: could not write a test file to $STORAGE_ROOT_REAL. Check permissions."
fi

# ---------------------------------------------------------------
# 12. Test disk information
# ---------------------------------------------------------------
echo "[10/13] (12) Testing disk information detection..."
php -r '
$root = $argv[1];
$total = disk_total_space($root);
$free = disk_free_space($root);
if ($total === false || $free === false) {
    fwrite(STDERR, "   WARNING: could not read disk stats for $root\n");
} else {
    printf("   Detected: %.2f GB total, %.2f GB free\n", $total / 1073741824, $free / 1073741824);
}
' "$STORAGE_ROOT_REAL"

# ---------------------------------------------------------------
# Web server config reminder
# ---------------------------------------------------------------
echo "[11/13] Web server configuration..."
echo "   Sample configs are provided in:"
echo "     - apache/storage-panel.conf"
echo "     - nginx/storage-panel.conf"
echo "   Adjust the document root, server_name/ServerName, and TLS settings, then reload your web server."

# ---------------------------------------------------------------
# PHP upload limits sanity check
# ---------------------------------------------------------------
echo "[12/13] Checking PHP upload-related settings..."
UPLOAD_MAX=$(php -r 'echo ini_get("upload_max_filesize");')
POST_MAX=$(php -r 'echo ini_get("post_max_size");')
MEM_LIMIT=$(php -r 'echo ini_get("memory_limit");')
echo "   upload_max_filesize = $UPLOAD_MAX"
echo "   post_max_size       = $POST_MAX"
echo "   memory_limit        = $MEM_LIMIT"
echo "   Note: this panel uses chunked uploads (5MB chunks by default), so these settings"
echo "   mainly need to comfortably exceed the chunk size, not the full file size."

# ---------------------------------------------------------------
# 13. Display final panel URL
# ---------------------------------------------------------------
echo "[13/13] Installation complete!"
echo
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
echo "==================================================="
echo "  Storage Panel installed successfully"
echo "==================================================="
echo "  Storage root : $STORAGE_ROOT_REAL"
echo "  Admin user   : $ADMIN_USER"
echo "  Panel URL    : http://${SERVER_IP:-your-server-ip}/  (configure your web server's document root to: $PANEL_DIR)"
echo
echo "  IMPORTANT: configure HTTPS and set FORCE_SECURE_COOKIE=true in config.php once TLS is active."
echo "  See README.md and SECURITY.md for full deployment guidance."
echo "==================================================="
