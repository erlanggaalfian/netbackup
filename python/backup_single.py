# File: python/backup_single.py
# Deskripsi: Script Python untuk mengambil cadangan konfigurasi dari satu perangkat jaringan (Cisco, Mikrotik, dll) melalui SSH/Telnet, dengan dekripsi kredensial otomatis.

import sys
import json
import time
import os
import warnings
import re # Tambahan modul untuk Auto-Cleaner

# ==============================================================================
# PENTING: Aktifkan pembungkam warning DI SINI, 
# SEBELUM meng-import telnetlib dan paramiko.
# ==============================================================================
warnings.filterwarnings("ignore", category=DeprecationWarning)
warnings.filterwarnings("ignore", category=UserWarning)
warnings.filterwarnings("ignore")

import socket
import telnetlib
import paramiko

# ==============================================================================
# FALLBACK ALGORITMA SSH KLASIK / LAMA
# Mengaktifkan kembali ssh-rsa, ssh-dss, key exchange lama, dan cipher CBC
# agar kompatibel dengan perangkat jaringan lama (misal: MikroTik RouterOS lama).
# ==============================================================================
try:
    # 1. Tambah host key lama (ssh-rsa, ssh-dss) ke preferred keys
    keys = list(paramiko.transport.Transport._preferred_keys)
    for k in ['ssh-rsa', 'ssh-dss']:
        if k not in keys:
            keys.append(k)
    paramiko.transport.Transport._preferred_keys = tuple(keys)

    # 2. Tambah key exchange (KEX) lama
    kexs = list(paramiko.transport.Transport._preferred_kex)
    for k in ['diffie-hellman-group1-sha1', 'diffie-hellman-group14-sha1', 'diffie-hellman-group-exchange-sha1']:
        if k not in kexs:
            kexs.append(k)
    paramiko.transport.Transport._preferred_kex = tuple(kexs)

    # 3. Tambah ciphers lama (CBC)
    ciphers = list(paramiko.transport.Transport._preferred_ciphers)
    for c in ['aes128-cbc', 'aes192-cbc', 'aes256-cbc', '3des-cbc']:
        if c not in ciphers:
            ciphers.append(c)
    paramiko.transport.Transport._preferred_ciphers = tuple(ciphers)

    # 4. Patch RSAKey.HASHES untuk mengembalikan dukungan ssh-rsa (SHA-1) guna mencegah KeyError: 'ssh-rsa'
    from cryptography.hazmat.primitives import hashes
    if hasattr(paramiko, 'RSAKey') and hasattr(paramiko.RSAKey, 'HASHES'):
        paramiko.RSAKey.HASHES['ssh-rsa'] = hashes.SHA1
    if hasattr(paramiko.rsakey, 'RSAKey') and hasattr(paramiko.rsakey.RSAKey, 'HASHES'):
        paramiko.rsakey.RSAKey.HASHES['ssh-rsa'] = hashes.SHA1

    # 5. Patch Transport._key_info untuk mengembalikan dukungan ssh-rsa -> RSAKey guna mencegah KeyError: 'ssh-rsa' saat verifikasi host key
    import paramiko.transport
    if hasattr(paramiko.transport, 'Transport') and hasattr(paramiko.transport.Transport, '_key_info'):
        paramiko.transport.Transport._key_info['ssh-rsa'] = paramiko.rsakey.RSAKey
except Exception:
    pass

from db import get_db_connection, decrypt_secret, log_to_db
from telegram_util import escape_html, send_telegram_from_cursor


def _send_telegram_alert(cursor, message_html):
    ok, err = send_telegram_from_cursor(cursor, message_html)
    if not ok:
        raise RuntimeError(err or 'Gagal mengirim Telegram')
    return True

def clear_php_cache():
    """Menghapus file cache statistik dashboard PHP agar diperbarui secara real-time."""
    try:
        cache_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'cache', 'dashboard_stats.json'))
        if os.path.exists(cache_path):
            os.remove(cache_path)
    except Exception:
        pass

def get_failed_status_value(cursor):
    """Mendapatkan nilai status gagal yang didukung oleh ENUM kolom status di database secara dinamis."""
    try:
        cursor.execute("SHOW COLUMNS FROM backups LIKE 'status'")
        row = cursor.fetchone()
        if row:
            # Cari kolom bertipe Type (atau type)
            type_str = row.get('Type') or row.get('type')
            if type_str:
                import re
                enum_values = re.findall(r"'(.*?)'", type_str)
                if enum_values:
                    for val in ['failed', 'failure', 'gagal', 'error']:
                        if val in enum_values:
                            return val
                    for val in enum_values:
                        if val.lower() != 'success':
                            return val
    except Exception:
        pass
    return 'failed'

def _peek_service_banner(ip, port, timeout=5):
    """Baca beberapa byte awal dari port untuk deteksi layanan (SSH/Telnet/dll)."""
    try:
        sock = socket.create_connection((ip, int(port)), timeout=timeout)
        sock.settimeout(timeout)
        data = sock.recv(256)
        sock.close()
        return data.decode('utf-8', 'ignore').strip()
    except Exception:
        return ""

def _connect_ssh(device, password, max_attempts=2):
    """
    Koneksi SSH dengan banner_timeout lebih panjang.
    Error 'Error reading SSH protocol banner' sering terjadi pada perangkat lambat
    atau port/protokol yang salah (mis. Telnet di port 23 tapi dikonfigurasi SSH).
    """
    last_error = None
    connect_kwargs = {
        'hostname': device['ip'],
        'port': int(device['port']),
        'username': device['username'],
        'password': password,
        'timeout': 30,
        'banner_timeout': 60,
        'auth_timeout': 30,
        'allow_agent': False,
        'look_for_keys': False,
    }

    for attempt in range(1, max_attempts + 1):
        client = paramiko.SSHClient()
        client.load_system_host_keys()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        try:
            try:
                client.connect(**connect_kwargs)
                return client
            except Exception as e:
                err_msg = str(e).lower()
                if 'incompatible ssh peer' in err_msg or 'no acceptable host key' in err_msg:
                    try:
                        fallback_kwargs = connect_kwargs.copy()
                        fallback_kwargs['disabled_algorithms'] = {
                            'pubkeys': ['rsa-sha2-256', 'rsa-sha2-512']
                        }
                        client.close()
                        client = paramiko.SSHClient()
                        client.load_system_host_keys()
                        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
                        client.connect(**fallback_kwargs)
                        return client
                    except TypeError:
                        pass
                    except Exception as fallback_e:
                        e = fallback_e
                raise e
        except Exception as e:
            last_error = e
            try:
                client.close()
            except Exception:
                pass
            if attempt < max_attempts:
                time.sleep(3)

    msg = str(last_error)
    if 'banner' in msg.lower():
        peek = _peek_service_banner(device['ip'], device['port'])
        hint = (
            f"Periksa protokol (SSH/Telnet), port ({device['port']}), "
            "ACL/firewall, dan beban CPU perangkat."
        )
        if peek and not peek.upper().startswith('SSH-'):
            hint = (
                f"Port merespons non-SSH ({peek[:60]}). "
                "Kemungkinan perlu protokol Telnet atau port lain. "
                + hint
            )
        raise Exception(f"Gagal baca banner SSH: {msg}. {hint}")

    raise Exception(f"Koneksi SSH gagal: {msg}")

def clean_terminal_artifacts(text):
    """
    Membersihkan sisa-sisa teks paginasi (--More--), karakter backspace (\x08),
    dan kode warna ANSI yang membuat hasil backup terlihat terpotong/berantakan.
    """
    # 1. Hapus tulisan --More-- (case insensitive)
    text = re.sub(r'(?i)\s*--more--\s*', '', text)
    # 2. Hapus karakter backspace (biasanya dipakai OLT untuk menghapus tulisan --More--)
    text = re.sub(r'\x08', '', text)
    # 3. Hapus kode warna terminal (ANSI escape sequences)
    text = re.sub(r'\x1b\[[0-9;]*[a-zA-Z]', '', text)
    return text

def run_backup(device_id):
    db_conn = get_db_connection()
    if not db_conn:
        return {"success": False, "error": "Koneksi database gagal"}
    
    cursor = db_conn.cursor(dictionary=True)
    device = None 
    
    try:
        query = """
            SELECT d.id, d.name, d.ip, d.port, d.protocol, d.username, d.password_enc, v.command AS vendor_command, v.is_interactive 
            FROM devices d 
            LEFT JOIN vendor v ON d.vendor_id = v.id 
            WHERE d.id = %s
        """
        cursor.execute(query, (device_id,))
        device = cursor.fetchone()

        if not device:
            return {"success": False, "error": "Perangkat tidak ditemukan di database"}

        password = decrypt_secret(device['password_enc'])
        if "ERROR:" in password:
             return {"success": False, "error": password}

        vendor_cmd = device.get('vendor_command') or ''
        protocol = str(device.get('protocol', 'ssh')).lower().strip()
        is_interactive = int(device.get('is_interactive') or 0)
        if not vendor_cmd.strip():
            raise Exception("Perintah vendor belum dikonfigurasi")
        
        config_output = ""
        error_output = ""
        
        if protocol == 'telnet':
            # ------------------------------------------
            # JALUR 1: MODE TELNET
            # ------------------------------------------
            try:
                tn = telnetlib.Telnet(device['ip'], int(device['port']), timeout=20)
                tn.expect([b"([Uu]sername|[Ll]ogin):"], timeout=10)
                tn.write(device['username'].encode('utf-8', 'ignore') + b"\n")
                tn.expect([b"[Pp]assword:"], timeout=10)
                tn.write(password.encode('utf-8', 'ignore') + b"\n")
                
                time.sleep(2) 
                
                commands = vendor_cmd.replace('\\n', '\n').split('\n')
                for cmd in commands:
                    tn.write(cmd.strip().encode('utf-8', 'ignore') + b"\n")
                    time.sleep(1.5)
                
                idle_seconds = 0
                max_idle_seconds = 45 
                
                while idle_seconds < max_idle_seconds:
                    out = tn.read_very_eager().decode('utf-8', 'ignore')
                    if out:
                        config_output += out
                        idle_seconds = 0 
                        
                        # FITUR BARU: AUTO-PAGINATOR (Tekan Spasi Otomatis)
                        last_chunk = config_output[-150:].lower()
                        if "more" in last_chunk or "press any key" in last_chunk:
                            tn.write(b" ") # Kirim spasi ke OLT
                            time.sleep(0.5)
                        else:
                            time.sleep(0.2)
                    else:
                        time.sleep(1)
                        idle_seconds += 1
                        
                        # Keluar cepat jika sudah ada prompt (menandakan selesai)
                        last_chars = config_output.strip()[-5:]
                        if idle_seconds >= 3 and ('#' in last_chars or '>' in last_chars):
                            break
                
                tn.close()
                if not config_output.strip():
                    raise Exception("Koneksi Telnet berhasil, tetapi tidak ada output konfigurasi yang diterima.")
                    
            except Exception as e:
                raise Exception(f"Gagal saat sesi Telnet: {str(e)}")

        elif protocol == 'ssh':
            client = _connect_ssh(device, password)

            if is_interactive == 1:
                # ------------------------------------------
                # JALUR 2: SSH MODE INTERAKTIF
                # ------------------------------------------
                channel = client.invoke_shell()
                time.sleep(1) 
                
                commands = vendor_cmd.replace('\\n', '\n').split('\n')
                for cmd in commands:
                    channel.send(cmd.strip() + "\n")
                    time.sleep(1.5)
                
                idle_seconds = 0
                max_idle_seconds = 45 
                
                while idle_seconds < max_idle_seconds:
                    if channel.recv_ready():
                        out = channel.recv(65535).decode('utf-8', 'ignore')
                        config_output += out
                        idle_seconds = 0 
                        
                        # FITUR BARU: AUTO-PAGINATOR
                        last_chunk = config_output[-150:].lower()
                        if "more" in last_chunk or "press any key" in last_chunk:
                            channel.send(" ") # Kirim spasi
                            time.sleep(0.5)
                    else:
                        time.sleep(1)
                        idle_seconds += 1
                        
                        last_chars = config_output.strip()[-5:]
                        if idle_seconds >= 3 and ('#' in last_chars or '>' in last_chars):
                            break
                
                client.close()
                if not config_output.strip():
                    raise Exception("Tidak ada output yang diterima pada Mode Interaktif.")
                    
            else:
                # ------------------------------------------
                # JALUR 3: SSH MODE NON-INTERAKTIF
                # ------------------------------------------
                stdin, stdout, stderr = client.exec_command(vendor_cmd)
                config_output = stdout.read().decode('utf-8', 'ignore')
                error_output = stderr.read().decode('utf-8', 'ignore')
                client.close()
                
                if error_output and not config_output:
                    raise Exception(error_output)

        else:
            raise Exception(f"Protokol '{protocol}' tidak didukung.")

        # ==========================================
        # 4. PENYIMPANAN & NOTIFIKASI
        # ==========================================
        
        # Eksekusi Auto-Cleaner sebelum disimpan ke database
        config_output = clean_terminal_artifacts(config_output)
        
        timestamp_str = time.strftime('%Y%m%d-%H%M%S')
        filename = f"{device['name'].replace(' ', '_')}_{device['ip']}_{timestamp_str}.txt"
            
        insert_query = "INSERT INTO backups (device_id, config, status, filename) VALUES (%s, %s, %s, %s)"
        cursor.execute(insert_query, (device_id, config_output, 'success', filename))
        db_conn.commit()
        clear_php_cache()
        
        log_to_db(cursor, db_conn, 'backup_success', f"Backup untuk '{device['name']}' berhasil via {protocol.upper()}.", device_id)

        try:
            msg = (
                f"✅ <b>NetBackup Alert: SUCCESS</b>\n\n"
                f"▪️ <b>Perangkat:</b> {escape_html(device['name'])}\n"
                f"▪️ <b>IP Address:</b> {escape_html(device['ip'])}\n"
                f"▪️ <b>Protokol:</b> {escape_html(protocol.upper())}\n"
                f"▪️ <b>Status:</b> Proses backup berhasil."
            )
            _send_telegram_alert(cursor, msg)
        except Exception as telegram_error:
            log_to_db(cursor, db_conn, 'telegram_error', f'Notif Telegram sukses gagal dikirim: {telegram_error}', device_id)

        return {
            "success": True, 
            "filename": filename, 
            "status": "success", 
            "message": f"Backup via {protocol.upper()} berhasil."
        }

    except Exception as e:
        error_message = str(e)
        device_name_for_log = device['name'] if device else f"ID={device_id}"
        protocol_used = protocol.upper() if 'protocol' in locals() else "UNKNOWN"
        
        db_insert_error = ""
        try:
            failed_status = get_failed_status_value(cursor)
            insert_query = "INSERT INTO backups (device_id, config, status, filename) VALUES (%s, %s, %s, %s)"
            cursor.execute(insert_query, (device_id, error_message, failed_status, 'N/A'))
            db_conn.commit()
            clear_php_cache()
            log_to_db(cursor, db_conn, 'backup_failed', f"Backup '{device_name_for_log}' ({protocol_used}) gagal: {error_message}", device_id)
        except Exception as db_err:
            db_insert_error = str(db_err)
            print(f"Gagal mencatat hasil backup ke database: {db_err}") 
            
        try:
            msg = (
                f"❌ <b>NetBackup Alert: FAILED</b>\n\n"
                f"▪️ <b>Perangkat:</b> {escape_html(device_name_for_log)}\n"
                f"▪️ <b>Protokol:</b> {escape_html(protocol_used)}\n"
                f"▪️ <b>Kendala:</b> {escape_html(error_message)}"
            )
            if db_insert_error:
                msg += f"\n▪️ <b>Error DB:</b> {escape_html(db_insert_error)}"
            _send_telegram_alert(cursor, msg)
        except Exception as telegram_error:
            try:
                log_to_db(cursor, db_conn, 'telegram_error', f'Notif Telegram gagal dikirim: {telegram_error}', device_id)
            except Exception:
                pass
 
        full_error = error_message
        if db_insert_error:
            full_error += f" (Gagal simpan ke DB: {db_insert_error})"
        return {"success": False, "error": full_error}

    finally:
        if db_conn and db_conn.is_connected():
            cursor.close()
            db_conn.close()

if __name__ == "__main__":
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
