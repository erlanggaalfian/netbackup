#!/bin/bash
#
# Skrip Instalasi Dependensi NetBackup untuk Debian 12
#
# Skrip ini akan menginstal semua perangkat lunak yang dibutuhkan
# untuk menjalankan aplikasi, namun TIDAK akan mengonfigurasi database atau Apache.
# Jalankan skrip ini dengan hak akses root atau sudo.
#

set -e # Hentikan skrip jika terjadi error

# --- Variabel Konfigurasi ---
APP_DIR="/var/www/netbackup"
WEB_USER="www-data"
PYTHON_VENV_DIR="$APP_DIR/venv"
LOG_FILE="/var/log/netbackup_install.log"

# --- Fungsi Helper ---
log() {
    echo "$(date +'%Y-%m-%d %H:%M:%S') - $1" | tee -a $LOG_FILE
}

# --- 1. Pengecekan Awal ---
log "Memulai instalasi dependensi NetBackup..."
if [ "$(id -u)" -ne 0 ]; then
   log "Error: Skrip ini harus dijalankan sebagai root atau dengan sudo."
   exit 1
fi

if [ -z "$BASH_VERSION" ]; then
    log "Error: Skrip ini harus dijalankan menggunakan bash, bukan sh."
    log "Silakan jalankan dengan: bash install_server.sh"
    exit 1
fi

# --- 2. Instalasi Dependensi Sistem ---
log "Memperbarui daftar paket sistem..."
apt-get update -y >> $LOG_FILE 2>&1

log "Membersihkan lock apt yang mungkin ada untuk mencegah stall..."
pkill -f "apt|dpkg" || true
rm -f /var/lib/apt/lists/lock /var/cache/apt/archives/lock /var/lib/dpkg/lock*
dpkg --configure -a >> $LOG_FILE 2>&1

log "Menginstal paket yang dibutuhkan (termasuk MariaDB & Apache, tanpa konfigurasi)..."
log "Output instalasi akan ditampilkan di bawah ini untuk debugging:"
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 php libapache2-mod-php php-mysql php-curl php-mbstring php-xml \
    mariadb-server python3 python3-venv python3-pip git util-linux

log "Instalasi dependensi sistem selesai."

# --- 3. Setup Direktori Aplikasi ---
if [ "$(pwd)" != "$APP_DIR" ]; then
    log "Menyiapkan direktori aplikasi di $APP_DIR..."
    if [ -d "$APP_DIR" ]; then
        log "Direktori $APP_DIR sudah ada, menghapusnya untuk instalasi baru."
        rm -rf "$APP_DIR"
    fi
    log "Menyalin file aplikasi ke $APP_DIR..."
    mkdir -p "$APP_DIR"
    find . -maxdepth 1 -mindepth 1 ! -name 'install_server.sh' -exec mv -t "$APP_DIR/" {} +
else
    log "Skrip dijalankan dari direktori target ($APP_DIR), melewati pemindahan file."
fi

log "Direktori aplikasi telah disiapkan."

# --- 4. Konfigurasi Backend Python ---
log "Membuat lingkungan virtual Python..."
python3 -m venv $PYTHON_VENV_DIR
log "Menginstal modul Python dari requirements.txt..."
$PYTHON_VENV_DIR/bin/pip install -r $APP_DIR/python/requirements.txt >> $LOG_FILE 2>&1
log "Modul Python berhasil diinstal."

# --- 5. Pengaturan Hak Akses ---
log "Mengatur hak akses file dan direktori..."
chown -R $WEB_USER:$WEB_USER $APP_DIR
find $APP_DIR -type d -exec chmod 755 {} \;
find $APP_DIR -type f -exec chmod 644 {} \;
chmod +x $PYTHON_VENV_DIR/bin/*
log "Hak akses selesai diatur."

# --- 6. Penjadwalan Cron Job ---
CRON_COMMAND="0 * * * * flock -n $APP_DIR/.cron.lock $PYTHON_VENV_DIR/bin/python3 $APP_DIR/python/run_scheduled_backup.py"
(crontab -u $WEB_USER -l 2>/dev/null | grep -v "run_scheduled_backup.py"; echo "$CRON_COMMAND") | crontab -u $WEB_USER -
log "Cron job untuk backup otomatis telah ditambahkan untuk user '$WEB_USER'."

# --- Selesai ---
log "======================================================"
log "✅ INSTALASI DEPENDENSI SELESAI!"
log "Langkah selanjutnya yang harus Anda lakukan secara manual:"
log "1. Konfigurasi Apache (buat Virtual Host, aktifkan site & mod_rewrite)."
log "2. Konfigurasi database MariaDB (buat database, user, dan berikan hak akses)."
log "3. Buat file 'config/db_setting.ini' dan sesuaikan isinya."
log "4. Impor skema database dari file 'sql/schema.sql'."
log "5. Akses aplikasi melalui browser untuk membuat akun admin pertama."
log "======================================================"

