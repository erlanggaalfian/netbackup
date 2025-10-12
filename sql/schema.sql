-- Skema Database NetBackup (Versi Final & Stabil)
-- Dibuat untuk MySQL 8+

-- Langkah 1: Buat database jika belum ada
CREATE DATABASE IF NOT EXISTS netbackup_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Langkah 2: Gunakan database yang baru dibuat
USE netbackup_db;

-- Langkah 3: Buat tabel-tabel yang dibutuhkan

-- Tabel untuk pengguna sistem
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  remember_token VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabel untuk vendor perangkat jaringan
CREATE TABLE IF NOT EXISTS vendor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  command VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Tabel untuk perangkat jaringan yang akan di-backup
CREATE TABLE IF NOT EXISTS devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  location VARCHAR(128) DEFAULT NULL,
  vendor_id INT NOT NULL,
  protocol ENUM('SSH','Telnet') NOT NULL DEFAULT 'SSH',
  port INT NOT NULL,
  username VARCHAR(128) NOT NULL,
  password_enc TEXT NOT NULL,
  last_seen TIMESTAMP NULL DEFAULT NULL,
  status ENUM('Online','Offline') DEFAULT 'Offline',
  CONSTRAINT fk_devices_vendor FOREIGN KEY (vendor_id) REFERENCES vendor(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Tabel untuk riwayat backup
CREATE TABLE IF NOT EXISTS backups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  config LONGTEXT,
  status ENUM('success','failure') NOT NULL,
  filename VARCHAR(255) NOT NULL,
  CONSTRAINT fk_backups_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabel untuk pengaturan jadwal backup (Struktur Final)
CREATE TABLE IF NOT EXISTS settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL UNIQUE,
  schedule ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
  run_hour INT NOT NULL DEFAULT 2, -- Jam (0-23)
  run_day INT NOT NULL DEFAULT 1,  -- Hari (1-28), relevan untuk bulanan
  active BOOLEAN NOT NULL DEFAULT TRUE,
  CONSTRAINT fk_settings_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabel untuk log aktivitas sistem
CREATE TABLE IF NOT EXISTS logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(32) NOT NULL,
  actor INT NULL,
  target_id INT NULL,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  details TEXT
) ENGINE=InnoDB;

-- Langkah 4: Masukkan data awal yang dibutuhkan

-- Data awal untuk vendor
INSERT IGNORE INTO vendor(name, command) VALUES
('mikrotik', '/export'),
('juniper', 'show configuration | display set'),
('cisco', 'show running-config'),
('zte', 'show running-config'),
('huawei', 'display current-configuration | no-more');
