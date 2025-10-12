import sys
import json
import paramiko
import time
import os
from db import get_db_connection, decrypt_secret, log_to_db

def run_backup(device_id):
    """
    Menjalankan proses backup untuk satu perangkat.
    Fungsi ini akan terhubung ke perangkat, menjalankan command,
    menyimpan output ke file, dan mencatat hasilnya ke database.
    """
    db_conn = get_db_connection()
    if not db_conn:
        return {"success": False, "error": "Koneksi database gagal"}
    
    cursor = db_conn.cursor(dictionary=True)
    device = None # Inisialisasi variabel device
    
    try:
        # 1. Ambil detail perangkat dari database
        query = """
            SELECT d.id, d.name, d.ip, d.port, d.protocol, d.username, d.password_enc, v.command AS vendor_command 
            FROM devices d 
            LEFT JOIN vendor v ON d.vendor_id = v.id 
            WHERE d.id = %s
        """
        cursor.execute(query, (device_id,))
        device = cursor.fetchone()

        if not device:
            return {"success": False, "error": "Perangkat tidak ditemukan di database"}

        # 2. Dekripsi password perangkat
        password = decrypt_secret(device['password_enc'])
        if "ERROR:" in password:
             return {"success": False, "error": password}

        # 3. Hubungkan ke perangkat menggunakan Paramiko (SSH)
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        
        client.connect(
            hostname=device['ip'],
            port=int(device['port']),
            username=device['username'],
            password=password,
            timeout=20, # Timeout koneksi 20 detik
            allow_agent=False,
            look_for_keys=False
        )
        
        # 4. Jalankan command backup
        stdin, stdout, stderr = client.exec_command(device['vendor_command'])
        config_output = stdout.read().decode('utf-8', 'ignore')
        error_output = stderr.read().decode('utf-8', 'ignore')
        client.close()
        
        if error_output and not config_output:
            raise Exception(error_output)
            
        # 5. Generate filename untuk referensi (tidak disimpan sebagai file)
        timestamp_str = time.strftime('%Y%m%d-%H%M%S')
        filename = f"{device['name'].replace(' ', '_')}_{device['ip']}_{timestamp_str}.txt"
            
        # 6. Catat keberhasilan ke database backups
        insert_query = "INSERT INTO backups (device_id, config, status, filename) VALUES (%s, %s, %s, %s)"
        cursor.execute(insert_query, (device_id, config_output, 'success', filename))
        db_conn.commit()
        
        # 7. LOG BARU: Catat keberhasilan ke tabel logs
        log_to_db(cursor, db_conn, 'backup_success', f"Backup terjadwal untuk '{device['name']}' berhasil.", device_id)


        return {
            "success": True, 
            "filename": filename, 
            "status": "success", 
            "message": "Backup berhasil dan file telah disimpan."
        }

    except Exception as e:
        error_message = str(e)
        device_name_for_log = device['name'] if device else f"ID={device_id}"
        
        # Catat kegagalan ke database backups
        try:
            insert_query = "INSERT INTO backups (device_id, config, status, filename) VALUES (%s, %s, %s, %s)"
            cursor.execute(insert_query, (device_id, error_message, 'failure', 'N/A'))
            db_conn.commit()

            # LOG BARU: Catat kegagalan ke tabel logs
            log_to_db(cursor, db_conn, 'backup_failed', f"Backup untuk '{device_name_for_log}' gagal: {error_message}", device_id)
        
        except Exception as db_err:
            print(f"Gagal mencatat error ke DB: {db_err}")
            
        return {"success": False, "error": error_message}

    finally:
        # Selalu pastikan koneksi database ditutup
        if db_conn and db_conn.is_connected():
            cursor.close()
            db_conn.close()

if __name__ == "__main__":
    # Bagian ini dieksekusi saat skrip dipanggil dari command line (PHP)
    if len(sys.argv) != 2:
        print(json.dumps({"success": False, "error": "Argumen Device ID tidak diberikan"}))
        sys.exit(1)
        
    try:
        device_id_arg = int(sys.argv[1])
        result = run_backup(device_id_arg)
        print(json.dumps(result))
    except ValueError:
        print(json.dumps({"success": False, "error": "Device ID yang diberikan tidak valid"}))
        sys.exit(1)
    except Exception as e:
        print(json.dumps({"success": False, "error": f"Terjadi error tak terduga: {e}"}))
        sys.exit(1)