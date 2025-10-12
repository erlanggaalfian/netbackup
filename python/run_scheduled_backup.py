import sys
import os
from datetime import datetime

# Tambahkan path proyek agar bisa mengimpor modul lain
project_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
sys.path.insert(0, project_root)

from python.db import get_db_connection, log_to_db
from python.backup_single import run_backup

def main():
    """
    Fungsi utama untuk menjalankan backup terjadwal.
    """
    now = datetime.now()
    
    db_conn = get_db_connection()
    if not db_conn:
        print(f"[{now}] Gagal terhubung ke database.")
        return

    cursor = db_conn.cursor(dictionary=True)
    
    # Catat bahwa skrip ini berhasil dimulai.
    log_to_db(cursor, db_conn, 'schedule_start', 'Proses backup terjadwal dimulai oleh pemicu.')
    
    try:
        current_hour = now.hour
        current_weekday = now.weekday()  # Senin=0, Minggu=6
        current_day_of_month = now.day

        # Query untuk mengambil perangkat yang dijadwalkan pada jam ini
        query = """
            SELECT d.id, d.name, s.schedule, s.run_hour, s.run_day
            FROM devices d
            JOIN settings s ON d.id = s.device_id
            WHERE s.active = 1 AND s.run_hour = %s
        """
        cursor.execute(query, (current_hour,))
        devices = cursor.fetchall()
        
        if not devices:
            log_to_db(cursor, db_conn, 'schedule_info', f"Tidak ada perangkat yang dijadwalkan untuk jam {current_hour}:00.")
            return

        devices_to_backup = []
        for dev in devices:
            if dev['schedule'] == 'daily':
                devices_to_backup.append(dev)
            elif dev['schedule'] == 'weekly' and current_weekday == 6: # Minggu
                devices_to_backup.append(dev)
            elif dev['schedule'] == 'monthly' and current_day_of_month == dev.get('run_day', 1):
                devices_to_backup.append(dev)
        
        if not devices_to_backup:
            log_to_db(cursor, db_conn, 'schedule_info', f"Jadwal ditemukan untuk jam {current_hour}:00, namun tidak ada yang cocok dengan frekuensi hari ini.")
            return

        log_to_db(cursor, db_conn, 'schedule_running', f"Memulai proses backup untuk {len(devices_to_backup)} perangkat.")

        for device in devices_to_backup:
            device_id = device['id']
            # Menjalankan backup untuk satu perangkat.
            # Hasilnya (berhasil/gagal) akan dicatat oleh run_backup()
            run_backup(device_id)

    except Exception as e:
        log_to_db(cursor, db_conn, 'schedule_error', f"Terjadi error kritis: {e}")
    finally:
        log_to_db(cursor, db_conn, 'schedule_end', 'Proses backup terjadwal selesai.')
        if db_conn.is_connected():
            cursor.close()
            db_conn.close()
        print(f"[{now}] Proses backup terjadwal selesai.")

if __name__ == "__main__":
    main()
