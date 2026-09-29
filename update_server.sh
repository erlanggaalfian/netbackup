#!/bin/bash
# NetBackup — update server (konsep mirip setup/update CCTV Monitoring)
#
# Usage (dari folder source project):
#   chmod +x update_server.sh
#   sudo bash update_server.sh
#   sudo bash update_server.sh --yes
#   sudo bash update_server.sh --version 2.8.0
#
# Script akan:
#   - Auto-detect lokasi install (/var/www/<domain>)
#   - Backup database & config sensitif
#   - Salin codebase terbaru tanpa timpa db_setting.ini / token
#   - Migrasi schema, refresh Python venv, cron, Apache

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

# =========================================================
# CONFIG
# =========================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Default versi release — bisa diubah di sini atau saat menjalankan script
DEFAULT_APP_VERSION="2.8.1"
APP_VERSION=""
VERSION_ARG=""

WEB_USER="www-data"
LOG_FILE="/var/log/netbackup_update.log"
BACKUP_DIR="/var/backups/netbackup"
APACHE_CONF="/etc/apache2/sites-available/netbackup.conf"
SKIP_CONFIRM=0
SKIP_BACKUP=0

while [ $# -gt 0 ]; do
    case "$1" in
        --yes|-y) SKIP_CONFIRM=1 ;;
        --no-backup) SKIP_BACKUP=1 ;;
        --version|-v)
            shift
            if [ -z "${1:-}" ]; then
                echo -e "${RED}Opsi --version membutuhkan nilai (contoh: 2.8.0)${NC}"
                exit 1
            fi
            VERSION_ARG="$1"
            ;;
        --help|-h)
            echo "Usage: sudo bash update_server.sh [opsi]"
            echo ""
            echo "Opsi:"
            echo "  --yes, -y              Lewati konfirmasi interaktif"
            echo "  --no-backup            Lewati backup database"
            echo "  --version, -v VER      Set versi release (default: ${DEFAULT_APP_VERSION})"
            echo "  --help, -h             Tampilkan bantuan ini"
            exit 0
            ;;
        *)
            echo -e "${RED}Opsi tidak dikenal: $1${NC}"
            echo "Gunakan: sudo bash update_server.sh --help"
            exit 1
            ;;
    esac
    shift
done

read_version_file() {
    local file="$1"
    local fallback="${2:-}"
    if [ -f "$file" ]; then
        local v
        v="$(tr -d '\r\n' < "$file" | head -n1 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        if [ -n "$v" ]; then
            echo "$v"
            return
        fi
    fi
    echo "$fallback"
}

read_suggested_version() {
    read_version_file "$SCRIPT_DIR/VERSION" "$DEFAULT_APP_VERSION"
}

resolve_app_version() {
    local suggested
    suggested="$(read_suggested_version)"

    if [ -n "$VERSION_ARG" ]; then
        APP_VERSION="$VERSION_ARG"
        return
    fi

    if [ "$SKIP_CONFIRM" -eq 1 ]; then
        APP_VERSION="$suggested"
        return
    fi

    echo ""
    echo -e "${YELLOW}Versi release NetBackup${NC}"
    if [ -n "$INSTALLED_VERSION" ]; then
        echo -e "Versi terpasang : ${CYAN}v${INSTALLED_VERSION}${NC}"
    else
        echo -e "Versi terpasang : ${YELLOW}tidak diketahui${NC}"
    fi
    echo -e "Default script  : ${CYAN}v${DEFAULT_APP_VERSION}${NC}"
    if [ -f "$SCRIPT_DIR/VERSION" ]; then
        echo -e "Sumber VERSION  : ${CYAN}v${suggested}${NC}"
    fi
    read -rp "Versi yang akan diinstall [${suggested}]: " INPUT_VERSION
    APP_VERSION="${INPUT_VERSION:-$suggested}"
}

print_version_upgrade_summary() {
    if [ -n "$INSTALLED_VERSION" ]; then
        echo -e "Versi terpasang : ${CYAN}v${INSTALLED_VERSION}${NC}"
        echo -e "Versi target     : ${GREEN}v${APP_VERSION}${NC}"
        if [ "$INSTALLED_VERSION" = "$APP_VERSION" ]; then
            warn "Versi target sama dengan versi terpasang."
        else
            echo -e "Upgrade          : ${CYAN}v${INSTALLED_VERSION}${NC} → ${GREEN}v${APP_VERSION}${NC}"
        fi
    else
        echo -e "Versi terpasang : ${YELLOW}tidak diketahui (file VERSION tidak ada)${NC}"
        echo -e "Versi target     : ${GREEN}v${APP_VERSION}${NC}"
    fi
}

# =========================================================
# HELPERS
# =========================================================
error_exit() {
    echo ""
    echo -e "${RED}==================================================${NC}"
    echo -e "${RED} ❌ UPDATE GAGAL${NC}"
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

# =========================================================
# LOG FUNCTION
# =========================================================

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

ini_get() {
    local key="$1"
    local file="$2"
    awk -F= -v key="$key" '
        /^\[database\]/ { in_db=1; next }
        /^\[/ { in_db=0 }
        in_db && $1 ~ "^[[:space:]]*" key "[[:space:]]*$" {
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2)
            print $2
            exit
        }
    ' "$file"
}

ini_get_install() {
    local key="$1"
    local file="$2"
    awk -F= -v key="$key" '
        /^\[install\]/ { in_i=1; next }
        /^\[/ { in_i=0 }
        in_i && $1 ~ "^[[:space:]]*" key "[[:space:]]*$" {
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2)
            print $2
            exit
        }
    ' "$file"
}

apache_read_docroot() {
    local conf="$1"
    grep -iE '^\s*DocumentRoot\s+' "$conf" 2>/dev/null | head -n1 | awk '{print $2}'
}

apache_read_server_name() {
    local conf="$1"
    grep -iE '^\s*ServerName\s+' "$conf" 2>/dev/null | head -n1 | awk '{print $2}'
}

apache_find_conf_for_docroot() {
    local docroot="$1"
    local conf doc base
    for conf in /etc/apache2/sites-available/*.conf; do
        [ -f "$conf" ] || continue
        base="$(basename "$conf")"
        case "$base" in
            *-le-ssl.conf|000-default.conf) continue ;;
        esac
        doc="$(apache_read_docroot "$conf")"
        if [ "$doc" = "$docroot" ]; then
            echo "$conf"
            return 0
        fi
    done
}

apache_detect_install() {
    local conf doc base
    for conf in /etc/apache2/sites-available/*.conf; do
        [ -f "$conf" ] || continue
        base="$(basename "$conf")"
        case "$base" in
            *-le-ssl.conf|000-default.conf) continue ;;
        esac
        doc="$(apache_read_docroot "$conf")"
        if [ -n "$doc" ] \
            && [ -d "$doc/web" ] \
            && [ -f "$doc/web/index.php" ] \
            && [ -f "$doc/python/run_scheduled_backup.py" ]; then
            TARGET_DIR="$doc"
            APACHE_CONF="$conf"
            success "Terdeteksi dari Apache ($base): $TARGET_DIR"
            return 0
        fi
    done
    return 1
}

resolve_apache_and_domain() {
    local from_ini

    from_ini="$(ini_get_install APACHE_SITE "$INI_FILE" 2>/dev/null || true)"
    if [ -n "$from_ini" ] && [ -f "/etc/apache2/sites-available/$from_ini" ]; then
        APACHE_CONF="/etc/apache2/sites-available/$from_ini"
    elif [ -z "$APACHE_CONF" ] || [ ! -f "$APACHE_CONF" ]; then
        from_ini="$(apache_find_conf_for_docroot "$TARGET_DIR")"
        if [ -n "$from_ini" ]; then
            APACHE_CONF="$from_ini"
        fi
    fi

    SERVER_NAME="$(ini_get_install SERVER_NAME "$INI_FILE" 2>/dev/null || true)"
    if [ -z "$SERVER_NAME" ] && [ -f "$APACHE_CONF" ]; then
        SERVER_NAME="$(apache_read_server_name "$APACHE_CONF")"
    fi
}

resolve_access_urls() {
    HTTPS_URL=""
    IP_ADDR="$(hostname -I | awk '{print $1}')"

    if [ -n "$SERVER_NAME" ]; then
        HTTP_URL="http://${SERVER_NAME}/"
        if [ -f "$APACHE_CONF" ]; then
            if grep -qiE '^\s*Redirect\s+permanent\s+/+\s+https://' "$APACHE_CONF"; then
                HTTPS_URL="https://${SERVER_NAME}/"
            fi
            if [ -z "$HTTPS_URL" ] && [ -f "/etc/apache2/sites-available/$(basename "$APACHE_CONF" .conf)-le-ssl.conf" ]; then
                HTTPS_URL="https://${SERVER_NAME}/"
            fi
        fi
    else
        HTTP_URL=""
    fi
}

mysql_app_exec() {
    local sql="$1"
    if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
        return 1
    fi
    if [ -n "$DB_PASS" ]; then
        mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$sql"
    else
        mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" "$DB_NAME" -e "$sql"
    fi
}

mysql_app_scalar() {
    local sql="$1"
    if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
        return 1
    fi
    if [ -n "$DB_PASS" ]; then
        mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    else
        mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    fi
}

sync_tree() {
    local src="$1"
    local dst="$2"
    if command -v rsync &>/dev/null; then
        rsync -a --delete \
            --exclude '__pycache__/' \
            --exclude '*.pyc' \
            --exclude '.git/' \
            "$src/" "$dst/"
    else
        rm -rf "$dst"
        mkdir -p "$dst"
        cp -a "$src/." "$dst/"
        find "$dst" -type d -name '__pycache__' -exec rm -rf {} + 2>/dev/null || true
        find "$dst" -type f -name '*.pyc' -delete 2>/dev/null || true
    fi
}

# =========================================================
# ROOT CHECK & BANNER
# =========================================================

if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}Updater harus dijalankan dengan root/sudo.${NC}"
    echo -e "Contoh: ${GREEN}sudo bash update_server.sh${NC}"
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
echo -e "${GREEN}NetBackup Server Updater${NC}"
echo -e "${YELLOW}Debian 12 / Ubuntu — update tanpa ubah database & kredensial${NC}"
echo -e "${CYAN}Versi default: v${DEFAULT_APP_VERSION}${NC}"
echo -e "${CYAN}Source: ${SCRIPT_DIR}${NC}"
echo ""

# =========================================================
# DETEKSI LOKASI INSTALASI
# =========================================================

echo -e "${BLUE}==================================================${NC}"
echo -e "${BLUE} Deteksi Lokasi Instalasi${NC}"
echo -e "${BLUE}==================================================${NC}"
echo ""

log "Mendeteksi lokasi instalasi NetBackup..."
TARGET_DIR=""
APACHE_CONF=""

if ! apache_detect_install; then
    for dir in /var/www/*; do
        if [ -d "$dir/web" ] && [ -f "$dir/web/index.php" ] && [ -f "$dir/python/run_scheduled_backup.py" ]; then
            TARGET_DIR="$dir"
            success "Terdeteksi dari scan /var/www: $TARGET_DIR"
            break
        fi
    done
fi

if [ -z "$TARGET_DIR" ] && [ -d "/var/www/netbackup" ]; then
    TARGET_DIR="/var/www/netbackup"
    success "Menggunakan default: $TARGET_DIR"
fi

if [ -z "$TARGET_DIR" ] || [ ! -d "$TARGET_DIR" ]; then
    echo -e "${RED}Lokasi instalasi NetBackup tidak ditemukan di /var/www.${NC}"
    echo -e "Jalankan instalasi penuh: ${GREEN}sudo bash install_server.sh${NC}"
    exit 1
fi

VENV_DIR="$TARGET_DIR/venv"
INI_FILE="$TARGET_DIR/config/db_setting.ini"
SERVER_NAME=""
HTTP_URL=""
HTTPS_URL=""
IP_ADDR=""

if [ ! -f "$INI_FILE" ]; then
    echo -e "${RED}Konfigurasi database tidak ditemukan: $INI_FILE${NC}"
    echo -e "Jalankan instalasi penuh: ${GREEN}sudo bash install_server.sh${NC}"
    exit 1
fi

DB_HOST="$(ini_get DB_HOST "$INI_FILE")"
DB_NAME="$(ini_get DB_NAME "$INI_FILE")"
DB_USER="$(ini_get DB_USER "$INI_FILE")"
DB_PASS="$(ini_get DB_PASS "$INI_FILE")"
DB_PORT="$(ini_get DB_PORT "$INI_FILE")"
[ -z "$DB_PORT" ] && DB_PORT="3306"

resolve_apache_and_domain
if [ -n "$APACHE_CONF" ] && [ -f "$APACHE_CONF" ]; then
    success "Apache site: $(basename "$APACHE_CONF")"
fi
if [ -n "$SERVER_NAME" ]; then
    success "Domain: $SERVER_NAME"
fi

INSTALLED_VERSION="$(read_version_file "$TARGET_DIR/VERSION" "")"

echo ""
echo -e "${BLUE}==================================================${NC}"
echo -e "${BLUE} Ringkasan Update${NC}"
echo -e "${BLUE}==================================================${NC}"
echo -e "Sumber script  : ${CYAN}${SCRIPT_DIR}${NC}"
echo -e "Target install : ${CYAN}${TARGET_DIR}${NC}"
if [ -n "$SERVER_NAME" ]; then
    echo -e "Domain         : ${CYAN}${SERVER_NAME}${NC}"
fi
echo -e "Apache site    : ${CYAN}${APACHE_CONF}${NC}"
echo -e "Database       : ${CYAN}${DB_NAME:-?}${NC} @ ${CYAN}${DB_HOST:-localhost}:${DB_PORT}${NC}"
if [ -n "$INSTALLED_VERSION" ]; then
    echo -e "Versi terpasang: ${CYAN}v${INSTALLED_VERSION}${NC}"
else
    echo -e "Versi terpasang: ${YELLOW}tidak diketahui${NC}"
fi
echo ""

resolve_app_version
print_version_upgrade_summary
echo ""

if [ "$SKIP_CONFIRM" -ne 1 ]; then
    read -rp "Lanjutkan update? [y/N]: " CONFIRM
    case "$CONFIRM" in
        y|Y|yes|YES) ;;
        *) warn "Update dibatalkan."; exit 0 ;;
    esac

    if [ "$SKIP_BACKUP" -eq 0 ]; then
        read -rp "Apakah Anda ingin membackup database sebelum melakukan update? [Y/n]: " CONFIRM_BACKUP
        case "$CONFIRM_BACKUP" in
            n|N|no|NO) SKIP_BACKUP=1 ;;
            *) SKIP_BACKUP=0 ;;
        esac
    fi
fi

echo ""
echo -e "${BLUE}==================================================${NC}"
echo -e "${GREEN} Memulai update NetBackup...${NC}"
echo -e "${BLUE}==================================================${NC}"
echo ""

log "Update dimulai (sumber: $SCRIPT_DIR, target: $TARGET_DIR, versi: v${INSTALLED_VERSION:-?} → v${APP_VERSION})"
# =========================================================
# [1/8] BACKUP DATABASE (opsional tapi direkomendasikan)
# =========================================================

if [ "$SKIP_BACKUP" -eq 1 ]; then
    log "[1/8] Backup database dilewati atas permintaan pengguna."
else
    log "[1/8] Backup database sebelum migrasi..."
    mkdir -p "$BACKUP_DIR"
    DB_BACKUP_FILE="$BACKUP_DIR/${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql"

    if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && command -v mysqldump &>/dev/null; then
        if [ -n "$DB_PASS" ]; then
            mysqldump -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" \
                --single-transaction --routines --triggers "$DB_NAME" \
                > "$DB_BACKUP_FILE" 2>> "$LOG_FILE"
        else
            mysqldump -h "${DB_HOST:-localhost}" -u "$DB_USER" \
                --single-transaction --routines --triggers "$DB_NAME" \
                > "$DB_BACKUP_FILE" 2>> "$LOG_FILE"
        fi
        success "Backup database: $DB_BACKUP_FILE"
    else
        warn "Backup database dilewati (mysqldump/kredensial tidak tersedia)"
    fi
fi

# =========================================================
# [2/8] BACKUP KONFIGURASI SENSITIF
# =========================================================

log "[2/8] Menyimpan konfigurasi & kredensial existing..."

TMP_CONFIG="$(mktemp -d)"
cp -a "$INI_FILE" "$TMP_CONFIG/db_setting.ini"

for secret in client_secrets.json token.json drive_key.json; do
    if [ -f "$TARGET_DIR/config/$secret" ]; then
        cp -a "$TARGET_DIR/config/$secret" "$TMP_CONFIG/$secret"
        success "$secret disimpan sementara"
    fi
done

success "Konfigurasi sensitif aman"

# =========================================================
# [3/8] SALIN CODEBASE TERBARU
# =========================================================

log "[3/8] Menyalin codebase terbaru ke $TARGET_DIR..."

echo "$APP_VERSION" > "$SCRIPT_DIR/VERSION"
echo "$APP_VERSION" > "$TARGET_DIR/VERSION"
success "Versi v${APP_VERSION} ditulis ke VERSION"

for folder in web python sql style; do
    if [ -d "$SCRIPT_DIR/$folder" ]; then
        sync_tree "$SCRIPT_DIR/$folder" "$TARGET_DIR/$folder"
        success "Folder $folder diperbarui"
    fi
done

if [ -d "$SCRIPT_DIR/img" ]; then
    sync_tree "$SCRIPT_DIR/img" "$TARGET_DIR/img"
fi

mkdir -p "$TARGET_DIR/config"
[ -f "$SCRIPT_DIR/config/db.php" ] && cp -f "$SCRIPT_DIR/config/db.php" "$TARGET_DIR/config/"
[ -f "$SCRIPT_DIR/.htaccess" ] && cp -f "$SCRIPT_DIR/.htaccess" "$TARGET_DIR/"
[ -f "$SCRIPT_DIR/install_server.sh" ] && cp -f "$SCRIPT_DIR/install_server.sh" "$TARGET_DIR/"
[ -f "$SCRIPT_DIR/update_server.sh" ] && cp -f "$SCRIPT_DIR/update_server.sh" "$TARGET_DIR/"
[ -f "$SCRIPT_DIR/Otomatisasi_Backup_Database_ke_Google_Drive.md" ] && cp -f "$SCRIPT_DIR/Otomatisasi_Backup_Database_ke_Google_Drive.md" "$TARGET_DIR/" 2>/dev/null || true
if [ -d "$SCRIPT_DIR/apache" ]; then
    mkdir -p "$TARGET_DIR/apache"
    cp -a "$SCRIPT_DIR/apache/." "$TARGET_DIR/apache/"
fi

# Restore konfigurasi sensitif (tidak ditimpa oleh source baru)
cp -a "$TMP_CONFIG/db_setting.ini" "$INI_FILE"
for secret in client_secrets.json token.json drive_key.json; do
    if [ -f "$TMP_CONFIG/$secret" ]; then
        cp -a "$TMP_CONFIG/$secret" "$TARGET_DIR/config/$secret"
    fi
done
rm -rf "$TMP_CONFIG"

success "Codebase terbaru tersalin (db_setting.ini & token tidak ditimpa)"

# =========================================================
# [4/8] MIGRASI DATABASE (schema.sql — aman, IF NOT EXISTS)
# =========================================================

log "[4/8] Menjalankan migrasi database (schema.sql)..."

if [ -f "$TARGET_DIR/sql/schema.sql" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    SCHEMA_TMP="$(mktemp)"
    # Abaikan CREATE DATABASE / USE agar tidak menimpa nama DB produksi
    grep -v -E '^(CREATE DATABASE|USE )' "$TARGET_DIR/sql/schema.sql" > "$SCHEMA_TMP"

    if [ -n "$DB_PASS" ]; then
        mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
            < "$SCHEMA_TMP" >> "$LOG_FILE" 2>&1
    else
        mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" "$DB_NAME" \
            < "$SCHEMA_TMP" >> "$LOG_FILE" 2>&1
    fi
    rm -f "$SCHEMA_TMP"
    mysql_app_exec "ALTER TABLE backups ADD INDEX idx_backups_status_ts (status, timestamp);" >> "$LOG_FILE" 2>&1 || true
    mysql_app_exec "ALTER TABLE backups ADD INDEX idx_backups_device_last (device_id, timestamp, id);" >> "$LOG_FILE" 2>&1 || true
    success "Migrasi schema selesai ($DB_NAME @ ${DB_HOST:-localhost})"
else
    warn "schema.sql atau kredensial DB tidak lengkap, migrasi dilewati"
fi

log "Menyiapkan integrasi Telegram & Google Drive..."
if mysql_app_exec "INSERT IGNORE INTO sys_settings (setting_key, setting_value) VALUES ('telegram_bot_token',''),('telegram_chat_id',''),('google_drive_folder_id','');" >> "$LOG_FILE" 2>&1; then
    success "Key sys_settings integrasi siap"
else
    warn "Gagal menyiapkan sys_settings integrasi — cek kredensial DB"
fi

CURRENT_GDRIVE_FOLDER="$(mysql_app_scalar "SELECT setting_value FROM sys_settings WHERE setting_key='google_drive_folder_id' LIMIT 1" || true)"
CURRENT_GDRIVE_FOLDER="${CURRENT_GDRIVE_FOLDER// /}"

if [ "$SKIP_CONFIRM" -ne 1 ] && [ -z "$CURRENT_GDRIVE_FOLDER" ]; then
    echo ""
    echo -e "${YELLOW}Google Drive Folder ID (opsional)${NC}"
    echo -e "Ambil dari URL folder di Google Drive. Kosongkan Enter untuk lewati / isi nanti di Pengaturan."
    read -rp "Folder ID: " INPUT_GDRIVE_FOLDER
    if [ -n "$INPUT_GDRIVE_FOLDER" ]; then
        ESCAPED_GDRIVE="$(printf '%s' "$INPUT_GDRIVE_FOLDER" | sed "s/'/''/g")"
        if mysql_app_exec "INSERT INTO sys_settings (setting_key, setting_value) VALUES ('google_drive_folder_id','${ESCAPED_GDRIVE}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);" >> "$LOG_FILE" 2>&1; then
            success "Google Drive Folder ID disimpan"
            CURRENT_GDRIVE_FOLDER="$INPUT_GDRIVE_FOLDER"
        else
            warn "Gagal menyimpan Google Drive Folder ID"
        fi
    fi
fi

# =========================================================
# [5/8] VERIFIKASI APACHE
# =========================================================

log "[5/8] Memverifikasi konfigurasi Apache..."

if [ -f "$APACHE_CONF" ]; then
    a2enmod rewrite >> "$LOG_FILE" 2>&1 || true
    a2ensite "$(basename "$APACHE_CONF")" >> "$LOG_FILE" 2>&1 || true
    if apache2ctl configtest >> "$LOG_FILE" 2>&1; then
        success "Konfigurasi Apache valid"
    else
        warn "apache2ctl configtest gagal — periksa manual sebelum production"
    fi
else
    warn "$APACHE_CONF tidak ditemukan — lewati verifikasi Apache"
fi

# =========================================================
# [6/8] PYTHON VIRTUAL ENVIRONMENT
# =========================================================

log "[6/8] Memperbarui dependensi Python Virtual Environment..."

if [ ! -d "$VENV_DIR" ]; then
    log "Virtual environment tidak ditemukan, membuat baru..."
    python3 -m venv "$VENV_DIR"
fi

"$VENV_DIR/bin/pip" install --upgrade pip setuptools wheel >> "$LOG_FILE" 2>&1

if [ -f "$TARGET_DIR/python/requirements.txt" ]; then
    PIP_REQ="$(mktemp)"
    awk '/^# PYTHON PACKAGES/{p=1;next} p && /^[^#]/ && NF{print}' "$TARGET_DIR/python/requirements.txt" > "$PIP_REQ"
    if [ -s "$PIP_REQ" ]; then
        "$VENV_DIR/bin/pip" install -U -r "$PIP_REQ" >> "$LOG_FILE" 2>&1
    else
        "$VENV_DIR/bin/pip" install -U \
            mysql-connector-python paramiko cryptography requests \
            google-api-python-client google-auth google-auth-oauthlib google-auth-httplib2 \
            >> "$LOG_FILE" 2>&1
    fi
    rm -f "$PIP_REQ"
else
    "$VENV_DIR/bin/pip" install -U \
        mysql-connector-python paramiko cryptography requests \
        google-api-python-client google-auth google-auth-oauthlib google-auth-httplib2 \
        >> "$LOG_FILE" 2>&1
fi

success "Library Python diperbarui"

# =========================================================
# [7/8] PERMISSION & CRON
# =========================================================

log "[7/8] Menerapkan hak akses file & memperbarui cron scheduler..."

mkdir -p "$TARGET_DIR/cache"

chown -R "$WEB_USER:$WEB_USER" "$TARGET_DIR"
find "$TARGET_DIR" -type d -exec chmod 755 {} \;
find "$TARGET_DIR" -type f -exec chmod 644 {} \;
chmod 640 "$INI_FILE"
chmod +x "$VENV_DIR/bin/"* 2>/dev/null || true

GDRIVE_LOG="/var/log/netbackup_gdrive.log"
touch "$GDRIVE_LOG" 2>/dev/null || true
chown "$WEB_USER:$WEB_USER" "$GDRIVE_LOG" 2>/dev/null || true

CRON_COMMAND="0 * * * * flock -n $TARGET_DIR/.cron.lock $VENV_DIR/bin/python3 $TARGET_DIR/python/run_scheduled_backup.py > /dev/null 2>&1"
CONNECTIVITY_CRON="*/3 * * * * flock -n $TARGET_DIR/cache/.connectivity_refresh.lock php $TARGET_DIR/web/cli_refresh_connectivity.php > /dev/null 2>&1"
GDRIVE_CRON=""
if [ -f "$TARGET_DIR/config/client_secrets.json" ]; then
    GDRIVE_CRON="0 3 * * * flock -n $TARGET_DIR/.gdrive.lock $VENV_DIR/bin/python3 $TARGET_DIR/python/backup_db_to_drive.py >> $GDRIVE_LOG 2>&1"
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
    warn "Google Drive: config/client_secrets.json belum ada — cron DB→Drive dilewati"
fi

TG_CONFIGURED="$(mysql_app_scalar "SELECT COUNT(*) FROM sys_settings WHERE setting_key IN ('telegram_bot_token','telegram_chat_id') AND TRIM(setting_value) <> ''" || echo 0)"
if [ "${TG_CONFIGURED:-0}" -ge 2 ]; then
    success "Telegram: token & chat ID OK (notifikasi HTML, perbaikan v${APP_VERSION})"
else
    warn "Telegram: isi Bot Token & Chat ID di menu Pengaturan"
fi

if [ -n "$CURRENT_GDRIVE_FOLDER" ]; then
    success "Google Drive Folder ID: ${CURRENT_GDRIVE_FOLDER}"
elif [ -f "$TARGET_DIR/config/client_secrets.json" ]; then
    warn "Google Drive: Folder ID belum diisi — Pengaturan atau jalankan update lagi"
fi

success "Permission & cron scheduler diperbarui"

# =========================================================
# [8/8] RESTART APACHE & VERIFIKASI
# =========================================================

log "[8/8] Me-restart Apache & verifikasi aplikasi..."

if command -v apache2ctl &>/dev/null; then
    systemctl restart apache2
    if systemctl is-active --quiet apache2; then
        success "apache2 aktif"
    else
        warn "apache2 gagal restart — cek: systemctl status apache2"
    fi
fi

sleep 2

# Verifikasi web — curl ke 127.0.0.1 kadang HTTP 403 (vhost/Host header) meski situs normal via domain
HTTP_CODE="000"
CHECK_URL=""
resolve_access_urls
HEALTH_URLS=()
if [ -n "$SERVER_NAME" ]; then
    HEALTH_URLS+=("http://${SERVER_NAME}/login" "http://${SERVER_NAME}/")
fi
if [ -n "$IP_ADDR" ]; then
    HEALTH_URLS+=("http://${IP_ADDR}/login" "http://${IP_ADDR}/")
fi
HEALTH_URLS+=(
    "http://127.0.0.1/login"
    "http://127.0.0.1/"
    "http://127.0.0.1/web/login.php"
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
    HTTP_CODE="$code"
done

if [ -n "$CHECK_URL" ]; then
    success "Web merespons di $CHECK_URL (HTTP $HTTP_CODE)"
elif systemctl is-active --quiet apache2 2>/dev/null \
    && [ -f "$TARGET_DIR/web/login.php" ] \
    && [ -f "$TARGET_DIR/web/index.php" ]; then
    success "File aplikasi OK — Apache aktif"
    if [ "$HTTP_CODE" = "403" ]; then
        echo -e "${CYAN}  Catatan: curl internal HTTP 403 sering normal (bukan error aplikasi).${NC}"
        echo -e "${CYAN}  Verifikasi: buka ${HTTP_URL}login di browser.${NC}"
    else
        warn "Curl health-check HTTP $HTTP_CODE — cek manual di browser jika perlu"
    fi
else
    warn "Web tidak merespons normal (HTTP $HTTP_CODE)"
    echo -e "  Cek: tail -50 /var/log/apache2/*error.log"
fi

success "Update selesai"

resolve_access_urls

echo ""
echo -e "${GREEN}==================================================${NC}"
echo -e "${GREEN} ✅ NETBACKUP BERHASIL DIUPDATE${NC}"
echo -e "${GREEN}==================================================${NC}"
echo ""
echo -e "${CYAN}URL${NC}"
if [ -n "$SERVER_NAME" ]; then
    if [ -n "$HTTPS_URL" ]; then
        echo "  HTTPS: ${HTTPS_URL}"
    fi
    echo "  HTTP : ${HTTP_URL}"
else
    warn "Domain tidak terdeteksi — cek ServerName di ${APACHE_CONF}"
fi
echo ""
echo -e "${CYAN}Instalasi:${NC}"
if [ -n "$INSTALLED_VERSION" ]; then
    echo "  Versi       : v${INSTALLED_VERSION} → v${APP_VERSION}"
else
    echo "  Versi       : v${APP_VERSION}"
fi
if [ -n "$SERVER_NAME" ]; then
    echo "  Domain      : ${SERVER_NAME}"
fi
echo "  Folder      : ${TARGET_DIR}"
echo "  Apache site : ${APACHE_CONF}"
echo ""
echo -e "${CYAN}Database:${NC}"
echo "  Host     : ${DB_HOST:-localhost}:${DB_PORT}"
echo "  Database : ${DB_NAME}"
echo "  User     : ${DB_USER}"
echo ""
if [ -f "$DB_BACKUP_FILE" ]; then
    echo -e "${CYAN}Backup DB:${NC}"
    echo "  ${DB_BACKUP_FILE}"
    echo ""
fi
echo -e "${CYAN}Config:${NC}"
echo "  ${INI_FILE}"
echo ""
echo -e "${CYAN}Log update:${NC} ${LOG_FILE}"
echo ""
echo -e "${CYAN}Integrasi:${NC}"
if [ "${TG_CONFIGURED:-0}" -ge 2 ]; then
    echo "  Telegram    : terkonfigurasi (HTML)"
else
    echo "  Telegram    : isi di Pengaturan → Bot Token & Chat ID"
fi
if [ -f "$TARGET_DIR/config/client_secrets.json" ]; then
    echo "  Google Drive: client_secrets.json OK"
    if [ -n "$CURRENT_GDRIVE_FOLDER" ]; then
        echo "  Folder ID   : ${CURRENT_GDRIVE_FOLDER}"
    else
        echo "  Folder ID   : belum diisi (Pengaturan)"
    fi
    echo "  Cron DB     : 03:00 daily → ${GDRIVE_LOG}"
else
    echo "  Google Drive: salin config/client_secrets.json + OAuth (lihat panduan repo)"
fi
echo ""
echo -e "${YELLOW}Langkah berikutnya:${NC}"
echo "  1. Hard refresh browser: Ctrl+Shift+R"
echo "  2. Pengaturan → uji Telegram & isi Google Drive Folder ID jika perlu"
echo "  3. OAuth Drive (sekali): sudo -u www-data $VENV_DIR/bin/python3 $TARGET_DIR/python/backup_db_to_drive.py"
echo "  4. Verifikasi login & backup manual perangkat"
echo ""
echo -e "${GREEN}db_setting.ini, token Google Drive, dan data backup tidak diubah.${NC}"
echo ""
