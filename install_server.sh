#!/bin/bash
# NetBackup — instalasi server lengkap (konsep mirip setup_server.sh CCTV Monitoring)
#
# Usage (dari folder source project):
#   chmod +x install_server.sh
#   sudo bash install_server.sh
#
# Script akan:
#   - Install paket sistem (Apache, PHP, MariaDB, Python, …)
#   - Salin source ke /var/www/<domain>
#   - Buat database + user + schema + seed integrasi (Telegram/GDrive)
#   - VirtualHost Apache + opsi HTTPS (Let's Encrypt / self-signed / manual)
#   - Python venv, cron backup perangkat & Google Drive, permission, verifikasi web

set -e

# =========================================================
# COLOR & PATH
# =========================================================

RED='\033[1;31m'
GREEN='\033[1;32m'
YELLOW='\033[1;33m'
BLUE='\033[1;34m'
CYAN='\033[1;36m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ -f "$SCRIPT_DIR/VERSION" ]; then
    APP_VERSION="$(tr -d '\r\n' < "$SCRIPT_DIR/VERSION" | head -n1)"
else
    APP_VERSION="2.8.1"
fi

WEB_USER="www-data"
LOG_FILE="/var/log/netbackup_install.log"

# =========================================================
# HELPERS
# =========================================================

error_exit() {
    echo ""
    echo -e "${RED}==================================================${NC}"
    echo -e "${RED} ❌ INSTALLASI GAGAL${NC}"
    echo -e "${RED}==================================================${NC}"
    echo ""
    if [ -f "$LOG_FILE" ]; then
        echo -e "${YELLOW}Detail error (20 baris terakhir):${NC}"
        tail -20 "$LOG_FILE"
        echo ""
        echo -e "${YELLOW}Full log:${NC} $LOG_FILE"
    fi
    echo ""
    exit 1
}

trap error_exit ERR

log() {
    echo -e "${CYAN}[$(date '+%H:%M:%S')]${NC} $1"
    mkdir -p "$(dirname "$LOG_FILE")"
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

success() {
    echo -e "${GREEN}✔ $1${NC}"
}

warn() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

slugify() {
    echo "$1" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9.-]//g'
}

# Nama file Apache: sites-available/<namadomain>.conf
domain_conf_basename() {
    slugify "$1"
}

mysql_root_test() {
    if [ -n "$MYSQL_ROOT_PASSWORD" ]; then
        mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "SELECT 1" >> "$LOG_FILE" 2>&1
        return $?
    fi
    if mysql -u root -e "SELECT 1" >> "$LOG_FILE" 2>&1; then
        return 0
    fi
    mysql -e "SELECT 1" >> "$LOG_FILE" 2>&1
}

mysql_root_exec() {
    local sql="$1"
    if [ -n "$MYSQL_ROOT_PASSWORD" ]; then
        mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "$sql" >> "$LOG_FILE" 2>&1
    elif mysql -u root -e "SELECT 1" >> "$LOG_FILE" 2>&1; then
        mysql -u root -e "$sql" >> "$LOG_FILE" 2>&1
    else
        mysql -e "$sql" >> "$LOG_FILE" 2>&1
    fi
}

mysql_root_file() {
    local file="$1"
    if [ -n "$MYSQL_ROOT_PASSWORD" ]; then
        mysql -u root -p"$MYSQL_ROOT_PASSWORD" < "$file" >> "$LOG_FILE" 2>&1
    elif mysql -u root -e "SELECT 1" >> "$LOG_FILE" 2>&1; then
        mysql -u root < "$file" >> "$LOG_FILE" 2>&1
    else
        mysql < "$file" >> "$LOG_FILE" 2>&1
    fi
}

mysql_app_exec() {
    local sql="$1"
    if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
        return 1
    fi
    if [ -n "$DB_PASS" ]; then
        mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$sql" >> "$LOG_FILE" 2>&1
    else
        mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" "$DB_NAME" -e "$sql" >> "$LOG_FILE" 2>&1
    fi
}

install_pip_requirements() {
    local req_file="$1"
    local venv_pip="$2"
    if [ -f "$req_file" ]; then
        local pip_req
        pip_req="$(mktemp)"
        awk '/^# PYTHON PACKAGES/{p=1;next} p && /^[^#]/ && NF{print}' "$req_file" > "$pip_req"
        if [ -s "$pip_req" ]; then
            "$venv_pip" install -r "$pip_req" >> "$LOG_FILE" 2>&1
        else
            "$venv_pip" install \
                mysql-connector-python paramiko cryptography requests \
                google-api-python-client google-auth google-auth-oauthlib google-auth-httplib2 \
                >> "$LOG_FILE" 2>&1
        fi
        rm -f "$pip_req"
    else
        "$venv_pip" install \
            mysql-connector-python paramiko cryptography requests \
            google-api-python-client google-auth google-auth-oauthlib google-auth-httplib2 \
            >> "$LOG_FILE" 2>&1
    fi
}

sync_install_source() {
    local src="$1"
    local dst="$2"
    mkdir -p "$dst"
    if command -v rsync &>/dev/null; then
        rsync -a \
            --exclude '.git/' \
            --exclude 'venv/' \
            --exclude '__pycache__/' \
            --exclude '*.pyc' \
            --exclude 'config/db_setting.ini' \
            --exclude 'config/token.json' \
            --exclude 'config/client_secrets.json' \
            --exclude 'config/drive_key.json' \
            --exclude 'cache/device_connectivity.json' \
            --exclude '.cron.lock' \
            --exclude '.gdrive.lock' \
            "$src/" "$dst/"
    elif command -v tar &>/dev/null; then
        tar -C "$src" -cf - \
            --exclude='.git' \
            --exclude='venv' \
            --exclude='__pycache__' \
            --exclude='*.pyc' \
            --exclude='config/db_setting.ini' \
            --exclude='config/token.json' \
            --exclude='config/client_secrets.json' \
            --exclude='config/drive_key.json' \
            --exclude='cache/device_connectivity.json' \
            --exclude='.cron.lock' \
            --exclude='.gdrive.lock' \
            . | tar -C "$dst" -xf -
    else
        for entry in "$src"/*; do
            [ -e "$entry" ] || continue
            base="$(basename "$entry")"
            case "$base" in
                .git|venv|__pycache__) continue ;;
            esac
            cp -a "$entry" "$dst/"
        done
    fi
}

# =========================================================
# ROOT CHECK & BANNER
# =========================================================

if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}Installer harus dijalankan dengan root/sudo.${NC}"
    echo -e "Contoh: ${GREEN}sudo bash install_server.sh${NC}"
    exit 1
fi

if [ ! -f "$SCRIPT_DIR/sql/schema.sql" ] || [ ! -f "$SCRIPT_DIR/web/index.php" ]; then
    echo -e "${RED}Jalankan script dari root folder project NetBackup (ada sql/schema.sql).${NC}"
    exit 1
fi

clear
echo -e "${BLUE}"
cat << 'EOF'

 _   _      _   ____             _
| \ | | ___| |_| __ )  __ _  ___| | ___   _ _ __
|  \| |/ _ \ __|  _ \ / _` |/ __| |/ / | | | '_ \
| |\  |  __/ |_| |_) | (_| | (__|   <| |_| | |_) |
|_| \_|\___|\__|____/ \__,_|\___|_|\_\\__,_| .__/
                                           |_|

EOF
echo -e "${NC}"
echo -e "${GREEN}NetBackup Server Installer${NC}"
echo -e "${YELLOW}Debian 12 / Ubuntu — konsep setup seperti CCTV Monitoring${NC}"
echo -e "${CYAN}Versi: v${APP_VERSION}${NC}"
echo -e "${CYAN}Source: ${SCRIPT_DIR}${NC}"
echo ""

# =========================================================
# [1] KONFIGURASI WEB & DOMAIN
# =========================================================

echo -e "${BLUE}==================================================${NC}"
echo -e "${BLUE} [1/11] Konfigurasi Web & Domain${NC}"
echo -e "${BLUE}==================================================${NC}"

DEFAULT_DOMAIN="netbackup.local"
read -rp "Nama domain / ServerName [${DEFAULT_DOMAIN}]: " SERVER_NAME
SERVER_NAME=${SERVER_NAME:-$DEFAULT_DOMAIN}

SITE_CONF="$(domain_conf_basename "$SERVER_NAME")"
if [ -z "$SITE_CONF" ]; then
    SITE_CONF="netbackup.local"
    warn "Nama domain tidak valid, menggunakan: ${SITE_CONF}"
    SERVER_NAME="$SITE_CONF"
fi

DEFAULT_APP_DIR="/var/www/${SERVER_NAME}"
read -rp "Folder instalasi [${DEFAULT_APP_DIR}]: " APP_DIR
APP_DIR=${APP_DIR:-$DEFAULT_APP_DIR}

APACHE_CONF="/etc/apache2/sites-available/${SITE_CONF}.conf"
VENV_DIR="$APP_DIR/venv"

echo ""

# =========================================================
# [2] KONFIGURASI DATABASE
# =========================================================

echo -e "${BLUE}==================================================${NC}"
echo -e "${BLUE} [2/11] Konfigurasi Database${NC}"
echo -e "${BLUE}==================================================${NC}"
echo -e "${YELLOW}(Tekan Enter untuk nilai default)${NC}"
echo ""

read -rp "Database Host [localhost]: " DB_HOST
DB_HOST=${DB_HOST:-localhost}

read -rp "Database Port [3306]: " DB_PORT
DB_PORT=${DB_PORT:-3306}

read -rp "Database Name [netbackup_db]: " DB_NAME
DB_NAME=${DB_NAME:-netbackup_db}

read -rp "Database User [netbackup_user]: " DB_USER
DB_USER=${DB_USER:-netbackup_user}

read -rsp "Password user database (kosong = auto generate): " DB_PASS
echo ""
if [ -z "$DB_PASS" ]; then
    DB_PASS="$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)"
    echo -e "${YELLOW}Password database auto-generated: ${DB_PASS}${NC}"
fi

echo ""
echo -e "${YELLOW}Password root MariaDB/MySQL (kosongkan jika pakai auth socket tanpa password):${NC}"
read -rsp "MySQL root password: " MYSQL_ROOT_PASSWORD
echo ""

read -rp "Encryption Key aplikasi [auto generate]: " ENC_KEY
if [ -z "$ENC_KEY" ]; then
    ENC_KEY="$(openssl rand -hex 32)"
fi

echo ""

# =========================================================
# [3] OPSI HTTPS
# =========================================================

echo -e "${BLUE}==================================================${NC}"
echo -e "${BLUE} [3/11] HTTPS (SSL)${NC}"
echo -e "${BLUE}==================================================${NC}"
echo "  1) HTTP saja (tanpa SSL)"
echo "  2) Let's Encrypt (certbot — domain harus mengarah ke server ini)"
echo "  3) Self-signed (development / intranet)"
echo "  4) Sertifikat manual (path file .crt / .key)"
echo ""
read -rp "Pilih opsi [1]: " SSL_CHOICE
SSL_CHOICE=${SSL_CHOICE:-1}

SSL_MODE="none"
SSL_EMAIL=""
SSL_CERT=""
SSL_KEY=""

case "$SSL_CHOICE" in
    2)
        SSL_MODE="letsencrypt"
        read -rp "Email untuk Let's Encrypt: " SSL_EMAIL
        if [ -z "$SSL_EMAIL" ]; then
            SSL_EMAIL="admin@${SERVER_NAME}"
            warn "Menggunakan email default: $SSL_EMAIL"
        fi
        ;;
    3)
        SSL_MODE="selfsigned"
        ;;
    4)
        SSL_MODE="manual"
        read -rp "Path SSL Certificate (.crt/.pem): " SSL_CERT
        read -rp "Path SSL Private Key (.key): " SSL_KEY
        if [ ! -f "$SSL_CERT" ] || [ ! -f "$SSL_KEY" ]; then
            echo -e "${RED}File sertifikat tidak ditemukan.${NC}"
            exit 1
        fi
        ;;
    *)
        SSL_MODE="none"
        ;;
esac

echo ""
echo -e "${BLUE}==================================================${NC}"
echo -e "${GREEN} Memulai instalasi...${NC}"
echo -e "${BLUE}==================================================${NC}"
echo -e "Domain     : ${CYAN}${SERVER_NAME}${NC}"
echo -e "Install ke  : ${CYAN}${APP_DIR}${NC}"
echo -e "Database   : ${CYAN}${DB_NAME}${NC} @ ${CYAN}${DB_HOST}:${DB_PORT}${NC}"
echo -e "HTTPS      : ${CYAN}${SSL_MODE}${NC}"
echo ""

read -rp "Lanjutkan instalasi? [y/N]: " CONFIRM
case "$CONFIRM" in
    y|Y|yes|YES) ;;
    *) echo -e "${YELLOW}Instalasi dibatalkan.${NC}"; exit 0 ;;
esac

echo ""

# =========================================================
# [4] PAKET SISTEM
# =========================================================

log "[4/11] Update repository & install paket..."

APT_PACKAGES=(
    apache2
    php
    libapache2-mod-php
    php-mysql
    php-curl
    php-mbstring
    php-xml
    php-zip
    mariadb-server
    mariadb-client
    python3
    python3-pip
    python3-venv
    python3-dev
    build-essential
    libssl-dev
    libffi-dev
    git
    curl
    unzip
    openssl
    rsync
    util-linux
)

if [ "$SSL_MODE" = "letsencrypt" ]; then
    APT_PACKAGES+=(certbot python3-certbot-apache)
fi

DEBIAN_FRONTEND=noninteractive apt-get update -y >> "$LOG_FILE" 2>&1
DEBIAN_FRONTEND=noninteractive apt-get install -y "${APT_PACKAGES[@]}" >> "$LOG_FILE" 2>&1

systemctl enable apache2 mariadb >> "$LOG_FILE" 2>&1
systemctl restart apache2 mariadb >> "$LOG_FILE" 2>&1

success "Paket sistem terinstall"

# =========================================================
# [5] SALIN SOURCE KE /var/www/<domain>
# =========================================================

log "[5/11] Menyalin source ke ${APP_DIR}..."

if [ -d "$APP_DIR" ] && [ "$(ls -A "$APP_DIR" 2>/dev/null)" ]; then
    warn "Folder ${APP_DIR} sudah ada — file akan disinkronkan (config sensitif dipertahankan jika ada)."
fi

sync_install_source "$SCRIPT_DIR" "$APP_DIR"
mkdir -p "$APP_DIR/cache" "$APP_DIR/config"

success "Source disalin ke ${APP_DIR}"

# =========================================================
# [6] DATABASE
# =========================================================

log "[6/11] Setup database MariaDB/MySQL..."

if ! mysql_root_test; then
    echo -e "${RED}Koneksi root database gagal. Periksa password root MariaDB.${NC}"
    echo -e "Coba: ${YELLOW}sudo mysql -u root -p${NC}"
    exit 1
fi

mysql_root_exec "CREATE DATABASE IF NOT EXISTS \`${DB_NAME//\`/}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql_root_exec "CREATE USER IF NOT EXISTS '$(printf "%s" "$DB_USER" | sed "s/'/''/g")'@'localhost' IDENTIFIED BY '$(printf "%s" "$DB_PASS" | sed "s/'/''/g")';"
mysql_root_exec "ALTER USER '$(printf "%s" "$DB_USER" | sed "s/'/''/g")'@'localhost' IDENTIFIED BY '$(printf "%s" "$DB_PASS" | sed "s/'/''/g")';"
mysql_root_exec "GRANT ALL PRIVILEGES ON \`${DB_NAME//\`/}\`.* TO '$(printf "%s" "$DB_USER" | sed "s/'/''/g")'@'localhost';"
mysql_root_exec "FLUSH PRIVILEGES;"

success "Database dan user dibuat"

log "Import schema.sql..."
TEMP_SCHEMA="$(mktemp)"
sed "s/netbackup_db/${DB_NAME}/g" "$APP_DIR/sql/schema.sql" > "$TEMP_SCHEMA"
mysql_root_file "$TEMP_SCHEMA"
rm -f "$TEMP_SCHEMA"

success "Schema database diimport"

log "Menyiapkan integrasi Telegram & Google Drive (sys_settings)..."
if mysql_app_exec "INSERT IGNORE INTO sys_settings (setting_key, setting_value) VALUES ('telegram_bot_token',''),('telegram_chat_id',''),('google_drive_folder_id','');"; then
    success "Key sys_settings integrasi siap"
else
    warn "Gagal menyiapkan sys_settings — cek kredensial DB"
fi

echo ""
echo -e "${YELLOW}Google Drive Folder ID (opsional)${NC}"
echo -e "Ambil dari URL folder di Google Drive. Kosongkan Enter untuk lewati / isi nanti di Pengaturan."
read -rp "Folder ID: " INPUT_GDRIVE_FOLDER
if [ -n "$INPUT_GDRIVE_FOLDER" ]; then
    ESCAPED_GDRIVE="$(printf '%s' "$INPUT_GDRIVE_FOLDER" | sed "s/'/''/g")"
    if mysql_app_exec "INSERT INTO sys_settings (setting_key, setting_value) VALUES ('google_drive_folder_id','${ESCAPED_GDRIVE}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);"; then
        success "Google Drive Folder ID disimpan"
    else
        warn "Gagal menyimpan Google Drive Folder ID"
    fi
fi

echo "$APP_VERSION" > "$APP_DIR/VERSION"
success "Versi v${APP_VERSION} ditulis ke ${APP_DIR}/VERSION"

# =========================================================
# [7] KONFIGURASI APLIKASI
# =========================================================

log "[7/11] Menulis db_setting.ini..."

cat > "$APP_DIR/config/db_setting.ini" <<EOF
[database]
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
ENCRYPTION_KEY=${ENC_KEY}

[install]
SERVER_NAME=${SERVER_NAME}
APP_DIR=${APP_DIR}
APACHE_SITE=${SITE_CONF}.conf
INSTALLED_AT=$(date -Iseconds)
EOF

chmod 640 "$APP_DIR/config/db_setting.ini"
success "db_setting.ini dibuat"

# =========================================================
# [8] PYTHON VENV
# =========================================================

log "[8/11] Python virtual environment & dependencies..."

python3 -m venv "$VENV_DIR"
"$VENV_DIR/bin/pip" install --upgrade pip setuptools wheel >> "$LOG_FILE" 2>&1
install_pip_requirements "$APP_DIR/python/requirements.txt" "$VENV_DIR/bin/pip"

success "Python environment siap"

# =========================================================
# [9] APACHE VIRTUALHOST
# =========================================================

log "[9/11] Konfigurasi Apache VirtualHost..."

a2enmod rewrite >> "$LOG_FILE" 2>&1
if [ "$SSL_MODE" != "none" ]; then
    a2enmod ssl headers >> "$LOG_FILE" 2>&1
fi

APACHE_TEMPLATE="$SCRIPT_DIR/apache/netbackup.conf"
if [ ! -f "$APACHE_TEMPLATE" ]; then
    APACHE_TEMPLATE="$APP_DIR/apache/netbackup.conf"
fi

if [ -f "$APACHE_TEMPLATE" ]; then
    sed -e "s|__SERVER_NAME__|${SERVER_NAME}|g" \
        -e "s|__DOCUMENT_ROOT__|${APP_DIR}|g" \
        -e "s|__SITE_SLUG__|${SITE_CONF}|g" \
        "$APACHE_TEMPLATE" > "$APACHE_CONF"
else
    cat > "$APACHE_CONF" <<EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    ServerAdmin webmaster@${SERVER_NAME}
    DocumentRoot ${APP_DIR}
    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/${SITE_CONF}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_CONF}-access.log combined
</VirtualHost>
EOF
fi

a2dissite 000-default.conf >> "$LOG_FILE" 2>&1 || true
a2ensite "${SITE_CONF}.conf" >> "$LOG_FILE" 2>&1

apache2ctl configtest >> "$LOG_FILE" 2>&1
systemctl reload apache2

success "VirtualHost HTTP: ${APACHE_CONF}"

# =========================================================
# [10] HTTPS
# =========================================================

log "[10/11] Setup HTTPS (${SSL_MODE})..."

HTTPS_URL=""
case "$SSL_MODE" in
    letsencrypt)
        if certbot --apache -d "$SERVER_NAME" --non-interactive --agree-tos \
            -m "$SSL_EMAIL" --redirect >> "$LOG_FILE" 2>&1; then
            success "Let's Encrypt aktif untuk ${SERVER_NAME}"
            HTTPS_URL="https://${SERVER_NAME}/"
        else
            warn "Certbot gagal — pastikan DNS ${SERVER_NAME} mengarah ke IP server."
            warn "Site tetap berjalan di HTTP. Jalankan ulang: certbot --apache -d ${SERVER_NAME}"
        fi
        ;;
    selfsigned)
        SSL_DIR="/etc/ssl/netbackup"
        mkdir -p "$SSL_DIR"
        SSL_CERT="$SSL_DIR/${SITE_CONF}.crt"
        SSL_KEY="$SSL_DIR/${SITE_CONF}.key"
        openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
            -keyout "$SSL_KEY" -out "$SSL_CERT" \
            -subj "/CN=${SERVER_NAME}/O=NetBackup/C=ID" >> "$LOG_FILE" 2>&1
        chmod 600 "$SSL_KEY"
        chmod 644 "$SSL_CERT"

        cat > "$APACHE_CONF" <<EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    Redirect permanent / https://${SERVER_NAME}/
</VirtualHost>

<VirtualHost *:443>
    ServerName ${SERVER_NAME}
    ServerAdmin webmaster@${SERVER_NAME}
    DocumentRoot ${APP_DIR}
    SSLEngine on
    SSLCertificateFile ${SSL_CERT}
    SSLCertificateKeyFile ${SSL_KEY}
    Header always set Strict-Transport-Security "max-age=31536000"
    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/${SITE_CONF}-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_CONF}-ssl-access.log combined
</VirtualHost>
EOF
        apache2ctl configtest >> "$LOG_FILE" 2>&1
        systemctl reload apache2
        success "HTTPS self-signed aktif"
        HTTPS_URL="https://${SERVER_NAME}/"
        warn "Browser akan memperingatkan sertifikat self-signed — normal untuk intranet."
        ;;
    manual)
        cat > "$APACHE_CONF" <<EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    Redirect permanent / https://${SERVER_NAME}/
</VirtualHost>

<VirtualHost *:443>
    ServerName ${SERVER_NAME}
    ServerAdmin webmaster@${SERVER_NAME}
    DocumentRoot ${APP_DIR}
    SSLEngine on
    SSLCertificateFile ${SSL_CERT}
    SSLCertificateKeyFile ${SSL_KEY}
    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/${SITE_CONF}-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_CONF}-ssl-access.log combined
</VirtualHost>
EOF
        apache2ctl configtest >> "$LOG_FILE" 2>&1
        systemctl reload apache2
        success "HTTPS manual aktif"
        HTTPS_URL="https://${SERVER_NAME}/"
        ;;
    *)
        success "HTTP saja (tanpa SSL)"
        ;;
esac

# =========================================================
# [11] PERMISSION & CRON
# =========================================================

log "[11/11] Permission, cache, cron scheduler & verifikasi..."

mkdir -p "$APP_DIR/cache"

chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod 640 "$APP_DIR/config/db_setting.ini"
chmod +x "$VENV_DIR/bin/"* 2>/dev/null || true

GDRIVE_LOG="/var/log/netbackup_gdrive.log"
touch "$GDRIVE_LOG" 2>/dev/null || true
chown "$WEB_USER:$WEB_USER" "$GDRIVE_LOG" 2>/dev/null || true

CRON_COMMAND="0 * * * * flock -n $APP_DIR/.cron.lock $VENV_DIR/bin/python3 $APP_DIR/python/run_scheduled_backup.py > /dev/null 2>&1"
CONNECTIVITY_CRON="*/3 * * * * flock -n $APP_DIR/cache/.connectivity_refresh.lock php $APP_DIR/web/cli_refresh_connectivity.php > /dev/null 2>&1"
GDRIVE_CRON=""
if [ -f "$APP_DIR/config/client_secrets.json" ]; then
    GDRIVE_CRON="0 3 * * * flock -n $APP_DIR/.gdrive.lock $VENV_DIR/bin/python3 $APP_DIR/python/backup_db_to_drive.py >> $GDRIVE_LOG 2>&1"
fi

(
    crontab -u "$WEB_USER" -l 2>/dev/null | grep -v "run_scheduled_backup.py" | grep -v "backup_db_to_drive.py" | grep -v "cli_refresh_connectivity.php" || true
    echo "$CRON_COMMAND"
    echo "$CONNECTIVITY_CRON"
    if [ -n "$GDRIVE_CRON" ]; then
        echo "$GDRIVE_CRON"
    fi
) | crontab -u "$WEB_USER" -

success "Cron backup perangkat diperbarui (setiap jam)"
success "Cron cek konektivitas diperbarui (setiap 3 menit)"
if [ -n "$GDRIVE_CRON" ]; then
    success "Cron backup DB→Google Drive: 03:00 daily"
else
    warn "Google Drive: salin config/client_secrets.json lalu jalankan update_server.sh untuk aktifkan cron DB→Drive"
fi

systemctl restart apache2 mariadb >> "$LOG_FILE" 2>&1

HTTP_CODE="000"
CHECK_URL=""
IP_ADDR="$(hostname -I | awk '{print $1}')"
HEALTH_URLS=(
    "http://${SERVER_NAME}/login"
    "http://${SERVER_NAME}/"
    "http://${IP_ADDR}/login"
    "http://127.0.0.1/login"
)
for try_url in "${HEALTH_URLS[@]}"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" -L --max-time 8 "$try_url" 2>/dev/null || echo "000")
    case "$code" in
        200|301|302|308)
            HTTP_CODE="$code"
            CHECK_URL="$try_url"
            break
            ;;
    esac
done
if [ "$HTTP_CODE" != "000" ]; then
    success "Verifikasi web OK (HTTP ${HTTP_CODE} — ${CHECK_URL})"
else
    warn "Verifikasi web gagal — cek Apache & DNS, lalu buka http://${SERVER_NAME}/setup"
fi

success "Instalasi selesai"

# =========================================================
# RINGKASAN
# =========================================================

IP_ADDR="$(hostname -I | awk '{print $1}')"
HTTP_URL="http://${SERVER_NAME}/"
[ -z "$HTTPS_URL" ] && HTTPS_URL=""

GDRIVE_FOLDER="$(mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -s -e "SELECT setting_value FROM sys_settings WHERE setting_key='google_drive_folder_id' LIMIT 1" 2>/dev/null || true)"
GDRIVE_FOLDER="${GDRIVE_FOLDER// /}"

echo ""
echo -e "${GREEN}==================================================${NC}"
echo -e "${GREEN} ✅ NETBACKUP BERHASIL DIINSTALL${NC}"
echo -e "${GREEN}==================================================${NC}"
echo ""
echo -e "${CYAN}URL:${NC}"
if [ "$SSL_MODE" != "none" ] && [ -n "$HTTPS_URL" ]; then
    echo "  HTTPS : ${HTTPS_URL}"
fi
echo "  HTTP  : ${HTTP_URL}"
if [ -n "$CHECK_URL" ] && [ "$HTTP_CODE" != "000" ]; then
    echo "  Health: ${CHECK_URL} (HTTP ${HTTP_CODE})"
fi
echo ""
echo -e "${CYAN}Instalasi:${NC}"
echo "  Versi       : v${APP_VERSION}"
echo "  Domain      : ${SERVER_NAME}"
echo "  Folder      : ${APP_DIR}"
echo "  Apache site : ${APACHE_CONF}"
echo ""
echo -e "${CYAN}Database:${NC}"
echo "  Host     : ${DB_HOST}:${DB_PORT}"
echo "  Database : ${DB_NAME}"
echo "  User     : ${DB_USER}"
echo "  Password : ${DB_PASS}"
echo ""
echo -e "${CYAN}Cron (www-data):${NC}"
echo "  Backup perangkat : setiap jam"
if [ -n "$GDRIVE_CRON" ]; then
    echo "  Backup DB→Drive  : 03:00 daily"
else
    echo "  Backup DB→Drive  : belum (butuh client_secrets.json)"
fi
echo ""
echo -e "${CYAN}Integrasi (isi di Pengaturan):${NC}"
echo "  Telegram Bot Token & Chat ID"
if [ -n "$GDRIVE_FOLDER" ]; then
    echo "  Google Drive Folder ID : ${GDRIVE_FOLDER}"
else
    echo "  Google Drive Folder ID : belum diisi"
fi
echo ""
echo -e "${CYAN}Config:${NC}"
echo "  ${APP_DIR}/config/db_setting.ini"
echo ""
echo -e "${CYAN}Log instalasi:${NC} ${LOG_FILE}"
if [ -n "$GDRIVE_CRON" ]; then
    echo -e "${CYAN}Log GDrive:${NC} ${GDRIVE_LOG}"
fi
echo ""
echo -e "${YELLOW}Langkah berikutnya:${NC}"
echo "  1. Buka URL di browser & selesaikan setup admin (/setup)"
echo "  2. Isi Telegram & Google Drive di menu Pengaturan (jika belum)"
echo "  3. Salin config/client_secrets.json untuk backup DB otomatis ke Drive"
echo "  4. Kelola perangkat, vendor, jadwal backup, dan dashboard"
echo ""
echo -e "${GREEN}NetBackup siap digunakan.${NC}"
echo ""
