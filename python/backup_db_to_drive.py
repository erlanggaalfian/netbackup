# File: python/backup_db_to_drive.py
# Deskripsi: Script Python untuk melakukan dump database MariaDB/MySQL secara lokal dan mengunggahnya ke Google Drive serta mengirim notifikasi via Telegram.

import os
import time
import subprocess
import sys
from datetime import datetime, timedelta
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

# Memanggil fungsi database untuk mengambil token Telegram
sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from db import get_db_connection, get_db_config
from telegram_util import escape_html, get_telegram_credentials, send_telegram_html

# ==================== KONFIGURASI ====================
DB_SETTINGS_PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'config', 'db_setting.ini'))
CLIENT_SECRETS_FILE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'config', 'client_secrets.json'))
TOKEN_FILE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'config', 'token.json'))
INI_PATH = DB_SETTINGS_PATH
LOCAL_BACKUP_DIR = "/tmp" 

# Google Drive Folder ID (fallback jika belum di sys_settings)
GOOGLE_DRIVE_FOLDER_ID_DEFAULT = ""

# Port Autentikasi Lokal
PORT_AUTH = 8080

# Batas umur simpan berkas (6 bulan = 180 hari)
RETENTION_DAYS = 180
# =====================================================

def get_db_credentials():
    """Membaca kredensial database dari file db_setting.ini."""
    cfg = get_db_config()
    return {
        'host': cfg['host'],
        'user': cfg['user'],
        'password': cfg['password'],
        'database': cfg['database'],
        'port': cfg.get('port', 3306),
    }

def get_google_drive_folder_id():
    """Folder ID dari sys_settings, fallback ke GOOGLE_DRIVE_FOLDER_ID_DEFAULT."""
    folder_id = (GOOGLE_DRIVE_FOLDER_ID_DEFAULT or '').strip()
    db_conn = None
    cursor = None
    try:
        db_conn = get_db_connection()
        if not db_conn:
            return folder_id
        cursor = db_conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT setting_value FROM sys_settings WHERE setting_key = 'google_drive_folder_id' LIMIT 1"
        )
        row = cursor.fetchone()
        if row and (row.get('setting_value') or '').strip():
            folder_id = row['setting_value'].strip()
    except Exception as e:
        print(f"[!] Gagal membaca google_drive_folder_id: {e}")
    finally:
        if db_conn is not None and db_conn.is_connected():
            if cursor is not None:
                cursor.close()
            db_conn.close()
    return folder_id

def get_gdrive_service():
    """Otentikasi OAuth 2.0 dan inisialisasi API Drive"""
    creds = None
    # [DIPERBAIKI] URL tanpa format markdown otomatis
    scopes = ["https://www.googleapis.com/auth/drive"]

    if os.path.exists(TOKEN_FILE):
        creds = Credentials.from_authorized_user_file(TOKEN_FILE, scopes)
        
    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            try:
                creds.refresh(Request())
            except Exception:
                creds = None
                
        if not creds:
            if not os.path.exists(CLIENT_SECRETS_FILE):
                raise FileNotFoundError(f"[!] Kredensial tidak ditemukan di {CLIENT_SECRETS_FILE}")
            
            flow = InstalledAppFlow.from_client_secrets_file(CLIENT_SECRETS_FILE, scopes)
            print("\n" + "="*70)
            print("⚠️ INTEGRASI GOOGLE DRIVE PERTAMA KALI")
            print("="*70)
            print("Jalankan SSH tunnel dari komputer lokal Anda:")
            print(f"  ssh -L {PORT_AUTH}:localhost:{PORT_AUTH} root@IP_SERVER")
            print("="*70 + "\n")
            
            creds = flow.run_local_server(port=PORT_AUTH, open_browser=False, access_type='offline', prompt='consent')

        with open(TOKEN_FILE, 'w') as token:
            token.write(creds.to_json())
            print("[+] Token autentikasi baru berhasil disimpan.")
            
    return build('drive', 'v3', credentials=creds)

def create_db_dump():
    """Dump database lokal ke format .sql.gz"""
    creds = get_db_credentials()
    if not creds.get('user') or not creds.get('database'):
        print("[!] DB_USER atau DB_NAME kosong di db_setting.ini")
        return None

    timestamp = time.strftime('%Y%m%d-%H%M%S')
    filename = f"db_backup_{creds['database']}_{timestamp}.sql.gz"
    filepath = os.path.join(LOCAL_BACKUP_DIR, filename)
    dump_cmd = [
        "mysqldump",
        f"-u{creds['user']}",
        f"-h{creds['host']}",
        f"-P{creds.get('port', 3306)}",
        "--single-transaction",
        creds['database'],
    ]
    env = os.environ.copy()
    if creds.get('password'):
        env['MYSQL_PWD'] = creds['password']
    try:
        with open(filepath, 'wb') as output_file:
            dump = subprocess.run(
                dump_cmd,
                check=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=env,
            )
            subprocess.run(["gzip"], check=True, input=dump.stdout, stdout=output_file)
        print(f"[*] Berhasil membuat dump database lokal: {filepath}")
        return filepath
    except subprocess.CalledProcessError as e:
        err = (e.stderr or b'').decode('utf-8', errors='replace').strip()
        print(f"[!] Gagal membuat dump database: {err or e}")
        return None

def upload_and_purge_drive(file_path):
    """Unggah file ke Drive dan hapus backup usang (Retention Policy)"""
    if not file_path or not os.path.exists(file_path):
        return False

    folder_id = get_google_drive_folder_id()
    if not folder_id:
        print("[!] Google Drive Folder ID kosong. Isi di Pengaturan atau set GOOGLE_DRIVE_FOLDER_ID_DEFAULT.")
        return False

    try:
        service = get_gdrive_service()

        file_metadata = {'name': os.path.basename(file_path), 'parents': [folder_id]}
        media = MediaFileUpload(file_path, mimetype='application/x-gzip', resumable=True)
        
        print("[*] Memulai proses unggah ke Google Drive...")
        file_drive = service.files().create(body=file_metadata, media_body=media, fields='id').execute()
        print(f"[+] Berhasil diunggah! File ID di Google Drive: {file_drive.get('id')}")

        print(f"[*] Memeriksa berkas lama (>{RETENTION_DAYS} hari)...")
        cutoff_date = datetime.now() - timedelta(days=RETENTION_DAYS)
        cutoff_str = cutoff_date.isoformat() + 'Z'
        query = f"'{folder_id}' in parents and createdTime < '{cutoff_str}' and trashed = false"
        
        results = service.files().list(q=query, fields="files(id, name, createdTime)", pageSize=100).execute()
        files_to_delete = results.get('files', [])
        
        if files_to_delete:
            for f in files_to_delete:
                print(f"[-] Menghapus berkas usang: {f['name']}")
                service.files().delete(fileId=f['id']).execute()
            print("[+] Proses pembersihan berkas lama selesai.")
        else:
            print("[*] Tidak ada berkas usang yang dihapus.")
        return True
    except Exception as e:
        print(f"[!] Terjadi kendala operasional Google Drive: {e}")
        return False
    finally:
        if os.path.exists(file_path):
            os.remove(file_path)

def send_telegram_notif(status, filename, keterangan):
    """Kirim notifikasi Telegram (HTML)."""
    db_conn = None
    cursor = None
    try:
        db_conn = get_db_connection()
        if not db_conn:
            print("[!] Gagal menyambung ke database untuk token Telegram.")
            return

        cursor = db_conn.cursor(dictionary=True)
        token, chat_id = get_telegram_credentials(cursor)

        if not token or not chat_id:
            print("[-] Token Telegram/Chat ID kosong di database.")
            return

        now = datetime.now()
        tanggal = now.strftime('%d-%m-%Y')
        jam = now.strftime('%H:%M:%S')
        icon = "\u2705" if status == "success" else "\u274C"
        header_status = "SUCCESS" if status == "success" else "FAILED"

        msg = (
            f"{icon} <b>NetBackup Alert: {header_status}</b>\n\n"
            f"\u25AA\uFE0F <b>Tipe Backup:</b> Database Sistem\n"
            f"\u25AA\uFE0F <b>Nama File:</b> {escape_html(filename)}\n"
            f"\u25AA\uFE0F <b>Tanggal:</b> {escape_html(tanggal)}\n"
            f"\u25AA\uFE0F <b>Jam:</b> {escape_html(jam)} WIB\n"
            f"\u25AA\uFE0F <b>Status:</b> {escape_html(keterangan)}"
        )

        ok, err = send_telegram_html(token, chat_id, msg)
        if ok:
            print("[*] Notifikasi Telegram berhasil dikirim.")
        else:
            print(f"[!] Gagal mengirim notif Telegram: {err}")

    except Exception as e:
        print(f"[!] Gagal mengirim notif Telegram: {e}")
    finally:
        if db_conn is not None and db_conn.is_connected():
            if cursor is not None:
                cursor.close()
            db_conn.close()

if __name__ == "__main__":
    print("[*] Memulai proses backup database ke Google Drive...")
    backup_file_path = create_db_dump()
    
    if backup_file_path:
        filename = os.path.basename(backup_file_path)
        success = upload_and_purge_drive(backup_file_path)
        
        if success:
            send_telegram_notif("success", filename, "Database berhasil diunggah ke Google Drive.")
        else:
            send_telegram_notif("failed", filename, "Proses gagal saat mengunggah database ke Google Drive.")
    else:
         send_telegram_notif("failed", "-", "Gagal membuat file dump database secara lokal.")
