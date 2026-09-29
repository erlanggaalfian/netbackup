# 🌐 Panduan Lengkap: Otomatisasi Backup Database ke Google Drive (OAuth 2.0)

Melakukan pencadangan (backup) database secara berkala adalah langkah krusial untuk menjaga keamanan data sistem. Namun, menyimpannya hanya di lokal server memiliki risiko tinggi jika terjadi kegagalan perangkat keras (hardware failure).

Panduan ini menuntun Anda mengonfigurasi skrip Python otomatis untuk melakukan dump database, mengompresnya, mengunggahnya langsung ke Google Drive pribadi Anda (memanfaatkan kuota gratis 15 GB), serta menghapus berkas usang yang berumur lebih dari 6 bulan secara otomatis.

> **Catatan path:** Ganti `/var/www/netbackup` dengan folder instalasi Anda (mis. `/var/www/backup.perusahaan.co.id`) jika berbeda. Path bisa dicek di `config/db_setting.ini` section `[install]` → `APP_DIR`.

---

## 🏗️ Mengapa Memilih OAuth 2.0 dibanding Service Account?

Mungkin Anda bertanya, mengapa tidak memakai Service Account (Akun Layanan) yang kelihatannya lebih praktis tanpa perlu login browser?

**Kebijakan Kuota Google Terbaru:** Google menetapkan bahwa Service Account bawaan memiliki kuota penyimpanan default sebesar **0 GB**. Jika dipaksa, Anda akan menemui error `storageQuotaExceeded` (HttpError 403).

**Solusi OAuth 2.0:** Dengan OAuth 2.0 Desktop App, skrip Python mengunggah berkas atas nama akun Gmail asli Anda sendiri. Dengan begitu, Anda dapat menikmati kapasitas penyimpanan gratis **15 GB** bawaan akun Gmail tanpa biaya tambahan.

---

## 🛠️ Langkah 1: Registrasi Proyek di Google Cloud Console

Kunjungi [Google Cloud Console](https://console.cloud.google.com/) dan login dengan akun Gmail Anda.

1. Klik **Dropdown Project** → **New Project**
2. Nama: `NetBackup-Cloud-Storage` → **Create**
3. Pastikan project aktif

---

## 🔑 Langkah 2: Mengaktifkan Google Drive API

1. Cari: **Google Drive API**
2. Klik **Enable**

---

## 🛡️ Langkah 3: OAuth Consent Screen

1. Menu: **APIs & Services** → **OAuth consent screen**
2. User Type: **External**
3. App name: `NetBackup Uploader`
4. Tambahkan email Anda di **Test Users**

---

## 🔑 Langkah 4: Credential OAuth

1. **Create Credentials** → **OAuth Client ID**
2. Type: **Desktop App**
3. Download `client_secrets.json`
4. Simpan ke server:

```text
/var/www/netbackup/config/client_secrets.json
```

Atur permission:

```bash
sudo chown www-data:www-data /var/www/netbackup/config/client_secrets.json
sudo chmod 640 /var/www/netbackup/config/client_secrets.json
```

---

## 📂 Langkah 5: Google Drive Folder

Buat folder di Google Drive:

```text
NetBackup_Database_Archives
```

Ambil **Folder ID** dari URL browser:

```text
https://drive.google.com/drive/folders/FOLDER_ID_ANDA
```

Edit file `python/backup_db_to_drive.py` di server, ubah baris:

```python
GOOGLE_DRIVE_FOLDER_ID = "FOLDER_ID_ANDA"
```

Jika path instalasi bukan default, sesuaikan juga:

```python
INI_PATH = "/var/www/netbackup/config/db_setting.ini"
CLIENT_SECRETS_FILE = "/var/www/netbackup/config/client_secrets.json"
TOKEN_FILE = "/var/www/netbackup/config/token.json"
```

---

## 💻 Langkah 6: Dependensi Python

Jika Anda sudah menjalankan `install_server.sh`, library Google API sudah terpasang di virtual environment:

```bash
/var/www/netbackup/venv/bin/pip list | grep google
```

Jika belum, install manual:

```bash
sudo /var/www/netbackup/venv/bin/pip install \
  google-api-python-client google-auth google-auth-oauthlib google-auth-httplib2
```

---

## 📝 Langkah 7: Script Python

Script sudah tersedia di repository:

```text
python/backup_db_to_drive.py
```

Fitur script:

| Fitur | Keterangan |
|-------|------------|
| Dump database | `mysqldump` + gzip ke `/tmp` |
| Upload Drive | OAuth 2.0, folder ID yang Anda set |
| Retention | Hapus backup Drive > 180 hari |
| Telegram | Notifikasi sukses/gagal (jika token diisi di Pengaturan) |
| Kredensial DB | Dibaca otomatis dari `config/db_setting.ini` |

---

## 🧪 Langkah 8: Autentikasi Pertama (SSH Tunnel)

OAuth membutuhkan browser sekali saat pertama kali. Dari komputer lokal, buka tunnel SSH:

```bash
ssh -L 8080:localhost:8080 root@IP_SERVER
```

Di terminal server (session terpisah), jalankan:

```bash
sudo -u www-data /var/www/netbackup/venv/bin/python3 \
  /var/www/netbackup/python/backup_db_to_drive.py
```

Ikuti URL autentikasi yang muncul di browser lokal. Setelah sukses, token disimpan ke:

```text
/var/www/netbackup/config/token.json
```

---

## ⏰ Langkah 9: Cron Job Harian

Contoh backup database setiap hari jam 03:00:

```bash
sudo crontab -u www-data -e
```

Tambahkan:

```bash
0 3 * * * /var/www/netbackup/venv/bin/python3 /var/www/netbackup/python/backup_db_to_drive.py >> /var/log/netbackup_gdrive.log 2>&1
```

Sesuaikan path jika instalasi di folder domain custom.

---

## 🛠️ Troubleshooting

### Port 8080 sudah dipakai

```bash
ss -lptn 'sport = :8080'
sudo kill -9 PID
```

### Error `access_denied`

Tambahkan email Gmail Anda ke **Test Users** di OAuth Consent Screen Google Cloud.

### Error `invalid_scope`

Pastikan scope di script tetap:

```python
scopes = ["https://www.googleapis.com/auth/drive"]
```

### Error `storageQuotaExceeded`

Pastikan menggunakan **OAuth Desktop App**, bukan Service Account.

### Notifikasi Telegram tidak terkirim

Isi **Bot Token** dan **Chat ID** di menu **Pengaturan** aplikasi NetBackup (tabel `sys_settings`).

---

## 🤝 Lisensi

MIT License
