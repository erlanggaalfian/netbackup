-- File: sql/schema.sql
-- Deskripsi: Skema basis data relasional MySQL/MariaDB untuk menyimpan konfigurasi aplikasi NetBackup.

-- Langkah 1: Inisialisasi Database
CREATE DATABASE IF NOT EXISTS netbackup_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE netbackup_db;

-- 1. Tabel Users (Untuk Autentikasi & Sesi)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100),
  `role` ENUM('admin', 'user') DEFAULT 'user',
  `remember_token` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tabel Vendor (Manajemen Perintah per Vendor)
CREATE TABLE IF NOT EXISTS `vendor` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(64) NOT NULL UNIQUE,
  `command` VARCHAR(255) NOT NULL, -- Perintah spesifik untuk ambil config
  `is_interactive` TINYINT(1) NOT NULL DEFAULT 0, -- 1 Jika butuh invoke_shell (Arista/ZTE), 0 jika exec_command biasa
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tabel Sites (Manajemen Lokasi / Wilayah)
CREATE TABLE IF NOT EXISTS `sites` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tabel Roles (Manajemen Tipe Perangkat: Core, Metro, Edge, dll)
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Tabel Devices (Data Utama Perangkat Jaringan)
CREATE TABLE IF NOT EXISTS `devices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(128) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `vendor_id` INT,
  `site_id` INT,
  `role_id` INT,
  `protocol` ENUM('SSH', 'Telnet') NOT NULL DEFAULT 'SSH',
  `port` INT NOT NULL DEFAULT 22,
  `username` VARCHAR(128) NOT NULL,
  `password_enc` TEXT NOT NULL, -- Menyimpan password perangkat yang sudah dienkripsi
  `status` ENUM('Online', 'Offline', 'Unknown') DEFAULT 'Unknown',
  `last_seen` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`vendor_id`) REFERENCES `vendor`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE SET NULL,
  KEY `idx_devices_site` (`site_id`),
  KEY `idx_devices_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Tabel Backups (Riwayat Metadata & Isi Konfigurasi)
CREATE TABLE IF NOT EXISTS `backups` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `device_id` INT NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `config` LONGTEXT, -- Menyimpan isi konfigurasi (LONGTEXT untuk data besar)
  `status` ENUM('success', 'failed') NOT NULL,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE,
  KEY `idx_backups_device_ts` (`device_id`, `timestamp`),
  KEY `idx_backups_device_last` (`device_id`, `timestamp`, `id`),
  KEY `idx_backups_timestamp` (`timestamp`),
  KEY `idx_backups_status_ts` (`status`, `timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Tabel Settings (Penjadwalan Otomatis per Perangkat)
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `device_id` INT NOT NULL UNIQUE,
  `schedule` ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'daily',
  `run_hour` INT NOT NULL DEFAULT 2, -- Jam (0-23)
  `run_day` INT NOT NULL DEFAULT 1,  -- Tanggal (1-28), untuk bulanan
  `run_weekday` INT NOT NULL DEFAULT 0, -- 0=Senin ... 6=Minggu
  `active` BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Tabel Sys Settings (Konfigurasi global)
CREATE TABLE IF NOT EXISTS `sys_settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Tabel Logs (Audit Trail & Aktivitas Sistem)
CREATE TABLE IF NOT EXISTS `logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `type` VARCHAR(32) NOT NULL, -- Login, Backup, Delete, dsb
  `actor` INT NULL, -- user_id yang melakukan aksi
  `target_id` INT NULL, -- ID dari device/backup yang terdampak
  `details` TEXT,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`actor`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Indeks performa (penting saat backup ribuan+ baris)
-- Indeks sudah didefinisikan di CREATE TABLE.
