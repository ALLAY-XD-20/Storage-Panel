
#!/usr/bin/env bash

# ===============================================================
# Storage Panel - Full Installer
# Ubuntu 22.04 / Debian
# PHP 8.2 + PHP-FPM + Nginx + SQLite
#
# Run:
#   chmod +x install.sh
#   sudo ./install.sh
# ===============================================================

set -Eeuo pipefail

PANEL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PANEL_DIR"

# ---------------------------------------------------------------
# Colors
# ---------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[+]${NC} $1"
}

info() {
    echo -e "${CYAN}[*]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[!]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

trap 'error "Installation failed at line $LINENO."; exit 1' ERR

# ---------------------------------------------------------------
# Root check
# ---------------------------------------------------------------
if [ "$(id -u)" -ne 0 ]; then
    error "Please run this installer as root."
    echo
    echo "Use:"
    echo "  sudo ./install.sh"
    exit 1
fi

clear

echo "==============================================================="
echo "              STORAGE PANEL INSTALLER"
echo "==============================================================="
echo
echo "Panel directory:"
echo "  $PANEL_DIR"
echo

# ---------------------------------------------------------------
# 1. Detect OS
# ---------------------------------------------------------------
info "[1/14] Detecting operating system..."

if [ ! -f /etc/os-release ]; then
    error "/etc/os-release not found."
    exit 1
fi

. /etc/os-release

echo "    OS: ${PRETTY_NAME:-Unknown}"

case "${ID:-}" in
    ubuntu|debian)
        ;;
    *)
        warn "This installer is designed for Ubuntu/Debian."
        warn "Detected: ${ID:-unknown}"
        ;;
esac

# ---------------------------------------------------------------
# 2. Update repositories
# ---------------------------------------------------------------
info "[2/14] Updating package repositories..."

export DEBIAN_FRONTEND=noninteractive

apt-get update -y

# ---------------------------------------------------------------
# 3. Basic packages
# ---------------------------------------------------------------
info "[3/14] Installing basic packages..."

apt-get install -y \
    ca-certificates \
    curl \
    wget \
    gnupg \
    lsb-release \
    software-properties-common \
    apt-transport-https \
    unzip \
    sqlite3 \
    nginx

# ---------------------------------------------------------------
# 4. PHP repository
# ---------------------------------------------------------------
info "[4/14] Configuring PHP 8.2 repository..."

if [ "${ID:-}" = "ubuntu" ]; then

    # Ubuntu 22.04 / Jammy
    if command -v add-apt-repository >/dev/null 2>&1; then
        add-apt-repository -y ppa:ondrej/php || true
    fi

    apt-get update -y

elif [ "${ID:-}" = "debian" ]; then

    # Debian uses packages from Sury
    if [ ! -f /etc/apt/sources.list.d/php.list ]; then
        curl -fsSL https://packages.sury.org/php/apt.gpg \
            -o /etc/apt/trusted.gpg.d/php.gpg

        echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" \
            > /etc/apt/sources.list.d/php.list
    fi

    apt-get update -y
fi

# ---------------------------------------------------------------
# 5. Install PHP 8.2
# ---------------------------------------------------------------
info "[5/14] Installing PHP 8.2 + PHP-FPM + extensions..."

PHP_PACKAGES=(
    php8.2
    php8.2-cli
    php8.2-fpm
    php8.2-common
    php8.2-sqlite3
    php8.2-zip
    php8.2-mbstring
    php8.2-xml
    php8.2-curl
    php8.2-gd
    php8.2-bcmath
    php8.2-intl
    php8.2-opcache
)

apt-get install -y "${PHP_PACKAGES[@]}"

# ---------------------------------------------------------------
# 6. Verify PHP
# ---------------------------------------------------------------
info "[6/14] Verifying PHP..."

if ! command -v php >/dev/null 2>&1; then
    error "PHP installation failed."
    exit 1
fi

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
PHP_MAJOR="$(php -r 'echo PHP_MAJOR_VERSION;')"
PHP_MINOR="$(php -r 'echo PHP_MINOR_VERSION;')"

echo "    PHP version: $PHP_VERSION"

if [ "$PHP_MAJOR" -lt 8 ] || {
    [ "$PHP_MAJOR" -eq 8 ] &&
    [ "$PHP_MINOR" -lt 2 ];
}; then
    error "PHP 8.2+ is required."
    exit 1
fi

# ---------------------------------------------------------------
# 7. Verify extensions
# ---------------------------------------------------------------
info "[7/14] Checking PHP extensions..."

REQUIRED_EXTENSIONS=(
    pdo
    pdo_sqlite
    sqlite3
    json
    fileinfo
    openssl
    mbstring
    zip
    curl
    xml
    gd
    intl
    bcmath
)

MISSING=()

for EXT in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -qi "^${EXT}$"; then
        MISSING+=("$EXT")
    fi
done

if [ "${#MISSING[@]}" -gt 0 ]; then
    error "Missing PHP extensions:"
    printf '  %s\n' "${MISSING[@]}"
    exit 1
fi

log "All required PHP extensions are installed."

# ---------------------------------------------------------------
# 8. PHP-FPM
# ---------------------------------------------------------------
info "[8/14] Configuring PHP-FPM..."

systemctl enable php8.2-fpm
systemctl restart php8.2-fpm

if ! systemctl is-active --quiet php8.2-fpm; then
    error "PHP-FPM failed to start."
    systemctl status php8.2-fpm --no-pager || true
    exit 1
fi

log "PHP-FPM is running."

# ---------------------------------------------------------------
# 9. Storage root
# ---------------------------------------------------------------
info "[9/14] Configuring storage..."

read -rp \
"Enter STORAGE_ROOT [/var/www/storage]: " \
STORAGE_ROOT_INPUT

STORAGE_ROOT_INPUT="${STORAGE_ROOT_INPUT:-/var/www/storage}"

case "$STORAGE_ROOT_INPUT" in
    /|/etc|/etc/*|/root|/root/*|/proc|/proc/*|/sys|/sys/*|/boot|/boot/*|/usr|/usr/*|/bin|/bin/*|/sbin|/sbin/*)
        error "Refusing to use a sensitive system path:"
        echo "  $STORAGE_ROOT_INPUT"
        exit 1
        ;;
esac

mkdir -p "$STORAGE_ROOT_INPUT"

STORAGE_ROOT_REAL="$(cd "$STORAGE_ROOT_INPUT" && pwd)"

echo "    Storage root: $STORAGE_ROOT_REAL"

# ---------------------------------------------------------------
# 10. Admin account
# ---------------------------------------------------------------
info "[10/14] Creating admin account..."

read -rp "Admin username: " ADMIN_USER

if [ -z "$ADMIN_USER" ]; then
    error "Admin username cannot be empty."
    exit 1
fi

while true; do

    read -rsp "Admin password (minimum 8 characters): " ADMIN_PASS
    echo

    read -rsp "Confirm password: " ADMIN_PASS_CONFIRM
    echo

    if [ "$ADMIN_PASS" != "$ADMIN_PASS_CONFIRM" ]; then
        warn "Passwords do not match."
        continue
    fi

    if [ "${#ADMIN_PASS}" -lt 8 ]; then
        warn "Password must contain at least 8 characters."
        continue
    fi

    break
done

# ---------------------------------------------------------------
# 11. Configure application
# ---------------------------------------------------------------
info "[11/14] Configuring Storage Panel..."

mkdir -p \
    "$PANEL_DIR/database" \
    "$PANEL_DIR/logs" \
    "$STORAGE_ROOT_REAL"

# Backup config
if [ -f "$PANEL_DIR/config.php" ]; then
    cp "$PANEL_DIR/config.php" \
       "$PANEL_DIR/config.php.bak.$(date +%s)"
fi

# Try to update STORAGE_ROOT in existing config
if [ -f "$PANEL_DIR/config.php" ]; then

    ESCAPED_ROOT="$(printf '%s' "$STORAGE_ROOT_REAL" | sed 's/[&|]/\\&/g')"

    if grep -q "STORAGE_ROOT" "$PANEL_DIR/config.php"; then

        sed -i \
            "s#^\([[:space:]]*define(['\"]STORAGE_ROOT['\"],[[:space:]]*\).*\$#\1'${ESCAPED_ROOT}');#" \
            "$PANEL_DIR/config.php" \
            2>/dev/null || true

    fi
fi

# ---------------------------------------------------------------
# Initialize database
# ---------------------------------------------------------------
if [ -f "$PANEL_DIR/database/init.php" ]; then

    info "Initializing SQLite database..."

    php "$PANEL_DIR/database/init.php" \
        "$ADMIN_USER" \
        "$ADMIN_PASS" \
        "$STORAGE_ROOT_REAL"

else
    warn "database/init.php was not found."
    warn "Skipping database initialization."
fi

# ---------------------------------------------------------------
# 12. Permissions
# ---------------------------------------------------------------
info "[12/14] Setting permissions..."

# Nginx/PHP-FPM normally uses www-data on Ubuntu/Debian
WEB_USER="www-data"
WEB_GROUP="www-data"

if ! id "$WEB_USER" >/dev/null 2>&1; then
    error "www-data user does not exist."
    exit 1
fi

# Application permissions
chown -R "$WEB_USER:$WEB_GROUP" \
    "$PANEL_DIR/database" \
    "$PANEL_DIR/logs" \
    "$STORAGE_ROOT_REAL"

chmod 750 \
    "$PANEL_DIR/database" \
    "$PANEL_DIR/logs" \
    "$STORAGE_ROOT_REAL"

if [ -f "$PANEL_DIR/database/panel.sqlite" ]; then
    chmod 640 "$PANEL_DIR/database/panel.sqlite"
fi

touch "$PANEL_DIR/logs/app.log"

chown "$WEB_USER:$WEB_GROUP" "$PANEL_DIR/logs/app.log"
chmod 640 "$PANEL_DIR/logs/app.log"

# Application files should be readable
find "$PANEL_DIR" \
    -type f \
    -not -path "$PANEL_DIR/.git/*" \
    -exec chmod 640 {} \; \
    2>/dev/null || true

# PHP files need to be readable
find "$PANEL_DIR" \
    -type f \
    -name "*.php" \
    -exec chmod 640 {} \; \
    2>/dev/null || true

# Directories
find "$PANEL_DIR" \
    -type d \
    -not -path "$PANEL_DIR/.git/*" \
    -exec chmod 750 {} \; \
    2>/dev/null || true

# ---------------------------------------------------------------
# Nginx configuration
# ---------------------------------------------------------------
info "[13/14] Configuring Nginx..."

NGINX_CONFIG="/etc/nginx/sites-available/storage-panel.conf"
NGINX_ENABLED="/etc/nginx/sites-enabled/storage-panel.conf"

# Backup existing config
if [ -f "$NGINX_CONFIG" ]; then
    cp "$NGINX_CONFIG" \
       "$NGINX_CONFIG.bak.$(date +%s)"
fi

cat > "$NGINX_CONFIG" <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    server_name _;

    root $PANEL_DIR;
    index index.php index.html;

    client_max_body_size 512M;

    # Main application
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # PHP
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;

        fastcgi_pass unix:/run/php/php8.2-fpm.sock;

        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;

        include fastcgi_params;
    }

    # Deny hidden files
    location ~ /\. {
        deny all;
    }

    # Protect sensitive files
    location ~* \.(sqlite|sqlite3|db|log|bak)$ {
        deny all;
    }

    # Protect project-sensitive directories
    location ~ ^/(database|logs|includes)/ {
        try_files \$uri =404;
    }
}
EOF

# Disable default Nginx site
rm -f /etc/nginx/sites-enabled/default

# Enable Storage Panel
ln -sf "$NGINX_CONFIG" "$NGINX_ENABLED"

# Test Nginx
nginx -t

# Enable/restart Nginx
systemctl enable nginx
systemctl restart nginx

if ! systemctl is-active --quiet nginx; then
    error "Nginx failed to start."
    systemctl status nginx --no-pager || true
    exit 1
fi

# ---------------------------------------------------------------
# PHP upload configuration
# ---------------------------------------------------------------
info "Configuring PHP upload limits..."

PHP_INI="/etc/php/8.2/fpm/php.ini"

if [ -f "$PHP_INI" ]; then

    sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 512M/' "$PHP_INI"
    sed -i 's/^post_max_size = .*/post_max_size = 512M/' "$PHP_INI"
    sed -i 's/^max_execution_time = .*/max_execution_time = 300/' "$PHP_INI"
    sed -i 's/^max_input_time = .*/max_input_time = 300/' "$PHP_INI"
    sed -i 's/^memory_limit = .*/memory_limit = 512M/' "$PHP_INI"

    systemctl restart php8.2-fpm
fi

# ---------------------------------------------------------------
# Final filesystem test
# ---------------------------------------------------------------
info "[14/14] Testing Storage Panel..."

TEST_FILE="$STORAGE_ROOT_REAL/.storage-panel-test"

if sudo -u "$WEB_USER" touch "$TEST_FILE" 2>/dev/null; then

    rm -f "$TEST_FILE"

    log "Storage write test: OK"

else

    warn "Storage write test failed."
    warn "PHP-FPM may not be able to write to:"
    echo "    $STORAGE_ROOT_REAL"
fi

# ---------------------------------------------------------------
# Get server IP
# ---------------------------------------------------------------
SERVER_IP=""

SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"

if [ -z "$SERVER_IP" ]; then
    SERVER_IP="YOUR_SERVER_IP"
fi

# ---------------------------------------------------------------
# Final status
# ---------------------------------------------------------------
echo
echo "==============================================================="
echo "              INSTALLATION COMPLETE"
echo "==============================================================="
echo
echo "PHP:"
echo "  Version       : $PHP_VERSION"
echo
echo "Panel:"
echo "  Directory     : $PANEL_DIR"
echo "  Storage       : $STORAGE_ROOT_REAL"
echo "  Admin         : $ADMIN_USER"
echo
echo "Services:"
echo "  Nginx         : $(systemctl is-active nginx)"
echo "  PHP-FPM       : $(systemctl is-active php8.2-fpm)"
echo
echo "Panel URL:"
echo "  http://$SERVER_IP/"
echo
echo "Nginx config:"
echo "  $NGINX_CONFIG"
echo
echo "==============================================================="
echo "IMPORTANT:"
echo "  Open port 80 in your VPS firewall/security group."
echo "  For production, configure HTTPS/SSL."
echo "==============================================================="
echo

# ---------------------------------------------------------------
# Useful commands
# ---------------------------------------------------------------
echo "Useful commands:"
echo
echo "  Check PHP:"
echo "    php -v"
echo
echo "  Check Nginx:"
echo "    nginx -t"
echo
echo "  Restart Nginx:"
echo "    systemctl restart nginx"
echo
echo "  Restart PHP-FPM:"
echo "    systemctl restart php8.2-fpm"
echo
echo "  Nginx logs:"
echo "    tail -f /var/log/nginx/error.log"
echo
echo "  Panel logs:"
echo "    tail -f $PANEL_DIR/logs/app.log"
echo
