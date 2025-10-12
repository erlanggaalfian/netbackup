import mysql.connector
import os
import configparser
import base64
import hashlib
# Import library kriptografi yang dibutuhkan
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.exceptions import InvalidTag

def get_db_connection():
    """Mendapatkan koneksi ke database dari file db_setting.ini."""
    try:
        config_path = os.path.join(os.path.dirname(__file__), '..', 'config', 'db_setting.ini')
        parser = configparser.ConfigParser()
        parser.read(config_path)
        db_config = parser['database']
        
        return mysql.connector.connect(
            host=db_config.get('DB_HOST', '127.0.0.1'),
            port=db_config.getint('DB_PORT', 3306),
            user=db_config.get('DB_USER'),
            password=db_config.get('DB_PASS'),
            database=db_config.get('DB_NAME')
        )
    except Exception as e:
        # Mengembalikan None jika koneksi gagal
        return None

def get_encryption_key_config():
    """Mendapatkan kunci enkripsi mentah dari file .ini."""
    try:
        config_path = os.path.join(os.path.dirname(__file__), '..', 'config', 'db_setting.ini')
        parser = configparser.ConfigParser()
        parser.read(config_path)
        return parser.get('database', 'ENCRYPTION_KEY')
    except Exception:
        return None
        
def log_to_db(cursor, db_conn, log_type, details, target_id=None, actor_id=None):
    """Fungsi helper terpusat untuk mencatat log ke database dari Python."""
    try:
        query = "INSERT INTO logs (type, details, timestamp, actor, target_id) VALUES (%s, %s, NOW(), %s, %s)"
        cursor.execute(query, (log_type, details, actor_id, target_id))
        db_conn.commit()
    except Exception as e:
        # Gagal mencatat log seharusnya tidak menghentikan proses utama
        print(f"Gagal mencatat log ke DB: {e}")


def decrypt_secret(encoded_secret):
    """
    Mendekripsi password perangkat yang dienkripsi oleh PHP menggunakan AES-256-GCM.
    Ini adalah implementasi AKTIF, bukan lagi placeholder.
    """
    enc_key = get_encryption_key_config()
    if not enc_key:
        return "ERROR: Encryption key not found"

    try:
        # 1. Decode base64 dari string yang disimpan di database
        raw_data = base64.b64decode(encoded_secret)
        
        # 2. Hasilkan kunci 32-byte dari ENCRYPTION_KEY, sama seperti di PHP
        key = hashlib.sha256(enc_key.encode('utf-8')).digest()
        
        # 3. Ekstrak IV, tag, dan ciphertext sesuai format OpenSSL
        # IV (Initialization Vector) adalah 12 byte pertama
        iv = raw_data[:12]
        # Tag otentikasi adalah 16 byte berikutnya
        tag = raw_data[12:28]
        # Sisa datanya adalah ciphertext (teks terenkripsi)
        ciphertext = raw_data[28:]
        
        # 4. Lakukan dekripsi
        aesgcm = AESGCM(key)
        decrypted_bytes = aesgcm.decrypt(iv, ciphertext + tag, None)
        
        # 5. Kembalikan password sebagai string
        return decrypted_bytes.decode('utf-8')

    except (InvalidTag, ValueError, TypeError) as e:
        # Error ini terjadi jika kunci salah atau data terkorupsi
        return f"ERROR: Decryption failed. Check your ENCRYPTION_KEY. Details: {e}"
    except Exception as e:
        return f"ERROR: An unexpected error occurred during decryption: {e}"
